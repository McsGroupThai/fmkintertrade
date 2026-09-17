<?php
declare(strict_types=1);

namespace Fmk;

/**
 * การเข้าสู่ระบบและการตรวจสิทธิ์
 *
 * หลักที่ยึด:
 *   - ตรวจทุกอย่างฝั่งเซิร์ฟเวอร์ ไม่ใช่ซ่อนปุ่มใน UI
 *   - ข้อความผิดพลาดไม่บอกว่าอีเมลมีอยู่จริงหรือไม่ (กันการไล่เดาว่ามีบัญชีไหนบ้าง)
 *   - ต่อให้ไม่มีบัญชีนั้น ก็ยังเสียเวลา hash เท่าเดิม (กันการเดาจากเวลาตอบกลับ)
 *   - จำกัดจำนวนครั้งที่ลองผิด ทั้งรายอีเมลและราย IP
 */
final class Auth
{
    /** hash หลอกไว้เทียบเวลาเมื่อไม่พบบัญชี — ไม่มีรหัสผ่านใดตรงกับค่านี้ */
    private const DUMMY_HASH = '$2y$12$usesomesillystringforsalttoburncyclesabcdefghijklmnopqrs';

    public const ERR_INVALID   = 'invalid';
    public const ERR_LOCKED    = 'locked';
    public const ERR_DISABLED  = 'disabled';

    /** ตัวเลือกการ hash — argon2id ถ้ามี ไม่งั้น bcrypt */
    public static function hashPassword(string $plain): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($plain, PASSWORD_ARGON2ID);
        }
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * พยายามเข้าสู่ระบบ
     *
     * @return array{ok:bool, error?:string, retry_after?:int, user?:array<string,mixed>}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip    = client_ip();

        $lock = self::lockState($email, $ip);
        if ($lock !== null) {
            self::record($email, $ip, false);
            Audit::log(null, 'login.blocked', 'user', $email, ['reason' => 'rate_limited']);
            return ['ok' => false, 'error' => self::ERR_LOCKED, 'retry_after' => $lock];
        }

        $user = Db::one(
            'SELECT id, email, password_hash, display_name, role, status, must_change_pw
               FROM users WHERE email = ?',
            [$email]
        );

        // ทำงานเท่ากันทั้งกรณีมีและไม่มีบัญชี เพื่อไม่ให้เวลาตอบกลับบอกใบ้
        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $okPw = password_verify($password, $hash);

        if (!is_array($user) || !$okPw) {
            self::record($email, $ip, false);
            Audit::log(null, 'login.failed', 'user', $email, ['ip' => $ip]);
            return ['ok' => false, 'error' => self::ERR_INVALID];
        }

        if ($user['status'] !== 'active') {
            self::record($email, $ip, false);
            Audit::log((int) $user['id'], 'login.disabled', 'user', (string) $user['id'], []);
            return ['ok' => false, 'error' => self::ERR_DISABLED];
        }

        // อัปเกรด hash ถ้ามาตรฐานเปลี่ยนไปแล้ว
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [
                self::hashPassword($password),
                $user['id'],
            ]);
        }

        self::record($email, $ip, true);
        Session::login((int) $user['id']);
        Csrf::rotate();

        Db::run(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [$ip, $user['id']]
        );
        Audit::log((int) $user['id'], 'login.success', 'user', (string) $user['id'], ['ip' => $ip]);

        unset($user['password_hash']);
        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        $u = Session::currentUser();
        if ($u !== null) {
            Audit::log((int) $u['id'], 'logout', 'user', (string) $u['id'], []);
        }
        Session::destroy();
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        return Session::currentUser();
    }

    /** บังคับว่าต้องล็อกอินก่อน ไม่งั้นเด้งไปหน้า login */
    public static function requireLogin(): array
    {
        $u = self::user();
        if ($u === null) {
            $to = $_SERVER['REQUEST_URI'] ?? '';
            $q  = is_string($to) && $to !== '' ? '?next=' . rawurlencode($to) : '';
            redirect(Config::get('admin_base', '/admin') . '/login.php' . $q);
        }

        /* รหัสผ่านที่ผู้ดูแลตั้งให้ ต้องเปลี่ยนเป็นของตัวเองก่อนใช้งานอย่างอื่น
           เพราะรหัสนั้นผ่านมือคนอื่นมาแล้ว อาจอยู่ในแชตหรือกระดาษโน้ตที่ไหนก็ได้
           ดักที่นี่ที่เดียวเพราะทุกหน้าผ่านทางนี้ ไม่มีหน้าไหนหลุดรอด */
        if ((int) ($u['must_change_pw'] ?? 0) === 1) {
            $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            if (!in_array($script, ['account.php', 'logout.php'], true)) {
                redirect(Config::get('admin_base', '/admin') . '/account.php?force=1');
            }
        }

        return $u;
    }

    /** บังคับบทบาท — ใช้กับหน้าที่เฉพาะ admin เท่านั้น */
    public static function requireRole(string $role): array
    {
        $u = self::requireLogin();
        if ($u['role'] !== $role) {
            Audit::log((int) $u['id'], 'access.denied', 'page', (string) ($_SERVER['REQUEST_URI'] ?? ''), [
                'need' => $role,
                'has'  => $u['role'],
            ]);
            self::denyPage($role, (string) $u['role'], (string) $u['email']);
        }
        return $u;
    }

    /**
     * หน้าแจ้งว่าไม่มีสิทธิ์
     *
     * เดิมขึ้นแค่ "คุณไม่มีสิทธิ์เข้าหน้านี้" บนหน้าเปล่า ๆ ไม่มีทางกลับ
     * ซึ่งทำให้ผู้ใช้งงว่าตัวเองทำอะไรผิด — โดยเฉพาะเมื่ออีเมลมีคำว่า admin
     * แต่สิทธิ์จริงเป็นผู้แก้ไขเนื้อหา จึงต้องบอกให้ชัดว่าต้องใช้สิทธิ์อะไร
     * ตอนนี้มีสิทธิ์อะไร และต้องทำอย่างไรต่อ
     */
    private static function denyPage(string $need, string $has, string $email): never
    {
        $label = static fn(string $r): string => $r === 'admin' ? 'ผู้ดูแลระบบ' : 'ผู้แก้ไขเนื้อหา';
        $base = (string) Config::get('admin_base', '/admin');
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        http_response_code(403);
        echo '<!doctype html><html lang="th"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<meta name="robots" content="noindex, nofollow"><title>ไม่มีสิทธิ์เข้าหน้านี้</title>'
           . '<style>'
           . 'body{margin:0;background:#F4F7F5;color:#1A1A1A;font:15px/1.7 \'Noto Sans Thai\',system-ui,sans-serif;'
           . 'display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}'
           . '.c{background:#fff;border:1px solid #DCE3DF;border-radius:16px;padding:32px;max-width:520px;width:100%}'
           . 'h1{font-size:19px;color:#0A2439;margin:0 0 10px}'
           . 'dl{display:grid;grid-template-columns:auto 1fr;gap:6px 18px;font-size:14px;margin:18px 0}'
           . 'dt{color:#5B6570}dd{margin:0;font-weight:600}'
           . 'p{margin:0 0 6px;color:#3B4148}'
           . '.n{font-size:13px;color:#5B6570;background:#F7FAF8;border:1px solid #DCE3DF;'
           . 'border-radius:9px;padding:12px 14px;margin-top:16px}'
           . 'a{display:inline-block;margin-top:18px;padding:11px 18px;border-radius:9px;'
           . 'background:#0E5B91;color:#fff;text-decoration:none;font-weight:700;font-size:14px}'
           . '</style></head><body><div class="c">'
           . '<h1>หน้านี้เปิดได้เฉพาะ' . $e($label($need)) . '</h1>'
           . '<p>บัญชีที่คุณใช้อยู่ยังไม่มีสิทธิ์พอ จึงไม่เห็นข้อมูลในหน้านี้</p>'
           . '<dl>'
           . '<dt>บัญชีที่ใช้อยู่</dt><dd>' . $e($email) . '</dd>'
           . '<dt>สิทธิ์ที่มี</dt><dd>' . $e($label($has)) . '</dd>'
           . '<dt>สิทธิ์ที่ต้องใช้</dt><dd>' . $e($label($need)) . '</dd>'
           . '</dl>'
           . '<div class="n">ถ้าคุณควรมีสิทธิ์นี้ ให้ผู้ดูแลระบบเข้าไปที่ '
           . '<strong>ผู้ใช้งาน</strong> แล้วเปลี่ยนสิทธิ์ของบัญชีนี้เป็น <strong>ผู้ดูแลระบบ</strong> ให้'
           . '</div>'
           . '<a href="' . $e($base) . '/index.php">← กลับไปหน้าหลัก</a>'
           . '</div></body></html>';
        exit;
    }

    /** ---------------------------------------------------- rate limiting */

    private static function record(string $email, string $ip, bool $ok): void
    {
        Db::run(
            'INSERT INTO login_attempts (email, ip, successful) VALUES (?, ?, ?)',
            [$email, $ip, $ok ? 1 : 0]
        );
    }

    /**
     * ถ้ายังถูกล็อกอยู่ คืนจำนวนวินาทีที่ต้องรอ ไม่งั้นคืน null
     */
    private static function lockState(string $email, string $ip): ?int
    {
        $window  = (int) Config::security('attempt_window_minutes');
        $maxMail = (int) Config::security('max_attempts_per_email');
        $maxIp   = (int) Config::security('max_attempts_per_ip');
        $lockMin = (int) Config::security('lockout_minutes');

        $byEmail = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND successful = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$email, $window]
        );
        $byIp = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip = ? AND successful = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, $window]
        );

        if ($byEmail < $maxMail && $byIp < $maxIp) {
            return null;
        }

        $last = Db::value(
            'SELECT MAX(created_at) FROM login_attempts
              WHERE (email = ? OR ip = ?) AND successful = 0',
            [$email, $ip]
        );
        $lastTs = is_string($last) ? strtotime($last) : time();
        $until  = $lastTs + ($lockMin * 60);

        return max(1, $until - time());
    }
}

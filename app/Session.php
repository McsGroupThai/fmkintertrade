<?php
declare(strict_types=1);

namespace Fmk;

/**
 * Session ที่ตรวจสอบฝั่งเซิร์ฟเวอร์
 *
 * cookie เก็บแค่ session id ส่วนสถานะ "ใครล็อกอินอยู่" เก็บในตาราง sessions
 * ทำแบบนี้เพื่อให้ยกเลิก session ได้จริง (ปิดบัญชีแล้วเตะออกทันที) และตรวจหมดอายุได้แน่นอน
 *
 * ในตารางเก็บ sha256 ของ session id ไม่ใช่ตัวจริง — ต่อให้ฐานข้อมูลรั่ว
 * ก็เอา id ไปสวมรอยไม่ได้
 */
final class Session
{
    private const KEY_USER = 'fmk_uid';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = self::isHttps();

        session_set_cookie_params([
            'lifetime' => 0,          // หมดอายุเมื่อปิดเบราว์เซอร์
            'path'     => '/',
            'httponly' => true,       // JavaScript อ่านไม่ได้
            'secure'   => $https,     // บน production ที่เป็น https จะเป็น true เอง
            'samesite' => 'Strict',   // กัน CSRF ข้ามเว็บอีกชั้น
        ]);
        session_name('FMKSESS');
        session_start();
    }

    /**
     * คำขอนี้มาทาง HTTPS หรือไม่
     *
     * โฮสต์หลายเจ้าวาง nginx หรือ Cloudflare ไว้หน้า Apache ทำให้ PHP เห็นเป็น http
     * ทั้งที่ผู้ใช้เปิด https อยู่ ถ้าดูแค่ $_SERVER['HTTPS'] คุกกี้จะไม่ติดธง Secure
     * แล้ววันหนึ่งที่มีใครเผลอเปิดเว็บด้วย http:// คุกกี้ล็อกอินจะวิ่งเป็นข้อความธรรมดา
     *
     * หัว X-Forwarded-Proto ปลอมได้ก็จริง แต่การปลอมทำได้แค่ "เพิ่ม" ธง Secure
     * ซึ่งทำให้เข้มขึ้นไม่ใช่หลวมลง จึงเชื่อได้อย่างปลอดภัยเฉพาะการตัดสินใจข้อนี้
     */
    public static function isHttps(): bool
    {
        $flag = (string) ($_SERVER['HTTPS'] ?? '');
        if ($flag !== '' && strtolower($flag) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public static function id(): string
    {
        return session_id() ?: '';
    }

    private static function hash(string $sid): string
    {
        return hash('sha256', $sid);
    }

    /**
     * รหัสของ session ปัจจุบันในรูปที่เก็บในตาราง
     *
     * ใช้ตอนเปลี่ยนรหัสผ่านแล้วอยากเตะ session อื่นออกทั้งหมด
     * แต่ให้คนที่กำลังเปลี่ยนอยู่ทำงานต่อได้โดยไม่ต้องล็อกอินใหม่
     */
    public static function currentHash(): ?string
    {
        self::start();
        $sid = self::id();
        return $sid === '' ? null : self::hash($sid);
    }

    /** เรียกหลังตรวจรหัสผ่านผ่านแล้วเท่านั้น */
    public static function login(int $userId): void
    {
        self::start();

        // ออก session id ใหม่ กัน session fixation
        session_regenerate_id(true);

        $_SESSION = [self::KEY_USER => $userId];

        $idleMin  = (int) Config::security('session_idle_minutes');
        $absHours = (int) Config::security('session_absolute_hours');

        Db::run(
            'INSERT INTO sessions (id, user_id, ip, user_agent, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))',
            [self::hash(self::id()), $userId, client_ip(), user_agent(), $absHours]
        );

        unset($idleMin);
    }

    /**
     * คืนข้อมูลผู้ใช้ที่ล็อกอินอยู่ หรือ null ถ้าไม่มี/หมดอายุ/ถูกปิดบัญชี
     *
     * @return array<string,mixed>|null
     */
    public static function currentUser(): ?array
    {
        self::start();

        $uid = $_SESSION[self::KEY_USER] ?? null;
        if (!is_int($uid)) {
            return null;
        }

        $idleMin = (int) Config::security('session_idle_minutes');

        $row = Db::one(
            'SELECT u.id, u.email, u.display_name, u.role, u.status, u.must_change_pw,
                    s.created_at AS session_started, s.last_seen_at, s.expires_at
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.id = ?
                AND s.user_id = ?
                AND s.expires_at > NOW()
                AND s.last_seen_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [self::hash(self::id()), $uid, $idleMin]
        );

        if ($row === null) {
            self::destroy();
            return null;
        }

        if ($row['status'] !== 'active') {
            // บัญชีถูกปิดระหว่างที่ยังล็อกอินค้างอยู่ — เตะออกทันที
            self::destroy();
            return null;
        }

        Db::run('UPDATE sessions SET last_seen_at = NOW() WHERE id = ?', [self::hash(self::id())]);

        return $row;
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            self::start();
        }

        $sid = self::id();
        if ($sid !== '') {
            try {
                Db::run('DELETE FROM sessions WHERE id = ?', [self::hash($sid)]);
            } catch (\Throwable) {
                // ถ้าฐานข้อมูลล่ม ก็ยังต้องล้าง session ฝั่งนี้ให้ได้
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Strict',
            ]);
        }
        session_destroy();
    }

    /** ลบ session ที่หมดอายุทิ้ง เรียกเป็นครั้งคราวก็พอ */
    public static function gc(): void
    {
        $idleMin = (int) Config::security('session_idle_minutes');
        Db::run(
            'DELETE FROM sessions
              WHERE expires_at <= NOW()
                 OR last_seen_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$idleMin]
        );
    }
}

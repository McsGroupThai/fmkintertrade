<?php
declare(strict_types=1);

namespace Fmk;

use RuntimeException;

/**
 * จัดการบัญชีผู้ใช้หลังบ้าน
 *
 * กฎทั้งหมดรวมไว้ที่นี่ที่เดียว หน้าเว็บกับคำสั่ง CLI จึงใช้กฎชุดเดียวกัน
 * ถ้ากระจายกฎไว้ในหน้าเว็บ วันหนึ่งจะมีทางเข้าที่ลืมตรวจ
 *
 * เรื่องที่ต้องระวังเป็นพิเศษ:
 *   - ห้ามปิดหรือลดสิทธิ์ผู้ดูแลระบบคนสุดท้าย ไม่งั้นจะไม่มีใครเข้าหลังบ้านได้อีกเลย
 *     และระบบนี้ไม่มีอีเมลกู้รหัส ทางแก้เดียวคือเข้าไปแก้ฐานข้อมูลตรง ๆ
 *   - เปลี่ยนรหัสผ่านแล้วต้องเตะ session อื่นของคนนั้นออก ไม่งั้นคนที่ขโมย session ไป
 *     ยังใช้ต่อได้ทั้งที่เจ้าของเปลี่ยนรหัสแล้ว
 */
final class Users
{
    /** @var array<string,string> */
    public const ROLES = [
        'admin'  => 'ผู้ดูแลระบบ',
        'editor' => 'ผู้แก้ไขเนื้อหา',
    ];

    /** @var array<string,string> */
    public const STATUSES = [
        'active'   => 'ใช้งานอยู่',
        'disabled' => 'ปิดการใช้งาน',
    ];

    public static function roleLabel(string $role): string
    {
        return self::ROLES[$role] ?? $role;
    }

    /* ------------------------------------------------------------ อ่าน */

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::all(
            'SELECT u.id, u.email, u.display_name, u.role, u.status, u.must_change_pw,
                    u.last_login_at, u.last_login_ip, u.created_at,
                    (SELECT COUNT(*) FROM sessions s
                      WHERE s.user_id = u.id AND s.expires_at > NOW()) AS active_sessions
               FROM users u
           ORDER BY u.role = "admin" DESC, u.email'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT id, email, display_name, role, status, must_change_pw,
                    last_login_at, created_at
               FROM users WHERE id = ?',
            [$id]
        );
    }

    public static function activeAdminCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
    }

    /* -------------------------------------------------------- ตรวจค่า */

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function validateEmail(string $email, ?int $exceptId = null): void
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('อีเมลไม่ถูกต้อง');
        }
        if (mb_strlen($email) > 190) {
            throw new RuntimeException('อีเมลยาวเกินไป');
        }
        $row = $exceptId === null
            ? Db::one('SELECT id FROM users WHERE email = ?', [$email])
            : Db::one('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId]);
        if ($row !== null) {
            throw new RuntimeException('อีเมลนี้มีบัญชีอยู่แล้ว');
        }
    }

    public static function validateRole(string $role): void
    {
        if (!isset(self::ROLES[$role])) {
            throw new RuntimeException('สิทธิ์ไม่ถูกต้อง');
        }
    }

    /**
     * ตรวจรหัสผ่าน
     *
     * ตั้งใจไม่บังคับให้ต้องมีตัวใหญ่/ตัวเลข/อักขระพิเศษ เพราะกฎแบบนั้นทำให้คนตั้ง
     * รหัสแบบ "Password1!" ซึ่งเดาง่ายกว่าวลียาว ๆ ธรรมดา — เน้นความยาวเป็นหลักแทน
     */
    public static function validatePassword(string $pw, string $email = ''): void
    {
        $min = (int) Config::security('min_password_length');

        if (mb_strlen($pw) < $min) {
            throw new RuntimeException("รหัสผ่านต้องยาวอย่างน้อย $min ตัวอักษร");
        }
        if (strlen($pw) > 200) {
            throw new RuntimeException('รหัสผ่านยาวเกินไป (สูงสุด 200 ตัวอักษร)');
        }
        if (trim($pw) !== $pw) {
            throw new RuntimeException('รหัสผ่านต้องไม่ขึ้นต้นหรือลงท้ายด้วยช่องว่าง');
        }

        $low = mb_strtolower($pw);

        $local = $email !== '' ? mb_strtolower((string) strstr($email . '@', '@', true)) : '';
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains($low, $local)) {
            throw new RuntimeException('รหัสผ่านต้องไม่มีชื่ออีเมลอยู่ข้างใน เพราะเป็นสิ่งแรกที่คนเดา');
        }

        foreach (['password', '12345678', 'qwerty', 'iloveyou', 'letmein', 'welcome',
                  'fmkinter', 'fmk12345', 'adminadmin', 'abcd1234'] as $bad) {
            if (str_contains($low, $bad)) {
                throw new RuntimeException('รหัสผ่านนี้เดาง่ายเกินไป ลองใช้วลีที่จำได้แต่คนอื่นเดาไม่ถูก');
            }
        }
    }

    /** สุ่มรหัสผ่านจาก CSPRNG ตัดอักขระที่อ่านผิดง่ายออก (0/O, 1/l/I) */
    public static function generatePassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    /* ------------------------------------------------------------ เขียน */

    /** @return array{id:int, password:string, generated:bool} */
    public static function create(
        string $email,
        string $displayName,
        string $role,
        string $password,
        int $byUserId
    ): array {
        $email = self::normalizeEmail($email);
        self::validateEmail($email);
        self::validateRole($role);

        $generated = $password === '';
        if ($generated) {
            $password = self::generatePassword();
        } else {
            self::validatePassword($password, $email);
        }

        Db::run(
            'INSERT INTO users (email, password_hash, display_name, role, must_change_pw)
             VALUES (?, ?, ?, ?, 1)',
            [$email, Auth::hashPassword($password), mb_substr(trim($displayName), 0, 120), $role]
        );
        $id = (int) Db::pdo()->lastInsertId();

        Audit::log($byUserId, 'user.created', 'user', (string) $id, ['email' => $email, 'role' => $role]);

        return ['id' => $id, 'password' => $password, 'generated' => $generated];
    }

    /**
     * แก้ชื่อ สิทธิ์ และสถานะของบัญชีอื่น
     *
     * @param int $actorId ผู้ที่กำลังกดแก้ ใช้กันไม่ให้แก้สิทธิ์หรือปิดบัญชีตัวเอง
     */
    public static function update(
        int $id,
        string $displayName,
        string $role,
        string $status,
        int $actorId
    ): void {
        $target = self::find($id);
        if ($target === null) {
            throw new RuntimeException('ไม่พบบัญชีนี้');
        }
        self::validateRole($role);
        if (!isset(self::STATUSES[$status])) {
            throw new RuntimeException('สถานะไม่ถูกต้อง');
        }

        if ($id === $actorId) {
            /* กันพลาดแบบคลาสสิก: เผลอลดสิทธิ์หรือปิดบัญชีตัวเองแล้วออกไปไหนไม่ได้
               ถ้าอยากลดสิทธิ์ตัวเอง ให้ผู้ดูแลอีกคนเป็นคนทำให้ */
            if ($role !== $target['role']) {
                throw new RuntimeException('เปลี่ยนสิทธิ์ของตัวเองไม่ได้ ให้ผู้ดูแลระบบอีกคนเป็นคนเปลี่ยนให้');
            }
            if ($status !== $target['status']) {
                throw new RuntimeException('ปิดบัญชีตัวเองไม่ได้');
            }
        }

        self::assertStillHasAdmin($target, $role, $status);

        Db::run(
            'UPDATE users SET display_name = ?, role = ?, status = ? WHERE id = ?',
            [mb_substr(trim($displayName), 0, 120), $role, $status, $id]
        );

        $changes = [];
        if ($role !== $target['role']) {
            $changes['role'] = $target['role'] . ' → ' . $role;
        }
        if ($status !== $target['status']) {
            $changes['status'] = $target['status'] . ' → ' . $status;
        }
        if ($displayName !== $target['display_name']) {
            $changes['display_name'] = 'เปลี่ยนชื่อที่แสดง';
        }

        /* ปิดบัญชีแล้วต้องเตะออกทันที ไม่ใช่รอให้ session หมดอายุเอง
           (Session::currentUser() ตรวจ status ให้อยู่แล้ว แต่ลบทิ้งเลยชัดเจนกว่า) */
        if ($status === 'disabled') {
            self::revokeSessions($id);
        }

        if ($changes !== []) {
            Audit::log($actorId, 'user.updated', 'user', (string) $id,
                ['email' => $target['email']] + $changes);
        }
    }

    /**
     * ผู้ดูแลตั้งรหัสผ่านใหม่ให้คนอื่น
     *
     * ตั้ง must_change_pw ไว้เสมอ เพราะรหัสนี้ผ่านมือคนอื่นมาแล้ว
     * เจ้าของบัญชีต้องเปลี่ยนเป็นของตัวเองก่อนใช้งาน
     *
     * @return array{password:string, generated:bool, revoked:int}
     */
    public static function resetPassword(int $id, string $password, int $actorId): array
    {
        $target = self::find($id);
        if ($target === null) {
            throw new RuntimeException('ไม่พบบัญชีนี้');
        }

        $generated = $password === '';
        if ($generated) {
            $password = self::generatePassword();
        } else {
            self::validatePassword($password, (string) $target['email']);
        }

        Db::run(
            'UPDATE users SET password_hash = ?, must_change_pw = 1 WHERE id = ?',
            [Auth::hashPassword($password), $id]
        );

        $revoked = self::revokeSessions($id);
        self::unlock((string) $target['email']);

        Audit::log($actorId, 'user.password_reset', 'user', (string) $id, [
            'email'   => $target['email'],
            'revoked' => $revoked,
        ]);

        return ['password' => $password, 'generated' => $generated, 'revoked' => $revoked];
    }

    /**
     * เจ้าของบัญชีเปลี่ยนรหัสผ่านของตัวเอง
     *
     * ต้องกรอกรหัสเดิมด้วยเสมอ เผื่อกรณีมีคนมานั่งที่เครื่องที่เปิดค้างไว้
     * session ปัจจุบันไม่ถูกเตะออก แต่ session อื่นทั้งหมดถูกเตะ
     */
    public static function changeOwnPassword(int $id, string $current, string $new, string $confirm): int
    {
        $row = Db::one('SELECT email, password_hash FROM users WHERE id = ?', [$id]);
        if ($row === null) {
            throw new RuntimeException('ไม่พบบัญชีนี้');
        }
        if (!password_verify($current, (string) $row['password_hash'])) {
            throw new RuntimeException('รหัสผ่านเดิมไม่ถูกต้อง');
        }
        if (!hash_equals($new, $confirm)) {
            throw new RuntimeException('รหัสผ่านใหม่สองช่องไม่ตรงกัน');
        }
        if (password_verify($new, (string) $row['password_hash'])) {
            throw new RuntimeException('รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสเดิม');
        }
        self::validatePassword($new, (string) $row['email']);

        Db::run(
            'UPDATE users SET password_hash = ?, must_change_pw = 0 WHERE id = ?',
            [Auth::hashPassword($new), $id]
        );

        $revoked = self::revokeSessions($id, Session::currentHash());

        Audit::log($id, 'user.password_changed', 'user', (string) $id, ['revoked_others' => $revoked]);

        return $revoked;
    }

    public static function updateOwnName(int $id, string $displayName): void
    {
        Db::run('UPDATE users SET display_name = ? WHERE id = ?',
            [mb_substr(trim($displayName), 0, 120), $id]);
        Audit::log($id, 'user.profile_updated', 'user', (string) $id, []);
    }

    public static function delete(int $id, int $actorId): void
    {
        $target = self::find($id);
        if ($target === null) {
            throw new RuntimeException('ไม่พบบัญชีนี้');
        }
        if ($id === $actorId) {
            throw new RuntimeException('ลบบัญชีตัวเองไม่ได้');
        }
        /* ลบทิ้งแล้วชื่อในประวัติการแก้ไขจะกลายเป็น "—" (audit_log ตั้ง ON DELETE SET NULL)
           ถ้าแค่อยากห้ามเข้าใช้ ควรปิดบัญชีแทน — หน้าเว็บบอกเรื่องนี้ไว้แล้ว */
        self::assertStillHasAdmin($target, 'editor', 'disabled');

        Db::run('DELETE FROM users WHERE id = ?', [$id]);
        Audit::log($actorId, 'user.deleted', 'user', (string) $id, ['email' => $target['email']]);
    }

    /* ------------------------------------------------------- session */

    /** ลบ session ของผู้ใช้คนนี้ทั้งหมด ยกเว้นตัวที่ระบุ — คืนจำนวนที่ลบ */
    public static function revokeSessions(int $userId, ?string $exceptHash = null): int
    {
        $stmt = $exceptHash === null
            ? Db::run('DELETE FROM sessions WHERE user_id = ?', [$userId])
            : Db::run('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$userId, $exceptHash]);
        return $stmt->rowCount();
    }

    public static function forceLogout(int $id, int $actorId): int
    {
        $target = self::find($id);
        if ($target === null) {
            throw new RuntimeException('ไม่พบบัญชีนี้');
        }
        $n = self::revokeSessions($id);
        Audit::log($actorId, 'user.sessions_revoked', 'user', (string) $id,
            ['email' => $target['email'], 'count' => $n]);
        return $n;
    }

    /** ล้างประวัติการลองรหัสผิด — ใช้ปลดล็อกคนที่พิมพ์รหัสผิดหลายครั้ง */
    public static function unlock(string $email): int
    {
        return Db::run('DELETE FROM login_attempts WHERE email = ?',
            [self::normalizeEmail($email)])->rowCount();
    }

    /** ตอนนี้ถูกล็อกจากการลองรหัสผิดอยู่หรือไม่ */
    public static function isLockedOut(string $email): bool
    {
        $window = (int) Config::security('attempt_window_minutes');
        $max    = (int) Config::security('max_attempts_per_email');
        $n = (int) Db::value(
            'SELECT COUNT(*) FROM login_attempts
              WHERE email = ? AND successful = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [self::normalizeEmail($email), $window]
        );
        return $n >= $max;
    }

    /* ------------------------------------------------------------ กฎ */

    /**
     * ห้ามเอาผู้ดูแลระบบที่ใช้งานอยู่คนสุดท้ายออกจากตำแหน่ง
     *
     * @param array<string,mixed> $target บัญชีก่อนแก้
     */
    private static function assertStillHasAdmin(array $target, string $newRole, string $newStatus): void
    {
        $wasActiveAdmin  = $target['role'] === 'admin' && $target['status'] === 'active';
        $willBeActiveAdmin = $newRole === 'admin' && $newStatus === 'active';

        if ($wasActiveAdmin && !$willBeActiveAdmin && self::activeAdminCount() <= 1) {
            throw new RuntimeException(
                'นี่คือผู้ดูแลระบบที่ใช้งานอยู่คนสุดท้าย ถ้าเอาออกจะไม่มีใครเข้าหลังบ้านได้อีกเลย '
                . 'ให้ตั้งผู้ดูแลระบบอีกคนก่อน แล้วค่อยกลับมาทำรายการนี้'
            );
        }
    }
}

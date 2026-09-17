<?php
declare(strict_types=1);

namespace Fmk;

use RuntimeException;

/**
 * ข้อความจากแบบฟอร์มติดต่อบนหน้าเว็บ
 *
 * จุดสำคัญที่สุดคือ **ต้องไม่ทำข้อความของลูกค้าหาย**
 * ทุกอย่างที่ทำหลังจากบันทึกลงฐานข้อมูลสำเร็จแล้ว (เช่นส่งอีเมลแจ้งเตือน)
 * ต้องไม่มีทางทำให้คำขอล้มเหลว ฐานข้อมูลคือแหล่งข้อมูลจริง อีเมลเป็นแค่ของแถม
 *
 * กันสแปมโดยไม่พึ่งบริการภายนอก (ห้ามเพิ่มค่าใช้จ่ายรายเดือน):
 *   - honeypot  ช่องที่คนมองไม่เห็น ถ้ามีค่าแปลว่าเป็นบอท
 *   - time trap กรอกฟอร์ม 11 ช่องใน 3 วินาทีคือเป็นไปไม่ได้สำหรับคน
 *   - rate limit จำกัดจำนวนครั้งต่อ IP ต่อชั่วโมง
 *   - จำกัดความยาวทุกช่อง และนับจำนวนลิงก์ในข้อความ
 */
final class Messages
{
    /** ช่องล่อบอท — ชื่อต้องดูน่ากรอกสำหรับบอท แต่คนมองไม่เห็น */
    public const HONEYPOT = 'website';

    /** เร็วกว่านี้ไม่ใช่คนกรอก (วินาที) */
    private const MIN_FILL_SECONDS = 3;

    /** แบบฟอร์มเปิดค้างไว้นานกว่านี้ถือว่าหมดอายุ (วินาที) */
    private const MAX_FORM_AGE = 86400;

    private const MAX_PER_IP_PER_HOUR = 5;
    private const MAX_PER_IP_PER_DAY  = 20;

    /** ความยาวสูงสุดของแต่ละช่อง — ต้องไม่เกินขนาดคอลัมน์ในฐานข้อมูล */
    private const LIMITS = [
        'fullName'      => 120,
        'company'       => 120,
        'position'      => 120,
        'country'       => 60,
        'email'         => 180,
        'phone'         => 40,
        'solution'      => 100,
        'projectType'   => 100,
        'message'       => 4000,
        'contactMethod' => 30,
    ];

    /* ---------------------------------------------------------- รับข้อความ */

    /**
     * ตรวจและบันทึกข้อความจากฟอร์ม
     *
     * @param array<string,mixed> $in ข้อมูลดิบจาก $_POST
     * @return array{ok:bool, id?:int, error?:string, fields?:array<string,string>}
     */
    public static function submit(array $in, string $lang = 'th'): array
    {
        $ip = client_ip();

        /* ---- ด่านบอท ทำก่อนอย่างอื่นเพื่อไม่ให้เปลืองแรงเครื่อง ---- */
        if (trim((string) ($in[self::HONEYPOT] ?? '')) !== '') {
            self::block($ip, 'honeypot', '');
            /* ตอบว่าสำเร็จ เพื่อไม่ให้บอทรู้ว่าโดนจับได้แล้วไปปรับวิธี
               ข้อความไม่ถูกบันทึก แต่บอทไม่มีทางรู้ */
            return ['ok' => true, 'id' => 0];
        }

        $age = self::formAge($in['ts'] ?? null);
        if ($age !== null && $age < self::MIN_FILL_SECONDS) {
            self::block($ip, 'too_fast', $age . ' วินาที');
            return ['ok' => true, 'id' => 0];
        }
        if ($age !== null && $age > self::MAX_FORM_AGE) {
            return ['ok' => false, 'error' => 'expired'];
        }

        /* ---- จำกัดจำนวนครั้ง ---- */
        if (self::countFromIp($ip, 60) >= self::MAX_PER_IP_PER_HOUR
            || self::countFromIp($ip, 1440) >= self::MAX_PER_IP_PER_DAY) {
            self::block($ip, 'rate_limit', '');
            return ['ok' => false, 'error' => 'rate_limit'];
        }

        /* ---- ตรวจข้อมูล ---- */
        $f = [];
        foreach (array_keys(self::LIMITS) as $k) {
            $raw = is_string($in[$k] ?? null) ? $in[$k] : '';
            // ตัดอักขระควบคุมทิ้ง เหลือแค่ขึ้นบรรทัดใหม่กับแท็บ
            $raw = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
            $f[$k] = mb_substr(trim($raw), 0, self::LIMITS[$k]);
        }
        $consent = !empty($in['consent']);

        $errors = [];
        foreach (['fullName', 'company', 'email', 'phone'] as $k) {
            if ($f[$k] === '') {
                $errors[$k] = 'required';
            }
        }
        if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'email';
        }
        /* เบอร์โทร: ยอมรับเลข ช่องว่าง + - ( ) เท่านั้น และต้องมีเลขอย่างน้อย 6 ตัว
           กว้างพอสำหรับเบอร์ต่างประเทศ แต่ไม่ปล่อยให้ยัดข้อความมั่ว ๆ เข้ามา */
        if ($f['phone'] !== '') {
            $digits = preg_replace('/\D/', '', $f['phone']);
            if (!preg_match('/^[0-9 +\-().]+$/', $f['phone']) || mb_strlen((string) $digits) < 6) {
                $errors['phone'] = 'phone';
            }
        }
        if (!$consent) {
            $errors['consent'] = 'consent';
        }

        if ($errors !== []) {
            return ['ok' => false, 'error' => 'validation', 'fields' => $errors];
        }

        /* ข้อความที่ยัดลิงก์มาเป็นพรืดคือสแปมแทบทั้งหมด */
        if (preg_match_all('#https?://#i', $f['message']) > 4) {
            self::block($ip, 'too_many_links', '');
            return ['ok' => true, 'id' => 0];
        }

        /* ---- บันทึก ---- */
        Db::run(
            'INSERT INTO contact_messages
                (full_name, company, position, country, email, phone, solution,
                 project_type, message, contact_method, consent, lang, ip, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $f['fullName'], $f['company'], $f['position'], $f['country'],
                $f['email'], $f['phone'], $f['solution'], $f['projectType'],
                $f['message'], $f['contactMethod'], $consent ? 1 : 0,
                $lang === 'en' ? 'en' : 'th', $ip, user_agent(),
            ]
        );
        $id = (int) Db::pdo()->lastInsertId();

        Audit::log(null, 'contact.received', 'message', (string) $id, ['company' => $f['company']]);

        /* แจ้งเตือนทางอีเมลเป็นของแถม ล้มเหลวได้โดยไม่กระทบข้อความที่บันทึกไว้แล้ว */
        try {
            self::notify($id, $f);
        } catch (\Throwable) {
            // เงียบไว้ — ข้อความปลอดภัยอยู่ในฐานข้อมูลแล้ว
        }

        return ['ok' => true, 'id' => $id];
    }

    /** อายุของฟอร์มเป็นวินาที หรือ null ถ้าไม่ได้ส่ง ts มา */
    private static function formAge(mixed $ts): ?int
    {
        if (!is_string($ts) || !ctype_digit($ts)) {
            return null;
        }
        $age = time() - ((int) $ts);
        return $age < 0 ? 0 : $age;
    }

    private static function countFromIp(string $ip, int $minutes): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM contact_messages
              WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, $minutes]
        );
    }

    private static function block(string $ip, string $reason, string $detail): void
    {
        try {
            Db::run('INSERT INTO contact_blocked (ip, reason, detail) VALUES (?, ?, ?)',
                [$ip, $reason, mb_substr($detail, 0, 255)]);
        } catch (\Throwable) {
            // การบันทึกสถิติล้มเหลวต้องไม่ทำให้คำขอพัง
        }
    }

    /**
     * แจ้งเตือนผู้ดูแลทางอีเมล
     *
     * ใช้ mail() ของ PHP ซึ่งใช้เมลเซิร์ฟเวอร์ของโฮสต์เอง ไม่ใช่บริการภายนอก
     * และไม่มีค่าใช้จ่ายเพิ่ม แต่ยังไม่ได้ทดสอบบนโฮสต์จริง จึง **ปิดไว้เป็นค่าเริ่มต้น**
     * เปิดได้โดยใส่ contact_notify_email ใน config.local.php
     *
     * @param array<string,string> $f
     */
    private static function notify(int $id, array $f): void
    {
        $to = (string) Config::get('contact_notify_email', '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || !function_exists('mail')) {
            return;
        }

        $subject = '[FMK] มีคำขอติดต่อใหม่ #' . $id . ' จาก ' . $f['company'];
        $body = "มีคำขอติดต่อใหม่จากเว็บไซต์\n\n"
              . "ชื่อ      : {$f['fullName']}\n"
              . "บริษัท    : {$f['company']}\n"
              . "ตำแหน่ง   : {$f['position']}\n"
              . "ประเทศ    : {$f['country']}\n"
              . "อีเมล     : {$f['email']}\n"
              . "โทร       : {$f['phone']}\n"
              . "สนใจ      : {$f['solution']}\n"
              . "ประเภท    : {$f['projectType']}\n"
              . "ติดต่อทาง : {$f['contactMethod']}\n\n"
              . "ข้อความ:\n{$f['message']}\n\n"
              . "อ่านและตอบกลับได้ที่หลังบ้าน > กล่องข้อความ\n";

        /* หัวจดหมายต้องไม่มีขึ้นบรรทัดใหม่ปนมา ไม่งั้นจะถูกยัดหัวจดหมายเพิ่มได้
           (header injection) — ตรงนี้ข้อมูลมาจากผู้ใช้ภายนอกทั้งหมด */
        $clean = static fn(string $s): string => (string) preg_replace('/[\r\n]+/', ' ', $s);

        @mail(
            $to,
            '=?UTF-8?B?' . base64_encode($clean($subject)) . '?=',
            $body,
            "From: เว็บไซต์ FMK <noreply@" . $clean((string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . ">\r\n"
            . 'Content-Type: text/plain; charset=UTF-8'
        );
    }

    /* ------------------------------------------------------- ฝั่งผู้ดูแล */

    public static function unreadCount(): int
    {
        try {
            return (int) Db::value("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
        } catch (\Throwable) {
            return 0;   // ยังไม่ได้รัน migration — อย่าให้หน้าอื่นพังตาม
        }
    }

    /** @return array{rows:array<int,array<string,mixed>>, total:int} */
    public static function page(string $status = 'new', int $page = 1, int $perPage = 20): array
    {
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        if ($status === 'all') {
            $total = (int) Db::value('SELECT COUNT(*) FROM contact_messages');
            $rows = Db::all(
                "SELECT * FROM contact_messages ORDER BY id DESC LIMIT $perPage OFFSET $offset"
            );
        } else {
            $status = in_array($status, ['new', 'read', 'archived'], true) ? $status : 'new';
            $total = (int) Db::value('SELECT COUNT(*) FROM contact_messages WHERE status = ?', [$status]);
            $rows = Db::all(
                "SELECT * FROM contact_messages WHERE status = ? ORDER BY id DESC LIMIT $perPage OFFSET $offset",
                [$status]
            );
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM contact_messages WHERE id = ?', [$id]);
    }

    public static function setStatus(int $id, string $status, int $userId): void
    {
        if (!in_array($status, ['new', 'read', 'archived'], true)) {
            throw new RuntimeException('สถานะไม่ถูกต้อง');
        }
        if (self::find($id) === null) {
            throw new RuntimeException('ไม่พบข้อความนี้');
        }

        if ($status === 'new') {
            Db::run("UPDATE contact_messages SET status = 'new', read_at = NULL, read_by = NULL WHERE id = ?", [$id]);
        } else {
            Db::run(
                'UPDATE contact_messages SET status = ?, read_at = NOW(), read_by = ? WHERE id = ?',
                [$status, $userId, $id]
            );
        }
        Audit::log($userId, 'contact.status_changed', 'message', (string) $id, ['status' => $status]);
    }

    /** เปิดอ่านแล้วให้ถือว่าอ่านแล้วโดยอัตโนมัติ ผู้ดูแลจะได้ไม่ต้องกดซ้ำ */
    public static function markReadOnView(int $id, int $userId): void
    {
        Db::run(
            "UPDATE contact_messages SET status = 'read', read_at = NOW(), read_by = ?
              WHERE id = ? AND status = 'new'",
            [$userId, $id]
        );
    }

    public static function delete(int $id, int $userId): void
    {
        if (self::find($id) === null) {
            throw new RuntimeException('ไม่พบข้อความนี้');
        }
        Db::run('DELETE FROM contact_messages WHERE id = ?', [$id]);
        Audit::log($userId, 'contact.deleted', 'message', (string) $id, []);
    }

    /**
     * ส่งออกเป็น CSV ให้เปิดใน Excel ได้
     *
     * ใส่ BOM ไว้ข้างหน้า ไม่งั้น Excel บน Windows จะอ่านภาษาไทยเป็นตัวต่างดาว
     * และเติม ' หน้าค่าที่ขึ้นต้นด้วย = + - @ กัน Excel ตีความเป็นสูตร (CSV injection)
     */
    public static function toCsv(): string
    {
        $rows = Db::all('SELECT * FROM contact_messages ORDER BY id DESC');

        $head = ['เลขที่', 'วันที่', 'สถานะ', 'ชื่อ', 'บริษัท', 'ตำแหน่ง', 'ประเทศ',
                 'อีเมล', 'โทรศัพท์', 'โซลูชัน', 'ประเภทโครงการ', 'ติดต่อทาง', 'ภาษา', 'ข้อความ'];

        $safe = static function (mixed $v): string {
            $s = (string) $v;
            return $s !== '' && str_contains("=+-@\t\r", $s[0]) ? "'" . $s : $s;
        };

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('สร้างไฟล์ชั่วคราวไม่ได้');
        }
        fputcsv($fh, $head);
        foreach ($rows as $r) {
            fputcsv($fh, array_map($safe, [
                $r['id'], $r['created_at'], $r['status'], $r['full_name'], $r['company'],
                $r['position'], $r['country'], $r['email'], $r['phone'], $r['solution'],
                $r['project_type'], $r['contact_method'], $r['lang'], $r['message'],
            ]));
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return "\xEF\xBB\xBF" . $csv;
    }

    /** @return array<int,array<string,mixed>> สถิติการปฏิเสธล่าสุด */
    public static function blockedSummary(int $days = 7): array
    {
        return Db::all(
            'SELECT reason, COUNT(*) AS n, MAX(created_at) AS last_at
               FROM contact_blocked
              WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
           GROUP BY reason
           ORDER BY n DESC',
            [$days]
        );
    }

    public static function reasonLabel(string $r): string
    {
        return [
            'honeypot'       => 'บอทกรอกช่องที่คนมองไม่เห็น',
            'too_fast'       => 'กรอกเร็วเกินกว่าที่คนจะทำได้',
            'rate_limit'     => 'ส่งถี่เกินกำหนด',
            'too_many_links' => 'ยัดลิงก์มาเป็นจำนวนมาก',
        ][$r] ?? $r;
    }
}

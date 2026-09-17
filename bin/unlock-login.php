<?php
declare(strict_types=1);

/*
 * ปลดล็อกการเข้าสู่ระบบ
 *
 *   php bin/unlock-login.php              ล้างประวัติการลองผิดทั้งหมด
 *   php bin/unlock-login.php <อีเมล>       ล้างเฉพาะอีเมลนั้น
 *   php bin/unlock-login.php --status     ดูว่าตอนนี้ใครถูกล็อกอยู่บ้าง
 *
 * ใช้เมื่อ:
 *   - ผู้ดูแลลองรหัสผิดหลายครั้งจนถูกล็อก และรอ 15 นาทีไม่ไหว
 *   - รันชุดทดสอบซ้ำ ๆ จน IP ของตัวเองถูกนับว่าลองผิดเกินเกณฑ์
 *
 * หมายเหตุ: ตารางนี้เก็บแค่สถิติการพยายามเข้าระบบ ไม่มีเนื้อหาเว็บ
 * การล้างจึงไม่กระทบงานของผู้ดูแลเลย
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Config;
use Fmk\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arg = $argv[1] ?? '';
$window = (int) Config::security('attempt_window_minutes');

if ($arg === '--status') {
    $rows = Db::all(
        'SELECT email, ip, COUNT(*) AS fails, MAX(created_at) AS last_try
           FROM login_attempts
          WHERE successful = 0 AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
       GROUP BY email, ip
       ORDER BY fails DESC',
        [$window]
    );
    if ($rows === []) {
        echo "ไม่มีใครถูกล็อกอยู่\n";
        exit(0);
    }
    printf("การลองผิดใน %d นาทีที่ผ่านมา:\n", $window);
    printf("  %-34s %-16s %5s  %s\n", 'อีเมล', 'IP', 'ครั้ง', 'ล่าสุด');
    foreach ($rows as $r) {
        printf("  %-34s %-16s %5d  %s\n", $r['email'] ?: '(ว่าง)', $r['ip'], $r['fails'], $r['last_try']);
    }
    printf("\nเกณฑ์: %d ครั้งต่ออีเมล · %d ครั้งต่อ IP\n",
        (int) Config::security('max_attempts_per_email'),
        (int) Config::security('max_attempts_per_ip'));
    exit(0);
}

if ($arg !== '') {
    $n = Db::run('DELETE FROM login_attempts WHERE email = ?', [mb_strtolower(trim($arg))])->rowCount();
    echo "ล้างประวัติการลองผิดของ $arg แล้ว ($n รายการ)\n";
} else {
    $n = Db::run('DELETE FROM login_attempts')->rowCount();
    echo "ล้างประวัติการลองผิดทั้งหมดแล้ว ($n รายการ)\n";
}
echo "เข้าสู่ระบบได้ทันที\n";

<?php
declare(strict_types=1);

/*
 * จุดเริ่มต้นร่วมของทุกหน้าหลังบ้าน
 *
 * โฟลเดอร์นี้ตั้งใจให้อยู่ "นอก" ส่วนที่เว็บเข้าถึงได้
 * บนโฮสติ้งจริง public_html จะชี้ไปที่ public/ เท่านั้น app/ กับ config/ จะเรียกผ่าน URL ไม่ได้
 */

namespace Fmk;

require_once __DIR__ . '/helpers.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Fmk\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// เขียน log ไว้อ่าน ไม่โชว์ error ให้ผู้ใช้เห็นบน production
$isProd = Config::isProduction();
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

date_default_timezone_set('Asia/Bangkok');

if (PHP_SAPI !== 'cli') {
    send_security_headers();
}

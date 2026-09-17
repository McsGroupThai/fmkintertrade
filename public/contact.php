<?php
declare(strict_types=1);

/*
 * ปลายทางของแบบฟอร์มติดต่อบนหน้าเว็บสาธารณะ
 *
 * หน้าเว็บ (index.html) ยังเป็นไฟล์ static เหมือนเดิม ไฟล์นี้ถูกเรียกเฉพาะ
 * ตอนกดส่งฟอร์มเท่านั้น ถ้า PHP หรือฐานข้อมูลล่ม หน้าเว็บก็ยังแสดงได้ครบ
 * แค่ส่งฟอร์มไม่ได้ และจะมีข้อความบอกให้โทรหรืออีเมลแทน
 *
 * ไม่มีการล็อกอิน ใครก็ยิงเข้ามาได้ การตรวจทุกอย่างจึงอยู่ใน Fmk\Messages
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Messages;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** ตอบกลับแล้วจบ */
function reply(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'method']);
}

/* กันคนอื่นเอาฟอร์มไปวางบนเว็บตัวเองแล้วยิงเข้ามา
   ไม่ใช่ด่านหลัก (ปลอม Origin ได้) แต่ตัดขยะส่วนใหญ่ออกได้ฟรี ๆ */
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    /* HTTP_HOST มีพอร์ตติดมาด้วยเมื่อไม่ใช่พอร์ตมาตรฐาน (เช่น example.com:8080)
       ส่วน parse_url(..., PHP_URL_HOST) ตัดพอร์ตออกเสมอ ถ้าเทียบตรง ๆ จะไม่มีวันตรงกัน
       และฟอร์มติดต่อจะถูกปฏิเสธทั้งหมด — เจอตอนซ้อมติดตั้งบนพอร์ตที่ไม่ใช่ 80
       จึงตัดพอร์ตออกจากทั้งสองฝั่งก่อนเทียบ และเทียบแบบไม่สนตัวพิมพ์เพราะชื่อโดเมนไม่สนอยู่แล้ว */
    $hostRaw = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $host = mb_strtolower((string) parse_url('//' . $hostRaw, PHP_URL_HOST));
    $originHost = mb_strtolower((string) parse_url($origin, PHP_URL_HOST));
    if ($host !== '' && $originHost !== '' && !hash_equals($host, $originHost)) {
        reply(403, ['ok' => false, 'error' => 'origin']);
    }
}

/* ขนาดคำขอต้องไม่ใหญ่เกินเหตุ ฟอร์มนี้มีแค่ 11 ช่องข้อความ */
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 64 * 1024) {
    reply(413, ['ok' => false, 'error' => 'too_large']);
}

$lang = (is_string($_POST['lang'] ?? null) && $_POST['lang'] === 'en') ? 'en' : 'th';

try {
    $result = Messages::submit($_POST, $lang);
} catch (Throwable $ex) {
    /* ข้อความของลูกค้าสำคัญเกินกว่าจะปล่อยให้หายเงียบ ๆ — เขียน log ไว้เสมอ
       แต่ไม่บอกรายละเอียดภายในกับคนภายนอก */
    error_log('contact.php ล้มเหลว: ' . $ex->getMessage());
    reply(500, ['ok' => false, 'error' => 'server']);
}

reply($result['ok'] ? 200 : 422, $result);

<?php
declare(strict_types=1);

/*
 * นำเนื้อหาจาก content.json เข้าฐานข้อมูลเป็นฉบับร่างตั้งต้น
 *
 *   php bin/import-content.php [--force]
 *
 * ปกติรันครั้งเดียวตอนเริ่มระบบ ถ้ามีฉบับร่างอยู่แล้วจะไม่เขียนทับ
 * เว้นแต่ใส่ --force ซึ่งจะทิ้งงานที่ยังไม่ได้เผยแพร่ทั้งหมด
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Content;
use Fmk\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$force = in_array('--force', array_slice($argv, 1), true);

$existing = Db::one('SELECT updated_at FROM content_draft WHERE id = 1');
if ($existing !== null && !$force) {
    echo "มีฉบับร่างอยู่แล้ว (แก้ล่าสุด {$existing['updated_at']}) — ไม่เขียนทับ\n";
    echo "ถ้าต้องการล้างแล้วนำเข้าใหม่ ใส่ --force\n";
    exit(0);
}

$path = dirname(__DIR__) . '/content.json';
$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "อ่าน content.json ไม่ได้\n");
    exit(1);
}

$content = json_decode($raw, true);
if (!is_array($content)) {
    fwrite(STDERR, "content.json ไม่ใช่ JSON ที่ถูกต้อง\n");
    exit(1);
}

// เก็บเฉพาะส่วนที่เป็นเนื้อหาจริง ตัด metadata ของไฟล์ทิ้ง
$content = [
    'company' => $content['company'] ?? [],
    'i18n'    => $content['i18n'] ?? [],
];

// ทุกรายการในลิสต์ได้ธง visible เพื่อให้หลังบ้านสั่งซ่อนได้
$content = markVisible($content);

try {
    Content::validate($content);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

Db::run(
    'INSERT INTO content_draft (id, data, updated_by) VALUES (1, ?, NULL)
     ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = NULL',
    [Content::encode($content)]
);

echo "นำเข้าเนื้อหาเรียบร้อย\n";
echo '  ขนาด : ' . number_format(strlen(Content::encode($content))) . " bytes\n";
echo '  ภาษา : ' . implode(', ', array_keys($content['i18n'])) . "\n";
echo "\nขั้นต่อไป: เข้าหลังบ้านแล้วกดเผยแพร่เพื่อสร้าง public/index.html จากฐานข้อมูล\n";

/** ใส่ visible = true ให้ทุกรายการที่เป็น object ในลิสต์ */
function markVisible(mixed $node): mixed
{
    if (!is_array($node)) {
        return $node;
    }
    if (array_is_list($node)) {
        $out = [];
        foreach ($node as $item) {
            if (is_array($item) && !array_is_list($item)) {
                $item = ['visible' => true] + markVisible($item);
            } else {
                $item = markVisible($item);
            }
            $out[] = $item;
        }
        return $out;
    }
    $out = [];
    foreach ($node as $k => $v) {
        $out[$k] = markVisible($v);
    }
    return $out;
}

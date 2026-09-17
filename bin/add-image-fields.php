<?php
declare(strict_types=1);

/*
 * เพิ่มช่องใส่รูปลงในเนื้อหาที่มีอยู่ (รันครั้งเดียว)
 *
 *   php bin/add-image-fields.php
 *
 * ช่องที่เพิ่มจะเป็นค่าว่างทั้งหมด หน้าเว็บจึงยังแสดงเหมือนเดิมทุกประการ
 * จนกว่าผู้ดูแลจะเลือกรูปใส่เอง
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Content;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$c = Content::draft();
$added = [];

/** ใส่คีย์ถ้ายังไม่มี */
function ensure(array &$node, string $key, string $label, array &$added): void
{
    if (!array_key_exists($key, $node)) {
        $node[$key] = '';
        $added[] = $label;
    }
}

// โลโก้ — ใช้ร่วมทั้งสองภาษา จึงอยู่ใน company
ensure($c['company'], 'logo', 'โลโก้แถบบน', $added);
ensure($c['company'], 'logoSeal', 'ตราสัญลักษณ์ท้ายเว็บ', $added);

foreach (['en', 'th'] as $lang) {
    $L = &$c['i18n'][$lang];

    ensure($L['hero'], 'image', "แบนเนอร์หลัก ($lang)", $added);
    ensure($L['hero'], 'imageAlt', "คำบรรยายแบนเนอร์ ($lang)", $added);

    ensure($L['about'], 'image', "ภาพส่วนเกี่ยวกับเรา ($lang)", $added);
    ensure($L['about'], 'imageAlt', "คำบรรยายภาพเกี่ยวกับเรา ($lang)", $added);

    foreach (['projects', 'knowledge', 'solutions'] as $sec) {
        if (!isset($L[$sec]['items']) || !is_array($L[$sec]['items'])) {
            continue;
        }
        foreach ($L[$sec]['items'] as $i => &$item) {
            if (!is_array($item)) {
                continue;
            }
            ensure($item, 'image', "รูป $sec รายการที่ " . ($i + 1) . " ($lang)", $added);
            ensure($item, 'imageAlt', "คำบรรยายรูป $sec รายการที่ " . ($i + 1) . " ($lang)", $added);
        }
        unset($item);
    }
    unset($L);
}

if ($added === []) {
    echo "มีช่องรูปครบอยู่แล้ว ไม่ต้องเพิ่ม\n";
    exit(0);
}

Content::validate($c);
Content::saveDraft($c, 1);

echo 'เพิ่มช่องใส่รูป ', count($added), " ช่อง\n";
echo "  โลโก้ 2 · แบนเนอร์ 1 · เกี่ยวกับเรา 1 · ผลงาน 3 · บทความ 3 · บริการ 6 (คูณสองภาษาสำหรับช่องที่แยกภาษา)\n";
echo "\nทุกช่องเป็นค่าว่าง หน้าเว็บจึงยังเหมือนเดิมจนกว่าจะเลือกรูปใส่\n";

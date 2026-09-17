<?php
declare(strict_types=1);

/*
 * เพิ่มช่อง "เนื้อหาเต็ม" และสวิตช์เปิด/ปิดให้บทความแต่ละชิ้น (รันครั้งเดียว)
 *
 *   php bin/add-article-body.php            แก้ฉบับร่างในฐานข้อมูล (ใช้บนเว็บจริง)
 *   php bin/add-article-body.php --json     แก้ไฟล์ content.json แทน (ใช้ในเครื่องพัฒนา)
 *
 * ทำไมต้องมี:
 *   การ์ดบทความมีคำว่า "อ่านต่อ" มาตั้งแต่เว็บเดิม แต่กดไม่ได้เลยสักครั้ง
 *   เพราะไม่เคยมีที่เก็บเนื้อหาเต็มของบทความ
 *
 *   สคริปต์นี้เพิ่มที่เก็บให้ พร้อมสวิตช์ที่ "ปิดไว้" ตั้งแต่ต้น
 *   ตราบใดที่ยังปิดอยู่ การ์ดจะไม่มีคำว่า "อ่านต่อ" ให้กดเลย — ไม่ใช่มีแล้วกดไม่ติด
 *   เมื่อผู้ดูแลใส่เนื้อหาจริงแล้วกดเปิด การ์ดถึงจะกดได้
 *
 * ค่าเริ่มต้นจงใจให้เป็น "ปิด" และเนื้อหาว่าง เพราะบทความทั้งสามชิ้นบนเว็บ
 * ยังเป็นข้อความตัวอย่างของนักพัฒนาคนเดิม ยังไม่ใช่บทความจริงของ FMK
 *
 * ปลอดภัยต่อการรันซ้ำ: บทความที่มีช่องเหล่านี้แล้วจะถูกข้าม
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Content;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$useJson  = in_array('--json', $argv, true);
$jsonPath = dirname(__DIR__) . '/content.json';

$c = $useJson
    ? json_decode((string) file_get_contents($jsonPath), true)
    : Content::draft();

if (!is_array($c)) {
    fwrite(STDERR, "อ่านเนื้อหาไม่สำเร็จ\n");
    exit(1);
}

$changes = [];
$skipped = [];

foreach (['en', 'th'] as $lang) {
    $items = $c['i18n'][$lang]['knowledge']['items'] ?? null;
    if (!is_array($items)) {
        fwrite(STDERR, "ไม่พบรายการบทความของภาษา $lang\n");
        exit(1);
    }

    foreach ($items as $i => $item) {
        if (!is_array($item)) {
            continue;
        }
        $title = (string) ($item['title'] ?? ('รายการที่ ' . ($i + 1)));

        if (array_key_exists('readable', $item) || array_key_exists('body', $item)) {
            $skipped[] = "$lang บทความที่ " . ($i + 1) . ': มีช่องเหล่านี้แล้ว';
            continue;
        }

        /* ลำดับคีย์ต้องเหมือนกันทั้งสองภาษา เพราะ JSON ที่ฝังในหน้าเว็บ
           ต้องออกมาตรงกันทุกไบต์ระหว่างตัวสร้างฝั่ง PHP กับฝั่ง Node */
        $c['i18n'][$lang]['knowledge']['items'][$i]['readable'] = false;
        $c['i18n'][$lang]['knowledge']['items'][$i]['body']     = [];

        $changes[] = sprintf('%s บทความที่ %d "%s" — ปิดไว้ก่อน เนื้อหายังว่าง',
            $lang, $i + 1, mb_substr($title, 0, 40));
    }
}

if ($changes === []) {
    echo "ไม่มีอะไรต้องเปลี่ยน\n";
    foreach ($skipped as $s) {
        echo '  • ' . $s . "\n";
    }
    exit(0);
}

Content::validate($c);

if ($useJson) {
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    /* PHP ย่อหน้าด้วย 4 ช่อง แต่ content.json ในโปรเจกต์นี้ใช้ 2 ช่อง */
    $json = (string) preg_replace_callback('/^( +)/m',
        static fn(array $m): string => str_repeat(' ', (int) (strlen($m[1]) / 2)), $json);
    file_put_contents($jsonPath, $json . "\n");
    echo "แก้ content.json เรียบร้อย\n";
} else {
    Content::saveDraft($c, 1);
    echo "อัปเดตฉบับร่างเรียบร้อย\n";
}

foreach ($changes as $x) {
    echo '  • ' . $x . "\n";
}
foreach ($skipped as $s) {
    echo '  – ข้าม: ' . $s . "\n";
}

echo "\nวิธีเปิดบทความให้กดอ่านได้:\n";
echo "  หลังบ้าน → แก้ไขเนื้อหา → รายการบทความ → เลือกบทความที่ต้องการ\n";
echo "  ใส่ย่อหน้าที่ช่อง \"ย่อหน้า\" อย่างน้อยหนึ่งย่อหน้า แล้วติ๊ก \"เปิดให้กดอ่านเนื้อหาเต็ม\"\n";
echo "  ถ้ายังไม่มีย่อหน้าเลย ต่อให้ติ๊กเปิดไว้ การ์ดก็จะยังไม่มีปุ่มให้กด\n";
echo "  เพราะกดแล้วจะเจอหน้าต่างเปล่า ซึ่งแย่กว่าไม่มีปุ่ม\n";

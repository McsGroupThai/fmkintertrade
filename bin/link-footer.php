<?php
declare(strict_types=1);

/*
 * ให้ลิงก์ท้ายเว็บมีปลายทางจริง และเปลี่ยนข้อความบนการ์ดโซลูชัน (รันครั้งเดียว)
 *
 *   php bin/link-footer.php
 *
 * ทำไมต้องมี:
 *   ลิงก์ท้ายเว็บคอลัมน์ "บริษัท" และแถวข้อกำหนดเคยเป็น href="#" ทั้งหมด
 *   คือมีมือชี้ มีสีเปลี่ยนตอนเอาเมาส์ไปวาง แต่กดแล้วไม่ไปไหน
 *   โครงสร้างเดิมเก็บไว้เป็นข้อความเปล่า ๆ จึงไม่มีที่ให้ใส่ลิงก์เลย
 *   สคริปต์นี้เปลี่ยนเป็น {ข้อความ, ลิงก์} เพื่อให้ผู้ดูแลตั้งปลายทางเองได้จากหลังบ้าน
 *
 *   รายการที่ยังไม่มีปลายทาง (ผู้บริหาร ร่วมงานกับเรา นโยบายต่าง ๆ) จะเว้นลิงก์ว่างไว้
 *   หน้าเว็บจะไม่แสดงรายการนั้น จนกว่าจะมีหน้ารองรับแล้วมาใส่ลิงก์
 *
 * ปลอดภัยต่อการรันซ้ำ: รายการที่แปลงแล้วจะข้าม และข้อความที่ผู้ดูแลแก้เองแล้วจะไม่ถูกทับ
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Content;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/* อ้างด้วยลำดับได้เพราะสองภาษาเรียงรายการเหมือนกัน (Content::validate บังคับไว้อยู่แล้ว)
   ค่าว่าง = ยังไม่มีหน้ารองรับ */
$hrefs = [
    'company' => ['#about', '#about', '', '', '#contact'],
    'legal'   => ['', '', '', ''],
];

/* เปลี่ยนเฉพาะกรณีที่ยังเป็นข้อความเดิม ถ้าผู้ดูแลแก้เองไปแล้วให้เคารพของเขา */
$ctaOld = ['en' => 'Learn more', 'th' => 'ดูเพิ่มเติม'];
$ctaNew = ['en' => 'Ask about this', 'th' => 'สอบถามเรื่องนี้'];

$c = Content::draft();
$changes = [];
$skipped = [];

foreach (['en', 'th'] as $lang) {
    if (!isset($c['i18n'][$lang]['footer'])) {
        continue;
    }

    foreach ($hrefs as $key => $list) {
        if (!isset($c['i18n'][$lang]['footer'][$key]) || !is_array($c['i18n'][$lang]['footer'][$key])) {
            continue;
        }
        foreach ($c['i18n'][$lang]['footer'][$key] as $i => $item) {
            if (is_array($item)) {
                $skipped[] = "$lang footer.$key รายการที่ " . ($i + 1) . ' (แปลงไว้แล้ว)';
                continue;
            }
            $href = $list[$i] ?? '';
            $c['i18n'][$lang]['footer'][$key][$i] = ['label' => (string) $item, 'href' => $href];
            $changes[] = sprintf('%s footer.%s: "%s" → %s',
                $lang, $key, (string) $item, $href === '' ? 'ยังไม่มีลิงก์ (ซ่อนไว้ก่อน)' : $href);
        }
    }

    $cur = $c['i18n'][$lang]['cta']['learnMore'] ?? null;
    if ($cur === $ctaOld[$lang]) {
        $c['i18n'][$lang]['cta']['learnMore'] = $ctaNew[$lang];
        $changes[] = sprintf('%s cta.learnMore: "%s" → "%s"', $lang, $ctaOld[$lang], $ctaNew[$lang]);
    } elseif (is_string($cur) && $cur !== $ctaNew[$lang]) {
        $skipped[] = "$lang cta.learnMore = \"$cur\" (ผู้ดูแลแก้เองไว้ จึงไม่ทับ)";
    }
}

if ($changes === []) {
    echo "ไม่มีอะไรต้องเปลี่ยน — ฉบับร่างเป็นรูปแบบใหม่อยู่แล้ว\n";
    foreach ($skipped as $s) {
        echo '  • ' . $s . "\n";
    }
    exit(0);
}

Content::validate($c);
Content::saveDraft($c, 1);

echo "อัปเดตฉบับร่างเรียบร้อย ", count($changes), " รายการ\n";
foreach ($changes as $x) {
    echo '  • ' . $x . "\n";
}
foreach ($skipped as $s) {
    echo '  – ข้าม: ' . $s . "\n";
}
echo "\nนี่คือการแก้ \"ฉบับร่าง\" เท่านั้น หน้าเว็บจริงยังไม่เปลี่ยน\n";
echo "ให้เข้าหลังบ้าน ดูตัวอย่าง แล้วกด \"เผยแพร่\" เมื่อพอใจ\n";

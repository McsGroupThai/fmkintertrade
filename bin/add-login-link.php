<?php
declare(strict_types=1);

/*
 * เพิ่มการตั้งค่า "ลิงก์เข้าหลังบ้านที่ท้ายเว็บ" (รันครั้งเดียว)
 *
 *   php bin/add-login-link.php
 *
 * ค่าเริ่มต้นคือ "ปิด" — หน้าเว็บจึงยังเหมือนเดิมทุกประการ
 * ผู้ดูแลเปิดเองได้จากหน้า "ข้อมูลบริษัท" ในหลังบ้าน
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Content;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$c = Content::draft();
$added = [];

if (!array_key_exists('showAdminLink', $c['company'])) {
    $c['company']['showAdminLink'] = false;
    $added[] = 'company.showAdminLink (ปิดไว้)';
}

$labels = ['en' => 'Staff Login', 'th' => 'เข้าสู่ระบบผู้ดูแล'];
foreach ($labels as $lang => $text) {
    if (!isset($c['i18n'][$lang]['footer'])) {
        continue;
    }
    if (!array_key_exists('adminLabel', $c['i18n'][$lang]['footer'])) {
        $c['i18n'][$lang]['footer']['adminLabel'] = $text;
        $added[] = "footer.adminLabel ($lang) = \"$text\"";
    }
}

if ($added === []) {
    echo "มีการตั้งค่านี้อยู่แล้ว\n";
    exit(0);
}

Content::validate($c);
Content::saveDraft($c, 1);

echo "เพิ่มการตั้งค่าเรียบร้อย\n";
foreach ($added as $a) {
    echo '  • ' . $a . "\n";
}
echo "\nลิงก์ยังไม่แสดงบนเว็บ จนกว่าจะเปิดจากหน้า \"ข้อมูลบริษัท\" ในหลังบ้าน\n";

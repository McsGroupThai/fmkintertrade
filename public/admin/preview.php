<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use function Fmk\e;
use Fmk\Auth;
use Fmk\Builder;
use Fmk\Content;

/* ดูตัวอย่างฉบับร่างก่อนเผยแพร่ — ต้องล็อกอินก่อน และหน้านี้ไม่เขียนไฟล์ใด ๆ */
Auth::requireLogin();

/* ดูได้ทั้งหน้าแรกและหน้านโยบาย เพราะทั้งสองหน้าเผยแพร่พร้อมกัน
   ถ้าดูตัวอย่างได้แค่หน้าแรก หน้านโยบายจะขึ้นเว็บโดยไม่มีใครเคยเห็นก่อนเลย */
$page = ($_GET['page'] ?? '') === 'privacy' ? 'privacy' : 'home';
$html = Builder::render(
    Content::draft(),
    $page === 'privacy' ? 'template-privacy.html' : 'template.html'
);

/* หน้าเว็บอ้าง path สัมพัทธ์ (logo-monogram.png) แต่หน้านี้อยู่ใต้ /admin/
   จึงต้องบอกเบราว์เซอร์ว่าฐานของ path อยู่ที่ระดับบน ไม่งั้นรูปจะไม่ขึ้น */
$html = preg_replace(
    '/<meta charset="utf-8">/i',
    '<meta charset="utf-8"><base href="../">',
    $html,
    1
);

/* แถบเครื่องมือของตัวอย่าง
   ต้องมีทางกลับเสมอ ไม่งั้นผู้ใช้ติดอยู่ในหน้าตัวอย่างแล้วต้องกดย้อนกลับเอง
   ใช้ style ฝังในแท็กเพราะหน้านี้ยืมหน้าเว็บจริงมาแสดง ไม่ควรไปแตะ CSS ของเว็บ */
$backTo = 'admin/content.php';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if (is_string($ref) && preg_match('#/admin/content\.php\?s=([A-Za-z]+)#', $ref, $m)) {
    $backTo = 'admin/content.php?s=' . $m[1];
}

$bar = '<div id="fmk-previewbar" style="position:sticky;top:0;z-index:99999;'
     . 'background:#0A2439;color:#fff;font:14px/1.4 \'Noto Sans Thai\',system-ui,sans-serif;'
     . 'padding:10px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;'
     . 'box-shadow:0 2px 14px rgba(0,0,0,.28)">'
     . '<span style="background:#C9A050;color:#0A2439;font-weight:700;font-size:12px;'
     . 'padding:3px 10px;border-radius:999px">ตัวอย่างฉบับร่าง</span>'
     . '<span style="opacity:.8;font-size:13px">ยังไม่ขึ้นเว็บจริง · ผู้เข้าชมยังเห็นเนื้อหาชุดเดิม</span>'
     . '<span style="flex:1"></span>'
     . '<a href="' . e($backTo) . '" style="color:#fff;text-decoration:none;font-weight:600;'
     . 'border:1px solid #3C5A72;padding:7px 13px;border-radius:8px">← กลับไปแก้ไข</a>'
     . ($page === 'privacy'
        ? '<a href="admin/preview.php" style="color:#fff;text-decoration:none;font-weight:600;'
          . 'border:1px solid #3C5A72;padding:7px 13px;border-radius:8px">ดูหน้าแรก</a>'
        : '<a href="admin/preview.php?page=privacy" style="color:#fff;text-decoration:none;font-weight:600;'
          . 'border:1px solid #3C5A72;padding:7px 13px;border-radius:8px">ดูหน้านโยบาย</a>')
     . '<a href="index.html" target="_blank" rel="noopener" style="color:#fff;text-decoration:none;'
     . 'font-weight:600;border:1px solid #3C5A72;padding:7px 13px;border-radius:8px">ดูเว็บปัจจุบัน</a>'
     . '<a href="admin/publish.php" style="background:#C9A050;color:#0A2439;text-decoration:none;'
     . 'font-weight:700;padding:7px 15px;border-radius:8px">ไปหน้าเผยแพร่</a>'
     . '</div>';

$html = preg_replace('/<body([^>]*)>/i', '<body$1>' . $bar, $html, 1);

/* กันไม่ให้ตัวอย่างถูกจัดเก็บหรือถูกเก็บดัชนี */
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header_remove('Content-Security-Policy');   // หน้าเว็บจริงโหลดฟอนต์จาก Google Fonts

echo $html;

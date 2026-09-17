<?php
declare(strict_types=1);

/*
 * สร้างชุดไฟล์สำหรับอัปขึ้นโฮสต์
 *
 *   php bin/make-bundle.php
 *
 * ทำไมต้องมี:
 *   โฮสต์นี้เข้าได้แค่ DirectAdmin File Manager กับ phpMyAdmin ไม่มี SSH ไม่มี FTP
 *   การลากไฟล์ทีละไฟล์ผ่านหน้าเว็บมีโอกาสตกหล่นสูงมาก และที่แย่กว่าคือ
 *   มีโอกาสเผลออัป config.local.php ซึ่งมีรหัสผ่านขึ้นไปในโฟลเดอร์ที่คนนอกเปิดอ่านได้
 *   สคริปต์นี้จึงประกอบไฟล์ให้ครบและ "ตัดสิ่งที่ห้ามขึ้น" ออกตั้งแต่ต้นทาง
 *
 * สิ่งที่จงใจไม่ใส่ในชุด:
 *   config/config.local.php   มีรหัสผ่าน — ต้องพิมพ์บนโฮสต์เท่านั้น
 *   public/index.html         ไฟล์หน้าเว็บจริง อัปทับเมื่อไรคือเปลี่ยนเว็บทันที
 *                             ให้ระบบเขียนเองตอนกดปุ่มเผยแพร่ ซึ่งเป็นการทดสอบสิทธิ์เขียนไปในตัว
 *   deploy/recover.php        แยกไว้ต่างหาก ขึ้นตอนใช้แล้วลบทิ้งทันที
 *   snapshots/ verify.js build.js เครื่องมือฝั่งเครื่องพัฒนา ไม่เกี่ยวกับโฮสต์
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$dist = $root . '/dist';

/* ------------------------------------------------------------ เครื่องมือ */

function rmdirRecursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function copyTree(string $from, string $to, callable $accept): int
{
    if (!is_dir($to) && !mkdir($to, 0755, true) && !is_dir($to)) {
        throw new RuntimeException('สร้างโฟลเดอร์ไม่ได้: ' . $to);
    }
    $n = 0;
    foreach (scandir($from) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $src = $from . '/' . $name;
        $dst = $to . '/' . $name;
        if (is_dir($src)) {
            $n += copyTree($src, $dst, $accept);
            continue;
        }
        if (!$accept($src, $name)) {
            continue;
        }
        copy($src, $dst);
        $n++;
    }
    return $n;
}

function putFile(string $path, string $body): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('สร้างโฟลเดอร์ไม่ได้: ' . $dir);
    }
    file_put_contents($path, $body);
}

function zipTree(string $dir, string $zipPath, string $prefix = ''): int
{
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('สร้างไฟล์ zip ไม่ได้: ' . $zipPath);
    }
    $n = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        $entry = $prefix === '' ? $rel : $prefix . '/' . $rel;
        if ($f->isDir()) {
            $zip->addEmptyDir($entry);
        } else {
            $zip->addFile($f->getPathname(), $entry);
            $n++;
        }
    }
    $zip->close();
    return $n;
}

/* ----------------------------------------------- ตรวจก่อนว่าของครบจริงไหม */

foreach (['app/bootstrap.php', 'template.html', 'template-privacy.html', 'content.json', 'public/admin/index.php', 'deploy/checkhost.php', 'deploy/recover.php'] as $must) {
    if (!is_file($root . '/' . $must)) {
        fwrite(STDERR, "ขาดไฟล์ที่จำเป็น: $must\n");
        exit(1);
    }
}

rmdirRecursive($dist);
mkdir($dist, 0755, true);

$appDir = $dist . '/fmk-app';
$pubDir = $dist . '/fmk-public';

/* ------------------------------------------- 1. ชุดหลังบ้าน (นอก public_html) */

$phpOnly = static fn(string $src, string $name): bool => str_ends_with($name, '.php');

$c = 0;
$c += copyTree($root . '/app', $appDir . '/app', $phpOnly);
$c += copyTree($root . '/db', $appDir . '/db', static fn($s, $n) => str_ends_with($n, '.sql'));

/* เครื่องมือบรรทัดคำสั่งที่มีประโยชน์บนโฮสต์จริง ไม่เอาตัวที่ใช้ครั้งเดียวจบไปแล้ว */
$wantBin = ['migrate.php', 'create-user.php', 'import-content.php', 'snapshot.php', 'unlock-login.php'];
$c += copyTree($root . '/bin', $appDir . '/bin', static fn($s, $n) => in_array($n, $wantBin, true));

/* เฉพาะไฟล์ตัวอย่าง — config.local.php ห้ามขึ้นเด็ดขาด
   คัดลอกทั้งโฟลเดอร์ไม่ได้ เพราะในเครื่องพัฒนามี config.local.php ที่มีรหัสผ่านอยู่ข้าง ๆ */
mkdir($appDir . '/config', 0755, true);
copy($root . '/config/config.example.php', $appDir . '/config/config.example.php');
$c++;

copy($root . '/template.html', $appDir . '/template.html');
copy($root . '/template-privacy.html', $appDir . '/template-privacy.html');
copy($root . '/content.json', $appDir . '/content.json');
$c += 2;

/* ด่านสำรอง เผื่อโฮสต์วางโฟลเดอร์เหล่านี้ไว้นอก public_html ไม่ได้จริง ๆ */
foreach (['app', 'config', 'db', 'bin'] as $d) {
    copy($root . '/deploy/htaccess-deny-all', $appDir . '/' . $d . '/.htaccess');
    $c++;
}

/* ---------------------------------------------- 2. ชุดสาธารณะ (public_html) */

$skipPublic = ['index.html', 'index.html.tmp', 'privacy.html', 'privacy.html.tmp'];
$c2 = copyTree($root . '/public', $pubDir, static function (string $src, string $name) use ($skipPublic): bool {
    if (in_array($name, $skipPublic, true)) {
        return false;
    }
    /* รูปที่ผู้ดูแลอัปไว้ในเครื่องพัฒนา ไม่ต้องขนขึ้นโฮสต์ ให้อัปใหม่บนของจริง */
    if (preg_match('#/assets/media/#', str_replace('\\', '/', $src)) && $name !== '.htaccess') {
        return false;
    }
    return true;
});

/* โฟลเดอร์รูปต้องมีอยู่จริงตั้งแต่แรก ไม่งั้นการอัปโหลดรูปครั้งแรกจะพังถ้าสิทธิ์สร้างโฟลเดอร์ไม่พอ */
foreach ([$pubDir . '/assets/media', $pubDir . '/assets/media/thumb'] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0755, true);
    }
}

/* -------------------------------- 3. ไฟล์ SQL ก้อนเดียวสำหรับ phpMyAdmin */

/*
 * ทำไมต้องรวมเป็นไฟล์เดียว:
 *   bin/migrate.php รันได้เฉพาะผ่าน SSH ซึ่งโฮสต์นี้ไม่มี เหลือทางเดียวคือ phpMyAdmin
 *   การให้คนไล่ import ทีละไฟล์ห้าไฟล์ตามลำดับเลข คือการรอให้เกิดความผิดพลาด
 *   ไฟล์นี้จึงรวมทุกอย่างไว้ก้อนเดียว เรียงลำดับถูกต้องแล้ว import ครั้งเดียวจบ
 *
 * ท้ายไฟล์บันทึกลง schema_migrations ด้วย เผื่อวันหนึ่งมี SSH ขึ้นมา
 * migrate.php จะได้รู้ว่า 001 ถึง 004 รันไปแล้ว ไม่ไปรันซ้ำ
 */
$sqlFiles = glob($root . '/db/*.sql') ?: [];
sort($sqlFiles);

$install = "-- FMK Intertrade CMS — ติดตั้งฐานข้อมูลครั้งแรก\n"
         . '-- สร้างเมื่อ ' . date('Y-m-d H:i') . "\n"
         . "--\n"
         . "-- วิธีใช้: phpMyAdmin > เลือกฐานข้อมูลของเว็บ > แท็บ Import > เลือกไฟล์นี้ > Go\n"
         . "-- ไฟล์นี้ใช้ CREATE TABLE IF NOT EXISTS ทั้งหมด รันซ้ำแล้วไม่ทำลายข้อมูลเดิม\n\n"
         . "SET NAMES utf8mb4;\n"
         . "SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';\n\n";

$versions = [];
foreach ($sqlFiles as $f) {
    $versions[] = basename($f, '.sql');
    $install .= "-- ================================================================\n"
              . '-- ' . basename($f) . "\n"
              . "-- ================================================================\n\n"
              . trim((string) file_get_contents($f)) . "\n\n";
}

$install .= "-- ================================================================\n"
          . "-- บันทึกว่า migration เหล่านี้รันไปแล้ว\n"
          . "-- ================================================================\n\n"
          . "CREATE TABLE IF NOT EXISTS schema_migrations (\n"
          . "  version    VARCHAR(64) NOT NULL,\n"
          . "  applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
          . "  PRIMARY KEY (version)\n"
          . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n";
foreach ($versions as $v) {
    $install .= "INSERT IGNORE INTO schema_migrations (version) VALUES ('" . $v . "');\n";
}

/*
 * เนื้อหาตั้งต้น
 *
 * bin/import-content.php ก็รันผ่าน SSH เท่านั้นเหมือนกัน จึงต้องฝังเนื้อหามาในไฟล์นี้
 * ใส่เป็น "ฉบับร่าง" อย่างเดียว ไม่ใส่ประวัติการเผยแพร่ เพราะอยากให้คนกดปุ่มเผยแพร่เอง
 * การกดปุ่มนั้นคือบททดสอบว่า PHP เขียนทับ index.html บนโฮสต์ได้จริงหรือไม่
 */
$contentJson = (string) file_get_contents($root . '/content.json');
$decoded = json_decode($contentJson, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "content.json ไม่ใช่ JSON ที่ถูกต้อง\n");
    exit(1);
}
$draftPayload = json_encode(
    ['company' => $decoded['company'] ?? [], 'i18n' => $decoded['i18n'] ?? []],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($draftPayload === false) {
    fwrite(STDERR, "แปลงเนื้อหาเป็น JSON ไม่สำเร็จ\n");
    exit(1);
}
/* escape เองแทนการใช้ PDO เพราะสคริปต์นี้ไม่ควรต้องต่อฐานข้อมูลเลย
   ลำดับสำคัญ: แบ็กสแลชก่อน แล้วค่อยเครื่องหมายคำพูด */
$escaped = str_replace(
    ['\\', "'", "\r", "\n", "\x00", "\x1a"],
    ['\\\\', "\\'", '\\r', '\\n', '\\0', '\\Z'],
    $draftPayload
);

$install .= "\n-- ================================================================\n"
          . "-- เนื้อหาเว็บไซต์ตั้งต้น (ฉบับร่าง)\n"
          . '-- ' . number_format(strlen($draftPayload)) . " bytes\n"
          . "-- ================================================================\n\n"
          . "INSERT INTO content_draft (id, data) VALUES (1, '" . $escaped . "')\n"
          . "  ON DUPLICATE KEY UPDATE data = VALUES(data);\n";

putFile($dist . '/fmk-install.sql', $install);

/* --------------------------------------------- 4. ตัวตรวจสภาพโฮสต์ + กุญแจ */

$key = bin2hex(random_bytes(16));
$check = (string) file_get_contents($root . '/deploy/checkhost.php');
if (!str_contains($check, '__FMK_CHECK_KEY__')) {
    fwrite(STDERR, "checkhost.php ไม่มีที่ใส่กุญแจ\n");
    exit(1);
}
putFile($dist . '/fmk-check.php', str_replace('__FMK_CHECK_KEY__', $key, $check));

/* recover.php แยกไว้นอก zip โดยตั้งใจ จะได้ไม่มีใครเผลอแตกไฟล์แล้วมันขึ้นไปนอนบนเว็บ */
copy($root . '/deploy/recover.php', $dist . '/recover.php');

/* ------------------------------------------------------------ 5. บีบอัด */

$nApp = zipTree($appDir, $dist . '/fmk-app.zip');
$nPub = zipTree($pubDir, $dist . '/fmk-public.zip');

/* --------------------------------------------------- 6. ใบแนะนำการอัปโหลด */

$today = date('Y-m-d H:i');
$liveSize = is_file($root . '/public/index.html') ? number_format((int) filesize($root . '/public/index.html')) : '-';

$readme = <<<TXT
ชุดไฟล์ติดตั้ง FMK Intertrade CMS
สร้างเมื่อ $today

กุญแจสำหรับเปิดหน้าตรวจสภาพโฮสต์
    $key

    เปิดที่:  https://fmkintertrade.shop/fmk-check.php?k=$key

    กุญแจนี้สุ่มใหม่ทุกครั้งที่สร้างชุดไฟล์ ไม่ใช่รหัสผ่านของบัญชีใด
    ใช้เพื่อไม่ให้คนอื่นเดินมาเปิดดูสภาพเซิร์ฟเวอร์ของเราเฉย ๆ

------------------------------------------------------------------
ไฟล์ในชุดนี้
------------------------------------------------------------------

fmk-app.zip       ($nApp ไฟล์)  โค้ดหลังบ้าน — แตกไว้ "นอก" public_html
fmk-public.zip    ($nPub ไฟล์)  ไฟล์เว็บ — แตกไว้ "ใน" public_html
fmk-install.sql                 ฐานข้อมูลทั้งหมด import ผ่าน phpMyAdmin ครั้งเดียวจบ
fmk-check.php                   ตัวตรวจสภาพโฮสต์ ใช้แล้วลบทิ้ง
recover.php                     ใช้สร้างผู้ดูแลคนแรก ใช้แล้วลบทิ้งทันที

------------------------------------------------------------------
สิ่งที่จงใจไม่มีในชุดนี้ และเหตุผล
------------------------------------------------------------------

index.html
    ไฟล์หน้าเว็บจริง (ตอนนี้ในเครื่อง $liveSize bytes)
    ถ้าอัปทับตอนนี้ เว็บเปลี่ยนทันทีทั้งที่ยังไม่ได้ทดสอบอะไรเลย
    ให้ติดตั้งหลังบ้านให้เสร็จก่อน แล้วค่อยกดปุ่ม "เผยแพร่" ในหลังบ้าน
    ระบบจะเขียนไฟล์นี้ให้เอง ซึ่งเป็นการพิสูจน์สิทธิ์เขียนไฟล์ไปในตัว
    ถ้าเขียนไม่ได้ ระบบจะฟ้อง และเว็บเดิมยังอยู่ครบไม่ถูกแตะ

privacy.html
    หน้านโยบายความเป็นส่วนตัว ระบบเขียนให้พร้อมกับ index.html ตอนกดเผยแพร่
    ด้วยเหตุผลเดียวกัน จึงไม่ต้องอัปขึ้นไปเอง

config/config.local.php
    มีรหัสผ่านฐานข้อมูล ต้องพิมพ์บนโฮสต์เท่านั้น
    ห้ามส่งผ่านแชต ห้ามขึ้น Git ห้ามวางไว้ใน public_html

รูปในคลังรูป
    รูปที่อัปไว้ในเครื่องพัฒนาเป็นของทดสอบ ให้อัปใหม่บนเว็บจริง

------------------------------------------------------------------
ทำตามลำดับใน DEPLOY.md
------------------------------------------------------------------

อ่าน DEPLOY.md ให้จบก่อนเริ่ม โดยเฉพาะข้อ 1 (สำรองข้อมูล)
ห้ามข้ามข้อ 1 ไม่ว่ากรณีใด
TXT;

putFile($dist . '/UPLOAD-ME.txt', $readme);

/* ------------------------------------------------- 7. ตรวจว่าไม่มีของต้องห้ามหลุด */

/* ครั้งแรกที่รันสคริปต์นี้ โฟลเดอร์ config/ ไม่ถูกสร้างก่อน copy() จึงหลุดไปเงียบ ๆ
   ชุดไฟล์ออกมาครบทุกอย่าง ยกเว้นไฟล์ที่คนจะต้องใช้ตั้งค่าบนโฮสต์ และไม่มีอะไรเตือน
   ต่อไปนี้จึงไล่ตรวจรายชื่อไฟล์ที่ "ต้องมี" ทุกครั้ง */
$mustHave = [
    'fmk-app/app/bootstrap.php', 'fmk-app/app/Auth.php', 'fmk-app/app/Builder.php',
    'fmk-app/app/Config.php', 'fmk-app/app/Media.php', 'fmk-app/app/Messages.php',
    'fmk-app/config/config.example.php', 'fmk-app/config/.htaccess',
    'fmk-app/db/001_init.sql', 'fmk-app/db/002_content.sql',
    'fmk-app/db/003_media.sql', 'fmk-app/db/004_messages.sql',
    'fmk-app/bin/migrate.php', 'fmk-app/template.html', 'fmk-app/template-privacy.html', 'fmk-app/content.json',
    'fmk-public/.htaccess', 'fmk-public/admin/index.php', 'fmk-public/admin/login.php',
    'fmk-public/admin/app.js', 'fmk-public/admin/style.css', 'fmk-public/contact.php',
    'fmk-public/robots.txt', 'fmk-public/assets/media/.htaccess',
    'fmk-check.php', 'recover.php', 'UPLOAD-ME.txt', 'fmk-install.sql',
];
$absent = [];
foreach ($mustHave as $rel) {
    if (!is_file($dist . '/' . $rel)) {
        $absent[] = $rel;
    }
}
/* และไฟล์ที่ต้องไม่มี */
foreach (['fmk-public/index.html', 'fmk-public/privacy.html', 'fmk-app/config/config.local.php'] as $rel) {
    if (file_exists($dist . '/' . $rel)) {
        $absent[] = 'ไม่ควรมีแต่กลับมี: ' . $rel;
    }
}

$leaks = $absent;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dist) + 1));
    if (str_contains($rel, 'config.local.php')) {
        $leaks[] = $rel;
        continue;
    }
    if (str_ends_with($rel, '.zip') || !$f->isReadable()) {
        continue;
    }
    $body = (string) file_get_contents($f->getPathname());
    /* รหัสผ่านฐานข้อมูลของเครื่องพัฒนาต้องไม่ปรากฏที่ไหนเลยในชุดนี้ */
    if (preg_match("/'password'\s*=>\s*'(?!ใส่รหัสผ่านที่นี่)[^']{3,}'/u", $body)) {
        $leaks[] = $rel . ' (พบค่า password ที่ไม่ใช่ข้อความตัวอย่าง)';
    }
}

echo "สร้างชุดไฟล์เสร็จแล้วที่ dist/\n\n";
printf("  fmk-app.zip      %6d ไฟล์  %9s\n", $nApp, number_format((int) filesize($dist . '/fmk-app.zip')) . ' B');
printf("  fmk-public.zip   %6d ไฟล์  %9s\n", $nPub, number_format((int) filesize($dist . '/fmk-public.zip')) . ' B');
printf("  fmk-check.php    %20s\n", number_format((int) filesize($dist . '/fmk-check.php')) . ' B');
printf("  recover.php      %20s\n", number_format((int) filesize($dist . '/recover.php')) . ' B');
echo "\nกุญแจหน้าตรวจสภาพโฮสต์: $key\n";
echo "  https://fmkintertrade.shop/fmk-check.php?k=$key\n";

if ($leaks !== []) {
    echo "\n!! ชุดไฟล์ไม่ผ่านการตรวจ — ห้ามอัปขึ้นโฮสต์ !!\n";
    foreach ($leaks as $l) {
        echo "   $l\n";
    }
    exit(1);
}
echo "\nตรวจแล้ว: ไม่มี config.local.php และไม่มีรหัสผ่านจริงหลุดเข้าไปในชุด\n";

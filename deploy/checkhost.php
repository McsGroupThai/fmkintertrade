<?php
declare(strict_types=1);

/*
 * ตรวจสภาพโฮสต์ก่อนติดตั้ง — FMK Intertrade CMS
 *
 * ทำไมต้องมีไฟล์นี้:
 *   ทุกอย่างในโปรเจกต์นี้ทดสอบบนเซิร์ฟเวอร์ในตัวของ PHP ซึ่ง "ไม่อ่าน .htaccess เลย"
 *   และเป็นคนละ PHP คนละส่วนขยายกับโฮสต์จริง การเดาว่าโฮสต์พร้อมแล้วจึงเป็นการเดาล้วน ๆ
 *   ไฟล์นี้ไปยืนอยู่บนโฮสต์จริงแล้วถามคำถามเดียวกันกับที่ระบบจะถามตอนทำงานจริง
 *
 * วิธีใช้:
 *   1. อัปโหลดไฟล์นี้ไว้ที่ public_html/fmk-check.php
 *   2. เปิด  https://โดเมนของคุณ/fmk-check.php?k=<กุญแจในไฟล์ UPLOAD-ME.txt>
 *   3. อ่านผล แก้สิ่งที่เป็นสีแดงให้หมด
 *   4. ลบไฟล์นี้ทิ้ง  <-- สำคัญ อย่าทิ้งไว้บนเว็บ
 *
 * ไฟล์นี้ไม่แก้ไขอะไรบนเซิร์ฟเวอร์เลย นอกจากสร้างไฟล์ทดสอบชั่วคราวแล้วลบทิ้งทันที
 * และไม่แสดงรหัสผ่านใด ๆ ต่อให้อ่าน config.local.php ได้ก็ตาม
 */

/* กุญแจนี้ถูกสุ่มใหม่ทุกครั้งที่สร้างชุดไฟล์ติดตั้ง — กันคนอื่นเดินมาเปิดดูสภาพเซิร์ฟเวอร์เรา
   ไม่ใช่รหัสผ่านของบัญชีใด ใช้ครั้งเดียวแล้วลบไฟล์นี้ทิ้ง */
const FMK_CHECK_KEY = '__FMK_CHECK_KEY__';

$given = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
if (!hash_equals(FMK_CHECK_KEY, $given)) {
    /* ตอบเหมือนไม่มีไฟล์นี้อยู่จริง ไม่บอกใบ้ว่ามีอะไรซ่อนอยู่ */
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><title>404 Not Found</title><h1>Not Found</h1>';
    exit;
}

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

/** @var array<int,array{0:string,1:string,2:string,3:string}> หัวข้อ, ผล, ค่าที่เจอ, คำแนะนำ */
$rows = [];
const OK = 'ok', WARN = 'warn', BAD = 'bad', INFO = 'info';

function check(string $topic, string $verdict, string $found, string $advice = ''): void
{
    global $rows;
    $rows[] = [$topic, $verdict, $found, $advice];
}

function human(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return round($bytes / 1024) . ' KB';
}

function toBytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return -1;
    }
    $unit = strtolower(substr($v, -1));
    $n = (int) $v;
    return match ($unit) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => $n,
    };
}

/* ---------------------------------------------------------- 1. ตัว PHP เอง */

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
check(
    'รุ่นของ PHP',
    $phpOk ? OK : BAD,
    PHP_VERSION,
    $phpOk ? 'ต้องการ 8.1 ขึ้นไป' : 'ระบบใช้ไวยากรณ์ของ PHP 8.1 เปลี่ยนรุ่นใน DirectAdmin > Select PHP Version'
);

check('ระบบปฏิบัติการ / เซิร์ฟเวอร์', INFO, PHP_OS_FAMILY . ' · ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'ไม่ทราบ'), '');
check('วิธีที่ PHP ทำงาน', INFO, PHP_SAPI, PHP_SAPI === 'cli' ? '' : 'ใช้ดูว่าใช้ .htaccess ตั้งค่า PHP ได้หรือไม่');

/* ---------------------------------------------------- 2. ส่วนขยายที่ต้องมี */

$need = [
    'pdo_mysql' => 'ต่อฐานข้อมูล MariaDB — ขาดตัวนี้ระบบเปิดไม่ได้เลย',
    'mbstring'  => 'ตัดและนับข้อความภาษาไทยให้ถูกต้อง',
    'json'      => 'อ่านและเขียนเนื้อหาทั้งหมด',
    'session'   => 'จำการเข้าสู่ระบบ',
    'gd'        => 'ย่อรูปและสร้างรูปย่อในคลังรูป',
    'exif'      => 'หมุนรูปจากมือถือให้ตั้งตรง',
];
foreach ($need as $ext => $why) {
    $has = extension_loaded($ext);
    check('ส่วนขยาย ' . $ext, $has ? OK : BAD, $has ? 'มี' : 'ไม่มี', $has ? $why : $why . ' — เปิดใน DirectAdmin > Select PHP Version');
}

$nice = ['openssl' => 'ไม่บังคับ แต่ช่วยเรื่องการเชื่อมต่อที่เข้ารหัส', 'fileinfo' => 'ไม่บังคับ ใช้ตรวจชนิดไฟล์อีกชั้น'];
foreach ($nice as $ext => $why) {
    $has = extension_loaded($ext);
    check('ส่วนขยาย ' . $ext, $has ? OK : WARN, $has ? 'มี' : 'ไม่มี', $why);
}

if (extension_loaded('gd')) {
    $gd = gd_info();
    $webp = !empty($gd['WebP Support']);
    $jpeg = !empty($gd['JPEG Support']);
    $png  = !empty($gd['PNG Support']);
    $all  = $webp && $jpeg && $png;
    check(
        'gd รองรับชนิดรูปครบ',
        $all ? OK : ($jpeg && $png ? WARN : BAD),
        'JPEG ' . ($jpeg ? 'ได้' : 'ไม่ได้') . ' · PNG ' . ($png ? 'ได้' : 'ไม่ได้') . ' · WebP ' . ($webp ? 'ได้' : 'ไม่ได้'),
        $webp ? '' : 'รูปย่อในคลังรูปใช้ WebP ถ้าไม่รองรับ การอัปโหลดรูปจะล้มเหลว'
    );
}

/* -------------------------------------------- 3. ข้อจำกัดที่กระทบการใช้งาน */

$umax = toBytes((string) ini_get('upload_max_filesize'));
$pmax = toBytes((string) ini_get('post_max_size'));
$mem  = toBytes((string) ini_get('memory_limit'));
$need16 = 16 * 1024 * 1024;

check(
    'ขนาดไฟล์อัปโหลดสูงสุด',
    $umax >= $need16 ? OK : WARN,
    (string) ini_get('upload_max_filesize'),
    $umax >= $need16 ? 'ระบบจำกัดที่ 16 MB อยู่แล้ว' : 'น้อยกว่า 16 MB รูปจากกล้องบางรูปจะอัปไม่ขึ้น'
);
check(
    'ขนาดข้อมูลที่ส่งได้ต่อครั้ง',
    $pmax >= $umax ? OK : WARN,
    (string) ini_get('post_max_size'),
    $pmax >= $umax ? '' : 'ต้องไม่น้อยกว่าขนาดไฟล์อัปโหลดสูงสุด ไม่งั้นอัปรูปใหญ่แล้วเงียบหาย'
);
check(
    'หน่วยความจำต่อคำขอ',
    $mem === -1 || $mem >= 128 * 1024 * 1024 ? OK : WARN,
    (string) ini_get('memory_limit'),
    'ย่อรูปความละเอียดสูงกินหน่วยความจำมาก แนะนำอย่างน้อย 128M'
);

/* ------------------------------------------------- 4. .htaccess ทำงานจริงไหม */

$htOn = isset($_SERVER['FMK_HTACCESS']) || getenv('FMK_HTACCESS') !== false;
check(
    '.htaccess ถูกอ่านจริง',
    $htOn ? OK : WARN,
    $htOn ? 'ใช่ — เซิร์ฟเวอร์อ่านคำสั่งในไฟล์นั้น' : 'ยังพิสูจน์ไม่ได้',
    $htOn
        ? 'พิสูจน์ด้วยคำสั่ง SetEnv ในไฟล์ .htaccess ของเราเอง'
        : 'ถ้ายังไม่ได้อัป .htaccess ขึ้นไป ข้อนี้เป็นเรื่องปกติ · ถ้าอัปแล้วยังขึ้นแบบนี้ '
          . 'แปลว่าโฮสต์ปิด AllowOverride — หัวข้อความปลอดภัยและการห้ามรันไฟล์ในคลังรูปจะไม่ทำงาน'
);

$hdrOk = function_exists('apache_get_modules') ? in_array('mod_headers', apache_get_modules(), true) : null;
if ($hdrOk !== null) {
    check('mod_headers', $hdrOk ? OK : WARN, $hdrOk ? 'มี' : 'ไม่มี', 'ใช้ส่งหัวความปลอดภัยของหน้าเว็บ');
}

/* ----------------------------------------------------------- 5. HTTPS / SSL */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
      || (($_SERVER['SERVER_PORT'] ?? '') === '443');
check(
    'เปิดผ่าน HTTPS',
    $https ? OK : BAD,
    $https ? 'ใช่' : 'ไม่ใช่ — ตอนนี้เปิดผ่าน http ธรรมดา',
    $https
        ? 'คุกกี้ของหลังบ้านจะติดธง Secure ให้อัตโนมัติ'
        : 'ต้องติดตั้ง SSL ก่อน (DirectAdmin > SSL Certificates > Let s Encrypt) '
          . 'ไม่งั้นรหัสผ่านตอนล็อกอินวิ่งเป็นข้อความธรรมดา'
);

/* -------------------------------------------------- 6. ตำแหน่งและสิทธิ์ไฟล์ */

$here = str_replace('\\', '/', __DIR__);
check('โฟลเดอร์ที่ไฟล์นี้อยู่', INFO, $here, 'คัดลอกค่านี้ไปใส่ public_dir ใน config.local.php');

$docroot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']) : '';
if ($docroot !== '') {
    check(
        'รากของเว็บ (DOCUMENT_ROOT)',
        rtrim($docroot, '/') === rtrim($here, '/') ? OK : INFO,
        $docroot,
        rtrim($docroot, '/') === rtrim($here, '/') ? '' : 'ไฟล์นี้ไม่ได้อยู่ที่รากเว็บพอดี ดูให้แน่ใจว่าอัปถูกที่'
    );
}

$parent = dirname($here);
check('โฟลเดอร์ชั้นเหนือขึ้นไป', INFO, $parent, 'app/ config/ db/ ต้องไปอยู่ตรงนี้ ไม่ใช่ในโฟลเดอร์เว็บ');

/* เขียนไฟล์ในโฟลเดอร์เว็บได้ไหม — ข้อนี้คือหัวใจ ถ้าไม่ได้ ปุ่มเผยแพร่จะใช้ไม่ได้ */
$probe = $here . '/fmk-write-test-' . bin2hex(random_bytes(4)) . '.tmp';
$wrote = @file_put_contents($probe, 'test');
$canWrite = $wrote !== false;
if ($canWrite) {
    @unlink($probe);
}
check(
    'PHP เขียนไฟล์ในโฟลเดอร์เว็บได้',
    $canWrite ? OK : BAD,
    $canWrite ? 'ได้' : 'ไม่ได้',
    $canWrite
        ? 'ปุ่มเผยแพร่จะเขียนทับ index.html ได้'
        : 'กดเผยแพร่แล้วจะขึ้น error — ตรวจสิทธิ์โฟลเดอร์ (ปกติ 755) และเจ้าของไฟล์'
);

$live = $here . '/index.html';
if (is_file($live)) {
    check(
        'เขียนทับ index.html เดิมได้',
        is_writable($live) ? OK : BAD,
        (is_writable($live) ? 'ได้' : 'ไม่ได้') . ' · ขนาดปัจจุบัน ' . number_format((int) filesize($live)) . ' bytes'
            . ' · แก้ล่าสุด ' . date('Y-m-d H:i', (int) filemtime($live)),
        is_writable($live) ? '' : 'เปลี่ยนสิทธิ์ไฟล์เป็น 644 และให้เจ้าของเป็นผู้ใช้เดียวกับที่ PHP ทำงาน'
    );
} else {
    check('index.html เดิม', WARN, 'ไม่พบในโฟลเดอร์นี้', 'ถ้านี่คือเว็บจริง ต้องเจอไฟล์นี้ — ดูให้แน่ใจว่าอัปไฟล์ตรวจไว้ถูกโฟลเดอร์');
}

$assets = $here . '/assets/media';
if (is_dir($assets)) {
    check('โฟลเดอร์คลังรูป', is_writable($assets) ? OK : BAD, is_writable($assets) ? 'เขียนได้' : 'เขียนไม่ได้',
        is_writable($assets) ? '' : 'อัปโหลดรูปจะล้มเหลว — ตั้งสิทธิ์โฟลเดอร์เป็น 755');
} else {
    check('โฟลเดอร์คลังรูป', WARN, 'ยังไม่มี assets/media', 'จะมีหลังแตกไฟล์ชุดสาธารณะ');
}

/* --------------------------------------------- 7. โค้ดหลังบ้านมาถึงหรือยัง */

$appBoot = $parent . '/app/bootstrap.php';
$cfgFile = $parent . '/config/config.local.php';
check('พบ app/bootstrap.php', is_file($appBoot) ? OK : WARN, is_file($appBoot) ? $appBoot : 'ยังไม่มี',
    is_file($appBoot) ? '' : 'ยังไม่ได้แตกไฟล์ชุดหลังบ้าน หรือแตกผิดโฟลเดอร์');
check('พบ config/config.local.php', is_file($cfgFile) ? OK : WARN, is_file($cfgFile) ? 'มี' : 'ยังไม่มี',
    is_file($cfgFile) ? 'ไฟล์นี้มีรหัสผ่าน อยู่นอกโฟลเดอร์เว็บถูกต้องแล้ว' : 'คัดลอกจาก config.example.php แล้วใส่ค่าจริง');

/* ------------------------------------------------------ 8. ทดลองต่อฐานข้อมูล */

if (is_file($cfgFile)) {
    $cfg = @include $cfgFile;
    if (!is_array($cfg)) {
        check('ต่อฐานข้อมูล', BAD, 'อ่าน config.local.php ไม่ได้', 'ไฟล์ต้อง return array');
    } elseif (!extension_loaded('pdo_mysql')) {
        check('ต่อฐานข้อมูล', BAD, 'ข้ามเพราะไม่มี pdo_mysql', '');
    } else {
        $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
        $host = (string) ($db['host'] ?? '');
        $name = (string) ($db['database'] ?? '');
        $port = (int) ($db['port'] ?? 3306);
        $dsn  = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            check('ต่อฐานข้อมูล', OK, 'ต่อได้ · MariaDB/MySQL ' . $ver, 'ฐานข้อมูล ' . $name . ' ที่ ' . $host);

            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            $want = ['users', 'sessions', 'login_attempts', 'audit_log', 'content_draft', 'content_versions', 'media', 'contact_messages'];
            $missing = array_values(array_diff($want, $tables));
            check(
                'ตารางในฐานข้อมูล',
                $missing === [] ? OK : WARN,
                count($tables) . ' ตาราง' . ($missing === [] ? ' — ครบ' : ' — ยังขาด: ' . implode(', ', $missing)),
                $missing === [] ? '' : 'นำเข้าไฟล์ใน db/ ผ่าน phpMyAdmin ตามลำดับเลข 001 ถึง 004'
            );

            if (in_array('users', $tables, true)) {
                $n = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
                check(
                    'ผู้ดูแลระบบที่ใช้งานได้',
                    $n > 0 ? OK : WARN,
                    $n . ' บัญชี',
                    $n > 0 ? '' : 'ยังไม่มีใครล็อกอินได้ — ใช้ recover.php สร้างบัญชีแรก แล้วลบทิ้ง'
                );
            }
        } catch (Throwable $ex) {
            /* ข้อความของ PDO ไม่มีรหัสผ่านอยู่ในนั้น แต่กันไว้อีกชั้นด้วยการตัดให้สั้น */
            $msg = mb_substr($ex->getMessage(), 0, 160);
            check('ต่อฐานข้อมูล', BAD, 'ต่อไม่ได้ — ' . $msg,
                'ตรวจชื่อฐานข้อมูล ชื่อผู้ใช้ รหัสผ่าน และให้ host เป็น localhost บนโฮสต์');
        }
    }
} else {
    check('ต่อฐานข้อมูล', INFO, 'ยังไม่ได้ทดสอบ', 'จะทดสอบให้เมื่อมี config.local.php แล้ว');
}

/* ------------------------------------------------------------ 9. session */

$sessOk = false;
if (extension_loaded('session') && session_status() === PHP_SESSION_NONE) {
    $sessOk = @session_start();
    if ($sessOk) {
        session_destroy();
    }
}
check('เริ่ม session ได้', $sessOk ? OK : WARN, $sessOk ? 'ได้' : 'ไม่ได้',
    $sessOk ? '' : 'ตรวจว่าโฟลเดอร์ session.save_path เขียนได้');

/* --------------------------------------------------------- 10. อีเมล (ไม่บังคับ) */

check(
    'ฟังก์ชัน mail() ของ PHP',
    function_exists('mail') ? OK : WARN,
    function_exists('mail') ? 'เรียกใช้ได้' : 'ถูกปิด',
    'ใช้แจ้งเตือนเมื่อมีคำขอติดต่อใหม่ · ไม่บังคับ ข้อความถูกเก็บในฐานข้อมูลเสมอไม่ว่าจะส่งเมลได้หรือไม่'
);

/* ------------------------------------------------------------------ สรุปผล */

$bad = count(array_filter($rows, static fn(array $r): bool => $r[1] === BAD));
$warn = count(array_filter($rows, static fn(array $r): bool => $r[1] === WARN));
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ตรวจสภาพโฮสต์ — FMK CMS</title>
<style>
  :root{--line:#DCE3DF;--ink:#1A1A1A;--dim:#5B6570;--ok:#1B7F4B;--warn:#B06A00;--bad:#B3261E;--navy:#0A2439}
  *{box-sizing:border-box}
  body{margin:0;background:#F4F7F5;color:var(--ink);font:15px/1.65 'Noto Sans Thai',system-ui,-apple-system,sans-serif;padding:24px 16px}
  .wrap{max-width:900px;margin:0 auto}
  h1{font-size:21px;color:var(--navy);margin:0 0 6px}
  .sub{color:var(--dim);font-size:13.5px;margin:0 0 20px}
  .verdict{border-radius:14px;padding:18px 20px;margin-bottom:20px;font-weight:700;font-size:15px;line-height:1.6}
  .verdict.go{background:#E8F5EE;border:1px solid #9FD4B6;color:#12633A}
  .verdict.hold{background:#FDECEA;border:1px solid #F2B8B2;color:#8C1D16}
  .verdict.soso{background:#FFF6E5;border:1px solid #F0D19A;color:#8A5200}
  .verdict span{display:block;font-weight:400;font-size:13.5px;margin-top:6px;color:inherit;opacity:.85}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
  th,td{text-align:left;padding:11px 14px;border-bottom:1px solid var(--line);vertical-align:top;font-size:13.5px}
  th{background:#F7FAF8;font-size:12.5px;color:var(--dim);font-weight:700;letter-spacing:.02em}
  tr:last-child td{border-bottom:0}
  td.t{font-weight:600;width:29%}
  td.v{width:9%;white-space:nowrap;font-weight:700}
  td.f{width:34%;word-break:break-word;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px}
  td.a{color:var(--dim);font-size:12.5px}
  .ok{color:var(--ok)} .warn{color:var(--warn)} .bad{color:var(--bad)} .info{color:var(--dim)}
  .note{margin-top:20px;background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px 18px;font-size:13.5px}
  .note strong{color:var(--bad)}
  code{background:#EEF2F0;padding:1px 5px;border-radius:4px;font-size:12.5px}
  @media (max-width:640px){
    table,thead,tbody,tr,td,th{display:block}
    thead{display:none}
    tr{border-bottom:1px solid var(--line);padding:6px 0}
    td{border:0;padding:3px 14px;width:auto!important}
    td.t{padding-top:12px}
  }
</style>
</head>
<body>
<div class="wrap">
  <h1>ตรวจสภาพโฮสต์ก่อนติดตั้ง</h1>
  <p class="sub">
    <?= $e((string) ($_SERVER['HTTP_HOST'] ?? '')) ?> · ตรวจเมื่อ <?= $e(date('Y-m-d H:i:s')) ?> น.
  </p>

  <?php if ($bad > 0): ?>
    <div class="verdict hold">
      ยังติดตั้งไม่ได้ — มี <?= $bad ?> ข้อที่ต้องแก้ก่อน
      <span>ดูแถวสีแดงด้านล่าง แก้ให้ครบแล้วโหลดหน้านี้ใหม่</span>
    </div>
  <?php elseif ($warn > 0): ?>
    <div class="verdict soso">
      ผ่านข้อบังคับทั้งหมด แต่มี <?= $warn ?> ข้อที่ควรดู
      <span>ติดตั้งต่อได้ แต่อ่านแถวสีส้มให้เข้าใจก่อนว่าจะเสียความสามารถอะไรไป</span>
    </div>
  <?php else: ?>
    <div class="verdict go">
      โฮสต์พร้อมติดตั้ง
      <span>ผ่านทุกข้อ · ทำขั้นตอนถัดไปใน DEPLOY.md ได้เลย</span>
    </div>
  <?php endif; ?>

  <table>
    <thead><tr><th>หัวข้อ</th><th>ผล</th><th>ค่าที่เจอ</th><th>หมายเหตุ</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$topic, $verdict, $found, $advice]):
        $mark = match ($verdict) { OK => 'ผ่าน', WARN => 'ควรดู', BAD => 'ต้องแก้', default => '—' }; ?>
      <tr>
        <td class="t"><?= $e($topic) ?></td>
        <td class="v <?= $e($verdict) ?>"><?= $e($mark) ?></td>
        <td class="f"><?= $e($found) ?></td>
        <td class="a"><?= $e($advice) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="note">
    <strong>ลบไฟล์นี้ทิ้งเมื่อตรวจเสร็จ</strong> — เข้า DirectAdmin → File Manager →
    <code>public_html</code> แล้วลบ <code>fmk-check.php</code>
    ไฟล์นี้บอกโครงสร้างโฟลเดอร์และสถานะฐานข้อมูลของเซิร์ฟเวอร์ ไม่ควรเปิดทิ้งไว้
  </div>
</div>
</body>
</html>

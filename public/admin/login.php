<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use function Fmk\e;
use function Fmk\redirect;
use Fmk\Auth;
use Fmk\Csrf;

/* ล็อกอินอยู่แล้วก็ไม่ต้องเห็นหน้านี้ */
if (Auth::user() !== null) {
    redirect('index.php');
}

$error = '';
$email = '';
$notice = isset($_GET['bye']) ? 'ออกจากระบบเรียบร้อยแล้ว' : '';

/* ปลายทางหลังล็อกอิน — รับเฉพาะ path ภายในเว็บนี้ กัน open redirect */
$next = 'index.php';
$rawNext = $_GET['next'] ?? $_POST['next'] ?? '';
if (is_string($rawNext) && $rawNext !== ''
    && str_starts_with($rawNext, '/')
    && !str_starts_with($rawNext, '//')) {
    $next = $rawNext;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $error = 'เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง';
    } else {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $pass  = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        $r = Auth::attempt($email, $pass);

        if ($r['ok']) {
            redirect($next);
        }

        $error = match ($r['error'] ?? '') {
            Auth::ERR_LOCKED => sprintf(
                'ลองเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณารออีก %d นาทีแล้วลองใหม่',
                (int) ceil((int) ($r['retry_after'] ?? 60) / 60)
            ),
            Auth::ERR_DISABLED => 'บัญชีนี้ถูกปิดการใช้งาน กรุณาติดต่อผู้ดูแลระบบ',
            default            => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
        };
    }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>เข้าสู่ระบบ · FMK Intertrade</title>
<link rel="icon" href="../logo-seal.jpeg" type="image/jpeg">
<link rel="stylesheet" href="style.css">
</head>
<body class="auth">
<main class="card">
  <img src="../logo-monogram.png" alt="FMK Intertrade" class="logo">
  <h1>ระบบจัดการเว็บไซต์</h1>
  <p class="sub">FMK Intertrade Company Limited</p>

  <?php if ($notice !== ''): ?>
    <div class="notice" role="status"><?= e($notice) ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">

    <label for="email">อีเมล</label>
    <input type="email" id="email" name="email" value="<?= e($email) ?>"
           required autocomplete="username" autofocus inputmode="email">

    <label for="password">รหัสผ่าน</label>
    <input type="password" id="password" name="password" required autocomplete="current-password">

    <button type="submit">เข้าสู่ระบบ</button>
  </form>

  <p class="backlink"><a href="../">← กลับไปหน้าเว็บไซต์</a></p>
  <p class="note">
    หน้านี้สำหรับผู้ดูแลเว็บไซต์เท่านั้น ไม่มีการเปิดสมัครสมาชิก<br>
    ลืมรหัสผ่าน? ระบบนี้ไม่ส่งอีเมลกู้รหัส ให้ติดต่อผู้ดูแลระบบเพื่อตั้งรหัสใหม่ให้
  </p>
</main>
</body>
</html>

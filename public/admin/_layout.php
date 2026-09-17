<?php
declare(strict_types=1);

/* ส่วนหัว/ท้ายร่วมของทุกหน้าหลังบ้าน — เรียกผ่าน admin_header() / admin_footer() */

use function Fmk\e;
use Fmk\Content;
use Fmk\Csrf;

/**
 * เก็บข้อความแจ้งผลไว้ข้ามการ redirect
 *
 * ใช้คู่กับรูปแบบ POST แล้ว redirect ต่อ เพื่อให้ผู้ใช้กดรีเฟรชได้โดยไม่บันทึกซ้ำ
 */
function admin_set_flash(string $type, string $msg): void
{
    \Fmk\Session::start();
    $_SESSION['fmk_flash'] = ['type' => $type, 'msg' => $msg];
}

/** @return array{type:string,msg:string}|null */
function admin_take_flash(): ?array
{
    \Fmk\Session::start();
    $f = $_SESSION['fmk_flash'] ?? null;
    unset($_SESSION['fmk_flash']);
    return is_array($f) ? $f : null;
}

function admin_header(array $user, string $title, string $active = ''): void
{
    /* จำนวนข้อความที่ยังไม่ได้อ่าน ติดไว้บนเมนูทุกหน้า
       ถ้าไม่เห็นตัวเลข ผู้ดูแลจะลืมเข้าไปดู แล้วลูกค้าก็นั่งรอสายที่ไม่มีใครโทรกลับ */
    $unread = \Fmk\Messages::unreadCount();

    $nav = [
        'index.php'    => 'หน้าหลัก',
        'content.php'  => 'เนื้อหาเว็บไซต์',
        'media.php'    => 'รูปภาพ',
        'messages.php' => 'กล่องข้อความ' . ($unread > 0 ? ' (' . $unread . ')' : ''),
        'publish.php'  => 'ตรวจและเผยแพร่',
    ];
    /* เมนูจัดการผู้ใช้เห็นเฉพาะผู้ดูแลระบบ — แต่การซ่อนเมนูไม่ใช่การกันสิทธิ์
       ตัว users.php เรียก Auth::requireRole('admin') ของมันเองอยู่แล้ว */
    if (($user['role'] ?? '') === 'admin') {
        $nav['users.php'] = 'ผู้ใช้งาน';
    }

    /* สถานะรวมของเว็บ แสดงทุกหน้า ผู้ดูแลจะได้รู้เสมอว่ามีงานค้างหรือไม่ */
    $dirty = null;
    try {
        $dirty = Content::hasUnpublishedChanges();
    } catch (\Throwable) {
        // ยังไม่ได้นำเข้าเนื้อหา — ไม่ต้องแสดงสถานะ
    }
    ?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · FMK Intertrade</title>
<link rel="icon" href="../logo-seal.jpeg" type="image/jpeg">
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <div class="bar">
    <img src="../logo-monogram.png" alt="" class="mark">
    <div class="brand">
      <strong>ระบบจัดการเว็บไซต์</strong>
      <span>FMK Intertrade</span>
    </div>
    <nav class="mainnav" aria-label="เมนูหลัก">
      <?php foreach ($nav as $href => $label): ?>
        <a href="<?= e($href) ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <a href="../" target="_blank" rel="noopener" class="viewsite" title="เปิดหน้าเว็บในแท็บใหม่">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
          <path d="M15 3h6v6"/><path d="M10 14L21 3"/>
        </svg>
        ดูหน้าเว็บ
      </a>
      <a href="account.php" class="name" title="บัญชีของฉัน — เปลี่ยนรหัสผ่าน"><?= e((string) ($user['display_name'] ?: $user['email'])) ?></a>
      <span class="role role-<?= e((string) $user['role']) ?>">
        <?= $user['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'ผู้แก้ไขเนื้อหา' ?>
      </span>
      <form method="post" action="logout.php" class="inline">
        <?= Csrf::field() ?>
        <button type="submit" class="ghost">ออกจากระบบ</button>
      </form>
    </div>
  </div>

  <?php if ($dirty !== null): ?>
    <div class="sitestate <?= $dirty ? 'pending' : 'live' ?>">
      <div class="inner">
        <?php if ($dirty): ?>
          <span class="dot"></span>
          <strong>มีฉบับร่างที่ยังไม่ได้เผยแพร่</strong>
          <span class="sub">ผู้เข้าชมเว็บยังเห็นเนื้อหาชุดเดิมอยู่</span>
          <a href="publish.php">ตรวจและเผยแพร่</a>
        <?php else: ?>
          <span class="dot"></span>
          <strong>เว็บเป็นเวอร์ชันล่าสุดแล้ว</strong>
          <span class="sub">ไม่มีงานค้างรอเผยแพร่</span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</header>
<main class="wrap">
    <?php
    /* ไฟล์กู้คืนรหัสผ่านต้องอยู่บนเว็บแค่ตอนที่ใช้จริงเท่านั้น
       ถ้าลืมลบทิ้ง จะกลายเป็นประตูหลังที่เปิดค้างไว้ — เตือนให้เห็นทุกหน้าจนกว่าจะลบ */
    if (is_file(__DIR__ . '/recover.php')) {
        echo '<div class="flash err" role="alert"><strong>พบไฟล์ recover.php บนเว็บ</strong><br>'
           . 'ไฟล์นี้ใช้ตั้งรหัสผ่านใหม่ตอนเข้าหลังบ้านไม่ได้ ควรอยู่บนเว็บเฉพาะตอนที่กำลังใช้งานเท่านั้น<br>'
           . 'เข้า DirectAdmin → File Manager → <code>public_html/admin</code> แล้วลบไฟล์ <code>recover.php</code> ทิ้ง'
           . '</div>';
    }

    $f = admin_take_flash();
    if ($f !== null) {
        admin_flash($f['type'], $f['msg']);
    }
}

function admin_footer(): void
{
    echo "</main>\n<script src=\"app.js\"></script>\n</body>\n</html>\n";
}

/**
 * แถบแจ้งผล
 *
 * ชนิด newpw ใช้แสดงรหัสผ่านที่ระบบสุ่มให้ ซึ่งจะเห็นได้ครั้งเดียว
 * จึงทำให้เด่นและคัดลอกง่ายกว่าข้อความทั่วไป
 */
function admin_flash(string $type, string $msg): void
{
    if ($msg === '') {
        return;
    }
    $cls = match ($type) {
        'ok'    => 'flash ok',
        'newpw' => 'flash newpw',
        default => 'flash err',
    };
    echo '<div class="' . $cls . '" role="status">' . nl2br(e($msg)) . '</div>';
}

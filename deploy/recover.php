<?php
declare(strict_types=1);

/* =============================================================================
 * FMK Intertrade — กู้คืนการเข้าถึงหลังบ้าน (ใช้ชั่วคราวเท่านั้น)
 * =============================================================================
 *
 * ใช้เมื่อ:
 *   1) ผู้ดูแลระบบลืมรหัสผ่านกันหมด จนไม่มีใครเข้าหลังบ้านได้เลย
 *   2) เพิ่งติดตั้งเสร็จและยังไม่มีบัญชีผู้ดูแลสักคน (ตอนขึ้นเว็บครั้งแรก)
 *
 * ทำไมต้องมี:
 *   ระบบนี้ไม่มีอีเมลกู้รหัสผ่านโดยตั้งใจ (ต้องใช้บริการภายนอกที่มีค่าใช้จ่าย)
 *   และโฮสติ้งนี้ใช้ SSH ไม่ได้ จึงรันคำสั่ง php bin/create-user.php ไม่ได้
 *   ถ้าไม่มีไฟล์นี้ ทางออกเดียวคือเข้าไปแก้ฐานข้อมูลด้วยมือผ่าน phpMyAdmin
 *
 * -----------------------------------------------------------------------------
 * วิธีใช้ (ผ่าน DirectAdmin File Manager)
 * -----------------------------------------------------------------------------
 *   1. อัปโหลดไฟล์นี้ไปไว้ที่  public_html/admin/recover.php
 *   2. เปิด  https://โดเมนของคุณ/admin/recover.php
 *   3. ทำตามที่หน้าจอบอก (จะให้สร้างไฟล์เปล่าหนึ่งไฟล์เพื่อพิสูจน์ว่าคุณคือเจ้าของ)
 *   4. ตั้งรหัสผ่านใหม่
 *   5. ** ลบไฟล์นี้ทิ้งทันที **  ระบบจะขึ้นแถบเตือนสีแดงในหลังบ้านจนกว่าจะลบ
 *
 * -----------------------------------------------------------------------------
 * ความปลอดภัย
 * -----------------------------------------------------------------------------
 *   ไฟล์นี้ไม่ได้ถูกรวมอยู่ในชุดที่อัปขึ้นเว็บตามปกติ ต้องอัปเองเมื่อจำเป็นเท่านั้น
 *
 *   ต่อให้มีคนอื่นเจอไฟล์นี้บนเว็บ เขาก็ใช้ไม่ได้ เพราะต้องพิสูจน์ก่อนว่า
 *   **เขียนไฟล์ลงเซิร์ฟเวอร์ได้** ซึ่งทำได้เฉพาะคนที่เข้า DirectAdmin หรือ FTP ได้
 *   ซึ่งก็คือคนที่ควบคุมโฮสติ้งอยู่แล้ว
 *
 *   ทุกครั้งที่ใช้จะถูกบันทึกลงประวัติการกระทำ และ session ที่ค้างอยู่ทั้งหมดของ
 *   บัญชีนั้นจะถูกยกเลิก
 * =========================================================================== */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use function Fmk\e;
use Fmk\Audit;
use Fmk\Auth;
use Fmk\Config;
use Fmk\Csrf;
use Fmk\Db;
use Fmk\Session;
use Fmk\Users;

header('X-Robots-Tag: noindex, nofollow', true);

Session::start();

const CHALLENGE_KEY = 'fmk_recover_challenge';
const MAX_TRIES     = 20;

/* โจทย์พิสูจน์ตัวตน: ชื่อไฟล์สุ่มที่ต้องไปสร้างไว้ในโฟลเดอร์เดียวกับไฟล์นี้ */
if (!isset($_SESSION[CHALLENGE_KEY]) || !is_string($_SESSION[CHALLENGE_KEY])) {
    $_SESSION[CHALLENGE_KEY] = bin2hex(random_bytes(6));
    $_SESSION['fmk_recover_tries'] = 0;
}
$challenge = (string) $_SESSION[CHALLENGE_KEY];
$proofName = 'fmk-' . $challenge . '.txt';
$proofPath = __DIR__ . '/' . $proofName;

$proved = is_file($proofPath);

$msg = '';
$err = '';
$done = false;

/* ----------------------------------------------------------------- คำสั่ง */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $tries = (int) ($_SESSION['fmk_recover_tries'] ?? 0);

    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $err = 'หน้านี้เปิดค้างไว้นานเกินไป กรุณารีเฟรชแล้วทำใหม่';
    } elseif ($tries >= MAX_TRIES) {
        $err = 'ลองหลายครั้งเกินไป กรุณาปิดเบราว์เซอร์แล้วเปิดใหม่';
    } elseif (($_POST['do'] ?? '') === 'check') {
        $_SESSION['fmk_recover_tries'] = $tries + 1;
        if ($proved) {
            $msg = 'ยืนยันตัวตนเรียบร้อย';
        } else {
            $err = 'ยังไม่พบไฟล์ ' . $proofName . ' — ตรวจว่าสร้างไว้ในโฟลเดอร์เดียวกับ recover.php '
                 . 'และชื่อไฟล์ตรงกันทุกตัวอักษร (ระวังนามสกุล .txt ซ้ำสองชั้น)';
        }
    } elseif (($_POST['do'] ?? '') === 'apply') {
        $_SESSION['fmk_recover_tries'] = $tries + 1;

        if (!$proved) {
            $err = 'ยังไม่ได้ยืนยันตัวตน';
        } else {
            $email = Users::normalizeEmail(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
            $pw1   = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $pw2   = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';

            try {
                if (!hash_equals($pw1, $pw2)) {
                    throw new RuntimeException('รหัสผ่านสองช่องไม่ตรงกัน');
                }
                Users::validatePassword($pw1, $email);

                $existing = Db::one('SELECT id, role FROM users WHERE email = ?', [$email]);

                if ($existing === null) {
                    /* ไม่มีบัญชีนี้ → สร้างใหม่เป็นผู้ดูแลระบบ
                       ใช้ตอนติดตั้งครั้งแรก หรือเมื่อบัญชีเดิมถูกลบไปหมดแล้ว */
                    Users::validateEmail($email);
                    Db::run(
                        "INSERT INTO users (email, password_hash, display_name, role, status, must_change_pw)
                         VALUES (?, ?, ?, 'admin', 'active', 0)",
                        [$email, Auth::hashPassword($pw1), 'ผู้ดูแลระบบ']
                    );
                    $id = (int) Db::pdo()->lastInsertId();
                    $what = 'สร้างบัญชีผู้ดูแลระบบใหม่';
                } else {
                    /* มีบัญชีอยู่แล้ว → ตั้งรหัสใหม่ และดันให้เป็น admin ที่ใช้งานได้
                       เพราะจุดประสงค์ของหน้านี้คือ "ทำให้กลับเข้าไปได้" */
                    $id = (int) $existing['id'];
                    Db::run(
                        "UPDATE users
                            SET password_hash = ?, role = 'admin', status = 'active', must_change_pw = 0
                          WHERE id = ?",
                        [Auth::hashPassword($pw1), $id]
                    );
                    $what = 'ตั้งรหัสผ่านใหม่และคืนสิทธิ์ผู้ดูแลระบบ';
                }

                /* session เก่าทั้งหมดของบัญชีนี้ต้องใช้ไม่ได้ และปลดล็อกการลองรหัสผิด */
                Users::revokeSessions($id);
                Users::unlock($email);

                Audit::log($id, 'user.recovered', 'user', (string) $id, [
                    'email'  => $email,
                    'action' => $what,
                    'ip'     => \Fmk\client_ip(),
                ]);

                /* ใช้โจทย์ซ้ำไม่ได้ ต้องเริ่มใหม่ถ้าจะใช้อีก */
                @unlink($proofPath);
                unset($_SESSION[CHALLENGE_KEY], $_SESSION['fmk_recover_tries']);

                $msg = $what . ' สำหรับ ' . $email . ' เรียบร้อยแล้ว';
                $done = true;
            } catch (Throwable $ex) {
                $err = $ex->getMessage();
            }
        }
    }
}

/* ----------------------------------------------------------- ข้อมูลประกอบ */
$admins = [];
$dbOk = true;
try {
    $admins = Db::all("SELECT email, status FROM users WHERE role = 'admin' ORDER BY id");
} catch (Throwable $ex) {
    $dbOk = false;
    $err = $err !== '' ? $err : 'ต่อฐานข้อมูลไม่ได้: ' . $ex->getMessage();
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>กู้คืนการเข้าถึงหลังบ้าน · FMK Intertrade</title>
<style>
  :root{--navy:#0A2439;--blue:#0E5B91;--line:#DCE3DF;--muted:#5B6570;--danger:#B3261E}
  *{box-sizing:border-box}
  body{margin:0;background:#F4F7F5;color:#1A1A1A;font:15px/1.65 'Noto Sans Thai',system-ui,sans-serif;
       display:flex;justify-content:center;padding:28px 16px}
  .box{width:100%;max-width:640px;background:#fff;border:1px solid var(--line);border-radius:16px;padding:30px}
  h1{font-size:20px;color:var(--navy);margin:0 0 6px}
  .sub{color:var(--muted);font-size:13.5px;margin:0 0 22px}
  .step{border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}
  .step.on{border-color:var(--blue);box-shadow:0 0 0 3px rgba(14,91,145,.09)}
  .step.ok{border-color:#B7DCC0;background:#F4FBF6}
  h2{font-size:15px;color:var(--navy);margin:0 0 10px}
  code{background:#F2F5F3;border:1px solid var(--line);border-radius:6px;padding:3px 8px;
       font-family:ui-monospace,monospace;font-size:14px;user-select:all;word-break:break-all}
  ol{margin:10px 0 0;padding-left:20px}
  li{margin-bottom:7px}
  label{display:block;font-size:13px;font-weight:600;color:var(--navy);margin-top:14px}
  input[type=email],input[type=password]{width:100%;padding:11px 13px;border:1px solid var(--line);
       border-radius:9px;font:inherit;font-size:14px;margin-top:4px}
  button{margin-top:18px;padding:12px 20px;border:0;border-radius:9px;background:var(--blue);
       color:#fff;font:inherit;font-weight:700;cursor:pointer}
  button.ghost{background:#fff;color:var(--blue);border:1px solid var(--line)}
  .alert,.good,.warn{border-radius:9px;padding:12px 14px;font-size:13.5px;margin-bottom:14px}
  .alert{background:#FDECEA;border:1px solid #F5C2BE;color:var(--danger)}
  .good{background:#E7F4EA;border:1px solid #B7DCC0;color:#24613A}
  .warn{background:#FFF6E5;border:1px solid #F0DAA8;color:#7A5810}
  .hint{font-size:12.5px;color:var(--muted);line-height:1.55;margin-top:8px}
  ul.acc{margin:8px 0 0;padding-left:20px;font-size:13.5px}
</style>
</head>
<body>
<div class="box">
  <h1>กู้คืนการเข้าถึงหลังบ้าน</h1>
  <p class="sub">ใช้เมื่อเข้าหลังบ้านไม่ได้แล้วจริง ๆ เท่านั้น · หน้านี้ไม่ถูกเก็บไว้บนเว็บตามปกติ</p>

  <?php if ($err !== ''): ?><div class="alert"><?= nl2br(e($err)) ?></div><?php endif; ?>
  <?php if ($msg !== '' && !$done): ?><div class="good"><?= e($msg) ?></div><?php endif; ?>

  <?php if ($done): ?>
    <div class="good"><strong><?= e($msg) ?></strong></div>
    <div class="warn">
      <strong>ขั้นตอนสุดท้าย — สำคัญมาก</strong><br>
      กลับไปที่ DirectAdmin File Manager แล้ว <strong>ลบไฟล์ <code>recover.php</code> ทิ้งทันที</strong><br>
      ตราบใดที่ไฟล์นี้ยังอยู่บนเว็บ จะมีแถบเตือนสีแดงขึ้นในหลังบ้านตลอดเวลา
    </div>
    <p><a href="login.php">→ ไปหน้าเข้าสู่ระบบ</a></p>

  <?php elseif (!$dbOk): ?>
    <div class="step on">
      <h2>ต่อฐานข้อมูลไม่ได้</h2>
      <p class="hint">
        ตรวจว่ามีไฟล์ <code>config/config.local.php</code> อยู่นอก <code>public_html</code>
        และค่าชื่อฐานข้อมูล ผู้ใช้ รหัสผ่าน ตรงกับที่ตั้งไว้ใน DirectAdmin
      </p>
    </div>

  <?php else: ?>
    <div class="step <?= $proved ? 'ok' : 'on' ?>">
      <h2><?= $proved ? '✓ ขั้นที่ 1 — ยืนยันตัวตนแล้ว' : 'ขั้นที่ 1 — พิสูจน์ว่าคุณคือเจ้าของเว็บ' ?></h2>

      <?php if (!$proved): ?>
        <p class="hint" style="margin-top:0">
          เพื่อไม่ให้คนอื่นใช้หน้านี้ได้ ต้องพิสูจน์ก่อนว่าคุณเข้าถึงไฟล์บนเซิร์ฟเวอร์ได้
          ซึ่งทำได้เฉพาะคนที่เข้า DirectAdmin หรือ FTP ได้เท่านั้น
        </p>
        <ol>
          <li>เปิด <strong>DirectAdmin → File Manager</strong> ไปที่โฟลเดอร์ <code>public_html/admin</code></li>
          <li>กด <strong>Create File</strong> แล้วตั้งชื่อไฟล์ว่า<br><code><?= e($proofName) ?></code></li>
          <li>ไฟล์เปล่าก็ได้ ไม่ต้องใส่อะไรข้างใน</li>
          <li>กลับมาที่หน้านี้แล้วกดปุ่มด้านล่าง</li>
        </ol>
        <form method="post">
          <?= Csrf::field() ?>
          <button type="submit" name="do" value="check">ตรวจสอบแล้ว ไปต่อ</button>
        </form>
      <?php else: ?>
        <p class="hint" style="margin-top:0">พบไฟล์ <code><?= e($proofName) ?></code> แล้ว</p>
      <?php endif; ?>
    </div>

    <div class="step <?= $proved ? 'on' : '' ?>" <?= $proved ? '' : 'style="opacity:.45"' ?>>
      <h2>ขั้นที่ 2 — ตั้งรหัสผ่านใหม่</h2>

      <?php if ($admins === []): ?>
        <div class="warn" style="margin-bottom:0">
          ยังไม่มีบัญชีผู้ดูแลระบบในฐานข้อมูลเลย — กรอกอีเมลที่ต้องการใช้ แล้วระบบจะสร้างให้ใหม่
        </div>
      <?php else: ?>
        <p class="hint" style="margin-top:0">
          บัญชีผู้ดูแลระบบที่มีอยู่ — กรอกอีเมลตัวใดตัวหนึ่งเพื่อตั้งรหัสใหม่
          (หรือกรอกอีเมลใหม่เพื่อสร้างบัญชีผู้ดูแลเพิ่ม)
        </p>
        <ul class="acc">
          <?php foreach ($admins as $a): ?>
            <li><code><?= e((string) $a['email']) ?></code><?= $a['status'] !== 'active' ? ' (ปิดอยู่ — จะถูกเปิดคืนให้)' : '' ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <?php if ($proved): ?>
        <form method="post" autocomplete="off">
          <?= Csrf::field() ?>
          <label for="em">อีเมล</label>
          <input type="email" id="em" name="email" required maxlength="190" autocomplete="off">

          <label for="p1">รหัสผ่านใหม่</label>
          <input type="password" id="p1" name="password" required
                 minlength="<?= (int) Config::security('min_password_length') ?>" autocomplete="new-password">

          <label for="p2">พิมพ์รหัสผ่านใหม่อีกครั้ง</label>
          <input type="password" id="p2" name="password2" required
                 minlength="<?= (int) Config::security('min_password_length') ?>" autocomplete="new-password">

          <p class="hint">
            ยาวอย่างน้อย <?= (int) Config::security('min_password_length') ?> ตัวอักษร ·
            เครื่องอื่นที่ล็อกอินค้างไว้จะถูกออกจากระบบทั้งหมด ·
            การใช้หน้านี้ถูกบันทึกลงประวัติการกระทำ
          </p>

          <button type="submit" name="do" value="apply">ตั้งรหัสผ่านใหม่</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>

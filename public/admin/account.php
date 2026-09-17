<?php
declare(strict_types=1);

/*
 * บัญชีของฉัน — เปลี่ยนรหัสผ่านและชื่อที่แสดงของตัวเอง
 *
 * หน้านี้เข้าได้ทุกบทบาท และเป็นหนึ่งในสองหน้าที่เข้าได้ทั้งที่ยังไม่ได้เปลี่ยน
 * รหัสผ่านตั้งต้น (อีกหน้าคือ logout.php) — ดู Auth::requireLogin()
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use function Fmk\redirect;
use Fmk\Auth;
use Fmk\Config;
use Fmk\Csrf;
use Fmk\Db;
use Fmk\Users;

$user = Auth::requireLogin();
$me = (int) $user['id'];
$forced = (int) ($user['must_change_pw'] ?? 0) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        admin_set_flash('err', 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่แล้วลองอีกครั้ง');
        redirect('account.php');
    }

    $str = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $do = $str('do');

    try {
        switch ($do) {
            case 'password':
                $revoked = Users::changeOwnPassword($me, $str('current'), $str('new'), $str('confirm'));
                $msg = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
                if ($revoked > 0) {
                    $msg .= " · ออกจากระบบให้อีก $revoked เครื่องที่ล็อกอินค้างไว้";
                }
                admin_set_flash('ok', $msg);
                /* เพิ่งเปลี่ยนรหัสตั้งต้นเสร็จ พาไปหน้าหลักเลย จะได้เริ่มทำงานได้ */
                redirect($forced ? 'index.php' : 'account.php');
                break;

            case 'name':
                Users::updateOwnName($me, $str('display_name'));
                admin_set_flash('ok', 'บันทึกชื่อที่แสดงแล้ว');
                break;

            case 'logoutothers':
                $n = Users::revokeSessions($me, \Fmk\Session::currentHash());
                \Fmk\Audit::log($me, 'user.sessions_revoked', 'user', (string) $me, ['count' => $n, 'self' => true]);
                admin_set_flash('ok', $n > 0
                    ? "ออกจากระบบให้ $n เครื่องแล้ว เครื่องนี้ยังใช้งานต่อได้"
                    : 'ไม่มีเครื่องอื่นล็อกอินค้างอยู่');
                break;

            default:
                admin_set_flash('err', 'คำสั่งไม่ถูกต้อง');
        }
    } catch (Throwable $ex) {
        admin_set_flash('err', $ex->getMessage());
    }

    redirect('account.php');
}

$minLen = (int) Config::security('min_password_length');
$others = (int) Db::value(
    'SELECT COUNT(*) FROM sessions WHERE user_id = ? AND expires_at > NOW() AND id <> ?',
    [$me, \Fmk\Session::currentHash() ?? '']
);

admin_header($user, 'บัญชีของฉัน', '');
?>

<?php if ($forced): ?>
  <div class="flash warnflash" role="status">
    <strong>ต้องตั้งรหัสผ่านของคุณเองก่อน</strong><br>
    รหัสที่ใช้อยู่ตอนนี้เป็นรหัสที่ผู้ดูแลระบบตั้งให้ ซึ่งผ่านมือคนอื่นมาแล้ว
    ตั้งรหัสใหม่ที่มีแค่คุณรู้ แล้วจึงจะใช้งานหน้าอื่นได้
  </div>
<?php endif; ?>

<h1>บัญชีของฉัน</h1>
<p class="lead"><?= e((string) $user['email']) ?> · <?= e(Users::roleLabel((string) $user['role'])) ?></p>

<section class="panel">
  <h2>เปลี่ยนรหัสผ่าน</h2>
  <form method="post" class="userform" autocomplete="off">
    <?= Csrf::field() ?>

    <label for="cur">รหัสผ่านปัจจุบัน</label>
    <input type="password" id="cur" name="current" required autocomplete="current-password">

    <label for="np">รหัสผ่านใหม่</label>
    <input type="password" id="np" name="new" required autocomplete="new-password" minlength="<?= $minLen ?>">

    <label for="np2">พิมพ์รหัสผ่านใหม่อีกครั้ง</label>
    <input type="password" id="np2" name="confirm" required autocomplete="new-password" minlength="<?= $minLen ?>">

    <p class="hint">
      ยาวอย่างน้อย <?= $minLen ?> ตัวอักษร · ไม่บังคับให้มีตัวใหญ่หรืออักขระพิเศษ
      เพราะวลียาว ๆ ที่คุณจำได้ปลอดภัยกว่ารหัสสั้นที่มีสัญลักษณ์แปลก ๆ<br>
      เมื่อเปลี่ยนแล้ว เครื่องอื่นที่ล็อกอินค้างไว้จะถูกออกจากระบบทั้งหมด
    </p>

    <div class="formbar">
      <button type="submit" name="do" value="password" class="primary">เปลี่ยนรหัสผ่าน</button>
    </div>
  </form>
</section>

<?php if (!$forced): ?>
<section class="panel">
  <h2>ชื่อที่แสดง</h2>
  <form method="post" class="userform">
    <?= Csrf::field() ?>
    <label for="dn">ชื่อที่จะขึ้นบนแถบด้านบนและในประวัติการแก้ไข</label>
    <input type="text" id="dn" name="display_name" maxlength="120"
           value="<?= e((string) $user['display_name']) ?>">
    <div class="formbar">
      <button type="submit" name="do" value="name" class="secondary">บันทึกชื่อ</button>
    </div>
  </form>
</section>

<section class="panel">
  <h2>เครื่องที่ล็อกอินอยู่</h2>
  <?php if ($others === 0): ?>
    <p class="muted">มีแค่เครื่องนี้เครื่องเดียว</p>
  <?php else: ?>
    <p>นอกจากเครื่องนี้แล้ว ยังมีอีก <strong><?= $others ?></strong> เครื่องที่ล็อกอินค้างอยู่</p>
    <form method="post" class="inline">
      <?= Csrf::field() ?>
      <button type="submit" name="do" value="logoutothers" class="mini"
              data-confirm="ออกจากระบบให้ทุกเครื่องยกเว้นเครื่องนี้?">ออกจากระบบเครื่องอื่นทั้งหมด</button>
    </form>
  <?php endif; ?>
  <p class="hint">
    ระบบจะออกจากระบบให้อัตโนมัติเมื่อไม่มีความเคลื่อนไหวเกิน
    <?= (int) Config::security('session_idle_minutes') ?> นาที
    หรือครบ <?= (int) Config::security('session_absolute_hours') ?> ชั่วโมงนับจากล็อกอิน
  </p>
</section>
<?php endif; ?>

<?php admin_footer();

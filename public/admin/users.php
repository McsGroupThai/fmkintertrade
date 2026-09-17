<?php
declare(strict_types=1);

/*
 * จัดการผู้ใช้งาน — เฉพาะผู้ดูแลระบบ
 *
 * ทุกคำสั่งเป็นฟอร์ม POST จริง กด Enter หรือปิด JavaScript ก็ยังใช้ได้
 * และทุกคำสั่งผ่าน Fmk\Users ซึ่งเป็นที่เดียวที่เก็บกฎไว้
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use function Fmk\redirect;
use Fmk\Auth;
use Fmk\Csrf;
use Fmk\Users;

$user = Auth::requireRole('admin');
$me = (int) $user['id'];

/* ---------------------------------------------------------------- คำสั่ง */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        admin_set_flash('err', 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่แล้วลองอีกครั้ง');
        redirect('users.php');
    }

    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $id = (int) ($_POST['id'] ?? 0);
    $str = static fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';

    try {
        switch ($do) {
            case 'create':
                $r = Users::create($str('email'), $str('display_name'), $str('role'), $str('password'), $me);
                admin_set_flash('newpw', $r['generated']
                    ? "สร้างบัญชี {$str('email')} แล้ว\nรหัสผ่านที่สุ่มให้: {$r['password']}"
                    : "สร้างบัญชี {$str('email')} แล้ว");
                break;

            case 'update':
                Users::update($id, $str('display_name'), $str('role'), $str('status'), $me);
                admin_set_flash('ok', 'บันทึกการแก้ไขบัญชีแล้ว');
                break;

            case 'resetpw':
                /* กันคนยิง POST ตรง ๆ มาตั้งรหัสให้ตัวเอง — ระบบจะสุ่มรหัสให้แล้วเตะออกทันที
                   ถ้าไม่ทันคัดลอกรหัสก็เข้าบัญชีตัวเองไม่ได้อีก หน้าบัญชีของฉันปลอดภัยกว่า */
                if ($id === $me) {
                    throw new RuntimeException(
                        'ตั้งรหัสใหม่ให้ตัวเองผ่านหน้านี้ไม่ได้ '
                        . 'เพราะระบบจะสุ่มรหัสให้แล้วเตะออกจากระบบทันที — '
                        . 'ให้ไปที่หน้า "บัญชีของฉัน" แล้วตั้งรหัสที่คุณกำหนดเองแทน'
                    );
                }
                $r = Users::resetPassword($id, $str('password'), $me);
                $who = Users::find($id);
                $note = $r['generated']
                    ? "ตั้งรหัสผ่านใหม่ให้ {$who['email']} แล้ว\nรหัสผ่านใหม่: {$r['password']}"
                    : "ตั้งรหัสผ่านใหม่ให้ {$who['email']} แล้ว";
                if ($r['revoked'] > 0) {
                    $note .= "\nบังคับออกจากระบบไป {$r['revoked']} เครื่อง";
                }
                admin_set_flash('newpw', $note);
                break;

            case 'logoutall':
                $n = Users::forceLogout($id, $me);
                admin_set_flash('ok', $n > 0
                    ? "บังคับออกจากระบบแล้ว $n เครื่อง"
                    : 'บัญชีนี้ไม่ได้ล็อกอินค้างอยู่');
                break;

            case 'unlock':
                $who = Users::find($id);
                $n = $who === null ? 0 : Users::unlock((string) $who['email']);
                \Fmk\Audit::log($me, 'user.unlocked', 'user', (string) $id, ['count' => $n]);
                admin_set_flash('ok', 'ปลดล็อกแล้ว เข้าสู่ระบบได้ทันที');
                break;

            case 'delete':
                Users::delete($id, $me);
                admin_set_flash('ok', 'ลบบัญชีแล้ว');
                break;

            default:
                admin_set_flash('err', 'คำสั่งไม่ถูกต้อง');
        }
    } catch (Throwable $ex) {
        admin_set_flash('err', $ex->getMessage());
    }

    redirect('users.php');
}

$users = Users::all();
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? Users::find($editId) : null;
$minLen = (int) \Fmk\Config::security('min_password_length');

admin_header($user, 'ผู้ใช้งาน', 'users.php');
?>

<h1>ผู้ใช้งาน</h1>
<p class="lead">
  บัญชีสำหรับเข้าหลังบ้านเว็บไซต์ แยกขาดจากบัญชี DirectAdmin ของโฮสติ้ง
  เปลี่ยนรหัสผ่านของคุณเองได้ที่ <a href="account.php">บัญชีของฉัน</a>
</p>

<section class="panel">
  <h2>บัญชีทั้งหมด (<?= count($users) ?>)</h2>
  <table class="usertable">
    <thead>
      <tr>
        <th>อีเมล</th><th>ชื่อที่แสดง</th><th>สิทธิ์</th><th>สถานะ</th>
        <th>เข้าล่าสุด</th><th>จัดการ</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $uid = (int) $u['id'];
        $isMe = $uid === $me;
        $locked = Users::isLockedOut((string) $u['email']);
    ?>
      <tr class="<?= $u['status'] === 'disabled' ? 'rowoff' : '' ?>">
        <td>
          <strong><?= e((string) $u['email']) ?></strong>
          <?php if ($isMe): ?><span class="youtag">คุณ</span><?php endif; ?>
          <?php if ((int) $u['must_change_pw'] === 1): ?>
            <span class="warntag" title="ยังใช้รหัสที่ผู้ดูแลตั้งให้ ต้องเปลี่ยนก่อนใช้งาน">ต้องเปลี่ยนรหัส</span>
          <?php endif; ?>
          <?php if ($locked): ?>
            <span class="warntag">ถูกล็อกชั่วคราว</span>
          <?php endif; ?>
        </td>
        <td><?= e((string) $u['display_name']) ?: '<span class="muted">—</span>' ?></td>
        <td><?= e(Users::roleLabel((string) $u['role'])) ?></td>
        <td>
          <?= $u['status'] === 'active' ? 'ใช้งานอยู่' : '<span class="warn">ปิดอยู่</span>' ?>
          <?php if ((int) $u['active_sessions'] > 0): ?>
            <span class="muted">· ล็อกอินอยู่ <?= (int) $u['active_sessions'] ?></span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= $u['last_login_at'] !== null ? e((string) $u['last_login_at']) : 'ยังไม่เคย' ?></td>
        <td>
          <div class="rowops">
            <a class="minilink" href="?edit=<?= $uid ?>">แก้ไข</a>

            <?php if ($isMe): ?>
              <?php /* ตั้งรหัสใหม่ให้ตัวเองผ่านปุ่มนี้เป็นกับดัก — ระบบสุ่มรหัสให้แล้วเตะออกทันที
                       ถ้าไม่ทันคัดลอกรหัสที่ขึ้นมาครั้งเดียว ก็เข้าบัญชีตัวเองไม่ได้อีก
                       จึงพาไปหน้าบัญชีของฉันแทน ซึ่งตั้งรหัสที่ตัวเองรู้ และไม่หลุดจากระบบ */ ?>
              <a class="minilink" href="account.php">เปลี่ยนรหัสของฉัน</a>
            <?php else: ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button type="submit" name="do" value="resetpw" class="mini"
                        data-confirm-title="ตั้งรหัสผ่านใหม่ให้ผู้ใช้"
                        data-confirm-ok="ตั้งรหัสใหม่"
                        data-confirm="จะตั้งรหัสผ่านใหม่ให้ <?= e((string) $u['email']) ?>&#10;&#10;ระบบจะสุ่มรหัสให้และแสดงเพียงครั้งเดียว — คัดลอกไว้ก่อนปิดหน้า&#10;บัญชีนี้จะถูกออกจากระบบทุกเครื่องทันที">ตั้งรหัสใหม่</button>
              </form>
            <?php endif; ?>

            <?php if ((int) $u['active_sessions'] > 0): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button type="submit" name="do" value="logoutall" class="mini"
                        data-confirm="บังคับให้ <?= e((string) $u['email']) ?> ออกจากระบบทุกเครื่อง?">เตะออก</button>
              </form>
            <?php endif; ?>

            <?php if ($locked): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button type="submit" name="do" value="unlock" class="mini">ปลดล็อก</button>
              </form>
            <?php endif; ?>

            <?php if (!$isMe): ?>
              <form method="post" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= $uid ?>">
                <button type="submit" name="do" value="delete" class="mini danger"
                        data-confirm="ลบบัญชี <?= e((string) $u['email']) ?> ถาวร?&#10;ชื่อในประวัติการแก้ไขจะกลายเป็นขีด&#10;&#10;ถ้าแค่อยากห้ามเข้าใช้ ให้กดแก้ไขแล้วตั้งเป็น &quot;ปิดการใช้งาน&quot; แทน">ลบ</button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($editing !== null): ?>
<section class="panel" id="edit">
  <h2>แก้ไข <?= e((string) $editing['email']) ?></h2>
  <form method="post" class="userform">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">

    <label for="ed_name">ชื่อที่แสดง</label>
    <input type="text" id="ed_name" name="display_name" maxlength="120"
           value="<?= e((string) $editing['display_name']) ?>">

    <label for="ed_role">สิทธิ์</label>
    <select id="ed_role" name="role" class="iconsel"
            <?= (int) $editing['id'] === $me ? 'disabled' : '' ?>>
      <?php foreach (Users::ROLES as $k => $name): ?>
        <option value="<?= e($k) ?>"<?= $editing['role'] === $k ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="ed_status">สถานะ</label>
    <select id="ed_status" name="status" class="iconsel"
            <?= (int) $editing['id'] === $me ? 'disabled' : '' ?>>
      <?php foreach (Users::STATUSES as $k => $name): ?>
        <option value="<?= e($k) ?>"<?= $editing['status'] === $k ? ' selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if ((int) $editing['id'] === $me): ?>
      <input type="hidden" name="role" value="<?= e((string) $editing['role']) ?>">
      <input type="hidden" name="status" value="<?= e((string) $editing['status']) ?>">
      <p class="hint">
        เปลี่ยนสิทธิ์หรือปิดบัญชีของตัวเองไม่ได้ ให้ผู้ดูแลระบบอีกคนเป็นคนทำให้
        — กันเผลอกดแล้วเข้าหลังบ้านไม่ได้อีก
      </p>
    <?php else: ?>
      <p class="hint">
        <strong>ปิดการใช้งาน</strong> = เข้าระบบไม่ได้และถูกเตะออกทันที แต่ชื่อยังอยู่ในประวัติการแก้ไข
        ปลอดภัยกว่าการลบ
      </p>
    <?php endif; ?>

    <div class="formbar">
      <button type="submit" name="do" value="update" class="primary">บันทึก</button>
      <a class="btnlink" href="users.php">ยกเลิก</a>
    </div>
  </form>
</section>
<?php endif; ?>

<section class="panel">
  <h2>เพิ่มบัญชีใหม่</h2>
  <form method="post" class="userform">
    <?= Csrf::field() ?>

    <label for="new_email">อีเมล</label>
    <input type="email" id="new_email" name="email" required maxlength="190" autocomplete="off">

    <label for="new_name">ชื่อที่แสดง</label>
    <input type="text" id="new_name" name="display_name" maxlength="120" autocomplete="off">

    <label for="new_role">สิทธิ์</label>
    <select id="new_role" name="role" class="iconsel">
      <option value="editor">ผู้แก้ไขเนื้อหา — แก้เนื้อหาและรูปได้ แต่จัดการผู้ใช้ไม่ได้</option>
      <option value="admin">ผู้ดูแลระบบ — ทำได้ทุกอย่างรวมถึงจัดการผู้ใช้</option>
    </select>

    <label for="new_pw">รหัสผ่านตั้งต้น</label>
    <input type="text" id="new_pw" name="password" autocomplete="new-password"
           placeholder="เว้นว่างไว้ = ให้ระบบสุ่มให้ (แนะนำ)">
    <p class="hint">
      ถ้าตั้งเอง ต้องยาวอย่างน้อย <?= $minLen ?> ตัวอักษร ·
      ไม่ว่าจะตั้งเองหรือให้สุ่ม เจ้าของบัญชีจะถูกบังคับให้เปลี่ยนเป็นรหัสของตัวเองในการเข้าใช้ครั้งแรก
    </p>

    <div class="formbar">
      <button type="submit" name="do" value="create" class="primary">สร้างบัญชี</button>
    </div>
  </form>
</section>

<section class="panel">
  <h2>ผู้ดูแลระบบทำอะไรได้บ้าง</h2>
  <table>
    <thead><tr><th>งาน</th><th>ผู้แก้ไขเนื้อหา</th><th>ผู้ดูแลระบบ</th></tr></thead>
    <tbody>
      <tr><td>แก้เนื้อหาและรูปภาพ</td><td>ได้</td><td>ได้</td></tr>
      <tr><td>เผยแพร่ขึ้นเว็บจริง · ย้อนเวอร์ชัน</td><td>ได้</td><td>ได้</td></tr>
      <tr><td>ดูประวัติความเคลื่อนไหวทั้งระบบ</td><td><span class="muted">ไม่ได้</span></td><td>ได้</td></tr>
      <tr><td>จัดการผู้ใช้งาน</td><td><span class="muted">ไม่ได้</span></td><td>ได้</td></tr>
    </tbody>
  </table>
  <p class="hint">
    ระบบนี้ไม่มีอีเมลกู้รหัสผ่าน ถ้าผู้ดูแลระบบลืมรหัสทุกคน ต้องเข้าไปแก้ที่ฐานข้อมูลโดยตรง
    — จึงควรมีผู้ดูแลระบบอย่างน้อย 2 คนเสมอ
    (ตอนนี้มี <?= Users::activeAdminCount() ?> คน)
  </p>
</section>

<?php admin_footer();

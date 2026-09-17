<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use Fmk\Auth;
use Fmk\Config;
use Fmk\Content;
use Fmk\Csrf;

$user = Auth::requireLogin();

$flashOk = '';
$flashErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        $flashErr = 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        try {
            if ($action === 'publish') {
                $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
                $r = Content::publish((int) $user['id'], $note);
                $flashOk = sprintf(
                    'เผยแพร่เรียบร้อย — เวอร์ชัน %d · เขียน index.html %s bytes · privacy.html %s bytes',
                    $r['version'],
                    number_format($r['bytes']),
                    number_format($r['privacyBytes'])
                );
            } elseif ($action === 'restore') {
                /* ย้อนกลับเป็นงานที่กระทบเนื้อหาทั้งเว็บ จำกัดให้เฉพาะผู้ดูแลระบบ */
                if ($user['role'] !== 'admin') {
                    throw new RuntimeException('เฉพาะผู้ดูแลระบบเท่านั้นที่ย้อนกลับเวอร์ชันได้');
                }
                $vid = (int) ($_POST['version'] ?? 0);
                Content::restore($vid, (int) $user['id']);
                $flashOk = "ดึงเวอร์ชัน $vid กลับมาเป็นฉบับร่างแล้ว — ตรวจดูตัวอย่างก่อน แล้วค่อยกดเผยแพร่";
            }
        } catch (Throwable $ex) {
            $flashErr = $ex->getMessage();
        }
    }
}

$meta = Content::draftMeta();
$dirty = Content::hasUnpublishedChanges();
$versions = Content::versions();
$live = Config::publicDir() . '/index.html';

admin_header($user, 'เผยแพร่และประวัติ', 'publish.php');
?>

<h1>เผยแพร่และประวัติ</h1>
<p class="lead">
  การแก้ไขจะยังไม่ขึ้นเว็บจนกว่าจะกดเผยแพร่ แนะนำให้กดดูตัวอย่างก่อนทุกครั้ง
</p>

<?php admin_flash('ok', $flashOk); admin_flash('err', $flashErr); ?>

<section class="panel">
  <h2>สถานะปัจจุบัน</h2>
  <dl>
    <dt>ฉบับร่าง</dt>
    <dd>
      <?= $dirty
        ? '<strong class="warn">มีการแก้ไขที่ยังไม่ได้เผยแพร่</strong>'
        : 'ตรงกับที่เผยแพร่อยู่' ?>
    </dd>
    <dt>แก้ล่าสุด</dt>
    <dd><?= e((string) ($meta['updated_at'] ?? '—')) ?><?= $meta['by'] ? ' โดย ' . e((string) $meta['by']) : '' ?></dd>
    <dt>ไฟล์บนเว็บ</dt>
    <dd>
      <?= is_file($live)
        ? number_format((int) filesize($live)) . ' bytes · แก้ไขเมื่อ ' . e(date('Y-m-d H:i', (int) filemtime($live)))
        : 'ยังไม่มี' ?>
    </dd>
  </dl>

  <div class="formbar">
    <a href="preview.php" target="_blank" rel="noopener" class="btnlink">ดูตัวอย่างฉบับร่าง</a>
    <a href="../index.html" target="_blank" rel="noopener" class="btnlink">ดูหน้าเว็บที่เผยแพร่อยู่</a>
  </div>
</section>

<section class="panel">
  <h2>เผยแพร่ขึ้นหน้าเว็บ</h2>
  <p class="muted">
    ระบบจะเขียนไฟล์ <code>public/index.html</code> ใหม่จากฉบับร่าง
    และเก็บสำเนาไว้ในประวัติเพื่อให้ย้อนกลับได้
  </p>
  <form method="post" class="pubform">
    <?= Csrf::field() ?>
    <input type="hidden" name="action" value="publish">
    <label for="note">บันทึกช่วยจำ (ไม่บังคับ)</label>
    <input type="text" id="note" name="note" maxlength="255" placeholder="เช่น แก้เบอร์โทร เพิ่มบริการใหม่">
    <button type="submit" onclick="return confirm('เผยแพร่ฉบับร่างขึ้นหน้าเว็บ?')">เผยแพร่</button>
  </form>
</section>

<section class="panel">
  <h2>ประวัติการเผยแพร่</h2>
  <?php if ($versions === []): ?>
    <p class="muted">ยังไม่เคยเผยแพร่</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>เวอร์ชัน</th><th>เมื่อ</th><th>โดย</th><th>บันทึกช่วยจำ</th><th>ขนาด</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($versions as $v): ?>
        <tr class="<?= $v['is_current'] ? 'current' : '' ?>">
          <td>
            #<?= (int) $v['id'] ?>
            <?= $v['is_current'] ? ' <span class="tag">ใช้อยู่</span>' : '' ?>
          </td>
          <td><?= e((string) $v['published_at']) ?></td>
          <td><?= e((string) $v['email']) ?></td>
          <td><?= e((string) $v['note']) ?: '<span class="muted">—</span>' ?></td>
          <td><?= number_format((int) $v['size']) ?></td>
          <td>
            <?php if ($user['role'] === 'admin' && !$v['is_current']): ?>
            <form method="post" class="inline">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="restore">
              <input type="hidden" name="version" value="<?= (int) $v['id'] ?>">
              <button type="submit" class="mini"
                onclick="return confirm('ดึงเวอร์ชัน #<?= (int) $v['id'] ?> กลับมาเป็นฉบับร่าง? งานที่ยังไม่ได้เผยแพร่จะถูกแทนที่')">
                ย้อนกลับ
              </button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="margin-top:12px">
      การย้อนกลับจะดึงเนื้อหาเก่ามาเป็นฉบับร่างเท่านั้น ยังไม่ขึ้นเว็บจนกว่าจะกดเผยแพร่อีกครั้ง
    </p>
  <?php endif; ?>
</section>

<?php admin_footer(); ?>

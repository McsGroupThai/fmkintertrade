<?php
declare(strict_types=1);

/*
 * กล่องข้อความ — คำขอติดต่อที่ลูกค้าส่งมาจากฟอร์มบนหน้าเว็บ
 *
 * เข้าได้ทั้ง admin และ editor เพราะการตอบลูกค้าคืองานประจำวัน
 * ไม่ใช่งานดูแลระบบ
 */

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use function Fmk\redirect;
use Fmk\Auth;
use Fmk\Csrf;
use Fmk\Messages;

$user = Auth::requireLogin();
$me = (int) $user['id'];

/* ------------------------------------------------------------ ดาวน์โหลด */
if (($_GET['export'] ?? '') === 'csv') {
    $csv = Messages::toCsv();
    \Fmk\Audit::log($me, 'contact.exported', 'message', '', ['bytes' => strlen($csv)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fmk-messages-' . date('Y-m-d') . '.csv"');
    header('Content-Length: ' . strlen($csv));
    header('X-Content-Type-Options: nosniff');
    echo $csv;
    exit;
}

/* ---------------------------------------------------------------- คำสั่ง */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'messages.php?s=' . rawurlencode(is_string($_GET['s'] ?? null) ? $_GET['s'] : 'new');

    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        admin_set_flash('err', 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่แล้วลองอีกครั้ง');
        redirect($back);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';

    try {
        switch ($do) {
            case 'read':     Messages::setStatus($id, 'read', $me);     admin_set_flash('ok', 'ทำเครื่องหมายว่าอ่านแล้ว'); break;
            case 'unread':   Messages::setStatus($id, 'new', $me);      admin_set_flash('ok', 'ทำเครื่องหมายว่ายังไม่ได้อ่าน'); break;
            case 'archive':  Messages::setStatus($id, 'archived', $me); admin_set_flash('ok', 'เก็บเข้ากรุแล้ว'); break;
            case 'delete':   Messages::delete($id, $me);                admin_set_flash('ok', 'ลบข้อความแล้ว'); break;
            default:         admin_set_flash('err', 'คำสั่งไม่ถูกต้อง');
        }
    } catch (Throwable $ex) {
        admin_set_flash('err', $ex->getMessage());
    }
    redirect($back);
}

/* -------------------------------------------------------------- แสดงผล */
$viewId = (int) ($_GET['id'] ?? 0);
$view = $viewId > 0 ? Messages::find($viewId) : null;
if ($view !== null) {
    Messages::markReadOnView($viewId, $me);
    $view = Messages::find($viewId);
}

$status = is_string($_GET['s'] ?? null) ? $_GET['s'] : 'new';
$page = max(1, (int) ($_GET['p'] ?? 1));
$list = Messages::page($status, $page, 20);
$pages = (int) ceil($list['total'] / 20);
$unread = Messages::unreadCount();

$tabs = ['new' => 'ยังไม่ได้อ่าน', 'read' => 'อ่านแล้ว', 'archived' => 'เก็บเข้ากรุ', 'all' => 'ทั้งหมด'];

admin_header($user, 'กล่องข้อความ', 'messages.php');
?>

<h1>กล่องข้อความ</h1>
<p class="lead">
  คำขอติดต่อที่ลูกค้าส่งมาจากแบบฟอร์มบนหน้าเว็บ
  <?php if ($unread > 0): ?>
    · <strong>ยังไม่ได้อ่าน <?= $unread ?> ข้อความ</strong>
  <?php endif; ?>
</p>

<div class="msgtabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="?s=<?= e($k) ?>" class="<?= $k === $status ? 'on' : '' ?>">
      <?= e($label) ?><?php if ($k === 'new' && $unread > 0): ?> <span class="cnt"><?= $unread ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <a class="msgexport" href="?export=csv">ดาวน์โหลดทั้งหมดเป็น CSV</a>
</div>

<?php if ($view !== null): ?>
<section class="panel msgview">
  <h2>คำขอ #<?= (int) $view['id'] ?> · <?= e((string) $view['company']) ?></h2>
  <dl>
    <dt>ส่งเมื่อ</dt><dd><?= e((string) $view['created_at']) ?> (ภาษา<?= $view['lang'] === 'en' ? 'อังกฤษ' : 'ไทย' ?>)</dd>
    <dt>ชื่อ</dt><dd><?= e((string) $view['full_name']) ?></dd>
    <dt>บริษัท</dt><dd><?= e((string) $view['company']) ?></dd>
    <?php if ($view['position'] !== ''): ?><dt>ตำแหน่ง</dt><dd><?= e((string) $view['position']) ?></dd><?php endif; ?>
    <?php if ($view['country'] !== ''): ?><dt>ประเทศ</dt><dd><?= e((string) $view['country']) ?></dd><?php endif; ?>
    <dt>อีเมล</dt><dd><a href="mailto:<?= e((string) $view['email']) ?>"><?= e((string) $view['email']) ?></a></dd>
    <dt>โทรศัพท์</dt><dd><a href="tel:<?= e((string) $view['phone']) ?>"><?= e((string) $view['phone']) ?></a></dd>
    <?php if ($view['solution'] !== ''): ?><dt>สนใจ</dt><dd><?= e((string) $view['solution']) ?></dd><?php endif; ?>
    <?php if ($view['project_type'] !== ''): ?><dt>ประเภทโครงการ</dt><dd><?= e((string) $view['project_type']) ?></dd><?php endif; ?>
    <?php if ($view['contact_method'] !== ''): ?><dt>สะดวกติดต่อทาง</dt><dd><?= e((string) $view['contact_method']) ?></dd><?php endif; ?>
    <dt>ยินยอมให้ติดต่อ</dt><dd><?= ((int) $view['consent'] === 1) ? 'ยินยอม' : '—' ?></dd>
  </dl>

  <?php if (trim((string) $view['message']) !== ''): ?>
    <h3 class="msgh3">ข้อความ</h3>
    <div class="msgbody"><?= nl2br(e((string) $view['message'])) ?></div>
  <?php endif; ?>

  <div class="formbar">
    <a class="btnlink" href="mailto:<?= e((string) $view['email']) ?>?subject=<?= rawurlencode('ตอบกลับคำขอ #' . $view['id'] . ' — FMK Intertrade') ?>">ตอบกลับทางอีเมล</a>
    <form method="post" action="?s=<?= e($status) ?>" class="inline">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
      <?php if ($view['status'] !== 'archived'): ?>
        <button type="submit" name="do" value="archive" class="secondary">เก็บเข้ากรุ</button>
      <?php endif; ?>
      <button type="submit" name="do" value="unread" class="secondary">ทำเครื่องหมายว่ายังไม่ได้อ่าน</button>
    </form>
    <a class="btnlink" href="?s=<?= e($status) ?>">กลับไปที่รายการ</a>
  </div>
</section>
<?php endif; ?>

<section class="panel">
  <h2><?= e($tabs[$status] ?? 'ทั้งหมด') ?> (<?= (int) $list['total'] ?>)</h2>

  <?php if ($list['rows'] === []): ?>
    <p class="muted">
      <?= $status === 'new' ? 'ไม่มีข้อความใหม่' : 'ไม่มีข้อความในหมวดนี้' ?>
    </p>
  <?php else: ?>
    <table class="msgtable">
      <thead><tr><th>วันที่</th><th>บริษัท / ผู้ติดต่อ</th><th>สนใจ</th><th>ช่องทาง</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list['rows'] as $m): ?>
        <tr class="<?= $m['status'] === 'new' ? 'isnew' : '' ?>">
          <td class="muted nowrap"><?= e(substr((string) $m['created_at'], 0, 16)) ?></td>
          <td>
            <a href="?s=<?= e($status) ?>&amp;id=<?= (int) $m['id'] ?>">
              <strong><?= e((string) $m['company']) ?></strong>
            </a>
            <div class="muted"><?= e((string) $m['full_name']) ?></div>
          </td>
          <td class="muted"><?= e((string) $m['solution']) ?: '—' ?></td>
          <td class="muted nowrap">
            <?= e((string) $m['email']) ?><br><?= e((string) $m['phone']) ?>
          </td>
          <td>
            <div class="rowops">
              <a class="minilink" href="?s=<?= e($status) ?>&amp;id=<?= (int) $m['id'] ?>">เปิดอ่าน</a>
              <form method="post" action="?s=<?= e($status) ?>" class="inline">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button type="submit" name="do" value="delete" class="mini danger"
                        data-confirm="ลบคำขอจาก <?= e((string) $m['company']) ?> ถาวร?&#10;ข้อมูลติดต่อของลูกค้าจะหายไปและกู้กลับไม่ได้">ลบ</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($pages > 1): ?>
      <div class="pager">
        <?php for ($i = 1; $i <= $pages; $i++): ?>
          <a href="?s=<?= e($status) ?>&amp;p=<?= $i ?>" class="<?= $i === $page ? 'on' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php
$blocked = Messages::blockedSummary(7);
if ($blocked !== []):
?>
<section class="panel">
  <h2>คำขอที่ระบบปฏิเสธ (7 วันล่าสุด)</h2>
  <table>
    <thead><tr><th>เหตุผล</th><th>จำนวน</th><th>ล่าสุด</th></tr></thead>
    <tbody>
    <?php foreach ($blocked as $b): ?>
      <tr>
        <td><?= e(Messages::reasonLabel((string) $b['reason'])) ?></td>
        <td><?= (int) $b['n'] ?></td>
        <td class="muted"><?= e((string) $b['last_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">
    รายการเหล่านี้ไม่ได้ถูกบันทึกเป็นข้อความ เพราะเข้าข่ายบอทหรือส่งถี่ผิดปกติ
    แสดงไว้เพื่อให้เห็นว่ามีการยิงฟอร์มเข้ามามากน้อยแค่ไหน
  </p>
</section>
<?php endif; ?>

<?php admin_footer();

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use Fmk\Auth;
use Fmk\Csrf;
use Fmk\Media;

$user = Auth::requireLogin();

/* ---------- คำขอแบบ AJAX (อัปโหลด / ลบ / แก้คำบรรยาย) ---------- */
$isAjax = ($_GET['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null);
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $result = null;
    $error = '';

    if (!$ok) {
        $error = 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง';
    } else {
        try {
            if ($action === 'upload') {
                if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                    throw new RuntimeException('ไม่ได้แนบไฟล์มา');
                }
                /** @var array{name:string,type:string,tmp_name:string,error:int,size:int} $f */
                $f = $_FILES['file'];
                $m = Media::store($f, (int) $user['id']);
                $result = [
                    'id'      => $m['id'],
                    'url'     => Media::url($m),
                    'thumb'   => Media::thumbUrl($m),
                    'name'    => $m['orig_name'],
                    'size'    => Media::humanSize((int) $m['bytes']),
                    'dim'     => $m['width'] . '×' . $m['height'],
                    'resized' => (bool) ($m['resized'] ?? false),
                    'origDim' => ($m['orig_w'] ?? 0) . '×' . ($m['orig_h'] ?? 0),
                ];
            } elseif ($action === 'delete') {
                Media::delete((string) ($_POST['id'] ?? ''), (int) $user['id']);
                $result = ['deleted' => true];
            } elseif ($action === 'alt') {
                Media::setAlt(
                    (string) ($_POST['id'] ?? ''),
                    (string) ($_POST['alt_th'] ?? ''),
                    (string) ($_POST['alt_en'] ?? ''),
                    (int) $user['id']
                );
                $result = ['saved' => true];
            } else {
                throw new RuntimeException('คำสั่งไม่ถูกต้อง');
            }
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($error === '' ? 200 : 400);
        echo json_encode(
            $error === '' ? ['ok' => true, 'data' => $result] : ['ok' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $flashErr = $error;
    $flashOk  = $error === '' ? 'ดำเนินการเรียบร้อย' : '';
}

$items = Media::all();
$usageMap = [];
foreach ($items as $m) {
    $usageMap[$m['id']] = Media::usage((string) $m['id']);
}

admin_header($user, 'คลังรูปภาพ', 'media.php');
?>

<h1>คลังรูปภาพ</h1>
<p class="lead">
  ลากรูปจากคอมพิวเตอร์มาวางได้เลย ไม่ต้องย่อรูปหรือแปลงไฟล์มาก่อน
  ระบบจะหมุนรูปให้ตั้งตรง ย่อขนาดให้พอดีกับเว็บ และลบข้อมูลตำแหน่งที่ติดมากับรูปให้อัตโนมัติ
</p>

<?php admin_flash('ok', $flashOk ?? ''); admin_flash('err', $flashErr ?? ''); ?>

<div id="dropzone" class="dropzone" tabindex="0" role="button"
     aria-label="เลือกรูปเพื่ออัปโหลด">
  <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor"
       stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v13"/>
  </svg>
  <strong>ลากรูปมาวางที่นี่</strong>
  <span>หรือคลิกเพื่อเลือกไฟล์จากเครื่อง · เลือกได้หลายรูปพร้อมกัน</span>
  <small>รองรับ JPG, PNG และ WebP · ไฟล์ละไม่เกิน 16 MB</small>
  <input type="file" id="filepick" accept="image/jpeg,image/png,image/webp" multiple hidden>
</div>

<div id="uploadlog" class="uploadlog" hidden></div>

<section class="panel">
  <h2>รูปทั้งหมด <span class="count" id="mediacount"><?= count($items) ?> รูป</span></h2>

  <?php if ($items === []): ?>
    <p class="muted" id="emptymsg">ยังไม่มีรูปในคลัง ลากรูปมาวางด้านบนเพื่อเริ่มต้น</p>
  <?php endif; ?>

  <div class="mediagrid" id="mediagrid">
    <?php foreach ($items as $m): ?>
      <?php $used = $usageMap[$m['id']]; ?>
      <figure class="mcard" data-id="<?= e((string) $m['id']) ?>">
        <div class="mthumb">
          <img src="../<?= e(Media::thumbUrl($m)) ?>" alt="" loading="lazy">
        </div>
        <figcaption>
          <strong title="<?= e((string) $m['orig_name']) ?>"><?= e((string) $m['orig_name']) ?></strong>
          <span class="meta"><?= e($m['width'] . '×' . $m['height']) ?> · <?= e(Media::humanSize((int) $m['bytes'])) ?></span>
          <?php if ($used !== []): ?>
            <span class="usedtag">ใช้อยู่ที่ <?= e(implode(', ', $used)) ?></span>
          <?php else: ?>
            <span class="freetag">ยังไม่ได้ใช้</span>
          <?php endif; ?>
        </figcaption>

        <form method="post" class="altform" data-id="<?= e((string) $m['id']) ?>">
          <?= Csrf::field() ?>
          <input type="hidden" name="action" value="alt">
          <input type="hidden" name="id" value="<?= e((string) $m['id']) ?>">
          <label>คำบรรยายรูป (ไทย)</label>
          <input type="text" name="alt_th" value="<?= e((string) $m['alt_th']) ?>"
                 placeholder="เช่น โรงเรือนปศุสัตว์ระบบปิด" maxlength="255">
          <label>คำบรรยายรูป (อังกฤษ)</label>
          <input type="text" name="alt_en" value="<?= e((string) $m['alt_en']) ?>"
                 placeholder="e.g. Evaporative livestock housing" maxlength="255">
          <p class="hint">คำบรรยายช่วยให้คนตาบอดที่ใช้โปรแกรมอ่านหน้าจอเข้าใจรูป และช่วยเรื่องอันดับค้นหา</p>
          <div class="mactions">
            <button type="submit" class="mini">บันทึกคำบรรยาย</button>
            <button type="button" class="mini danger" data-del="<?= e((string) $m['id']) ?>"
                    <?= $used !== [] ? 'disabled title="รูปนี้ถูกใช้อยู่บนเว็บ เอาออกจากจุดที่ใช้ก่อนจึงจะลบได้"' : '' ?>>
              ลบรูป
            </button>
          </div>
        </form>
      </figure>
    <?php endforeach; ?>
  </div>
</section>

<form id="csrfholder" hidden><?= Csrf::field() ?></form>

<?php admin_footer(); ?>

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use Fmk\Auth;
use Fmk\Audit;
use Fmk\Content;
use Fmk\Db;
use Fmk\Session;

$user = Auth::requireLogin();

/* เก็บกวาด session ที่หมดอายุเป็นครั้งคราว */
if (random_int(1, 20) === 1) {
    Session::gc();
}

$isAdmin = $user['role'] === 'admin';
$log = $isAdmin ? Audit::recent(10) : [];

$unreadMsgs = \Fmk\Messages::unreadCount();

$draftMeta = ['updated_at' => null, 'by' => null];
$mediaCount = 0;
try {
    $draftMeta = Content::draftMeta();
    $mediaCount = (int) Db::value('SELECT COUNT(*) FROM media');
} catch (Throwable) {
    // ยังไม่ได้นำเข้าเนื้อหา — แสดงหน้าได้ตามปกติ
}

admin_header($user, 'หน้าหลัก', 'index.php');
?>

<h1>สวัสดี<?= $user['display_name'] !== '' ? ' ' . e((string) $user['display_name']) : '' ?></h1>
<p class="lead">เลือกงานที่ต้องการทำได้เลย</p>

<section class="tasks">
  <a class="task" href="content.php">
    <span class="ticon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>
      </svg>
    </span>
    <h2>แก้เนื้อหา</h2>
    <p>ข้อความไทยและอังกฤษ บริการ ผลงาน บทความ ข้อมูลติดต่อ</p>
    <?php if ($draftMeta['updated_at'] !== null): ?>
      <span class="tmeta">แก้ล่าสุด <?= e((string) $draftMeta['updated_at']) ?></span>
    <?php endif; ?>
  </a>

  <a class="task" href="media.php">
    <span class="ticon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
        <path d="M21 15l-5-5L5 21"/>
      </svg>
    </span>
    <h2>จัดการรูปภาพ</h2>
    <p>อัปโหลดรูปใหม่ ใส่คำบรรยาย และดูว่ารูปไหนถูกใช้ที่ไหน</p>
    <span class="tmeta"><?= $mediaCount ?> รูปในคลัง</span>
  </a>

  <a class="task" href="messages.php">
    <span class="ticon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 6 9-6"/>
      </svg>
    </span>
    <h2>กล่องข้อความ</h2>
    <p>คำขอติดต่อที่ลูกค้าส่งมาจากแบบฟอร์มบนหน้าเว็บ</p>
    <span class="tmeta<?= $unreadMsgs > 0 ? ' warn' : '' ?>">
      <?= $unreadMsgs > 0 ? 'ยังไม่ได้อ่าน ' . $unreadMsgs . ' ข้อความ' : 'อ่านครบแล้ว' ?>
    </span>
  </a>

  <a class="task" href="publish.php">
    <span class="ticon" aria-hidden="true">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4Z"/>
      </svg>
    </span>
    <h2>ตรวจและเผยแพร่</h2>
    <p>ดูตัวอย่างก่อนขึ้นเว็บจริง เผยแพร่ และย้อนกลับเวอร์ชันเก่าได้</p>
  </a>
</section>

<section class="panel">
  <h2>บัญชีที่ใช้อยู่</h2>
  <dl>
    <dt>อีเมล</dt><dd><?= e((string) $user['email']) ?></dd>
    <dt>สิทธิ์</dt><dd><?= $isAdmin ? 'ผู้ดูแลระบบ — แก้ได้ทุกอย่าง' : 'ผู้แก้ไขเนื้อหา' ?></dd>
    <dt>ออกจากระบบอัตโนมัติ</dt><dd><?= e((string) $user['expires_at']) ?></dd>
  </dl>
  <div class="formbar">
    <a class="btnlink" href="account.php">เปลี่ยนรหัสผ่านของฉัน</a>
    <?php if ($isAdmin): ?>
      <a class="btnlink" href="users.php">จัดการผู้ใช้งาน</a>
    <?php endif; ?>
  </div>
</section>

<?php if ($isAdmin): ?>
<section class="panel">
  <h2>ความเคลื่อนไหวล่าสุด</h2>
  <?php if ($log === []): ?>
    <p class="muted">ยังไม่มีรายการ</p>
  <?php else: ?>
    <table>
      <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>การกระทำ</th></tr></thead>
      <tbody>
      <?php foreach ($log as $r): ?>
        <tr>
          <td><?= e((string) $r['created_at']) ?></td>
          <td><?= e((string) $r['email']) ?></td>
          <td><?= e(Audit::label((string) $r['action'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php
admin_footer();

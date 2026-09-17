<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

use function Fmk\e;
use function Fmk\redirect;
use Fmk\Audit;
use Fmk\Auth;
use Fmk\Content;
use Fmk\Csrf;
use Fmk\Media;
use Fmk\Sections;

$user = Auth::requireLogin();

$sections = Sections::all();
$sec = is_string($_GET['s'] ?? null) ? $_GET['s'] : 'company';
if (!isset($sections[$sec])) {
    $sec = 'company';
}

/* ---------------------------------------------------------------- บันทึก */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'content.php?s=' . rawurlencode($sec);

    if (!Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
        admin_set_flash('err', 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบใหม่แล้วลองอีกครั้ง');
        redirect($back);
    }

    $draft = Content::draft();

    // 1) เก็บข้อความที่พิมพ์ไว้ก่อนเสมอ จะได้ไม่หายเวลากดปุ่มจัดการรายการ
    $fields = $_POST['f'] ?? [];
    if (is_array($fields)) {
        foreach ($fields as $path => $value) {
            if (is_string($path) && is_string($value)) {
                Content::setAt($draft, $path, $value);
            }
        }
    }

    /* ช่องเปิด/ปิด: เบราว์เซอร์ไม่ส่งค่ามาเลยเมื่อไม่ได้ติ๊ก
       จึงต้องมีรายชื่อช่องทั้งหมดส่งมาด้วย แล้วตีความว่าที่ไม่มาคือปิด */
    $toggles = $_POST['b_all'] ?? [];
    $onList  = $_POST['b'] ?? [];
    if (is_array($toggles)) {
        foreach ($toggles as $path) {
            if (is_string($path)) {
                Content::setAt($draft, $path, is_array($onList) && isset($onList[$path]));
            }
        }
    }

    // คำบรรยายรูปดึงมาจากคลังให้อัตโนมัติ ผู้ใช้กรอกที่เดียวคือตอนอัปโหลด
    syncImageAlts($draft);

    // ไอคอนและรหัสภายในใช้ร่วมกันสองภาษา คัดลอกจากอังกฤษไปไทยให้ตรงกันเสมอ
    syncNeutralFields($draft);

    // 2) แล้วค่อยทำคำสั่งจัดการรายการ ถ้ามี
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $note = '';
    $opError = null;
    if ($do !== '' && !in_array($do, ['save', 'save_preview', 'save_publish'], true)) {
        [$op, $arrPath, $idx] = array_pad(explode(':', $do, 3), 3, '');
        $note = applyItemOp($draft, $op, $arrPath, (int) $idx, $opError);
    }

    // เลขลำดับบนการ์ดให้ระบบเรียงเอง ผู้ใช้จะได้ไม่ต้องมาไล่แก้เองตอนสลับลำดับ
    renumber($draft);

    try {
        /* บันทึกข้อความที่พิมพ์ไว้ก่อนเสมอ แม้คำสั่งจัดการรายการจะทำไม่ได้
           ไม่งั้นผู้ใช้จะเสียสิ่งที่เพิ่งพิมพ์ไปเพราะกดปุ่มเพิ่มรายการที่เพิ่มไม่ได้ */
        Content::saveDraft($draft, (int) $user['id']);
        if ($opError !== null) {
            admin_set_flash('err', $opError);
        } else {
            admin_set_flash('ok', $note !== '' ? "บันทึกแล้ว · $note" : 'บันทึกฉบับร่างแล้ว');
        }
        Audit::log((int) $user['id'], 'content.draft_saved', 'section', $sec, ['op' => $do]);

        if ($do === 'save_preview') {
            redirect('preview.php');
        }
        if ($do === 'save_publish') {
            redirect('publish.php');
        }
    } catch (Throwable $ex) {
        admin_set_flash('err', $ex->getMessage());
    }

    /* redirect เสมอหลังบันทึก เพื่อให้กดรีเฟรชแล้วไม่บันทึกซ้ำหรือเพิ่มรายการซ้ำ */
    redirect($back);
}

$content = Content::draft();
$meta = Content::draftMeta();

/* -------------------------------------------------------------- ฟังก์ชัน */

/**
 * เติมคำบรรยายรูปจากคลังให้ทุกจุดที่ใช้รูปนั้น
 * ผู้ใช้กรอกครั้งเดียวตอนอัปโหลด ไม่ต้องมากรอกซ้ำทุกจุดที่เอารูปไปใช้
 */
function syncImageAlts(array &$c): void
{
    foreach (['en', 'th'] as $lang) {
        if (isset($c['i18n'][$lang]) && is_array($c['i18n'][$lang])) {
            walkAlts($c['i18n'][$lang], $lang);
        }
    }
}

function walkAlts(array &$node, string $lang): void
{
    if (array_key_exists('image', $node) && array_key_exists('imageAlt', $node) && is_string($node['image'])) {
        $url = $node['image'];
        if ($url === '') {
            $node['imageAlt'] = '';
        } else {
            $m = Media::find(basename($url, '.' . pathinfo($url, PATHINFO_EXTENSION)));
            $node['imageAlt'] = $m === null ? '' : (string) ($lang === 'th' ? $m['alt_th'] : $m['alt_en']);
        }
    }
    foreach ($node as &$v) {
        if (is_array($v)) {
            walkAlts($v, $lang);
        }
    }
    unset($v);
}

/**
 * คัดลอกฟิลด์ที่ไม่ขึ้นกับภาษา (ไอคอน, รหัสภายใน) จากฝั่งอังกฤษไปฝั่งไทย
 *
 * หลังบ้านแสดงตัวเลือกไอคอนช่องเดียว ถ้าไม่คัดลอกให้ ฝั่งไทยจะยังเป็นไอคอนเดิม
 * แล้วสลับภาษาบนเว็บจะเห็นไอคอนคนละแบบ
 */
function syncNeutralFields(array &$c): void
{
    if (!isset($c['i18n']['en'], $c['i18n']['th'])) {
        return;
    }
    copyNeutral($c['i18n']['en'], $c['i18n']['th']);
}

function copyNeutral(array $en, array &$th): void
{
    foreach ($en as $k => $v) {
        if (!array_key_exists($k, $th)) {
            continue;
        }
        if (in_array($k, ['k', 'key'], true) && is_string($v)) {
            $th[$k] = $v;
            continue;
        }
        /* สวิตช์เปิดอ่านบทความไม่ใช่ข้อความ จึงต้องเหมือนกันทั้งสองภาษาเสมอ
           ถ้าปล่อยให้ตั้งแยกกัน จะเกิดกรณีที่บทความกดอ่านได้เฉพาะภาษาอังกฤษ */
        if ($k === 'readable' && is_bool($v)) {
            $th[$k] = $v;
            continue;
        }
        if (is_array($v) && is_array($th[$k])) {
            copyNeutral($v, $th[$k]);
        }
    }
}

/**
 * ไล่ใส่เลขลำดับบนการ์ดใหม่ตามลำดับที่แสดงจริง
 * เดิมผู้ใช้ต้องมาแก้เลขเองทุกครั้งที่สลับลำดับ ซึ่งลืมง่ายและทำให้หน้าเว็บดูผิด
 */
function renumber(array &$c): void
{
    foreach (['en', 'th'] as $lang) {
        if (!isset($c['i18n'][$lang]) || !is_array($c['i18n'][$lang])) {
            continue;
        }
        walkNumbers($c['i18n'][$lang]);
    }
}

function walkNumbers(array &$node): void
{
    foreach ($node as &$v) {
        if (!is_array($v)) {
            continue;
        }
        if (array_is_list($v)) {
            $allHaveNum = $v !== [];
            foreach ($v as $item) {
                if (!is_array($item) || !array_key_exists('num', $item)) {
                    $allHaveNum = false;
                    break;
                }
            }
            if ($allHaveNum) {
                $n = 1;
                foreach ($v as &$item) {
                    $item['num'] = str_pad((string) $n++, 2, '0', STR_PAD_LEFT);
                }
                unset($item);
            }
        }
        walkNumbers($v);
    }
    unset($v);
}

/**
 * จัดการรายการในลิสต์ — ทำกับทั้งภาษาอังกฤษและไทยพร้อมกันเสมอ
 * ถ้าทำแค่ภาษาเดียว โครงสร้างสองภาษาจะไม่ตรงกันและหน้าเว็บจะพังตอนสลับภาษา
 */
function applyItemOp(array &$c, string $op, string $enPath, int $i, ?string &$error = null): string
{
    $paths = [$enPath];
    if (str_starts_with($enPath, 'i18n.en.')) {
        $paths[] = 'i18n.th.' . substr($enPath, 8);
    }

    $note = '';
    foreach ($paths as $p) {
        $list = Content::at($c, $p);
        if (!is_array($list) || !array_is_list($list)) {
            continue;
        }

        switch ($op) {
            case 'up':
                if ($i > 0 && isset($list[$i])) {
                    [$list[$i - 1], $list[$i]] = [$list[$i], $list[$i - 1]];
                    $note = 'เลื่อนขึ้น';
                }
                break;
            case 'down':
                if (isset($list[$i], $list[$i + 1])) {
                    [$list[$i], $list[$i + 1]] = [$list[$i + 1], $list[$i]];
                    $note = 'เลื่อนลง';
                }
                break;
            case 'del':
                if (isset($list[$i])) {
                    array_splice($list, $i, 1);
                    $note = 'ลบรายการแล้ว';
                }
                break;
            case 'hide':
            case 'show':
                if (isset($list[$i]) && is_array($list[$i])) {
                    $list[$i]['visible'] = ($op === 'show');
                    $note = $op === 'show' ? 'แสดงรายการนี้แล้ว' : 'ซ่อนรายการนี้แล้ว';
                }
                break;
            case 'add':
                if ($p === 'company.social') {
                    $added = addSocial($list, $error);
                    if ($added !== '') {
                        $note = $added;
                    }
                    break;
                }
                $list[] = blankLike($list[0] ?? '');
                $note = 'เพิ่มรายการใหม่แล้ว — อย่าลืมกรอกข้อความ';
                break;
        }

        Content::setAt($c, $p, $list);
    }
    return $note;
}

/**
 * เพิ่มช่องทางติดต่อใหม่
 *
 * ต่างจากรายการอื่นตรงที่ต้องมี key ที่ถูกต้องตั้งแต่แรก ปล่อยให้ว่างไม่ได้
 * เลือกประเภทที่ยังไม่ถูกใช้ให้อัตโนมัติ และถ้าใช้ครบทุกประเภทแล้วก็ไม่ให้เพิ่ม
 *
 * รายการใหม่ถูกซ่อนไว้ก่อนโดยตั้งใจ เพราะยังไม่มีลิงก์
 * ถ้าปล่อยให้แสดงเลย ท้ายเว็บจะมีไอคอนที่กดแล้วไม่ไปไหน
 *
 * @param array<int,mixed> $list
 */
function addSocial(array &$list, ?string &$error): string
{
    $opts = Sections::socialOptions();

    $used = [];
    foreach ($list as $s) {
        if (is_array($s) && is_string($s['key'] ?? null) && $s['key'] !== '') {
            $used[$s['key']] = true;
        }
    }

    $free = null;
    foreach ($opts as $k => $name) {
        if (!isset($used[$k])) {
            $free = $k;
            break;
        }
    }

    if ($free === null) {
        $error = 'เพิ่มไม่ได้ — ใส่ช่องทางติดต่อครบทุกประเภทแล้ว (' . implode(' · ', $opts) . ') '
               . 'ถ้าต้องการเปลี่ยน ให้แก้รายการที่มีอยู่แทน';
        return '';
    }

    $item = is_array($list[0] ?? null)
        ? blankLike($list[0])
        : ['visible' => true, 'key' => '', 'label' => '', 'href' => ''];

    if (is_array($item)) {
        $item['key']     = $free;
        $item['label']   = $opts[$free];
        $item['href']    = '';
        $item['visible'] = false;
    }
    $list[] = $item;

    return 'เพิ่ม ' . $opts[$free] . ' แล้ว — ใส่ลิงก์ก่อน แล้วกดปุ่ม "แสดง" ให้ขึ้นเว็บ';
}

/** สร้างรายการเปล่าที่มีรูปทรงเหมือนรายการเดิม */
function blankLike(mixed $sample): mixed
{
    if (is_string($sample)) {
        return '';
    }
    if (is_array($sample)) {
        $out = [];
        foreach ($sample as $k => $v) {
            $out[$k] = match (true) {
                $k === 'visible' => true,
                $k === 'k'       => (is_string($v) && $v !== '') ? $v : 'check',
                $k === 'key'     => '',
                default          => blankLike($v),
            };
        }
        return $out;
    }
    return '';
}

/* -------------------------------------------------------------- แสดงผล */

$isCompany = $sec === 'company';
$enNode = $isCompany ? ($content['company'] ?? []) : ($content['i18n']['en'][$sec] ?? null);
$thNode = $isCompany ? null : ($content['i18n']['th'][$sec] ?? null);
$enBase = $isCompany ? 'company' : "i18n.en.$sec";
$thBase = $isCompany ? '' : "i18n.th.$sec";

/* จดไว้ว่าช่องทางติดต่อไหนถูกใช้แล้ว เพื่อปิดตัวเลือกที่ซ้ำใน dropdown */
socialUsage(is_array($content['company']['social'] ?? null) ? $content['company']['social'] : []);

admin_header($user, Sections::label($sec), 'content.php');
?>

<div class="editor">
  <aside class="sidenav">
    <h2>เลือกส่วนที่ต้องการแก้</h2>
    <?php foreach (Sections::groups() as $group => $keys): ?>
      <div class="navgroup">
        <h3><?= e($group) ?></h3>
        <?php foreach ($keys as $key): ?>
          <?php if (!isset($sections[$key])) { continue; } ?>
          <a href="?s=<?= e($key) ?>" class="<?= $key === $sec ? 'on' : '' ?>">
            <strong><?= e($sections[$key]['label']) ?></strong>
            <span><?= e($sections[$key]['hint']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <section class="editmain">
    <?php if ($enNode === null): ?>
      <p class="muted">ไม่พบเนื้อหาส่วนนี้</p>
    <?php else: ?>
    <form method="post" class="cform" id="editform">
      <?= Csrf::field() ?>

      <!-- แถบคำสั่งลอยตาม เห็นสถานะและกดบันทึกได้ตลอดโดยไม่ต้องเลื่อนขึ้นบนสุด -->
      <div class="actionbar" id="actionbar">
        <div class="abtitle">
          <strong><?= e(Sections::label($sec)) ?></strong>
          <span><?= e(Sections::groupOf($sec)) ?></span>
        </div>

        <span class="savestate" id="savestate" data-saved-text="<?= e(
            $meta['updated_at'] !== null ? 'บันทึกฉบับร่างแล้ว' : 'ยังไม่เคยบันทึก'
        ) ?>">
          <?= $meta['updated_at'] !== null ? 'บันทึกฉบับร่างแล้ว' : 'ยังไม่เคยบันทึก' ?>
        </span>

        <div class="abbtns">
          <button type="submit" name="do" value="save" class="primary">บันทึกฉบับร่าง</button>
          <button type="submit" name="do" value="save_preview" class="secondary"
                  title="บันทึกก่อน แล้วเปิดหน้าดูตัวอย่าง">ดูตัวอย่าง</button>
          <button type="submit" name="do" value="save_publish" class="secondary"
                  title="บันทึกก่อน แล้วไปหน้าเผยแพร่">ไปหน้าเผยแพร่</button>
        </div>
      </div>

      <p class="editnote">
        การแก้ไขจะยังไม่ขึ้นเว็บจนกว่าจะกดเผยแพร่
        <?php if ($meta['updated_at'] !== null): ?>
          · บันทึกล่าสุด <?= e((string) $meta['updated_at']) ?>
        <?php endif; ?>
      </p>

      <?php if (!$isCompany): ?>
        <div class="langhead" aria-hidden="true"><span>ภาษาอังกฤษ</span><span>ภาษาไทย</span></div>
      <?php endif; ?>

      <?php renderNode($enNode, $thNode, $enBase, $thBase, $isCompany); ?>

      <div class="formbar">
        <button type="submit" name="do" value="save" class="primary">บันทึกฉบับร่าง</button>
        <button type="submit" name="do" value="save_preview" class="secondary">ดูตัวอย่าง</button>
        <button type="submit" name="do" value="save_publish" class="secondary">ไปหน้าเผยแพร่</button>
      </div>
    </form>
    <?php endif; ?>
  </section>
</div>

<?php
/* หน้าต่างเลือกรูป — โหลดคลังมาไว้ในหน้าเลย ผู้ใช้จะได้ไม่ต้องรอตอนเปิด */
$library = Media::all();
?>
<div id="imgpicker" class="picker" hidden>
  <div class="pickerbox" role="dialog" aria-modal="true" aria-labelledby="pickertitle" tabindex="-1">
    <header>
      <h2 id="pickertitle">เลือกรูปจากคลัง</h2>
      <button type="button" class="mini" data-close-picker>ปิด</button>
    </header>

    <?php if ($library === []): ?>
      <p class="muted" style="padding:24px">
        ยังไม่มีรูปในคลัง — <a href="media.php" target="_blank" rel="noopener">ไปอัปโหลดรูปก่อน</a>
        แล้วกลับมาหน้านี้อีกครั้ง
      </p>
    <?php else: ?>
      <div class="pickgrid">
        <?php foreach ($library as $m): ?>
          <button type="button" class="pickitem"
                  data-url="<?= e(Media::url($m)) ?>"
                  data-alt-th="<?= e((string) $m['alt_th']) ?>"
                  data-alt-en="<?= e((string) $m['alt_en']) ?>">
            <img src="../<?= e(Media::thumbUrl($m)) ?>" alt="" loading="lazy">
            <span><?= e((string) $m['orig_name']) ?></span>
            <small><?= e($m['width'] . '×' . $m['height']) ?></small>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <footer><a href="media.php" target="_blank" rel="noopener">จัดการคลังรูปภาพ</a></footer>
  </div>
</div>

<?php
admin_footer();


/* ------------------------------------------------------------ ตัวแสดงผล */

function renderNode(mixed $en, mixed $th, string $enPath, string $thPath, bool $single, string $label = ''): void
{
    if (is_string($en)) {
        if (isImageField($enPath)) {
            renderImageSlot($en, $enPath, $thPath, $label);
            return;
        }
        if (Sections::isSocialKeyField($enPath)) {
            renderSocialKey($en, $enPath);
            return;
        }
        if (Sections::isIconField(lastKey($enPath))) {
            renderIconPicker($en, $enPath, $thPath, $label);
            return;
        }
        renderText($en, is_string($th) ? $th : '', $enPath, $thPath, $single, $label);
        return;
    }

    if (is_bool($en)) {
        renderToggle($en, $enPath, $label);
        return;
    }

    if (!is_array($en)) {
        return;
    }

    if (array_is_list($en)) {
        renderList($en, is_array($th) ? $th : [], $enPath, $thPath, $single, $label);
        return;
    }

    if ($label !== '') {
        echo '<fieldset class="grp"><legend>' . e($label) . '</legend>';
    }
    foreach ($en as $k => $v) {
        /* ฟิลด์เชิงเทคนิคไม่ต้องแสดง และไม่ต้องส่งกลับ
           เพราะตอนบันทึกเราเริ่มจากฉบับร่างเดิมเสมอ ค่าที่ไม่ได้ส่งมาจึงคงอยู่ตามเดิม */
        if (Sections::isHiddenField((string) $k, "$enPath.$k")) {
            continue;
        }
        $thv = is_array($th) && array_key_exists($k, $th) ? $th[$k] : null;
        renderNode($v, $thv, "$enPath.$k", $thPath === '' ? '' : "$thPath.$k", $single, Sections::fieldLabel((string) $k));
    }
    if ($label !== '') {
        echo '</fieldset>';
    }
}

function lastKey(string $path): string
{
    $p = strrpos($path, '.');
    return $p === false ? $path : substr($path, $p + 1);
}

function isImageField(string $path): bool
{
    return in_array(lastKey($path), ['image', 'logo', 'logoSeal'], true);
}

/**
 * จำว่าช่องทางติดต่อไหนถูกใช้ไปแล้วโดยรายการที่เท่าไร
 *
 * ใช้ปิดตัวเลือกที่ซ้ำใน dropdown ของรายการอื่น ผู้ใช้จะได้เลือกซ้ำไม่ได้ตั้งแต่แรก
 * ไม่ต้องรอให้กดบันทึกแล้วค่อยโดนปฏิเสธ (และ option ที่ disabled กันได้แม้ปิด JavaScript)
 *
 * @param array<int,mixed>|null $social
 * @return array<string,int> ช่องทาง => ลำดับรายการที่ใช้อยู่
 */
function socialUsage(?array $social = null): array
{
    static $map = [];
    if ($social !== null) {
        $map = [];
        foreach ($social as $i => $s) {
            $k = is_array($s) && is_string($s['key'] ?? null) ? $s['key'] : '';
            if ($k !== '' && !isset($map[$k])) {
                $map[$k] = (int) $i;
            }
        }
    }
    return $map;
}

/**
 * ช่องทางติดต่อ — ให้เลือกจากรายการที่ระบบวาดไอคอนให้ได้จริงเท่านั้น
 * เดิมฟิลด์นี้ถูกซ่อนไว้ รายการที่เพิ่มใหม่จึงได้ค่าว่างแล้วไอคอนหายไปเงียบ ๆ
 */
function renderSocialKey(string $value, string $enPath): void
{
    $opts = Sections::socialOptions();
    $used = socialUsage();
    $self = (int) explode('.', $enPath)[2];   // company.social.<i>.key

    echo '<div class="row">';
    echo '<label class="rl">ช่องทาง</label>';
    echo '<div class="rf one">';
    echo '<select name="f[' . e($enPath) . ']" class="iconsel">';

    if ($value === '') {
        echo '<option value="" selected>— ยังไม่ได้เลือก —</option>';
    } elseif (!isset($opts[$value])) {
        /* ค่าที่ระบบไม่รู้จัก ต้องยังเห็นอยู่ ไม่งั้นกดบันทึกแล้วข้อมูลเดิมจะถูกเปลี่ยนเงียบ ๆ */
        echo '<option value="' . e($value) . '" selected>' . e($value) . ' (ระบบไม่รองรับ)</option>';
    }

    foreach ($opts as $k => $name) {
        $takenBy = $used[$k] ?? null;
        $dup = $takenBy !== null && $takenBy !== $self;
        echo '<option value="' . e($k) . '"'
           . ($k === $value ? ' selected' : '')
           . ($dup ? ' disabled' : '') . '>'
           . e($dup ? $name . ' (ใช้ไปแล้ว)' : $name)
           . '</option>';
    }

    echo '</select>';
    echo '<p class="hint">ระบบวาดไอคอนให้ตามช่องทางที่เลือก · ใส่ได้ประเภทละหนึ่งรายการ</p>';
    echo '</div></div>';
}

/** ไอคอน — ให้เลือกจากรายการ ไม่ให้พิมพ์เอง เพราะพิมพ์ผิดแล้วไอคอนหายไปเฉย ๆ */
function renderIconPicker(string $value, string $enPath, string $thPath, string $label): void
{
    $opts = Sections::iconOptions();
    echo '<div class="row">';
    echo '<label class="rl">' . e($label) . '</label>';
    echo '<div class="rf one">';
    echo '<select name="f[' . e($enPath) . ']" class="iconsel">';
    if (!isset($opts[$value]) && $value !== '') {
        echo '<option value="' . e($value) . '" selected>' . e($value) . ' (ค่าเดิม)</option>';
    }
    foreach ($opts as $k => $name) {
        echo '<option value="' . e($k) . '"' . ($k === $value ? ' selected' : '') . '>' . e($name) . '</option>';
    }
    echo '</select>';
    echo '<p class="hint">ไอคอนใช้ร่วมกันทั้งสองภาษา เลือกครั้งเดียวพอ</p>';
    echo '</div></div>';
}

/** ช่องเปิด/ปิด */
function renderToggle(bool $on, string $path, string $label): void
{
    $hint = str_ends_with($path, 'showAdminLink')
        ? 'ถ้าเปิด จะมีลิงก์เข้าระบบผู้ดูแลเล็ก ๆ อยู่ท้ายเว็บ ผู้เข้าชมทั่วไปก็เห็นได้ '
          . 'ถ้าปิด ต้องพิมพ์ที่อยู่หลังบ้านเองหรือเก็บเป็นบุ๊กมาร์ก'
        : '';

    echo '<div class="row">';
    echo '<label class="rl">' . e($label) . '</label>';
    echo '<div class="rf one">';
    echo '<input type="hidden" name="b_all[]" value="' . e($path) . '">';
    echo '<label class="toggle">';
    echo '<input type="checkbox" name="b[' . e($path) . ']" value="1"' . ($on ? ' checked' : '') . '>';
    echo '<span>' . ($on ? 'เปิดอยู่' : 'ปิดอยู่') . '</span>';
    echo '</label>';
    if ($hint !== '') {
        echo '<p class="hint">' . e($hint) . '</p>';
    }
    echo '</div></div>';
}

/** ช่องเลือกรูป — รูปใช้ร่วมกันสองภาษา เลือกครั้งเดียวเขียนลงทั้งสอง path */
function renderImageSlot(string $value, string $enPath, string $thPath, string $label): void
{
    $media = $value !== ''
        ? Media::find(basename($value, '.' . pathinfo($value, PATHINFO_EXTENSION)))
        : null;

    echo '<div class="row">';
    echo '<label class="rl">' . e($label === 'image' ? 'รูปภาพ' : $label) . '</label>';
    echo '<div class="rf one">';
    echo '<div class="imgslot" data-field="' . e($enPath) . '">';

    echo '<input type="hidden" class="imgval" name="f[' . e($enPath) . ']" value="' . e($value) . '">';
    if ($thPath !== '') {
        echo '<input type="hidden" class="imgval" name="f[' . e($thPath) . ']" value="' . e($value) . '">';
    }

    echo '<div class="imgpreview' . ($value === '' ? ' none' : '') . '">';
    echo $value === '' ? 'ยังไม่มีรูป' : '<img src="../' . e($value) . '" alt="">';
    echo '</div>';

    echo '<div class="imgbody">';
    echo '<div class="imgbtns">';
    echo '<button type="button" class="mini" data-pick-for="' . e($enPath) . '" data-pick-label>'
       . ($value === '' ? 'เลือกรูปจากคลัง' : 'เปลี่ยนรูป') . '</button>';
    echo '<button type="button" class="mini danger" data-clear-img="' . e($enPath) . '"'
       . ($value === '' ? ' hidden' : '') . '>เอารูปออก</button>';
    echo '<a href="media.php" class="minilink" target="_blank" rel="noopener">เปิดคลังรูป</a>';
    echo '</div>';

    echo '<p class="hint">';
    echo $media !== null && $media['alt_th'] !== ''
        ? 'คำบรรยายจากคลัง: ' . e((string) $media['alt_th'])
        : 'รูปนี้ใช้ร่วมกันทั้งสองภาษา';
    echo '</p>';

    echo '</div>';   // .imgbody
    echo '</div></div></div>';
}

function renderText(string $en, string $th, string $enPath, string $thPath, bool $single, string $label): void
{
    $long = mb_strlen($en) > 70 || mb_strlen($th) > 70;

    echo '<div class="row">';
    echo '<label class="rl">' . e($label) . '</label>';
    echo '<div class="rf' . ($single ? ' one' : '') . '">';

    echo '<div class="fld">';
    if (!$single) {
        echo '<span class="langtag">ภาษาอังกฤษ</span>';
    }
    echo $long
        ? '<textarea name="f[' . e($enPath) . ']" rows="3">' . e($en) . '</textarea>'
        : '<input type="text" name="f[' . e($enPath) . ']" value="' . e($en) . '">';
    echo '</div>';

    if (!$single && $thPath !== '') {
        echo '<div class="fld">';
        echo '<span class="langtag th">ภาษาไทย</span>';
        echo $long
            ? '<textarea name="f[' . e($thPath) . ']" rows="3">' . e($th) . '</textarea>'
            : '<input type="text" name="f[' . e($thPath) . ']" value="' . e($th) . '">';
        echo '</div>';
    }

    echo '</div></div>';
}

function renderList(array $en, array $th, string $enPath, string $thPath, bool $single, string $label): void
{
    $n = count($en);
    echo '<div class="list">';
    echo '<div class="listhead"><h3>' . e($label !== '' ? $label : 'รายการ') . '</h3>'
       . '<span class="count">' . $n . ' รายการ</span></div>';

    foreach ($en as $i => $item) {
        $thItem = $th[$i] ?? null;
        $hidden = is_array($item) && ($item['visible'] ?? true) === false;

        echo '<article class="item' . ($hidden ? ' ishidden' : '') . '">';
        echo '<div class="itemhead">';
        echo '<span class="num">' . ($i + 1) . '</span>';
        if ($hidden) {
            echo '<span class="hidetag">ซ่อนอยู่ · ไม่แสดงบนเว็บ</span>';
        }
        echo '<div class="ops">';
        if (is_array($item)) {
            $op = $hidden ? 'show' : 'hide';
            echo '<button type="submit" name="do" value="' . e("$op:$enPath:$i") . '" class="mini">'
               . ($hidden ? 'แสดง' : 'ซ่อน') . '</button>';
        }
        echo '<button type="submit" name="do" value="' . e("up:$enPath:$i") . '" class="mini"'
           . ($i === 0 ? ' disabled' : '') . ' title="เลื่อนขึ้น" aria-label="เลื่อนขึ้น">↑</button>';
        echo '<button type="submit" name="do" value="' . e("down:$enPath:$i") . '" class="mini"'
           . ($i === $n - 1 ? ' disabled' : '') . ' title="เลื่อนลง" aria-label="เลื่อนลง">↓</button>';
        echo '<button type="submit" name="do" value="' . e("del:$enPath:$i") . '" class="mini danger"'
           . ' data-confirm="ลบรายการนี้? การลบจะมีผลกับทั้งสองภาษา">ลบ</button>';
        echo '</div></div>';

        echo '<div class="itembody">';
        renderNode(
            $item,
            $thItem,
            "$enPath.$i",
            $thPath === '' ? '' : "$thPath.$i",
            $single,
            is_string($item) ? 'ข้อความ' : ''
        );
        echo '</div></article>';
    }

    echo '<button type="submit" name="do" value="' . e("add:$enPath:0") . '" class="addbtn">+ เพิ่มรายการ</button>';
    echo '</div>';
}

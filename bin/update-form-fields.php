<?php
declare(strict_types=1);

/*
 * ปรับข้อความของแบบฟอร์มติดต่อ จาก "ตัวอย่าง" เป็น "ของจริง"
 *
 *   php bin/update-form-fields.php [--dry]
 *
 * ทำไมต้องแก้ข้อความ:
 *   เดิมฟอร์มไม่ส่งข้อมูลจริง ข้อความจึงเขียนบอกตรง ๆ ว่าเป็นตัวอย่าง
 *   ("แบบฟอร์มตัวอย่าง — ต้องเชื่อมต่อระบบหลังบ้าน", "บันทึกข้อมูลแล้ว (ตัวอย่าง)")
 *   ตอนนี้ฟอร์มส่งถึงเราจริงแล้ว ข้อความชุดนั้นกลายเป็นข้อมูลผิด ต้องเปลี่ยน
 *
 * สิ่งที่ทำ — เปลี่ยนชื่อคีย์โดยคงลำดับเดิมไว้ แล้วใส่ข้อความใหม่:
 *   demoBadge → privacyNote     บอกว่าข้อมูลถูกใช้ทำอะไร
 *   demoTitle → sentTitle       หัวข้อตอนส่งสำเร็จ
 *   demoBody  → sentBody        คำอธิบายตอนส่งสำเร็จ
 * และเพิ่ม errServer · errTooMany · errExpired สำหรับตอนส่งไม่สำเร็จ
 *
 * รันซ้ำได้ ถ้าปรับไปแล้วจะไม่ทำอะไรเพิ่ม
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Builder;
use Fmk\Content;
use Fmk\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', $argv, true);

/** ข้อความใหม่ แยกตามภาษา */
const TEXT = [
    'th' => [
        'privacyNote' => 'ข้อมูลที่กรอกจะถูกส่งถึงทีมงาน FMK โดยตรง และใช้เพื่อติดต่อกลับเรื่องนี้เท่านั้น',
        'sentTitle'   => 'ส่งคำขอเรียบร้อยแล้ว',
        'sentBody'    => 'ทีมงานได้รับข้อมูลของคุณแล้ว และจะติดต่อกลับโดยเร็วที่สุด',
        'errServer'   => 'ขออภัย ส่งคำขอไม่สำเร็จ กรุณาติดต่อเราโดยตรงทางช่องทางนี้',
        'errTooMany'  => 'คุณส่งคำขอมาหลายครั้งแล้ว กรุณารอสักครู่ หรือติดต่อเราโดยตรง',
        'errExpired'  => 'หน้าต่างนี้เปิดค้างไว้นานเกินไป กรุณาปิดแล้วเปิดใหม่อีกครั้ง',
    ],
    'en' => [
        'privacyNote' => 'Your details are sent directly to the FMK team and used only to respond to this enquiry.',
        'sentTitle'   => 'Request sent',
        'sentBody'    => 'We have received your details and will get back to you as soon as possible.',
        'errServer'   => 'Sorry, we could not send your request. Please contact us directly:',
        'errTooMany'  => 'You have sent several requests already. Please wait a moment, or contact us directly:',
        'errExpired'  => 'This window has been open too long. Please close it and open it again.',
    ],
];

/** คีย์เดิม => คีย์ใหม่ (คงตำแหน่งเดิมไว้) */
const RENAME = [
    'demoBadge' => 'privacyNote',
    'demoTitle' => 'sentTitle',
    'demoBody'  => 'sentBody',
];

/** คีย์ที่เพิ่มเข้ามา วางต่อท้ายกลุ่มข้อความแจ้งข้อผิดพลาดเดิม */
const ADD_AFTER = 'errConsent';
const ADDED = ['errServer', 'errTooMany', 'errExpired'];

/**
 * ปรับ form ของภาษาหนึ่ง คืนค่า true ถ้ามีการเปลี่ยนแปลง
 *
 * @param array<string,mixed> $form
 */
function migrateForm(array &$form, string $lang): bool
{
    $txt = TEXT[$lang];
    $changed = false;

    /* เปลี่ยนชื่อคีย์โดยคงลำดับ — สร้างใหม่ทั้งก้อนแล้วไล่ใส่ตามเดิม
       ถ้าใช้ unset+เพิ่มท้าย คีย์จะย้ายไปอยู่ท้ายสุด ทำให้ JSON ต่างจากเดิมโดยไม่จำเป็น */
    $out = [];
    foreach ($form as $k => $v) {
        $newKey = RENAME[$k] ?? $k;
        if ($newKey !== $k) {
            $changed = true;
        }
        $out[$newKey] = isset($txt[$newKey]) ? $txt[$newKey] : $v;
        if (isset($txt[$newKey]) && $v !== $txt[$newKey]) {
            $changed = true;
        }

        if ($k === ADD_AFTER) {
            foreach (ADDED as $add) {
                if (!array_key_exists($add, $form)) {
                    $out[$add] = $txt[$add];
                    $changed = true;
                }
            }
        }
    }

    /* เผื่อกรณีไม่มีคีย์ ADD_AFTER อยู่เลย — ต่อท้ายให้ครบ */
    foreach (ADDED as $add) {
        if (!array_key_exists($add, $out)) {
            $out[$add] = $txt[$add];
            $changed = true;
        }
    }

    $form = $out;
    return $changed;
}

/* ------------------------------------------------------------------ ทำงาน */

$draft = Content::draft();
$touched = false;

foreach (['en', 'th'] as $lang) {
    if (!isset($draft['i18n'][$lang]['form']) || !is_array($draft['i18n'][$lang]['form'])) {
        fwrite(STDERR, "ไม่พบ i18n.$lang.form\n");
        exit(1);
    }
    if (migrateForm($draft['i18n'][$lang]['form'], $lang)) {
        $touched = true;
    }
}

if (!$touched) {
    echo "ข้อความฟอร์มเป็นรุ่นใหม่อยู่แล้ว ไม่ต้องแก้อะไร\n";
    exit(0);
}

echo "ข้อความฟอร์มหลังปรับ (ภาษาไทย):\n";
foreach (['privacyNote', 'sentTitle', 'sentBody', 'errServer', 'errTooMany', 'errExpired'] as $k) {
    printf("  %-12s %s\n", $k, $draft['i18n']['th']['form'][$k] ?? '—');
}

$gone = array_intersect(array_keys(RENAME), array_keys($draft['i18n']['th']['form']));
if ($gone !== []) {
    fwrite(STDERR, "\nยังมีคีย์เดิมค้างอยู่: " . implode(', ', $gone) . "\n");
    exit(1);
}

if ($dry) {
    echo "\n--dry: ยังไม่ได้บันทึกอะไร\n";
    exit(0);
}

/* ตรวจโครงสร้างสองภาษาให้ตรงกันก่อนบันทึก — saveDraft ตรวจให้อยู่แล้ว */
$admin = Db::one("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1");
Content::saveDraft($draft, (int) ($admin['id'] ?? 0));
echo "\nบันทึกลงฉบับร่างแล้ว\n";

/* content.json ต้องตามไปด้วย ไม่งั้นเครื่องมือฝั่ง Node จะสร้างหน้าเว็บจากข้อมูลเก่า */
Builder::exportJson($draft);
echo "เขียน content.json แล้ว\n";
echo "\nขั้นต่อไป: ตรวจหน้าตาที่หลังบ้าน แล้วกดเผยแพร่เมื่อพอใจ\n";

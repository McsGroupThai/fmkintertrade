<?php
declare(strict_types=1);

/*
 * ซ่อมรูปที่เสียความโปร่งใส และสร้างรูปย่อใหม่ทั้งหมด
 *
 *   php bin/fix-media-alpha.php [--dry]
 *
 * ที่มา: เวอร์ชันแรกของระบบรูปลืมสั่ง imagesavealpha() ก่อนบันทึก PNG
 * และสร้างรูปย่อเป็น JPEG ซึ่งไม่มีชั้นความโปร่งใส
 * ผลคือพื้นหลังโปร่งใสของโลโก้กลายเป็นสีดำทึบ
 *
 * สคริปต์นี้:
 *   1. สร้างรูปย่อใหม่เป็น WebP ให้ทุกรูป (เก็บความโปร่งใสได้)
 *   2. ถ้ามีไฟล์ต้นฉบับเดิมอยู่ในโปรเจกต์ จะนำมาแทนไฟล์ที่เสียให้
 *
 * หมายเหตุ: รูปที่ผู้ใช้อัปโหลดเองและเสียความโปร่งใสไปแล้ว กู้ไม่ได้
 * เพราะระบบเก็บเฉพาะไฟล์ที่ประมวลผลแล้ว ต้องอัปโหลดใหม่
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Db;
use Fmk\Media;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dry = in_array('--dry', array_slice($argv, 1), true);

/** ไฟล์ต้นฉบับที่มีอยู่ในโปรเจกต์ ใช้กู้รูปที่เสียได้ */
$recoverable = [
    'logo-monogram.png' => dirname(__DIR__) . '/public/logo-monogram.png',
    'logo-seal.jpeg'    => dirname(__DIR__) . '/public/logo-seal.jpeg',
];

$rows = Db::all('SELECT * FROM media ORDER BY created_at');
if ($rows === []) {
    echo "ไม่มีรูปในคลัง\n";
    exit(0);
}

foreach ($rows as $m) {
    $id = (string) $m['id'];
    $main = Media::dir() . '/' . $id . '.' . $m['ext'];
    echo $m['orig_name'], "\n";

    if (!is_file($main)) {
        echo "  ! ไม่พบไฟล์ ข้าม\n\n";
        continue;
    }

    $lostAlpha = hasLostAlpha($main);
    $src = $recoverable[(string) $m['orig_name']] ?? null;

    if ($lostAlpha && $src !== null && is_file($src)) {
        echo "  พื้นหลังเสีย — กู้จากไฟล์ต้นฉบับในโปรเจกต์\n";
        if (!$dry) {
            $info = getimagesize($src);
            $im = loadAny($src, (int) $info[2]);
            if ($im !== null) {
                imagealphablending($im, false);
                imagesavealpha($im, true);
                imagepng($im, $main, 6);
                Db::run('UPDATE media SET width = ?, height = ?, bytes = ? WHERE id = ?',
                    [imagesx($im), imagesy($im), (int) filesize($main), $id]);
                imagedestroy($im);
                echo "  กู้ไฟล์จริงแล้ว\n";
            }
        }
    } elseif ($lostAlpha) {
        echo "  ! พื้นหลังเสียและไม่มีต้นฉบับให้กู้ — ต้องอัปโหลดรูปนี้ใหม่\n";
    } else {
        echo "  ไฟล์จริงปกติ\n";
    }

    // สร้างรูปย่อใหม่เสมอ
    if (!$dry) {
        makeThumb($main, Media::thumbDir() . '/' . $id . '.' . Media::THUMB_EXT);
        @unlink(Media::thumbDir() . '/' . $id . '.jpg');   // ทิ้งรูปย่อแบบเก่า
        echo "  สร้างรูปย่อใหม่เป็น WebP แล้ว\n";
    } else {
        echo "  (จะสร้างรูปย่อใหม่)\n";
    }
    echo "\n";
}

echo $dry ? "(--dry: ยังไม่ได้แก้อะไรจริง)\n" : "เรียบร้อย\n";

/* ---------------------------------------------------------------- ช่วย */

function loadAny(string $path, int $type): ?\GdImage
{
    $im = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default        => false,
    };
    return $im === false ? null : $im;
}

/** เดาว่ารูปเสียความโปร่งใสไปหรือยัง โดยดูว่ามุมทั้งสี่ทึบและเป็นสีดำสนิทหรือไม่ */
function hasLostAlpha(string $path): bool
{
    $info = getimagesize($path);
    if ($info === false || $info[2] !== IMAGETYPE_PNG) {
        return false;   // JPEG ไม่มีความโปร่งใสอยู่แล้ว ไม่ถือว่าเสีย
    }
    $im = loadAny($path, IMAGETYPE_PNG);
    if ($im === null) {
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $corners = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]];
    $blackOpaque = 0;
    foreach ($corners as [$x, $y]) {
        $c = imagecolorat($im, $x, $y);
        $a = ($c >> 24) & 0x7F;
        $rgb = $c & 0xFFFFFF;
        if ($a === 0 && $rgb === 0) {
            $blackOpaque++;
        }
    }
    imagedestroy($im);
    return $blackOpaque === 4;
}

function makeThumb(string $src, string $dest): void
{
    $info = getimagesize($src);
    if ($info === false) {
        return;
    }
    $im = loadAny($src, (int) $info[2]);
    if ($im === null) {
        return;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $scale = min(1.0, 480 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));

    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
    if ($clear !== false) {
        imagefilledrectangle($out, 0, 0, $nw, $nh, $clear);
    }
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagewebp($out, $dest, 82);

    imagedestroy($out);
    imagedestroy($im);
}

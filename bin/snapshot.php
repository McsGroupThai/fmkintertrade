<?php
declare(strict_types=1);

/*
 * สำรองและกู้คืนสถานะทั้งหมดของ CMS
 *
 *   php bin/snapshot.php save [ชื่อไฟล์]      สำรองสถานะปัจจุบัน
 *   php bin/snapshot.php restore [ชื่อไฟล์]   กู้กลับเป็นสถานะที่สำรองไว้
 *   php bin/snapshot.php list                 ดูรายการที่สำรองไว้
 *
 * ทำไมต้องมี:
 *   ชุดทดสอบอัตโนมัติแก้เนื้อหา อัปโหลดรูป และ "ย้อนเวอร์ชัน" บนฐานข้อมูลเดียวกับที่ผู้ดูแลใช้จริง
 *   ครั้งหนึ่งชุดทดสอบสั่งย้อนไปเวอร์ชันเก่า แล้วโลโก้ที่ผู้ดูแลเพิ่งตั้งก็หายไป
 *   ต่อไปนี้ต้อง save ก่อนรันเทสต์ และ restore หลังรันเสมอ
 *
 * สิ่งที่สำรอง: ฉบับร่าง · ประวัติการเผยแพร่ · คลังรูป (ทั้งข้อมูลและไฟล์)
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Config;
use Fmk\Db;
use Fmk\Media;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = dirname(__DIR__) . '/snapshots';
if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
    fwrite(STDERR, "สร้างโฟลเดอร์ snapshots ไม่ได้\n");
    exit(1);
}

$cmd = $argv[1] ?? '';
$name = $argv[2] ?? 'latest';
$path = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '.json';

switch ($cmd) {
    case 'save':
        save($path);
        break;
    case 'restore':
        restore($path);
        break;
    case 'list':
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            printf("  %-22s %8s bytes  %s\n", basename($f, '.json'),
                number_format((int) filesize($f)), date('Y-m-d H:i', (int) filemtime($f)));
        }
        break;
    default:
        fwrite(STDERR, "ใช้: php bin/snapshot.php save|restore|list [ชื่อ]\n");
        exit(1);
}

function save(string $path): void
{
    $draft = Db::one('SELECT data, updated_by FROM content_draft WHERE id = 1');
    $media = Db::all('SELECT * FROM media');

    $files = [];
    foreach ($media as $m) {
        $full = Media::dir() . '/' . $m['id'] . '.' . $m['ext'];
        $thumb = Media::thumbDir() . '/' . $m['id'] . '.' . Media::THUMB_EXT;
        $files[$m['id']] = [
            'main'  => is_file($full) ? base64_encode((string) file_get_contents($full)) : null,
            'thumb' => is_file($thumb) ? base64_encode((string) file_get_contents($thumb)) : null,
        ];
    }

    /* ต้องเก็บไฟล์สองตัวนี้ด้วย ไม่งั้นชุดทดสอบที่กดเผยแพร่จะเขียนทับแล้วกู้กลับไม่ได้
       content.json ถูกเขียนใหม่ทุกครั้งที่เผยแพร่ ส่วน index.html คือหน้าเว็บจริง */
    $root = dirname(__DIR__);

    $snap = [
        'saved_at'     => date('c'),
        'content_json' => is_file($root . '/content.json') ? file_get_contents($root . '/content.json') : null,
        'index_html'   => is_file(Config::publicDir() . '/index.html') ? file_get_contents(Config::publicDir() . '/index.html') : null,
        /* หน้านโยบายถูกเขียนใหม่พร้อมหน้าแรกทุกครั้งที่เผยแพร่ ถ้าไม่เก็บไว้ด้วยจะกู้กลับได้ไม่ครบ */
        'privacy_html' => is_file(Config::publicDir() . '/privacy.html') ? file_get_contents(Config::publicDir() . '/privacy.html') : null,
        'draft'    => $draft,
        'versions' => Db::all('SELECT * FROM content_versions'),
        'media'    => $media,
        'files'    => $files,
    ];

    file_put_contents($path, json_encode($snap, JSON_UNESCAPED_UNICODE));

    printf("สำรองแล้ว: %s\n", basename($path));
    printf("  ฉบับร่าง : %s\n", $draft === null ? 'ไม่มี' : number_format(strlen((string) $draft['data'])) . ' bytes');
    printf("  ประวัติ  : %d เวอร์ชัน\n", count($snap['versions']));
    printf("  รูป      : %d ไฟล์\n", count($media));
}

function restore(string $path): void
{
    if (!is_file($path)) {
        fwrite(STDERR, "ไม่พบไฟล์สำรอง: $path\n");
        exit(1);
    }
    $snap = json_decode((string) file_get_contents($path), true);
    if (!is_array($snap)) {
        fwrite(STDERR, "ไฟล์สำรองเสียหาย\n");
        exit(1);
    }

    $pdo = Db::pdo();
    $pdo->beginTransaction();
    try {
        Db::run('DELETE FROM content_versions');
        Db::run('DELETE FROM content_draft');
        Db::run('DELETE FROM media');

        if (is_array($snap['draft'] ?? null)) {
            Db::run('INSERT INTO content_draft (id, data, updated_by) VALUES (1, ?, ?)',
                [$snap['draft']['data'], $snap['draft']['updated_by']]);
        }
        foreach ($snap['versions'] ?? [] as $v) {
            Db::run(
                'INSERT INTO content_versions (id, data, note, published_by, published_at, is_current)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$v['id'], $v['data'], $v['note'], $v['published_by'], $v['published_at'], $v['is_current']]
            );
        }
        foreach ($snap['media'] ?? [] as $m) {
            Db::run(
                'INSERT INTO media (id, ext, mime, width, height, bytes, orig_name, alt_th, alt_en, uploaded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$m['id'], $m['ext'], $m['mime'], $m['width'], $m['height'], $m['bytes'],
                 $m['orig_name'], $m['alt_th'], $m['alt_en'], $m['uploaded_by'], $m['created_at']]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'กู้คืนไม่สำเร็จ: ' . $e->getMessage() . "\n");
        exit(1);
    }

    /* ไฟล์รูป: ลบทุกอย่างที่ไม่ได้อยู่ในไฟล์สำรอง แล้วเขียนของในไฟล์สำรองกลับ */
    $keep = [];
    foreach ($snap['media'] ?? [] as $m) {
        $keep[$m['id'] . '.' . $m['ext']] = true;
        $keep['thumb/' . $m['id'] . '.' . Media::THUMB_EXT] = true;
    }
    foreach (glob(Media::dir() . '/*.{jpg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        if (!isset($keep[basename($f)])) {
            @unlink($f);
        }
    }
    foreach (glob(Media::thumbDir() . '/*.*') ?: [] as $f) {
        if (!isset($keep['thumb/' . basename($f)])) {
            @unlink($f);
        }
    }
    foreach ($snap['files'] ?? [] as $id => $blob) {
        $m = null;
        foreach ($snap['media'] as $row) {
            if ($row['id'] === $id) { $m = $row; break; }
        }
        if ($m === null) {
            continue;
        }
        if (is_string($blob['main'] ?? null)) {
            file_put_contents(Media::dir() . '/' . $id . '.' . $m['ext'], base64_decode($blob['main']));
        }
        if (is_string($blob['thumb'] ?? null)) {
            file_put_contents(Media::thumbDir() . '/' . $id . '.' . Media::THUMB_EXT, base64_decode($blob['thumb']));
        }
    }

    /* คืนไฟล์เว็บด้วย — ชุดทดสอบที่กดเผยแพร่จะเขียนทับสองไฟล์นี้ */
    $root = dirname(__DIR__);
    $restoredFiles = [];
    if (is_string($snap['content_json'] ?? null)) {
        file_put_contents($root . '/content.json', $snap['content_json']);
        $restoredFiles[] = 'content.json';
    }
    if (is_string($snap['index_html'] ?? null)) {
        file_put_contents(Config::publicDir() . '/index.html', $snap['index_html']);
        $restoredFiles[] = 'public/index.html';
    }
    if (is_string($snap['privacy_html'] ?? null)) {
        file_put_contents(Config::publicDir() . '/privacy.html', $snap['privacy_html']);
        $restoredFiles[] = 'public/privacy.html';
    }

    printf("กู้คืนจาก %s (สำรองเมื่อ %s)\n", basename($path), $snap['saved_at'] ?? '-');
    printf("  ประวัติ : %d เวอร์ชัน · รูป %d ไฟล์\n", count($snap['versions'] ?? []), count($snap['media'] ?? []));
    if ($restoredFiles !== []) {
        printf("  ไฟล์เว็บ : %s\n", implode(' · ', $restoredFiles));
    } else {
        echo "  ไฟล์เว็บ : ไม่มีในไฟล์สำรองนี้ (สำรองไว้ก่อนเพิ่มความสามารถนี้)\n";
    }
}

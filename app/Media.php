<?php
declare(strict_types=1);

namespace Fmk;

use RuntimeException;

/**
 * คลังรูปภาพ
 *
 * แนวคิดการออกแบบ: ผู้ใช้ไม่ควรต้องรู้เรื่องขนาดไฟล์ ความละเอียด หรือชนิดไฟล์
 * ถ่ายจากมือถือมาแล้วลากวางได้เลย ระบบจัดการที่เหลือให้เอง
 *   - หมุนรูปให้ตั้งตรงตาม EXIF (รูปจากมือถือมักตะแคง)
 *   - ย่อรูปใหญ่ให้พอดีกับเว็บโดยอัตโนมัติ
 *   - สร้างรูปย่อไว้แสดงในคลัง
 *   - ล้าง metadata ทิ้ง (พิกัด GPS จากมือถือก็หายไปด้วย)
 *
 * ด้านความปลอดภัย: ไฟล์ถูก "วาดใหม่" ผ่าน GD ทุกไฟล์
 * ไม่ได้แค่คัดลอกของเดิมมา ดังนั้นโค้ดที่แอบฝังมาในไฟล์รูปจะไม่รอดมาถึงดิสก์
 */
final class Media
{
    /** ด้านยาวสุดของรูปที่เก็บจริง — พอสำหรับแบนเนอร์เต็มจอ */
    private const MAX_EDGE = 2400;

    /** ด้านยาวสุดของรูปย่อในคลัง */
    private const THUMB_EDGE = 480;

    /* รูปย่อใช้ WebP เพราะเก็บความโปร่งใสได้และไฟล์เล็กกว่า PNG มาก
       เดิมใช้ JPEG ซึ่งไม่มีชั้นความโปร่งใส พื้นหลังโลโก้จึงกลายเป็นดำ */
    public const THUMB_EXT = 'webp';

    /** ขนาดไฟล์ที่รับได้ */
    private const MAX_BYTES = 16 * 1024 * 1024;

    /** กันรูปที่ความละเอียดสูงจนกินหน่วยความจำจนเซิร์ฟเวอร์ล่ม */
    private const MAX_PIXELS = 50_000_000;

    private const ALLOWED = [
        IMAGETYPE_JPEG => ['jpg',  'image/jpeg'],
        IMAGETYPE_PNG  => ['png',  'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    public static function dir(): string
    {
        return Config::publicDir() . '/assets/media';
    }

    public static function thumbDir(): string
    {
        return self::dir() . '/thumb';
    }

    /** ที่อยู่ของรูปเมื่อเรียกจากหน้าเว็บ (สัมพัทธ์กับรากเว็บ) */
    public static function url(array $m): string
    {
        return 'assets/media/' . $m['id'] . '.' . $m['ext'];
    }

    public static function thumbUrl(array $m): string
    {
        return 'assets/media/thumb/' . $m['id'] . '.' . self::THUMB_EXT;
    }

    /** ---------------------------------------------------------- อัปโหลด */

    /**
     * รับไฟล์ที่อัปโหลดเข้ามาหนึ่งไฟล์
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return array<string,mixed> แถวในตาราง media
     */
    public static function store(array $file, int $userId): array
    {
        self::ensureDirs();

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage((int) ($file['error'] ?? 4)));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('ไฟล์ไม่ถูกต้อง');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException(sprintf(
                'ไฟล์ใหญ่เกินไป (%s) รับได้ไม่เกิน %d MB',
                self::humanSize((int) $file['size']),
                self::MAX_BYTES / 1024 / 1024
            ));
        }

        // เชื่อเฉพาะสิ่งที่อ่านได้จากตัวไฟล์จริง ไม่เชื่อนามสกุลหรือ Content-Type ที่ส่งมา
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            throw new RuntimeException('ไฟล์นี้ไม่ใช่รูปภาพที่เปิดได้ — รองรับ JPG, PNG และ WebP');
        }

        [$w, $h, $type] = $info;
        if (!isset(self::ALLOWED[$type])) {
            throw new RuntimeException('รองรับเฉพาะไฟล์ JPG, PNG และ WebP เท่านั้น');
        }
        if ($w * $h > self::MAX_PIXELS) {
            throw new RuntimeException('รูปมีความละเอียดสูงเกินไป กรุณาย่อขนาดก่อนอัปโหลด');
        }

        [$ext, $mime] = self::ALLOWED[$type];

        $img = self::load($file['tmp_name'], $type);
        if ($img === null) {
            throw new RuntimeException('เปิดไฟล์รูปไม่สำเร็จ ไฟล์อาจเสียหาย');
        }

        // รูปจากมือถือมักบันทึกไว้ตะแคงแล้วฝากมุมหมุนไว้ใน EXIF — จัดให้ตั้งตรงก่อน
        if ($type === IMAGETYPE_JPEG) {
            $img = self::applyExifRotation($img, $file['tmp_name']);
        }

        $img = self::fit($img, self::MAX_EDGE);

        $id = bin2hex(random_bytes(16));
        $path = self::dir() . '/' . $id . '.' . $ext;

        if (!self::save($img, $path, $type)) {
            imagedestroy($img);
            throw new RuntimeException('บันทึกรูปลงเซิร์ฟเวอร์ไม่สำเร็จ');
        }

        // รูปย่อเป็น WebP เสมอ — เก็บความโปร่งใสได้ และคลังโหลดเร็ว
        $thumb = self::fit($img, self::THUMB_EDGE, true);
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        imagewebp($thumb, self::thumbDir() . '/' . $id . '.' . self::THUMB_EXT, 82);
        imagedestroy($thumb);

        $finalW = imagesx($img);
        $finalH = imagesy($img);
        imagedestroy($img);

        Db::run(
            'INSERT INTO media (id, ext, mime, width, height, bytes, orig_name, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $ext, $mime, $finalW, $finalH, (int) filesize($path),
             mb_substr(self::cleanName($file['name']), 0, 190), $userId]
        );

        Audit::log($userId, 'media.uploaded', 'media', $id, [
            'name'   => $file['name'],
            'from'   => $w . '×' . $h,
            'to'     => $finalW . '×' . $finalH,
        ]);

        $row = self::find($id);
        if ($row === null) {
            throw new RuntimeException('บันทึกรูปแล้วแต่อ่านกลับไม่ได้');
        }
        $row['resized'] = ($finalW !== $w || $finalH !== $h);
        $row['orig_w'] = $w;
        $row['orig_h'] = $h;
        return $row;
    }

    /** ------------------------------------------------------------- อ่าน */

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return Db::all('SELECT * FROM media ORDER BY created_at DESC, id DESC');
    }

    /** @return array<string,mixed>|null */
    public static function find(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
            return null;
        }
        return Db::one('SELECT * FROM media WHERE id = ?', [$id]);
    }

    public static function setAlt(string $id, string $altTh, string $altEn, int $userId): void
    {
        if (self::find($id) === null) {
            throw new RuntimeException('ไม่พบรูปนี้');
        }
        Db::run('UPDATE media SET alt_th = ?, alt_en = ? WHERE id = ?', [
            mb_substr($altTh, 0, 255), mb_substr($altEn, 0, 255), $id,
        ]);
        Audit::log($userId, 'media.alt_updated', 'media', $id, []);
    }

    /** ------------------------------------------------------------- ลบ */

    public static function delete(string $id, int $userId): void
    {
        $m = self::find($id);
        if ($m === null) {
            throw new RuntimeException('ไม่พบรูปนี้');
        }
        if (self::usage($id) !== []) {
            throw new RuntimeException('ลบไม่ได้ เพราะรูปนี้ยังถูกใช้อยู่บนเว็บ — เอาออกจากจุดที่ใช้ก่อน');
        }

        @unlink(self::dir() . '/' . $m['id'] . '.' . $m['ext']);
        @unlink(self::thumbDir() . '/' . $m['id'] . '.' . self::THUMB_EXT);
        @unlink(self::thumbDir() . '/' . $m['id'] . '.jpg');   // ของเก่าก่อนเปลี่ยนมาใช้ WebP
        Db::run('DELETE FROM media WHERE id = ?', [$id]);
        Audit::log($userId, 'media.deleted', 'media', $id, ['name' => $m['orig_name']]);
    }

    /**
     * รูปนี้ถูกใช้อยู่ที่ไหนบ้างในฉบับร่าง
     *
     * @return array<int,string> รายชื่อจุดที่ใช้ เป็นภาษาไทยให้ผู้ใช้อ่านเข้าใจ
     */
    public static function usage(string $id): array
    {
        try {
            $c = Content::draft();
        } catch (\Throwable) {
            return [];
        }

        $hits = [];
        self::walk($c, '', $id, $hits);
        return array_values(array_unique($hits));
    }

    /** @param array<int,string> $hits */
    private static function walk(mixed $node, string $path, string $id, array &$hits): void
    {
        if (is_string($node)) {
            /* เนื้อหาเก็บเป็นที่อยู่ไฟล์เต็ม เช่น "assets/media/<id>.jpg" ไม่ใช่ id ล้วน
               จึงต้องหาว่ามี id อยู่ในสตริงไหม ไม่ใช่เทียบเท่ากันตรง ๆ */
            if ($node !== '' && str_contains($node, $id)) {
                $hits[] = self::describePath($path);
            }
            return;
        }
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            self::walk($v, $path === '' ? (string) $k : $path . '.' . $k, $id, $hits);
        }
    }

    /** แปลง path ในข้อมูลให้เป็นคำอธิบายที่คนอ่านรู้เรื่อง */
    private static function describePath(string $path): string
    {
        $p = preg_replace('/^i18n\.(en|th)\./', '', $path) ?? $path;

        return match (true) {
            str_starts_with($p, 'company.logoSeal') => 'ตราสัญลักษณ์ (ท้ายเว็บและไอคอนแท็บ)',
            str_starts_with($p, 'company.logo')     => 'โลโก้ (แถบบนและเมนู)',
            str_starts_with($p, 'hero.image')       => 'แบนเนอร์หลักหน้าแรก',
            str_starts_with($p, 'projects.featureImage') => 'ภาพใหญ่ในส่วนผลงาน',
            (bool) preg_match('/^projects\.items\.(\d+)/', $p, $m)  => 'ผลงานรายการที่ ' . ($m[1] + 1),
            (bool) preg_match('/^knowledge\.items\.(\d+)/', $p, $m) => 'บทความรายการที่ ' . ($m[1] + 1),
            (bool) preg_match('/^solutions\.items\.(\d+)/', $p, $m) => 'บริการรายการที่ ' . ($m[1] + 1),
            default => $p,
        };
    }

    /** --------------------------------------------------------- ประมวลผล */

    private static function load(string $path, int $type): ?\GdImage
    {
        $img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };
        return $img === false ? null : $img;
    }

    /** ชนิดที่เก็บความโปร่งใสได้ — โลโก้เกือบทั้งหมดเป็นแบบนี้ */
    private static function hasAlpha(int $type): bool
    {
        return $type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP;
    }

    private static function save(\GdImage $img, string $path, int $type): bool
    {
        if (self::hasAlpha($type)) {
            /* ต้องสั่งสองบรรทัดนี้ก่อนเซฟเสมอ ไม่งั้น GD ทิ้งชั้นความโปร่งใส
               แล้วพื้นหลังโปร่งของโลโก้จะกลายเป็นสีดำทึบ */
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        return match ($type) {
            IMAGETYPE_JPEG => imagejpeg($img, $path, 86),
            IMAGETYPE_PNG  => imagepng($img, $path, 6),
            IMAGETYPE_WEBP => imagewebp($img, $path, 86),
            default        => false,
        };
    }

    private static function applyExifRotation(\GdImage $img, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $o = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($o) {
            3       => imagerotate($img, 180, 0),
            6       => imagerotate($img, -90, 0),
            8       => imagerotate($img, 90, 0),
            default => null,
        };
        if ($rotated === null || $rotated === false) {
            return $img;
        }
        imagedestroy($img);
        return $rotated;
    }

    /** ย่อให้ด้านยาวสุดไม่เกินที่กำหนด — ไม่ขยายรูปเล็กให้ใหญ่ขึ้น เพราะจะแตก */
    private static function fit(\GdImage $img, int $maxEdge, bool $forceCopy = false): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1.0, $maxEdge / max($w, $h));

        if ($scale >= 1.0 && !$forceCopy) {
            return $img;
        }

        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        /* ผืนผ้าใบใหม่ของ GD เริ่มเป็นสีดำทึบ ต้องล้างเป็นโปร่งใสก่อน
           ไม่งั้นขอบของโลโก้โปร่งใสจะมีคราบดำติดมา */
        $clear = imagecolorallocatealpha($out, 0, 0, 0, 127);
        if ($clear !== false) {
            imagefilledrectangle($out, 0, 0, $nw, $nh, $clear);
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if ($forceCopy) {
            return $out;   // ตัวเรียกเป็นคนทำลายทิ้งเอง
        }
        imagedestroy($img);
        return $out;
    }

    /** ----------------------------------------------------------- ช่วย */

    private static function ensureDirs(): void
    {
        foreach ([self::dir(), self::thumbDir()] as $d) {
            if (!is_dir($d) && !mkdir($d, 0755, true) && !is_dir($d)) {
                throw new RuntimeException('สร้างโฟลเดอร์เก็บรูปไม่ได้');
            }
        }
        // กันไม่ให้โฟลเดอร์รูปรันสคริปต์ได้ ต่อให้มีไฟล์แปลกปลอมหลุดเข้าไป
        $ht = self::dir() . '/.htaccess';
        if (!is_file($ht)) {
            /* เนื้อหาต้องตรงกับไฟล์ public/assets/media/.htaccess ที่อยู่ในชุดติดตั้ง
               ถ้าวันหนึ่งไฟล์นั้นถูกลบไป ตัวที่สร้างขึ้นใหม่ต้องแข็งแรงเท่าเดิม ไม่ใช่อ่อนกว่า */
            file_put_contents($ht, <<<'TXT'
                # โฟลเดอร์นี้เก็บไฟล์ที่ผู้ใช้อัปโหลดเข้ามา — ถือว่าไม่น่าไว้ใจเสมอ
                # ระบบตรวจชนิดไฟล์และสร้างรูปใหม่ทับของเดิมอยู่แล้ว ไฟล์นี้คือด่านที่สอง
                Options -Indexes -ExecCGI
                RemoveHandler .php .phtml .phar .cgi .pl .py .sh
                AddType text/plain .php .phtml .phar .cgi .pl .py .sh
                <FilesMatch "\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh|htaccess|htpasswd)$">
                  Require all denied
                  <IfModule !mod_authz_core.c>
                    Order allow,deny
                    Deny from all
                  </IfModule>
                </FilesMatch>
                <IfModule mod_php.c>
                  php_flag engine off
                </IfModule>
                TXT);
        }
    }

    private static function cleanName(string $name): string
    {
        $name = basename($name);
        return preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $name) ?: 'image';
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format($bytes / 1024) . ' KB';
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ไฟล์ใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้',
            UPLOAD_ERR_PARTIAL   => 'อัปโหลดไม่ครบ กรุณาลองใหม่',
            UPLOAD_ERR_NO_FILE   => 'ไม่ได้เลือกไฟล์',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'เซิร์ฟเวอร์เขียนไฟล์ไม่ได้ กรุณาแจ้งผู้ดูแล',
            default              => 'อัปโหลดไม่สำเร็จ',
        };
    }
}

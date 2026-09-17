<?php
declare(strict_types=1);

namespace Fmk;

use RuntimeException;

/**
 * เนื้อหาเว็บไซต์ — ฉบับร่าง การเผยแพร่ ประวัติ และการย้อนกลับ
 *
 * ฐานข้อมูลคือแหล่งข้อมูลจริง (source of truth)
 * ส่วน content.json เป็นไฟล์ส่งออกที่เขียนทับทุกครั้งที่เผยแพร่ เพื่อให้เครื่องมือฝั่ง Node ใช้ต่อได้
 */
final class Content
{
    /** ---------------------------------------------------------- ฉบับร่าง */

    public static function draft(): array
    {
        $row = Db::one('SELECT data FROM content_draft WHERE id = 1');
        if ($row === null) {
            throw new RuntimeException('ยังไม่มีเนื้อหาในฐานข้อมูล — รัน php bin/import-content.php ก่อน');
        }
        return self::decode((string) $row['data']);
    }

    public static function saveDraft(array $content, int $userId): void
    {
        self::validate($content);
        Db::run(
            'INSERT INTO content_draft (id, data, updated_by) VALUES (1, ?, ?)
             ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by = VALUES(updated_by)',
            [self::encode($content), $userId]
        );
    }

    /** @return array{updated_at:?string, by:?string} */
    public static function draftMeta(): array
    {
        $row = Db::one(
            'SELECT d.updated_at, u.email
               FROM content_draft d
          LEFT JOIN users u ON u.id = d.updated_by
              WHERE d.id = 1'
        );
        return [
            'updated_at' => $row['updated_at'] ?? null,
            'by'         => $row['email'] ?? null,
        ];
    }

    /** ฉบับร่างต่างจากที่เผยแพร่อยู่หรือไม่ */
    public static function hasUnpublishedChanges(): bool
    {
        $cur = Db::one('SELECT data FROM content_versions WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
        if ($cur === null) {
            return true;
        }
        $draft = Db::one('SELECT data FROM content_draft WHERE id = 1');
        return ($draft['data'] ?? '') !== $cur['data'];
    }

    /** ------------------------------------------------------------ เผยแพร่ */

    /**
     * เขียน public/index.html จากฉบับร่าง แล้วบันทึกเป็นเวอร์ชันใหม่
     *
     * @return array{version:int, bytes:int, privacyBytes:int}
     */
    public static function publish(int $userId, string $note = ''): array
    {
        $content = self::draft();
        self::validate($content);

        $written = Builder::writePages($content);
        $bytes = $written['index'];
        Builder::exportJson($content);

        Db::run('UPDATE content_versions SET is_current = 0 WHERE is_current = 1');
        Db::run(
            'INSERT INTO content_versions (data, note, published_by, is_current) VALUES (?, ?, ?, 1)',
            [self::encode($content), mb_substr($note, 0, 255), $userId]
        );
        $version = (int) Db::pdo()->lastInsertId();

        Audit::log($userId, 'content.published', 'version', (string) $version, [
            'bytes'        => $bytes,
            'privacyBytes' => $written['privacy'],
            'note'         => $note,
        ]);

        return ['version' => $version, 'bytes' => $bytes, 'privacyBytes' => $written['privacy']];
    }

    /** @return array<int,array<string,mixed>> */
    public static function versions(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        return Db::all(
            "SELECT v.id, v.note, v.published_at, v.is_current, COALESCE(u.email, '—') AS email,
                    CHAR_LENGTH(v.data) AS size
               FROM content_versions v
          LEFT JOIN users u ON u.id = v.published_by
           ORDER BY v.id DESC
              LIMIT $limit"
        );
    }

    /**
     * ดึงเวอร์ชันเก่ากลับมาเป็นฉบับร่าง — ยังไม่เผยแพร่จนกว่าจะกดเผยแพร่อีกครั้ง
     * จงใจไม่ให้ย้อนกลับแล้วขึ้นเว็บทันที เพื่อให้มีจังหวะตรวจก่อนเสมอ
     */
    public static function restore(int $versionId, int $userId): void
    {
        $row = Db::one('SELECT data FROM content_versions WHERE id = ?', [$versionId]);
        if ($row === null) {
            throw new RuntimeException('ไม่พบเวอร์ชันที่ต้องการ');
        }
        $content = self::decode((string) $row['data']);
        self::saveDraft($content, $userId);
        Audit::log($userId, 'content.restored', 'version', (string) $versionId, []);
    }

    /** ------------------------------------------------------- ตรวจความถูกต้อง */

    /**
     * ตรวจให้แน่ใจว่าเนื้อหายังอยู่ในรูปทรงที่หน้าเว็บใช้ได้
     * เงื่อนไขสำคัญที่สุดคือโครงสร้าง en กับ th ต้องตรงกัน
     * ไม่งั้นสลับภาษาแล้วหน้าเว็บจะพัง
     */
    public static function validate(array $c): void
    {
        $err = [];

        foreach (['address', 'phones', 'phoneHref', 'email'] as $k) {
            if (!isset($c['company'][$k]) || !is_string($c['company'][$k]) || trim($c['company'][$k]) === '') {
                $err[] = "ข้อมูลบริษัท: $k ห้ามว่าง";
            }
        }
        if (!isset($c['company']['social']) || !is_array($c['company']['social'])) {
            $err[] = 'ข้อมูลบริษัท: social ต้องเป็นรายการ';
        } else {
            self::validateSocial($c['company']['social'], $err);
        }

        if (!isset($c['i18n']['en'], $c['i18n']['th'])) {
            $err[] = 'ต้องมีเนื้อหาทั้งภาษาอังกฤษและภาษาไทย';
        } else {
            self::compareShape($c['i18n']['en'], $c['i18n']['th'], 'i18n', $err);
        }

        if ($err !== []) {
            throw new RuntimeException("เนื้อหาไม่ผ่านการตรวจ:\n• " . implode("\n• ", $err));
        }
    }

    /**
     * ตรวจช่องทางติดต่อ (Facebook / YouTube / LINE / WhatsApp)
     *
     * ทำไมต้องตรวจฝั่งเซิร์ฟเวอร์ทั้งที่หน้าจอเป็น dropdown อยู่แล้ว:
     * dropdown กันได้แค่คนที่ใช้หน้าจอตามปกติ ใครยิง POST เองก็ส่งค่าอะไรมาก็ได้
     * ค่าที่ระบบไม่รู้จักจะทำให้ไอคอนท้ายเว็บหายไปเงียบ ๆ โดยไม่มีอะไรฟ้อง
     *
     * ชื่อที่แสดงและลิงก์บังคับเฉพาะรายการที่เปิดแสดงอยู่
     * รายการที่ซ่อนไว้ถือว่ายังกรอกไม่เสร็จ จึงยอมให้ว่างได้
     * (ถ้าบังคับทุกรายการ จะเพิ่มรายการใหม่ไม่ได้เลยเพราะบันทึกไม่ผ่านตั้งแต่แรก)
     *
     * @param array<mixed> $social
     * @param array<int,string> $err
     */
    private static function validateSocial(array $social, array &$err): void
    {
        $allowed = Sections::socialOptions();
        $seen = [];

        foreach ($social as $i => $s) {
            $n = (int) $i + 1;
            if (!is_array($s)) {
                $err[] = "ช่องทางติดต่อรายการที่ $n: รูปแบบข้อมูลไม่ถูกต้อง";
                continue;
            }

            $key = is_string($s['key'] ?? null) ? trim($s['key']) : '';
            if ($key === '') {
                $err[] = "ช่องทางติดต่อรายการที่ $n: ยังไม่ได้เลือกว่าเป็นช่องทางไหน";
            } elseif (!isset($allowed[$key])) {
                $err[] = sprintf('ช่องทางติดต่อรายการที่ %d: ระบบไม่รองรับ "%s" (รองรับ %s)',
                    $n, $key, implode(' · ', $allowed));
            } elseif (isset($seen[$key])) {
                $err[] = sprintf('ช่องทางติดต่อ: มี %s ซ้ำกันสองรายการ (รายการที่ %d และ %d) — ใส่ได้ประเภทละหนึ่งรายการ',
                    $allowed[$key], $seen[$key], $n);
            } else {
                $seen[$key] = $n;
            }

            if (($s['visible'] ?? true) === false) {
                continue;
            }
            /* ชื่อฟิลด์ตรงกับที่แสดงบนหน้าจอ ผู้ใช้จะได้หาช่องที่ต้องแก้เจอ */
            foreach (['label' => 'ข้อความ', 'href' => 'ลิงก์'] as $f => $thaiName) {
                if (!isset($s[$f]) || !is_string($s[$f]) || trim($s[$f]) === '') {
                    $err[] = "ช่องทางติดต่อรายการที่ $n: $thaiName ห้ามว่าง "
                           . '(ถ้ายังกรอกไม่เสร็จ ให้กดซ่อนรายการนี้ไว้ก่อน)';
                }
            }
        }
    }

    /** เทียบโครงสร้างสองภาษาแบบลงลึก */
    private static function compareShape(mixed $en, mixed $th, string $path, array &$err): void
    {
        if (is_array($en) !== is_array($th)) {
            $err[] = "$path: รูปแบบข้อมูลสองภาษาไม่ตรงกัน";
            return;
        }
        if (!is_array($en)) {
            return;
        }

        if (array_is_list($en) || array_is_list($th)) {
            if (!array_is_list($en) || !array_is_list($th)) {
                $err[] = "$path: ฝั่งหนึ่งเป็นรายการ อีกฝั่งไม่ใช่";
                return;
            }
            if (count($en) !== count($th)) {
                $err[] = sprintf('%s: จำนวนรายการไม่เท่ากัน (อังกฤษ %d, ไทย %d)', $path, count($en), count($th));
                return;
            }
            foreach ($en as $i => $v) {
                self::compareShape($v, $th[$i], "$path[$i]", $err);
            }
            return;
        }

        foreach (array_keys($en) as $k) {
            if (!array_key_exists($k, $th)) {
                $err[] = "$path.$k: ไม่มีในภาษาไทย";
                continue;
            }
            self::compareShape($en[$k], $th[$k], "$path.$k", $err);
        }
        foreach (array_keys($th) as $k) {
            if (!array_key_exists($k, $en)) {
                $err[] = "$path.$k: ไม่มีในภาษาอังกฤษ";
            }
        }
    }

    /** ------------------------------------------------------------ ช่วยเหลือ */

    public static function encode(array $c): string
    {
        $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('แปลงเนื้อหาเป็น JSON ไม่สำเร็จ');
        }
        return $json;
    }

    public static function decode(string $json): array
    {
        $c = json_decode($json, true);
        if (!is_array($c)) {
            throw new RuntimeException('เนื้อหาในฐานข้อมูลไม่ใช่ JSON ที่ถูกต้อง');
        }
        return $c;
    }

    /** อ่านค่าตาม path เช่น "i18n.en.hero.headline" */
    public static function at(array $c, string $path): mixed
    {
        $node = $c;
        foreach (explode('.', $path) as $seg) {
            if (is_array($node) && array_key_exists($seg, $node)) {
                $node = $node[$seg];
            } else {
                return null;
            }
        }
        return $node;
    }

    /** เขียนค่าตาม path — สร้างคีย์ใหม่ไม่ได้ ต้องมีอยู่แล้วเท่านั้น */
    public static function setAt(array &$c, string $path, mixed $value): bool
    {
        $segs = explode('.', $path);
        $node = &$c;
        foreach ($segs as $i => $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return false;
            }
            if ($i === count($segs) - 1) {
                $node[$seg] = $value;
                return true;
            }
            $node = &$node[$seg];
        }
        return false;
    }
}

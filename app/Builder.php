<?php
declare(strict_types=1);

namespace Fmk;

use RuntimeException;

/**
 * สร้าง index.html จาก template.html + เนื้อหา
 *
 * นี่คือคู่แฝดฝั่ง PHP ของ build.js — ผลลัพธ์ต้องออกมา "เหมือนกันทุกไบต์"
 * มีชุดทดสอบที่เทียบผลของสองตัวนี้อยู่ ถ้าแก้ตัวใดตัวหนึ่งต้องแก้อีกตัวด้วย
 *
 * หน้าที่สำคัญอีกอย่างคือ "กรองรายการที่ถูกซ่อนออก" ก่อนฝังลงหน้าเว็บ
 * รายการที่ตั้ง visible = false จะไม่ถูกส่งไปหน้าเว็บเลย ไม่ใช่แค่ซ่อนด้วย CSS
 */
final class Builder
{
    private const MARKER = '/*__FMK_CONTENT__*/';

    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * คีย์ที่แต่ละหน้าต้องใช้จริง
     *
     * ถ้าส่งเนื้อหาทั้งก้อนให้ทุกหน้า หน้าแรกจะแบกข้อความนโยบายอีกราว 17KB
     * ที่ไม่มีทางได้แสดงเลย ส่วนหน้านโยบายก็จะแบกเนื้อหาขายของทั้งเว็บเหมือนกัน
     * ต้องให้ผลตรงกับ PAGE_I18N ใน build.js เป๊ะ ๆ
     */
    private const PAGE_I18N = [
        'home'    => ['drop' => ['privacy']],
        'privacy' => ['keep' => ['privacy', 'footer']],
    ];

    /**
     * แปลงเนื้อหาฉบับเต็ม (ที่มีข้อมูลสำหรับบรรณาธิการ) ให้เหลือเฉพาะที่หน้านั้นต้องใช้
     *
     * @param string $page 'home' หรือ 'privacy'
     */
    public static function forPublic(array $content, string $page = 'home'): array
    {
        $rule = self::PAGE_I18N[$page] ?? self::PAGE_I18N['home'];

        return [
            'company' => self::strip($content['company'] ?? []),
            'i18n'    => [
                'en' => self::pickKeys(self::dropUnreadBodies(self::strip($content['i18n']['en'] ?? [])), $rule),
                'th' => self::pickKeys(self::dropUnreadBodies(self::strip($content['i18n']['th'] ?? [])), $rule),
            ],
        ];
    }

    /**
     * บทความที่ยังไม่ได้เปิดให้อ่าน ต้องไม่ส่งเนื้อหาไปหน้าเว็บเลย
     *
     * ถ้าปล่อยไป ข้อความที่ผู้ดูแลยังเขียนไม่เสร็จจะฝังอยู่ในซอร์สของหน้าเว็บจริง
     * ใครกด View Source ก็อ่านได้ และ Google ก็เก็บไปเข้าดัชนีได้
     * "ปิดอยู่" ต้องแปลว่าไม่มีใครเห็น ไม่ใช่แค่ไม่มีปุ่มให้กด
     *
     * ต้องให้ผลตรงกับ dropUnreadBodies() ใน build.js เป๊ะ ๆ
     */
    private static function dropUnreadBodies(mixed $lang): mixed
    {
        if (!is_array($lang) || !isset($lang['knowledge']['items']) || !is_array($lang['knowledge']['items'])) {
            return $lang;
        }
        foreach ($lang['knowledge']['items'] as $i => $item) {
            if (is_array($item) && isset($item['body']) && is_array($item['body'])
                && ($item['readable'] ?? null) !== true) {
                $lang['knowledge']['items'][$i]['body'] = [];
            }
        }
        return $lang;
    }

    /**
     * เลือกคีย์ตามกฎของหน้า โดยคงลำดับคีย์เดิมไว้
     * ลำดับสำคัญ เพราะ JSON ที่ออกมาต้องตรงกับฝั่ง Node ทุกไบต์
     *
     * @param array{drop?:array<int,string>, keep?:array<int,string>} $rule
     */
    private static function pickKeys(mixed $node, array $rule): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $out = [];
        foreach ($node as $k => $v) {
            $ok = isset($rule['keep'])
                ? in_array($k, $rule['keep'], true)
                : !in_array($k, $rule['drop'] ?? [], true);
            if ($ok) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * ตัดรายการที่ซ่อนไว้ทิ้ง และลบคีย์ที่เป็นข้อมูลสำหรับหลังบ้านออก
     * เพื่อให้ JSON ที่ฝังในหน้าเว็บมีรูปทรงเดิมเป๊ะเหมือนตอนก่อนมี CMS
     */
    private static function strip(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }

        $isList = array_is_list($node);

        if ($isList) {
            $out = [];
            foreach ($node as $item) {
                if (is_array($item) && array_key_exists('visible', $item) && $item['visible'] === false) {
                    continue;   // ซ่อนอยู่ — ไม่ส่งออกไปหน้าเว็บ
                }
                $out[] = self::strip($item);
            }
            return $out;
        }

        $out = [];
        foreach ($node as $k => $v) {
            if ($k === 'visible' || (is_string($k) && str_starts_with($k, '_'))) {
                continue;
            }
            $out[$k] = self::strip($v);
        }
        return $out;
    }

    /** เข้ารหัส JSON ให้ผลตรงกับ JSON.stringify ของ JavaScript */
    public static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('แปลงเนื้อหาเป็น JSON ไม่สำเร็จ: ' . json_last_error_msg());
        }
        // กัน payload ปิดแท็ก </script> ก่อนเวลา
        return str_replace('<', '\\u003c', $json);
    }

    /** ประกอบหน้าเว็บออกมาเป็นสตริง โดยไม่เขียนลงไฟล์ */
    /**
     * @param string $tplFile ชื่อไฟล์แม่แบบในรากโปรเจกต์
     *                        'template.html' คือหน้าแรก · 'template-privacy.html' คือหน้านโยบาย
     *                        ทั้งสองหน้ารับเนื้อหาก้อนเดียวกัน ต่างกันแค่ว่าหยิบส่วนไหนไปแสดง
     */
    public static function render(array $content, string $tplFile = 'template.html'): string
    {
        $tplPath = self::projectRoot() . '/' . $tplFile;
        $tpl = file_get_contents($tplPath);
        if ($tpl === false) {
            throw new RuntimeException('อ่าน ' . $tplFile . ' ไม่ได้');
        }
        if (!str_contains($tpl, self::MARKER)) {
            throw new RuntimeException('ไม่พบ ' . self::MARKER . ' ใน ' . $tplFile);
        }

        $page = $tplFile === 'template-privacy.html' ? 'privacy' : 'home';

        $block = "/* ---------- Content generated from content.json — DO NOT EDIT HERE ---------- */\n"
               . 'var FMK_CONTENT = ' . self::encode(self::forPublic($content, $page)) . ";\n"
               . "var COMPANY = FMK_CONTENT.company;\n"
               . 'var T = FMK_CONTENT.i18n;';

        // ใช้ str_replace ไม่ใช่ preg_replace เพราะเนื้อหาอาจมี $ ซึ่ง regex จะตีความเป็น backreference
        return str_replace(self::MARKER, $block, $tpl);
    }

    /**
     * เขียนหน้าเว็บจริง — เขียนลงไฟล์ชั่วคราวก่อนแล้วค่อยสลับ
     * เพื่อไม่ให้มีจังหวะที่ผู้เข้าชมเจอไฟล์ที่เขียนค้างอยู่ครึ่งเดียว
     */
    private static function writeAtomic(string $name, string $html): int
    {
        $dest = Config::publicDir() . '/' . $name;
        $tmp  = $dest . '.tmp';

        if (file_put_contents($tmp, $html) === false) {
            throw new RuntimeException('เขียนไฟล์ชั่วคราวของ ' . $name . ' ไม่ได้');
        }
        if (!rename($tmp, $dest)) {
            @unlink($tmp);
            throw new RuntimeException('สลับไฟล์ ' . $name . ' ไม่สำเร็จ');
        }

        return strlen($html);
    }

    /**
     * เขียนหน้าเว็บสาธารณะทุกหน้า
     *
     * เรนเดอร์ทั้งสองหน้าให้เสร็จก่อนแล้วค่อยเขียน ถ้าหน้าใดหน้าหนึ่งเรนเดอร์ไม่ผ่าน
     * จะโยน exception ตั้งแต่ยังไม่ได้แตะไฟล์จริงเลย — เว็บจึงไม่มีทางค้างอยู่ในสภาพ
     * ที่หน้าแรกเป็นเนื้อหาใหม่แต่หน้านโยบายยังเป็นของเก่า
     *
     * @return array{index:int, privacy:int} จำนวนไบต์ของแต่ละหน้า
     */
    public static function writePages(array $content): array
    {
        $index   = self::render($content, 'template.html');
        $privacy = self::render($content, 'template-privacy.html');

        return [
            'index'   => self::writeAtomic('index.html', $index),
            'privacy' => self::writeAtomic('privacy.html', $privacy),
        ];
    }

    /**
     * ส่งออกเนื้อหาเป็น content.json ให้เครื่องมือฝั่ง Node ใช้ต่อได้
     *
     * ต้องส่งออก "ฉบับเต็ม" รวมรายการที่ซ่อนไว้และธง visible ด้วย
     * ถ้าส่งออกเฉพาะส่วนที่เผยแพร่ รายการที่ซ่อนจะหายถาวรเมื่อนำเข้ากลับ
     * การตัดรายการที่ซ่อนออกเป็นหน้าที่ของตอน render เท่านั้น
     */
    public static function exportJson(array $content): void
    {
        $payload = [
            '_comment' => 'FMK Intertrade — editable site content (ฉบับเต็ม รวมรายการที่ซ่อนไว้). Managed by the admin panel.',
            '_schema'  => 2,
            'company'  => $content['company'] ?? [],
            'i18n'     => $content['i18n'] ?? [],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('ส่งออก content.json ไม่สำเร็จ');
        }
        // JSON_PRETTY_PRINT ของ PHP ใช้ 4 ช่อง ส่วน JSON.stringify(...,2) ใช้ 2 ช่อง ปรับให้ตรงกัน
        $json = preg_replace_callback(
            '/^(?: {4})+/m',
            static fn(array $m): string => str_repeat(' ', strlen($m[0]) / 2),
            $json
        );

        file_put_contents(self::projectRoot() . '/content.json', $json . "\n");
    }
}

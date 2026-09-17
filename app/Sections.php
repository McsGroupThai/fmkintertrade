<?php
declare(strict_types=1);

namespace Fmk;

/** แผนที่ส่วนต่าง ๆ ของหน้าเว็บ ใช้จัดเมนูและตั้งชื่อภาษาไทยให้หลังบ้าน */
final class Sections
{
    /**
     * จัดกลุ่มเมนูตามงานที่ผู้ดูแลคิดจะทำ ไม่ใช่ตามโครงสร้างข้อมูล
     * เช่นอยากแก้หน้าแรก ก็ควรเจอทุกอย่างของหน้าแรกอยู่ด้วยกัน
     *
     * @return array<string,array<int,string>>
     */
    public static function groups(): array
    {
        return [
            'ข้อมูลบริษัท'        => ['company'],
            'หน้าแรก'             => ['hero', 'trust', 'about', 'why', 'finalCta'],
            'บริการและโซลูชัน'    => ['solutions'],
            'ผลงาน'               => ['projects'],
            'บทความ'              => ['knowledge'],
            'สาขาและการติดต่อ'    => ['network', 'contact', 'form'],
            'เมนูและส่วนท้าย'     => ['topbar', 'nav', 'cta', 'footer'],
            'หน้านโยบาย'          => ['privacy'],
            'ข้อความระบบ'         => ['skip', 'brandDescriptor'],
        ];
    }

    /** @return array<string,array{label:string, hint:string}> */
    public static function all(): array
    {
        return [
            'company'          => ['label' => 'ข้อมูลบริษัท',      'hint' => 'ที่อยู่ เบอร์โทร อีเมล ช่องทางติดต่อ'],
            'hero'             => ['label' => 'แบนเนอร์หลัก',       'hint' => 'หัวเรื่องใหญ่บนสุดของหน้าแรก'],
            'trust'            => ['label' => 'จุดเด่น 4 ข้อ',      'hint' => 'แถบใต้แบนเนอร์'],
            'about'            => ['label' => 'เกี่ยวกับ FMK',      'hint' => 'เนื้อหาแนะนำบริษัท'],
            'why'              => ['label' => 'ทำไมต้อง FMK',       'hint' => 'เหตุผลที่ควรเลือกเรา'],
            'finalCta'         => ['label' => 'ส่วนชวนติดต่อ',      'hint' => 'ก่อนถึงส่วนท้ายเว็บ'],
            'solutions'        => ['label' => 'รายการบริการ',       'hint' => 'บริการทั้งหมดที่แสดงบนเว็บ'],
            'projects'         => ['label' => 'รายการผลงาน',        'hint' => 'โครงการที่แสดงบนเว็บ'],
            'knowledge'        => ['label' => 'รายการบทความ',       'hint' => 'บทความที่แสดงบนหน้าแรก'],
            'network'          => ['label' => 'สาขา',               'hint' => 'สาขาในไทยและต่างประเทศ'],
            'contact'          => ['label' => 'กล่องติดต่อ',        'hint' => 'หัวข้อในกล่องข้อมูลติดต่อ'],
            'form'             => ['label' => 'แบบฟอร์มติดต่อ',     'hint' => 'ชื่อช่องกรอกและข้อความเตือน'],
            'topbar'           => ['label' => 'แถบบนสุด',           'hint' => 'ประเทศที่ให้บริการ และสโลแกน'],
            'nav'              => ['label' => 'เมนูด้านบน',         'hint' => 'รายการเมนูนำทาง'],
            'cta'              => ['label' => 'ข้อความบนปุ่ม',      'hint' => 'ปุ่มหลักต่าง ๆ ทั้งเว็บ'],
            'footer'           => ['label' => 'ส่วนท้ายเว็บ',       'hint' => 'ลิงก์และข้อความท้ายหน้า'],
            'privacy'          => ['label' => 'นโยบายความเป็นส่วนตัว', 'hint' => 'เนื้อหาทั้งหมดของหน้า privacy.html'],
            'skip'             => ['label' => 'ข้อความช่วยการเข้าถึง', 'hint' => 'สำหรับผู้ใช้โปรแกรมอ่านหน้าจอ'],
            'brandDescriptor'  => ['label' => 'คำบรรยายแบรนด์',     'hint' => 'ข้อความเล็ก ๆ ใต้โลโก้'],
        ];
    }

    public static function label(string $key): string
    {
        return self::all()[$key]['label'] ?? $key;
    }

    public static function hint(string $key): string
    {
        return self::all()[$key]['hint'] ?? '';
    }

    /** ชื่อกลุ่มที่ส่วนนี้สังกัดอยู่ */
    public static function groupOf(string $key): string
    {
        foreach (self::groups() as $group => $keys) {
            if (in_array($key, $keys, true)) {
                return $group;
            }
        }
        return '';
    }

    /* ------------------------------------------------- ฟิลด์เชิงเทคนิค */

    /**
     * ฟิลด์ที่ผู้ดูแลทั่วไปไม่ควรต้องยุ่ง
     *
     * num  = เลขลำดับที่โชว์บนการ์ด ระบบเรียงให้เองตามลำดับรายการ
     * key  = รหัสภายในที่ใช้จับคู่ไอคอน
     *
     * ยกเว้นช่องทางติดต่อ (company.social) — ที่นั่น key คือ "ช่องทางไหน"
     * ซึ่งเป็นข้อมูลที่ผู้ดูแลต้องเลือกเอง ถ้าซ่อนไว้ รายการที่เพิ่มใหม่จะได้ key ว่าง
     * แล้วไอคอนหายไปเงียบ ๆ โดยไม่มีอะไรเตือน จึงต้องแสดงเป็นตัวเลือกให้เลือก
     */
    public static function isHiddenField(string $key, string $path = ''): bool
    {
        if ($key === 'key' && self::isSocialKeyField($path)) {
            return false;
        }
        return in_array($key, ['num', 'key', 'imageAlt', 'visible'], true);
    }

    /** ตรงกับ path แบบ company.social.0.key เท่านั้น */
    public static function isSocialKeyField(string $path): bool
    {
        return preg_match('/^company\.social\.\d+\.key$/', $path) === 1;
    }

    /**
     * ช่องทางติดต่อที่ระบบรองรับ พร้อมชื่อที่คนทั่วไปเรียก
     *
     * ต้องตรงกับ SOCIAL_PATHS ใน template.html (ยกเว้น line ที่วาดเป็นตัวอักษร)
     * ใส่ค่าอื่นแล้วไอคอนจะหายไปจากท้ายเว็บโดยไม่มีข้อความเตือน
     *
     * @return array<string,string>
     */
    public static function socialOptions(): array
    {
        return [
            'facebook' => 'Facebook',
            'youtube'  => 'YouTube',
            'line'     => 'LINE',
            'whatsapp' => 'WhatsApp',
        ];
    }

    /** ฟิลด์ที่เปลี่ยนจากช่องพิมพ์เป็นตัวเลือก เพื่อไม่ให้พิมพ์ผิดจนไอคอนหาย */
    public static function isIconField(string $key): bool
    {
        return $key === 'k';
    }

    /**
     * ไอคอนที่เลือกได้ พร้อมชื่อภาษาไทย
     *
     * ต้องตรงกับคีย์ใน ICON_PATHS ของ template.html เท่านั้น
     * ถ้าใส่ชื่อที่ไม่มีอยู่ ไอคอนจะหายไปจากหน้าเว็บ
     *
     * @return array<string,string>
     */
    public static function iconOptions(): array
    {
        return [
            'boxes'      => 'กล่องสินค้า',
            'sprout'     => 'ต้นกล้า / พืช',
            'medical'    => 'เวชภัณฑ์',
            'barn'       => 'โรงเรือนปศุสัตว์',
            'greenhouse' => 'โรงเรือนปลูกพืช',
            'snowflake'  => 'ห้องเย็น',
            'truck'      => 'รถขนส่ง',
            'globe'      => 'ลูกโลก / ต่างประเทศ',
            'gear'       => 'เฟือง / วิศวกรรม',
            'layers'     => 'ชั้นซ้อน / ครบวงจร',
            'trending'   => 'กราฟเติบโต',
            'shield'     => 'โล่ / ความมั่นใจ',
            'check'      => 'เครื่องหมายถูก',
            'handshake'  => 'จับมือ / พันธมิตร',
            'pin'        => 'หมุดตำแหน่ง',
            'phone'      => 'โทรศัพท์',
            'mail'       => 'อีเมล',
        ];
    }

    /** ชื่อภาษาไทยของฟิลด์ที่พบบ่อย ใช้ช่วยให้หลังบ้านอ่านง่ายขึ้น */
    public static function fieldLabel(string $key): string
    {
        return [
            'eyebrow'       => 'ข้อความนำ',
            'title'         => 'หัวเรื่อง',
            'headline'      => 'หัวเรื่องใหญ่',
            'sub'           => 'คำอธิบายรอง',
            'lead'          => 'ย่อหน้านำ',
            'body'          => 'เนื้อหา',
            'desc'          => 'คำอธิบาย',
            'label'         => 'ข้อความ',
            'name'          => 'ชื่อ',
            'city'          => 'เมือง',
            'role'          => 'บทบาท',
            'detail'        => 'รายละเอียด',
            'href'          => 'ลิงก์',
            'type'          => 'ประเภท',
            'scope'         => 'ขอบเขตงาน',
            'location'      => 'สถานที่',
            'tag'           => 'ป้ายกำกับ',
            'tags'          => 'ป้ายกำกับ',
            'category'      => 'หมวดหมู่',
            'k'             => 'ไอคอน',
            'key'           => 'ช่องทาง',
            'badge'         => 'ป้าย',
            'badgeDesc'     => 'คำอธิบายป้าย',
            'tagline'       => 'สโลแกน',
            'locations'     => 'ประเทศที่ให้บริการ',
            'viewAll'       => 'ปุ่มดูทั้งหมด',
            'eyebrow'       => 'ข้อความนำ',
            'updated'       => 'วันที่ปรับปรุงล่าสุด',
            'backToHome'    => 'ปุ่มกลับหน้าแรก',
            'intro'         => 'ย่อหน้านำ',
            'sections'      => 'หัวข้อในนโยบาย',
            'heading'       => 'ชื่อหัวข้อ',
            'body'          => 'ย่อหน้า',
            'items'         => 'รายการย่อย',
            'privacyLink'   => 'ลิงก์นโยบายใต้ช่องยินยอม',
            'readMore'      => 'ปุ่มอ่านต่อ',
            'readable'      => 'เปิดให้กดอ่านเนื้อหาเต็ม (ต้องใส่ย่อหน้าอย่างน้อยหนึ่งย่อหน้าก่อน)',
            'copyright'     => 'ข้อความลิขสิทธิ์',
            'hours'         => 'เวลาทำการ',
            'submit'        => 'ปุ่มส่ง',
            'consent'       => 'ข้อความยินยอม',
            'showAdminLink' => 'แสดงลิงก์เข้าระบบผู้ดูแลที่ท้ายเว็บ',
            'adminLabel'    => 'ข้อความบนลิงก์เข้าระบบ',
            'logo'          => 'โลโก้ (แถบบนและเมนู)',
            'logoSeal'      => 'ตราสัญลักษณ์ (ท้ายเว็บ)',
            'image'         => 'รูปภาพ',
            'address'       => 'ที่อยู่',
            'phones'        => 'เบอร์โทรที่แสดง',
            'phoneHref'     => 'เบอร์สำหรับกดโทร',
            'email'         => 'อีเมล',
            'social'        => 'ช่องทางติดต่อ',
            'pending'       => 'ข้อความเมื่อยังไม่มีข้อมูล',
            'thailand'      => 'หัวข้อฝั่งไทย',
            'overseas'      => 'หัวข้อต่างประเทศ',
        ][$key] ?? $key;
    }
}

<?php
declare(strict_types=1);

namespace Fmk;

/**
 * ประวัติการกระทำ — ใครทำอะไรเมื่อไร
 *
 * การบันทึก log ต้องไม่ทำให้งานหลักล้ม ถ้าเขียนไม่สำเร็จให้ปล่อยผ่าน
 * และห้ามบันทึกรหัสผ่านหรือ token ลงไปเด็ดขาด
 */
final class Audit
{
    /** @param array<string,mixed> $detail */
    public static function log(
        ?int $userId,
        string $action,
        string $entity = '',
        string $entityId = '',
        array $detail = []
    ): void {
        // กันพลาด: ตัดคีย์ที่ดูเหมือนความลับออกก่อนบันทึก
        foreach (array_keys($detail) as $k) {
            if (preg_match('/pass|secret|token|hash|csrf/i', (string) $k)) {
                $detail[$k] = '[ตัดออก]';
            }
        }

        try {
            Db::run(
                'INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    substr($action, 0, 64),
                    substr($entity, 0, 64),
                    substr($entityId, 0, 64),
                    json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    client_ip(),
                ]
            );
        } catch (\Throwable) {
            // ไม่ให้ log ที่ล้มเหลวไปทำให้คำขอหลักพัง
        }
    }

    /**
     * แปลชื่อการกระทำเป็นภาษาที่คนอ่านรู้เรื่อง
     *
     * อยู่ที่นี่เพราะมีหลายหน้าที่แสดงประวัติ ถ้าแยกไว้ในแต่ละหน้า
     * จะมีหน้าที่แสดงเป็นรหัสดิบ ๆ ให้ผู้ดูแลอ่านเอาเอง
     */
    public static function label(string $action): string
    {
        return [
            'login.success'         => 'เข้าสู่ระบบ',
            'login.failed'          => 'เข้าสู่ระบบไม่สำเร็จ',
            'login.blocked'         => 'ถูกระงับชั่วคราวจากการลองรหัสผิดหลายครั้ง',
            'login.disabled'        => 'พยายามเข้าด้วยบัญชีที่ถูกปิด',
            'logout'                => 'ออกจากระบบ',
            'content.draft_saved'   => 'บันทึกฉบับร่าง',
            'content.published'     => 'เผยแพร่ขึ้นเว็บ',
            'content.restored'      => 'ย้อนกลับเวอร์ชันเก่า',
            'media.uploaded'        => 'อัปโหลดรูป',
            'media.deleted'         => 'ลบรูป',
            'media.alt_updated'     => 'แก้คำบรรยายรูป',
            'user.created'          => 'สร้างบัญชีผู้ใช้',
            'user.updated'          => 'แก้ไขบัญชีผู้ใช้',
            'user.deleted'          => 'ลบบัญชีผู้ใช้',
            'user.password_reset'   => 'ตั้งรหัสผ่านใหม่ให้ผู้ใช้',
            'user.password_changed' => 'เปลี่ยนรหัสผ่านของตัวเอง',
            'user.profile_updated'  => 'แก้ชื่อที่แสดงของตัวเอง',
            'user.sessions_revoked' => 'บังคับออกจากระบบ',
            'user.recovered'        => '⚠ กู้คืนรหัสผ่านผ่านไฟล์ recover.php',
            'user.unlocked'         => 'ปลดล็อกการเข้าสู่ระบบ',
            'contact.received'      => 'ได้รับคำขอติดต่อจากลูกค้า',
            'contact.status_changed' => 'เปลี่ยนสถานะข้อความ',
            'contact.deleted'       => 'ลบข้อความจากลูกค้า',
            'contact.exported'      => 'ดาวน์โหลดข้อความเป็น CSV',
            'access.denied'         => 'เข้าหน้าที่ไม่มีสิทธิ์',
        ][$action] ?? $action;
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));
        return Db::all(
            "SELECT a.id, a.action, a.entity, a.entity_id, a.detail, a.ip, a.created_at,
                    COALESCE(u.email, '—') AS email
               FROM audit_log a
          LEFT JOIN users u ON u.id = a.user_id
           ORDER BY a.id DESC
              LIMIT $limit"
        );
    }
}

<?php
declare(strict_types=1);

namespace Fmk;

/**
 * CSRF token ต่อ session
 *
 * ทุกฟอร์มที่เปลี่ยนแปลงข้อมูล (POST) ต้องแนบ token และผ่าน Csrf::check()
 * ใช้ hash_equals เทียบ เพื่อไม่ให้เดาได้จากเวลาที่ใช้เปรียบเทียบ
 */
final class Csrf
{
    private const KEY = 'fmk_csrf';

    public static function token(): string
    {
        Session::start();
        if (!isset($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(self::token()) . '">';
    }

    public static function check(?string $given): bool
    {
        Session::start();
        $want = $_SESSION[self::KEY] ?? null;
        if (!is_string($want) || $want === '' || !is_string($given) || $given === '') {
            return false;
        }
        return hash_equals($want, $given);
    }

    /** ออก token ใหม่ — เรียกหลังล็อกอิน/ออกจากระบบ */
    public static function rotate(): void
    {
        Session::start();
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}

<?php
declare(strict_types=1);

namespace Fmk;

use RuntimeException;

final class Config
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    private static function load(): void
    {
        if (self::$data !== null) {
            return;
        }

        $path = dirname(__DIR__) . '/config/config.local.php';
        if (!is_file($path)) {
            throw new RuntimeException(
                'ไม่พบ config/config.local.php — คัดลอกจาก config.example.php แล้วใส่ค่าจริง'
            );
        }

        /** @var array<string,mixed> $cfg */
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new RuntimeException('config.local.php ต้อง return array');
        }

        self::$data = $cfg;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$data[$key] ?? $default;
    }

    public static function security(string $key): mixed
    {
        self::load();
        /** @var array<string,mixed> $sec */
        $sec = self::$data['security'] ?? [];
        if (!array_key_exists($key, $sec)) {
            throw new RuntimeException("ไม่มีค่าตั้ง security.$key");
        }
        return $sec[$key];
    }

    /**
     * โฟลเดอร์ที่เว็บเปิดให้เข้าถึงได้จริง
     *
     * ในเครื่องพัฒนาคือ public/ ที่อยู่ข้าง ๆ app/ แต่บนโฮสติ้งชื่อจริงคือ public_html
     * และอาจอยู่คนละชั้นกับรากโปรเจกต์ ถ้าฝังชื่อ public/ ไว้ตายตัว การกดเผยแพร่
     * จะไปเขียนไฟล์ผิดโฟลเดอร์แบบเงียบ ๆ คือระบบบอกว่าสำเร็จ แต่หน้าเว็บจริงไม่เปลี่ยน
     * จึงให้ตั้งค่าได้ และตรวจว่าโฟลเดอร์นั้นมีอยู่จริงก่อนใช้ ไม่ปล่อยให้พลาดเงียบ ๆ
     */
    public static function publicDir(): string
    {
        $custom = self::get('public_dir');
        if (is_string($custom) && $custom !== '') {
            $real = realpath($custom);
            if ($real === false || !is_dir($real)) {
                throw new RuntimeException(
                    'ค่า public_dir ใน config ชี้ไปที่ "' . $custom . '" ซึ่งไม่มีโฟลเดอร์นั้นอยู่จริง'
                );
            }
            return rtrim($real, '/' . DIRECTORY_SEPARATOR);
        }
        return dirname(__DIR__) . '/public';
    }

    public static function isProduction(): bool
    {
        return self::get('env') === 'production';
    }
}

<?php
declare(strict_types=1);

namespace Fmk;

/** escape สำหรับแสดงผลใน HTML — ใช้ทุกครั้งที่พิมพ์ข้อมูลที่ผู้ใช้ป้อน */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

/**
 * IP ของผู้เรียก
 *
 * จงใจอ่านจาก REMOTE_ADDR เท่านั้น ไม่เชื่อ X-Forwarded-For เพราะผู้เรียกปลอมได้
 * ถ้าวันหนึ่งเว็บไปอยู่หลัง proxy/CDN จริง ค่อยแก้ตรงนี้จุดเดียว
 */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) ? substr($ip, 0, 45) : '';
}

function user_agent(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return is_string($ua) ? substr($ua, 0, 255) : '';
}

/** ส่ง security header ชุดพื้นฐานให้ทุกหน้าหลังบ้าน */
function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; "
        . "style-src 'self'; script-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'"
    );
    header_remove('X-Powered-By');
}

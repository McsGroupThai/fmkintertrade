<?php
declare(strict_types=1);

/*
 * สร้างหรือแก้ไขบัญชีผู้ดูแล — ไม่มีการเปิดสมัครสมาชิกผ่านหน้าเว็บ
 *
 *   php bin/create-user.php <อีเมล> <admin|editor> [ชื่อที่แสดง] [--generate]
 *
 * ตัวสคริปต์จะถามรหัสผ่านทางแป้นพิมพ์ หรือสุ่มให้ถ้ากด Enter ผ่าน
 * ใส่ --generate เพื่อสุ่มให้เลยโดยไม่ต้องถาม (ใช้ตอนรันอัตโนมัติ)
 *
 * จงใจไม่ให้ส่งรหัสผ่านมาทาง argument เพราะมันจะไปโผล่ในประวัติคำสั่งและรายการโปรเซส
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Auth;
use Fmk\Audit;
use Fmk\Db;
use Fmk\Users;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array_slice($argv, 1);
$autoGenerate = in_array('--generate', $args, true);
$args = array_values(array_filter($args, static fn(string $a): bool => $a !== '--generate'));

$email = isset($args[0]) ? mb_strtolower(trim($args[0])) : '';
$role  = isset($args[1]) ? trim($args[1]) : '';
$name  = isset($args[2]) ? trim($args[2]) : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "ใช้: php bin/create-user.php <อีเมล> <admin|editor> [ชื่อที่แสดง]\n");
    exit(1);
}
if (!in_array($role, ['admin', 'editor'], true)) {
    fwrite(STDERR, "role ต้องเป็น admin หรือ editor\n");
    exit(1);
}

/** อ่านรหัสผ่านโดยไม่ให้ขึ้นจอถ้าทำได้ */
function read_secret(string $prompt): string
{
    echo $prompt;
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: ใช้ PowerShell อ่านแบบซ่อนตัวอักษร
        $cmd = 'powershell -NoProfile -Command "$p=Read-Host -AsSecureString;'
             . '[Runtime.InteropServices.Marshal]::PtrToStringAuto('
             . '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
        $out = shell_exec($cmd);
        echo "\n";
        return is_string($out) ? trim($out, "\r\n") : '';
    }
    shell_exec('stty -echo');
    $line = fgets(STDIN);
    shell_exec('stty echo');
    echo "\n";
    return is_string($line) ? trim($line) : '';
}

$pw = $autoGenerate ? '' : read_secret("รหัสผ่าน (Enter = ให้ระบบสุ่มให้): ");
$generated = false;

/* กฎเรื่องรหัสผ่านอยู่ที่ Fmk\Users ที่เดียว หน้าเว็บกับ CLI จะได้ตรวจเหมือนกัน */
if ($pw === '') {
    $pw = Users::generatePassword();
    $generated = true;
} else {
    try {
        Users::validatePassword($pw, $email);
    } catch (Throwable $ex) {
        fwrite(STDERR, $ex->getMessage() . "\n");
        exit(1);
    }
    $again = read_secret("พิมพ์รหัสผ่านอีกครั้ง: ");
    if (!hash_equals($pw, $again)) {
        fwrite(STDERR, "รหัสผ่านสองครั้งไม่ตรงกัน\n");
        exit(1);
    }
}

$hash = Auth::hashPassword($pw);
$existing = Db::one('SELECT id FROM users WHERE email = ?', [$email]);

if ($existing !== null) {
    Db::run(
        'UPDATE users SET password_hash = ?, role = ?, display_name = ?, status = \'active\' WHERE id = ?',
        [$hash, $role, $name, $existing['id']]
    );
    $id = (int) $existing['id'];
    Audit::log(null, 'user.updated', 'user', (string) $id, ['email' => $email, 'role' => $role]);
    echo "อัปเดตบัญชีเดิมแล้ว (id $id)\n";
} else {
    Db::run(
        'INSERT INTO users (email, password_hash, display_name, role) VALUES (?, ?, ?, ?)',
        [$email, $hash, $name, $role]
    );
    $id = (int) Db::pdo()->lastInsertId();
    Audit::log(null, 'user.created', 'user', (string) $id, ['email' => $email, 'role' => $role]);
    echo "สร้างบัญชีแล้ว (id $id)\n";
}

echo "  อีเมล : $email\n";
echo "  สิทธิ์ : $role\n";
if ($generated) {
    echo "  รหัสผ่านที่สุ่มให้ : $pw\n";
    echo "\n  จดเก็บไว้ให้ดี รหัสนี้จะไม่แสดงอีก และระบบเก็บไว้เป็น hash เท่านั้น\n";
}

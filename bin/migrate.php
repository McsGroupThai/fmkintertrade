<?php
declare(strict_types=1);

/*
 * รัน SQL migration ที่ยังไม่เคยรัน
 *
 *   php bin/migrate.php
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Db;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = dirname(__DIR__) . '/db';
$files = glob($dir . '/*.sql') ?: [];
sort($files);

if ($files === []) {
    fwrite(STDERR, "ไม่พบไฟล์ .sql ใน $dir\n");
    exit(1);
}

/* ตารางบันทึกเวอร์ชันต้องมีก่อน ไม่งั้นเช็กไม่ได้ว่าอะไรรันไปแล้ว */
Db::run('CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(64) NOT NULL,
    applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$done = [];
foreach (Db::all('SELECT version FROM schema_migrations') as $r) {
    $done[(string) $r['version']] = true;
}

$applied = 0;
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (isset($done[$version])) {
        echo "  ข้าม   $version (รันไปแล้ว)\n";
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "อ่านไฟล์ไม่ได้: $file\n");
        exit(1);
    }

    try {
        Db::pdo()->exec($sql);
        Db::run('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
        echo "  รัน    $version\n";
        $applied++;
    } catch (Throwable $e) {
        fwrite(STDERR, "ล้มเหลวที่ $version: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo $applied === 0 ? "\nฐานข้อมูลเป็นรุ่นล่าสุดอยู่แล้ว\n" : "\nรันไป $applied migration\n";

$tables = Db::all('SHOW TABLES');
echo "ตารางในฐานข้อมูล: " . count($tables) . "\n";
foreach ($tables as $t) {
    echo '  - ' . implode('', array_values($t)) . "\n";
}

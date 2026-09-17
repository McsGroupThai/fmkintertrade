<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use function Fmk\redirect;
use Fmk\Auth;
use Fmk\Csrf;

/* ออกจากระบบต้องเป็น POST + CSRF เท่านั้น
   ไม่งั้นคนอื่นแปะลิงก์ให้กดแล้วเตะเราออกได้ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !Csrf::check(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
    redirect('index.php');
}

Auth::logout();

/* bye=1 ทำให้หน้า login แสดงข้อความยืนยันว่าออกจากระบบแล้วจริง
   ไม่งั้นผู้ใช้จะไม่แน่ใจว่ากดติดหรือเปล่า */
redirect('login.php?bye=1');

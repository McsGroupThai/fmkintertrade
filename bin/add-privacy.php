<?php
declare(strict_types=1);

/*
 * เพิ่มเนื้อหาหน้า "นโยบายความเป็นส่วนตัว" และต่อลิงก์ให้ใช้งานได้จริง (รันครั้งเดียว)
 *
 *   php bin/add-privacy.php            แก้ฉบับร่างในฐานข้อมูล (ใช้บนเว็บจริง)
 *   php bin/add-privacy.php --json     แก้ไฟล์ content.json แทน (ใช้ในเครื่องพัฒนา)
 *
 * ต้องรัน bin/link-footer.php มาก่อน เพราะสคริปต์นี้ต้องใส่ลิงก์ลงในช่อง "ลิงก์"
 * ของรายการท้ายเว็บ ซึ่งช่องนั้นเพิ่งมีขึ้นจากสคริปต์นั้น
 *
 * ทำไมต้องมี:
 *   ข้อความยินยอมในฟอร์มติดต่อเขียนว่า "...ตามนโยบายความเป็นส่วนตัว"
 *   แต่ไม่เคยมีนโยบายให้อ่านจริง ซึ่งเป็นจุดอ่อนตาม PDPA
 *
 * เนื้อหาเขียนจาก "สิ่งที่ระบบทำจริง" ไม่ใช่แม่แบบสำเร็จรูป —
 * ช่องที่ฟอร์มเก็บ · IP กับ user agent ที่บันทึกไว้กันสแปม · คุกกี้ที่มีเฉพาะฝั่งผู้ดูแล
 * และการที่หน้าเว็บโหลดฟอนต์จาก Google ซึ่งทำให้ IP ผู้เข้าชมไปถึง Google
 *
 * ปลอดภัยต่อการรันซ้ำ: ถ้ามีเนื้อหาอยู่แล้วจะไม่ทับ
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use Fmk\Content;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$useJson = in_array('--json', $argv, true);

/* ------------------------------------------------------------------ เนื้อหา */

$privacy = [];

$privacy['th'] = [
    'eyebrow'    => 'ข้อมูลส่วนบุคคล',
    'title'      => 'นโยบายความเป็นส่วนตัว',
    'updated'    => 'ปรับปรุงล่าสุด 17 กันยายน 2569',
    'backToHome' => 'กลับหน้าแรก',
    'intro'      => 'นโยบายนี้อธิบายว่า บริษัท เอฟเอ็มเค อินเตอร์เทรด จำกัด '
                  . 'เก็บข้อมูลส่วนบุคคลอะไรจากผู้ที่เข้าใช้เว็บไซต์ fmkintertrade.shop '
                  . 'เก็บไปทำอะไร เก็บไว้นานเท่าไร และท่านมีสิทธิอะไรบ้าง '
                  . 'ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562',
    'sections'   => [
        [
            'heading' => '1. ผู้ควบคุมข้อมูลส่วนบุคคล',
            'body'    => [
                'บริษัท เอฟเอ็มเค อินเตอร์เทรด จำกัด เป็นผู้ควบคุมข้อมูลส่วนบุคคลของเว็บไซต์นี้',
                'ที่อยู่ 142/36 ซอยสุขสวิทยา ถนนสีลม เขตบางรัก กรุงเทพมหานคร 10500 '
                . 'โทร +66 2 268 1681-2 อีเมล support@fmkintertrade.com',
            ],
            'items'   => [],
        ],
        [
            'heading' => '2. ข้อมูลที่เราเก็บ',
            'body'    => [
                'การเข้าชมเว็บไซต์เฉย ๆ เราไม่ได้เก็บข้อมูลส่วนบุคคลของท่าน '
                . 'เราเก็บข้อมูลเฉพาะเมื่อท่านกรอกและกดส่งแบบฟอร์มติดต่อเท่านั้น',
                'ข้อมูลที่ท่านกรอกเอง',
            ],
            'items'   => [
                'ชื่อ-นามสกุล และชื่อบริษัท',
                'ตำแหน่งงาน และประเทศ',
                'อีเมล และเบอร์โทรศัพท์',
                'โซลูชันที่สนใจ ประเภทโครงการ และข้อความที่ท่านเขียน',
                'ช่องทางที่สะดวกให้ติดต่อกลับ',
            ],
        ],
        [
            'heading' => '3. ข้อมูลทางเทคนิคที่ระบบบันทึกอัตโนมัติ',
            'body'    => [
                'เมื่อมีการส่งแบบฟอร์ม ระบบจะบันทึกข้อมูลต่อไปนี้ไว้ด้วย '
                . 'เพื่อป้องกันการส่งสแปมจากโปรแกรมอัตโนมัติ และเพื่อตรวจสอบย้อนหลังเมื่อมีปัญหา',
            ],
            'items'   => [
                'หมายเลขไอพี (IP address) ที่ใช้ส่ง',
                'ข้อมูลเบราว์เซอร์และระบบปฏิบัติการ (user agent)',
                'ภาษาที่ท่านเลือกดูเว็บไซต์ และวันเวลาที่ส่ง',
                'กรณีที่ระบบปฏิเสธการส่ง เช่น ส่งถี่ผิดปกติ จะบันทึกหมายเลขไอพีและเหตุผลไว้ '
                . 'โดยไม่บันทึกเนื้อหาที่กรอก',
            ],
        ],
        [
            'heading' => '4. เราใช้ข้อมูลไปทำอะไร และใช้ฐานอะไรตามกฎหมาย',
            'body'    => [],
            'items'   => [
                'ติดต่อกลับเพื่อตอบคำถาม เสนอราคา หรือหารือโครงการตามที่ท่านร้องขอ '
                . '— ฐานความยินยอมที่ท่านให้ไว้ตอนกดส่งแบบฟอร์ม',
                'ป้องกันการส่งสแปมและการใช้งานเว็บไซต์โดยมิชอบ '
                . '— ฐานประโยชน์โดยชอบด้วยกฎหมายในการดูแลความปลอดภัยของระบบ',
            ],
        ],
        [
            'heading' => '5. คุกกี้',
            'body'    => [
                'หน้าเว็บไซต์ส่วนที่เปิดให้บุคคลทั่วไปเข้าชม ไม่มีการใช้คุกกี้ '
                . 'ไม่มีการติดตามพฤติกรรม และไม่มีเครื่องมือวิเคราะห์การเข้าชมของบุคคลที่สาม',
                'ระบบหลังบ้านสำหรับผู้ดูแลเว็บไซต์มีการใช้คุกกี้เซสชันหนึ่งตัว '
                . 'เพื่อคงสถานะการเข้าสู่ระบบเท่านั้น ซึ่งเป็นคุกกี้ที่จำเป็นต่อการทำงาน '
                . 'และผู้เข้าชมทั่วไปจะไม่ได้รับคุกกี้ตัวนี้',
            ],
            'items'   => [],
        ],
        [
            'heading' => '6. การเปิดเผยข้อมูลให้บุคคลอื่น',
            'body'    => [
                'เราไม่ขาย ไม่ให้เช่า และไม่แลกเปลี่ยนข้อมูลส่วนบุคคลของท่านกับบุคคลภายนอก '
                . 'ข้อมูลอาจไปถึงบุคคลภายนอกเฉพาะกรณีต่อไปนี้',
            ],
            'items'   => [
                'ผู้ให้บริการพื้นที่เว็บไซต์และฐานข้อมูลที่บริษัทใช้งานอยู่ '
                . 'ซึ่งเป็นผู้เก็บรักษาข้อมูลตามคำสั่งของบริษัทเท่านั้น',
                'เว็บไซต์เรียกใช้ฟอนต์จากบริการ Google Fonts '
                . 'การเรียกนี้ทำให้หมายเลขไอพีของท่านไปถึงเซิร์ฟเวอร์ของ Google '
                . 'ตามปกติของการโหลดไฟล์จากอินเทอร์เน็ต โดยเราไม่ได้ส่งข้อมูลอื่นใดให้',
                'กรณีที่มีกฎหมายหรือคำสั่งของหน่วยงานรัฐบังคับให้เปิดเผย',
            ],
        ],
        [
            'heading' => '7. เราเก็บข้อมูลไว้นานเท่าไร',
            'body'    => [
                'ข้อความที่ส่งผ่านแบบฟอร์มติดต่อ เก็บไว้ไม่เกิน 2 ปี '
                . 'นับจากการติดต่อกันครั้งล่าสุด จากนั้นจะถูกลบหรือทำให้ไม่สามารถระบุตัวบุคคลได้',
                'บันทึกการปฏิเสธการส่งแบบฟอร์มที่เก็บไว้เพื่อป้องกันสแปม เก็บไว้ไม่เกิน 90 วัน',
                'หากท่านขอให้ลบข้อมูลก่อนครบกำหนด เราจะดำเนินการให้ '
                . 'เว้นแต่มีเหตุตามกฎหมายที่ต้องเก็บไว้ต่อ',
            ],
            'items'   => [],
        ],
        [
            'heading' => '8. การรักษาความปลอดภัย',
            'body'    => [
                'เว็บไซต์เข้ารหัสการรับส่งข้อมูลด้วย HTTPS ทุกหน้า '
                . 'ข้อมูลที่ส่งผ่านแบบฟอร์มเก็บอยู่ในฐานข้อมูลที่ไม่เปิดให้เข้าถึงจากภายนอก '
                . 'และเปิดให้เฉพาะพนักงานที่มีบัญชีผู้ดูแลเท่านั้นเข้าดูได้',
                'รหัสผ่านของผู้ดูแลถูกเก็บแบบเข้ารหัสทางเดียว ไม่มีใครอ่านรหัสผ่านจริงได้ '
                . 'รวมถึงผู้ดูแลระบบเอง',
            ],
            'items'   => [],
        ],
        [
            'heading' => '9. สิทธิของท่าน',
            'body'    => [
                'ตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 ท่านมีสิทธิดังนี้',
            ],
            'items'   => [
                'ขอเข้าถึงและขอสำเนาข้อมูลส่วนบุคคลของท่าน',
                'ขอให้แก้ไขข้อมูลที่ไม่ถูกต้องหรือไม่เป็นปัจจุบัน',
                'ขอให้ลบหรือทำลายข้อมูล',
                'ขอให้ระงับการใช้ข้อมูลชั่วคราว',
                'คัดค้านการเก็บรวบรวม ใช้ หรือเปิดเผยข้อมูล',
                'ถอนความยินยอมที่เคยให้ไว้เมื่อใดก็ได้ โดยไม่กระทบการดำเนินการที่ทำไปก่อนหน้า',
                'ร้องเรียนต่อสำนักงานคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล '
                . 'หากเห็นว่าเราไม่ปฏิบัติตามกฎหมาย',
            ],
        ],
        [
            'heading' => '10. การเปลี่ยนแปลงนโยบายนี้',
            'body'    => [
                'หากมีการแก้ไขนโยบายนี้ เราจะปรับวันที่ "ปรับปรุงล่าสุด" ด้านบนของหน้า '
                . 'ขอแนะนำให้ท่านกลับมาอ่านเป็นครั้งคราว',
            ],
            'items'   => [],
        ],
        [
            'heading' => '11. ติดต่อเรื่องข้อมูลส่วนบุคคล',
            'body'    => [
                'หากท่านต้องการใช้สิทธิข้างต้น หรือมีข้อสงสัยเกี่ยวกับนโยบายนี้ '
                . 'ติดต่อได้ที่ support@fmkintertrade.com หรือโทร +66 2 268 1681-2 '
                . 'เราจะตอบกลับภายใน 30 วันนับจากวันที่ได้รับคำขอ',
            ],
            'items'   => [],
        ],
    ],
];

$privacy['en'] = [
    'eyebrow'    => 'PERSONAL DATA',
    'title'      => 'Privacy Policy',
    'updated'    => 'Last updated 17 September 2026',
    'backToHome' => 'Back to home',
    'intro'      => 'This policy explains what personal data FMK Intertrade Company Limited '
                  . 'collects from visitors to fmkintertrade.shop, why we collect it, '
                  . 'how long we keep it, and what rights you have under Thailand\'s '
                  . 'Personal Data Protection Act B.E. 2562 (2019).',
    'sections'   => [
        [
            'heading' => '1. Data controller',
            'body'    => [
                'FMK Intertrade Company Limited is the data controller for this website.',
                'Address: 142/36 Suksawitthaya Soi, Silom, Bangrak, Bangkok 10500, Thailand. '
                . 'Phone +66 2 268 1681-2. Email support@fmkintertrade.com',
            ],
            'items'   => [],
        ],
        [
            'heading' => '2. What we collect',
            'body'    => [
                'Simply browsing this website does not cause us to collect any personal data. '
                . 'We collect data only when you fill in and submit the contact form.',
                'Information you enter yourself',
            ],
            'items'   => [
                'Your full name and company name',
                'Job position and country',
                'Email address and phone number',
                'Solution of interest, project type and the message you write',
                'Your preferred way of being contacted',
            ],
        ],
        [
            'heading' => '3. Technical data recorded automatically',
            'body'    => [
                'When a form is submitted, the system also records the following, '
                . 'to block automated spam and to investigate problems after the fact.',
            ],
            'items'   => [
                'The IP address used to send it',
                'Browser and operating system information (user agent)',
                'The language you were viewing the site in, and the date and time of submission',
                'If a submission is rejected, for example sent unusually frequently, '
                . 'we record the IP address and the reason, but not what was typed',
            ],
        ],
        [
            'heading' => '4. Why we use it, and our legal basis',
            'body'    => [],
            'items'   => [
                'To reply to your enquiry, quote, or discuss a project as you asked us to '
                . '— on the basis of the consent you gave when submitting the form.',
                'To prevent spam and misuse of the website '
                . '— on the basis of our legitimate interest in keeping the system secure.',
            ],
        ],
        [
            'heading' => '5. Cookies',
            'body'    => [
                'The public part of this website uses no cookies, no behavioural tracking, '
                . 'and no third-party analytics.',
                'The administration area used by our staff sets one session cookie, '
                . 'solely to keep them signed in. It is strictly necessary for that function, '
                . 'and ordinary visitors never receive it.',
            ],
            'items'   => [],
        ],
        [
            'heading' => '6. Who else sees your data',
            'body'    => [
                'We do not sell, rent or trade your personal data. '
                . 'It may reach third parties only in these cases',
            ],
            'items'   => [
                'The hosting and database provider we use, which stores the data '
                . 'solely on our instructions.',
                'This website loads its typefaces from Google Fonts. That request reveals '
                . 'your IP address to Google servers, as any file loaded from the internet does. '
                . 'We send them nothing else.',
                'Where disclosure is required by law or by order of a government authority.',
            ],
        ],
        [
            'heading' => '7. How long we keep it',
            'body'    => [
                'Messages sent through the contact form are kept for no more than 2 years '
                . 'from our last contact with you, then deleted or anonymised.',
                'Records of rejected form submissions, kept for spam prevention, '
                . 'are held for no more than 90 days.',
                'If you ask us to delete your data sooner, we will, unless the law requires '
                . 'us to keep it.',
            ],
            'items'   => [],
        ],
        [
            'heading' => '8. Security',
            'body'    => [
                'Every page of this site is encrypted in transit with HTTPS. '
                . 'Form submissions are stored in a database that is not reachable from outside, '
                . 'and only staff with an administrator account can read them.',
                'Administrator passwords are stored one-way hashed. Nobody can read the real '
                . 'password, including our own system administrators.',
            ],
            'items'   => [],
        ],
        [
            'heading' => '9. Your rights',
            'body'    => [
                'Under the Personal Data Protection Act B.E. 2562, you have the right to',
            ],
            'items'   => [
                'Access your personal data and obtain a copy of it',
                'Have inaccurate or out-of-date data corrected',
                'Have your data erased or destroyed',
                'Have the use of your data suspended',
                'Object to the collection, use or disclosure of your data',
                'Withdraw consent at any time, without affecting what was done beforehand',
                'Lodge a complaint with the Personal Data Protection Committee '
                . 'if you believe we are not complying with the law',
            ],
        ],
        [
            'heading' => '10. Changes to this policy',
            'body'    => [
                'If we revise this policy we will update the "last updated" date at the top '
                . 'of this page. We suggest you check back from time to time.',
            ],
            'items'   => [],
        ],
        [
            'heading' => '11. Contact us about your data',
            'body'    => [
                'To exercise any of the rights above, or if you have questions about this policy, '
                . 'contact support@fmkintertrade.com or call +66 2 268 1681-2. '
                . 'We will respond within 30 days of receiving your request.',
            ],
            'items'   => [],
        ],
    ],
];

/* ข้อความบนลิงก์ใต้ช่องยินยอมในฟอร์มติดต่อ */
$formLink = ['th' => 'อ่านนโยบายความเป็นส่วนตัว', 'en' => 'Read our Privacy Policy'];

/* ------------------------------------------------------------------ ลงมือแก้ */

$jsonPath = dirname(__DIR__) . '/content.json';

$c = $useJson
    ? json_decode((string) file_get_contents($jsonPath), true)
    : Content::draft();

if (!is_array($c)) {
    fwrite(STDERR, "อ่านเนื้อหาไม่สำเร็จ\n");
    exit(1);
}

$changes = [];
$skipped = [];

foreach (['en', 'th'] as $lang) {
    if (!isset($c['i18n'][$lang]) || !is_array($c['i18n'][$lang])) {
        continue;
    }

    if (array_key_exists('privacy', $c['i18n'][$lang])) {
        $skipped[] = "$lang: มีเนื้อหานโยบายอยู่แล้ว จึงไม่ทับ";
    } else {
        $c['i18n'][$lang]['privacy'] = $privacy[$lang];
        $changes[] = sprintf('%s: เพิ่มเนื้อหานโยบาย %d หัวข้อ',
            $lang, count($privacy[$lang]['sections']));
    }

    if (isset($c['i18n'][$lang]['form']) && !array_key_exists('privacyLink', $c['i18n'][$lang]['form'])) {
        $c['i18n'][$lang]['form']['privacyLink'] = $formLink[$lang];
        $changes[] = "$lang: เพิ่มลิงก์นโยบายใต้ช่องยินยอมในฟอร์ม";
    }

    /* ต่อลิงก์ให้รายการแรกของแถวข้อกำหนด ซึ่งคือนโยบายความเป็นส่วนตัว
       รายการต้องเป็นรูปแบบใหม่ {ข้อความ, ลิงก์} แล้ว ไม่งั้นไม่มีที่ให้ใส่ลิงก์ */
    $legal = $c['i18n'][$lang]['footer']['legal'] ?? null;
    if (!is_array($legal) || !isset($legal[0]) || !is_array($legal[0])) {
        fwrite(STDERR, "ยังไม่ได้แปลงลิงก์ท้ายเว็บเป็นรูปแบบใหม่ — ให้รัน php bin/link-footer.php ก่อน\n");
        exit(1);
    }
    if (($legal[0]['href'] ?? '') === '') {
        $c['i18n'][$lang]['footer']['legal'][0]['href'] = 'privacy.html';
        $changes[] = sprintf('%s: ลิงก์ท้ายเว็บ "%s" → privacy.html',
            $lang, (string) ($legal[0]['label'] ?? ''));
    } else {
        $skipped[] = "$lang: ลิงก์ท้ายเว็บรายการแรกมีปลายทางอยู่แล้ว";
    }
}

if ($changes === []) {
    echo "ไม่มีอะไรต้องเปลี่ยน\n";
    foreach ($skipped as $s) {
        echo '  • ' . $s . "\n";
    }
    exit(0);
}

Content::validate($c);

if ($useJson) {
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    /* JSON_PRETTY_PRINT ของ PHP ย่อหน้าด้วย 4 ช่อง แต่ content.json ในโปรเจกต์นี้ใช้ 2 ช่อง
       ถ้าไม่ลดลงครึ่งหนึ่ง ไฟล์ทั้งไฟล์จะขึ้นเป็นบรรทัดที่เปลี่ยนไปหมด ทั้งที่แก้จริงไม่กี่จุด
       ปลอดภัยเพราะ JSON ที่ pretty-print แล้วไม่มีสตริงที่ขึ้นบรรทัดใหม่ (\n ถูก escape ไว้) */
    $json = (string) preg_replace_callback('/^( +)/m',
        static fn(array $m): string => str_repeat(' ', (int) (strlen($m[1]) / 2)), $json);
    file_put_contents($jsonPath, $json . "\n");
    echo "แก้ content.json เรียบร้อย\n";
} else {
    Content::saveDraft($c, 1);
    echo "อัปเดตฉบับร่างเรียบร้อย\n";
}

foreach ($changes as $x) {
    echo '  • ' . $x . "\n";
}
foreach ($skipped as $s) {
    echo '  – ข้าม: ' . $s . "\n";
}

if (!$useJson) {
    echo "\nนี่คือการแก้ \"ฉบับร่าง\" เท่านั้น หน้าเว็บจริงยังไม่เปลี่ยน\n";
    echo "ให้เข้าหลังบ้าน ดูตัวอย่าง แล้วกด \"เผยแพร่\" เมื่อพอใจ\n";
}

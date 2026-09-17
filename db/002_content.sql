-- FMK Intertrade CMS — schema 002 (Phase 3: เนื้อหาเว็บไซต์)
--
-- เก็บเนื้อหาเป็นเอกสาร JSON ทั้งก้อน ไม่แตกเป็นตารางย่อย เพราะ:
--   1. รูปทรงข้อมูลต้องตรงกับที่หน้าเว็บใช้อยู่แล้วเป๊ะ ๆ แตกตารางแล้วต้องประกอบกลับ เสี่ยงเพี้ยน
--   2. เนื้อหาทั้งเว็บมีขนาดแค่ ~40 KB อ่านทีเดียวจบ ไม่มีปัญหาประสิทธิภาพ
--   3. เก็บประวัติและย้อนกลับเวอร์ชันทำได้ตรงไปตรงมา

SET NAMES utf8mb4;

-- ฉบับร่าง — มีแถวเดียวเสมอ (id = 1) คือสิ่งที่ผู้ดูแลกำลังแก้อยู่
CREATE TABLE IF NOT EXISTS content_draft (
  id         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  data       LONGTEXT         NOT NULL,
  updated_by INT UNSIGNED         NULL DEFAULT NULL,
  updated_at DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_draft_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ทุกครั้งที่กดเผยแพร่ จะบันทึกสำเนาไว้ที่นี่ ใช้ดูประวัติและย้อนกลับ
CREATE TABLE IF NOT EXISTS content_versions (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  data         LONGTEXT      NOT NULL,
  note         VARCHAR(255)  NOT NULL DEFAULT '',
  published_by INT UNSIGNED      NULL DEFAULT NULL,
  published_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_current   TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_versions_current (is_current),
  CONSTRAINT fk_version_user FOREIGN KEY (published_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

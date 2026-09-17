-- FMK Intertrade CMS — schema 004 (Phase 5: รับข้อความจากแบบฟอร์มติดต่อ)
--
-- ก่อนหน้านี้ฟอร์มบนหน้าเว็บเป็นแค่ตัวอย่าง ลูกค้ากรอกแล้วเห็น "ส่งสำเร็จ"
-- แต่ข้อมูลหายไปทั้งหมด ตารางนี้คือที่เก็บจริง

SET NAMES utf8mb4;

-- ------------------------------------------------------- contact_messages
CREATE TABLE IF NOT EXISTS contact_messages (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- ข้อมูลที่ลูกค้ากรอก (ความยาวเผื่อไว้มากกว่าที่ฟอร์มจำกัด เพื่อไม่ให้ STRICT mode ตัดทิ้ง)
  full_name      VARCHAR(160)    NOT NULL DEFAULT '',
  company        VARCHAR(160)    NOT NULL DEFAULT '',
  position       VARCHAR(160)    NOT NULL DEFAULT '',
  country        VARCHAR(80)     NOT NULL DEFAULT '',
  email          VARCHAR(190)    NOT NULL DEFAULT '',
  phone          VARCHAR(60)     NOT NULL DEFAULT '',
  solution       VARCHAR(120)    NOT NULL DEFAULT '',
  project_type   VARCHAR(120)    NOT NULL DEFAULT '',
  message        TEXT            NOT NULL,
  contact_method VARCHAR(40)     NOT NULL DEFAULT '',
  consent        TINYINT(1)      NOT NULL DEFAULT 0,

  -- บริบทตอนส่ง ใช้ตอบกลับให้ถูกภาษา และใช้ตามรอยตอนมีปัญหา
  lang           CHAR(2)         NOT NULL DEFAULT 'th',
  ip             VARCHAR(45)     NOT NULL DEFAULT '',
  user_agent     VARCHAR(255)    NOT NULL DEFAULT '',

  -- สถานะการอ่าน  new = ยังไม่ได้อ่าน · read = อ่านแล้ว · archived = เก็บเข้ากรุ
  status         ENUM('new','read','archived') NOT NULL DEFAULT 'new',
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at        DATETIME            NULL DEFAULT NULL,
  read_by        INT UNSIGNED        NULL DEFAULT NULL,

  PRIMARY KEY (id),
  KEY idx_messages_status_time (status, created_at),
  KEY idx_messages_time (created_at),
  KEY idx_messages_ip_time (ip, created_at),
  CONSTRAINT fk_messages_reader FOREIGN KEY (read_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- contact_blocked
-- บันทึกความพยายามที่ถูกปฏิเสธ (บอท, ส่งถี่เกินไป, ข้อมูลไม่ผ่าน)
-- แยกจากตารางข้อความจริง เพื่อไม่ให้กล่องข้อความของผู้ดูแลรก
-- แต่ยังดูย้อนหลังได้ว่ามีการยิงฟอร์มผิดปกติหรือไม่
CREATE TABLE IF NOT EXISTS contact_blocked (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(45)     NOT NULL DEFAULT '',
  reason     VARCHAR(40)     NOT NULL DEFAULT '',
  detail     VARCHAR(255)    NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_blocked_ip_time (ip, created_at),
  KEY idx_blocked_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

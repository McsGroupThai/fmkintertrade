-- FMK Intertrade CMS — schema 003 (Phase 4: คลังรูปภาพ)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS media (
  id           CHAR(32)      NOT NULL,              -- ชื่อไฟล์บนดิสก์ด้วย สุ่มมา ไม่ใช้ชื่อไฟล์ของผู้ใช้
  ext          VARCHAR(5)    NOT NULL,
  mime         VARCHAR(40)   NOT NULL,
  width        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  height       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bytes        INT UNSIGNED  NOT NULL DEFAULT 0,
  orig_name    VARCHAR(190)  NOT NULL DEFAULT '',   -- เก็บไว้ให้ผู้ใช้จำได้ว่ารูปไหน ไม่ได้ใช้เป็นชื่อไฟล์
  alt_th       VARCHAR(255)  NOT NULL DEFAULT '',
  alt_en       VARCHAR(255)  NOT NULL DEFAULT '',
  uploaded_by  INT UNSIGNED      NULL DEFAULT NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_media_created (created_at),
  CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

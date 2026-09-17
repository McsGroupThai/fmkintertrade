-- FMK Intertrade CMS — schema 001 (Phase 2: บัญชีผู้ใช้และการเข้าสู่ระบบ)
--
-- ออกแบบบน MariaDB 10.11 ที่เปิด STRICT_TRANS_TABLES
-- ทุกคอลัมน์จึงกำหนด NOT NULL + DEFAULT ชัดเจน ไม่พึ่ง implicit default

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- users
CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190)    NOT NULL,
  password_hash   VARCHAR(255)    NOT NULL,
  display_name    VARCHAR(120)    NOT NULL DEFAULT '',
  role            ENUM('admin','editor') NOT NULL DEFAULT 'editor',
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  must_change_pw  TINYINT(1)      NOT NULL DEFAULT 0,
  last_login_at   DATETIME            NULL DEFAULT NULL,
  last_login_ip   VARCHAR(45)     NOT NULL DEFAULT '',
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------- login_attempts
-- ใช้จำกัดจำนวนครั้งที่ลองรหัสผ่าน นับแยกทั้งรายอีเมลและราย IP
CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email       VARCHAR(190)    NOT NULL DEFAULT '',
  ip          VARCHAR(45)     NOT NULL DEFAULT '',
  successful  TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempts_email_time (email, created_at),
  KEY idx_attempts_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- sessions
-- เก็บ session ฝั่งเซิร์ฟเวอร์ เพื่อให้ "บังคับออกจากระบบ" ได้จริง
-- และเห็นว่ามีใครล็อกอินค้างอยู่บ้าง
CREATE TABLE IF NOT EXISTS sessions (
  id           CHAR(64)        NOT NULL,        -- sha256 ของ session id ไม่เก็บตัวจริง
  user_id      INT UNSIGNED    NOT NULL,
  ip           VARCHAR(45)     NOT NULL DEFAULT '',
  user_agent   VARCHAR(255)    NOT NULL DEFAULT '',
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expires (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ audit_log
CREATE TABLE IF NOT EXISTS audit_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED        NULL DEFAULT NULL,   -- NULL = ระบบ หรือผู้ใช้ที่ยังไม่ระบุตัว
  action     VARCHAR(64)     NOT NULL,
  entity     VARCHAR(64)     NOT NULL DEFAULT '',
  entity_id  VARCHAR(64)     NOT NULL DEFAULT '',
  detail     TEXT            NOT NULL,
  ip         VARCHAR(45)     NOT NULL DEFAULT '',
  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_user_time (user_id, created_at),
  KEY idx_audit_action_time (action, created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------ migrations
CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(64) NOT NULL,
  applied_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

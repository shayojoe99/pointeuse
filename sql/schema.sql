-- ZKTeco Attendance Middleware — Database Schema

CREATE TABLE IF NOT EXISTS `devices` (
  `id`            INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  `serial_number` VARCHAR(64)     NOT NULL,
  `ip_address`    VARCHAR(45)     DEFAULT NULL,
  `last_activity` DATETIME        DEFAULT NULL,
  `created_at`    DATETIME        DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_serial` (`serial_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users synced from the device via OPERLOG
CREATE TABLE IF NOT EXISTS `device_users` (
  `id`          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  `device_sn`   VARCHAR(64)   NOT NULL,
  `user_id`     VARCHAR(32)   NOT NULL,
  `name`        VARCHAR(100)  DEFAULT NULL,
  `privilege`   TINYINT UNSIGNED DEFAULT 0  COMMENT '0=User,14=Admin',
  `card_number` VARCHAR(32)   DEFAULT NULL,
  `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_device_user` (`device_sn`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every attendance punch the device sends
CREATE TABLE IF NOT EXISTS `attendance_logs` (
  `id`          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  `device_sn`   VARCHAR(64)   NOT NULL,
  `user_id`     VARCHAR(32)   NOT NULL,
  `punch_time`  DATETIME      NOT NULL,
  `verify_type` TINYINT UNSIGNED DEFAULT 0
    COMMENT '0=Password,1=Fingerprint,2=Card,3=Face,4=Face+FP,5=FP+PW,15=Face',
  `inout_type`  TINYINT UNSIGNED DEFAULT 0
    COMMENT '0=CheckIn,1=CheckOut,2=BreakOut,3=BreakIn,4=OTIn,5=OTOut',
  `work_code`   VARCHAR(16)   DEFAULT NULL,
  `raw_data`    TEXT          DEFAULT NULL,
  `created_at`  DATETIME      DEFAULT CURRENT_TIMESTAMP,
  -- Prevents the same punch being stored twice if the device re-sends it
  UNIQUE KEY `uq_punch` (`device_sn`, `user_id`, `punch_time`),
  KEY `idx_punch_time` (`punch_time`),
  KEY `idx_user`       (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

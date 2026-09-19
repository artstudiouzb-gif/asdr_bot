-- Схема репостера. Все времена в UTC.

CREATE TABLE IF NOT EXISTS admins (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`         VARCHAR(64)  NOT NULL,
  `password_hash`    VARCHAR(255) NOT NULL,
  `telegram_user_id` BIGINT       NULL,
  `role`             ENUM('owner','admin') NOT NULL DEFAULT 'admin',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `failed_attempts`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`     DATETIME     NULL,
  `last_login_at`    DATETIME     NULL,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_username (`username`),
  UNIQUE KEY uq_admins_tg (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
  `id`           CHAR(64) PRIMARY KEY,          -- sha256 от токена в cookie
  `admin_id`     INT UNSIGNED NOT NULL,
  `ip`           VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `expires_at`   DATETIME NOT NULL,
  CONSTRAINT fk_sessions_admin FOREIGN KEY (`admin_id`) REFERENCES admins(`id`) ON DELETE CASCADE,
  KEY idx_sessions_expires (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `ip`         VARCHAR(45) NOT NULL,
  `username`   VARCHAR(64) NULL,
  `success`    TINYINT(1)  NOT NULL,
  `created_at` DATETIME    NOT NULL,
  KEY idx_attempts_ip (`ip`, `created_at`),
  KEY idx_attempts_user (`username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tg_accounts (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `label`         VARCHAR(64) NOT NULL,
  `kind`          ENUM('user','bot') NOT NULL DEFAULT 'user',
  `phone`         VARCHAR(32)  NULL,
  `token_enc`     VARBINARY(512) NULL,
  `username`      VARCHAR(64)  NULL,
  `session_path`  VARCHAR(255) NULL,
  `status`        ENUM('new','awaiting_code','awaiting_password','active','error') NOT NULL DEFAULT 'new',
  `last_error`    TEXT NULL,
  `last_check_at` DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_accounts_label (`label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tg_commands (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `account_id`  INT UNSIGNED NOT NULL,
  `command`     ENUM('login_start','login_code','login_password','logout','test_peer','resolve_peer') NOT NULL,
  `payload`     JSON NULL,
  `status`      ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  `result`      TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `executed_at` DATETIME NULL,
  CONSTRAINT fk_commands_account FOREIGN KEY (`account_id`) REFERENCES tg_accounts(`id`) ON DELETE CASCADE,
  KEY idx_commands_status (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(128) NOT NULL,
  `tg_identifier`   VARCHAR(255) NOT NULL,
  `tg_peer_id`      BIGINT NULL,
  `reader`          ENUM('mtproto','bot_admin','web') NOT NULL DEFAULT 'mtproto',
  `account_id`      INT UNSIGNED NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `fetch_limit`     SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  `last_message_id` BIGINT NOT NULL DEFAULT 0,
  `last_checked_at` DATETIME NULL,
  `last_error`      TEXT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sources_identifier (`tg_identifier`),
  KEY idx_sources_active (`is_active`),
  CONSTRAINT fk_sources_account FOREIGN KEY (`account_id`) REFERENCES tg_accounts(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS destinations (
  `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`               VARCHAR(128) NOT NULL,
  `tg_identifier`      VARCHAR(255) NOT NULL,
  `tg_peer_id`         BIGINT NULL,
  `publish_as`         ENUM('user','bot') NOT NULL DEFAULT 'user',
  `account_id`         INT UNSIGNED NULL,
  `rate_limit_per_min` SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  `last_error`         TEXT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dest_identifier (`tg_identifier`),
  CONSTRAINT fk_dest_account FOREIGN KEY (`account_id`) REFERENCES tg_accounts(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rule_sets (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(128) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rule_sets_name (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rules (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rule_set_id` INT UNSIGNED NULL,
  `name`        VARCHAR(128) NOT NULL,
  `type`        ENUM('SOCIAL_FOOTER','REMOVE_LINE','REMOVE_URL','REMOVE_BLOCK','REGEX_REPLACE',
                   'REPLACE_TEXT','APPEND_TEXT','PREPEND_TEXT','DROP_MESSAGE') NOT NULL,
  `pattern`     TEXT NULL,
  `replacement` TEXT NULL,
  `options`     JSON NULL,
  `priority`    SMALLINT NOT NULL DEFAULT 100,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rules_set (`rule_set_id`, `priority`),
  CONSTRAINT fk_rules_set FOREIGN KEY (`rule_set_id`) REFERENCES rule_sets(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signatures (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(128) NOT NULL,
  `content`    TEXT NOT NULL,
  `position`   ENUM('append','prepend') NOT NULL DEFAULT 'append',
  `separator`  VARCHAR(64) NOT NULL DEFAULT '\n\n',
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filter_sets (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(128) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS filters (
  `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `filter_set_id`    INT UNSIGNED NOT NULL,
  `kind`             ENUM('include_keyword','exclude_keyword','include_regex','exclude_regex',
                        'min_length','max_length','require_media','skip_media','skip_forwards') NOT NULL,
  `value`            VARCHAR(512) NULL,
  `case_insensitive` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_filters_set (`filter_set_id`),
  CONSTRAINT fk_filters_set FOREIGN KEY (`filter_set_id`) REFERENCES filter_sets(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routes (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `source_id`      INT UNSIGNED NOT NULL,
  `destination_id` INT UNSIGNED NOT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `delay_seconds`  INT UNSIGNED NOT NULL DEFAULT 0,
  `rule_set_id`    INT UNSIGNED NULL,
  `signature_id`   INT UNSIGNED NULL,
  `filter_set_id`  INT UNSIGNED NULL,
  `media_mode`     ENUM('all','text_only','skip_media') NOT NULL DEFAULT 'all',
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route (`source_id`, `destination_id`),
  KEY idx_routes_active (`is_active`),
  CONSTRAINT fk_routes_source FOREIGN KEY (`source_id`) REFERENCES sources(`id`) ON DELETE CASCADE,
  CONSTRAINT fk_routes_dest FOREIGN KEY (`destination_id`) REFERENCES destinations(`id`) ON DELETE CASCADE,
  CONSTRAINT fk_routes_ruleset FOREIGN KEY (`rule_set_id`) REFERENCES rule_sets(`id`) ON DELETE SET NULL,
  CONSTRAINT fk_routes_signature FOREIGN KEY (`signature_id`) REFERENCES signatures(`id`) ON DELETE SET NULL,
  CONSTRAINT fk_routes_filterset FOREIGN KEY (`filter_set_id`) REFERENCES filter_sets(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `source_id`         INT UNSIGNED NOT NULL,
  `source_message_id` BIGINT NOT NULL,
  `grouped_id`        BIGINT NULL,
  `posted_at`         DATETIME NULL,
  `raw_text`          MEDIUMTEXT NULL,
  `raw_entities`      JSON NULL,
  `media`             JSON NULL,
  `media_kind`        ENUM('none','photo','video','document','animation','audio','album','other') NOT NULL DEFAULT 'none',
  `is_forward`        TINYINT(1) NOT NULL DEFAULT 0,
  `content_hash`      CHAR(64) NULL,
  `fetched_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_messages_source_msg (`source_id`, `source_message_id`),
  KEY idx_messages_hash (`content_hash`),
  KEY idx_messages_group (`source_id`, `grouped_id`),
  CONSTRAINT fk_messages_source FOREIGN KEY (`source_id`) REFERENCES sources(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS publications (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `message_id`      INT UNSIGNED NOT NULL,
  `route_id`        INT UNSIGNED NOT NULL,
  `destination_id`  INT UNSIGNED NOT NULL,
  `status`          ENUM('NEW','PROCESSING','PUBLISHED','SKIPPED','ERROR','RETRY') NOT NULL DEFAULT 'NEW',
  `processed_text`  MEDIUMTEXT NULL,
  `skip_reason`     VARCHAR(255) NULL,
  `dest_message_id` BIGINT NULL,
  `attempts`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_error`      TEXT NULL,
  `scheduled_at`    DATETIME NOT NULL,
  `processed_at`    DATETIME NULL,
  `published_at`    DATETIME NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pub_route_message (`route_id`, `message_id`),
  KEY idx_pub_queue (`status`, `scheduled_at`),
  KEY idx_pub_dest_time (`destination_id`, `published_at`),
  CONSTRAINT fk_pub_message FOREIGN KEY (`message_id`) REFERENCES messages(`id`) ON DELETE CASCADE,
  CONSTRAINT fk_pub_route FOREIGN KEY (`route_id`) REFERENCES routes(`id`) ON DELETE CASCADE,
  CONSTRAINT fk_pub_dest FOREIGN KEY (`destination_id`) REFERENCES destinations(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
  `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `level`          ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
  `component`      VARCHAR(32) NOT NULL,
  `publication_id` INT UNSIGNED NULL,
  `source_id`      INT UNSIGNED NULL,
  `destination_id` INT UNSIGNED NULL,
  `message`        TEXT NOT NULL,
  `context`        JSON NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_logs_time (`created_at`),
  KEY idx_logs_level (`level`, `created_at`),
  KEY idx_logs_pub (`publication_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `started_at`  DATETIME NOT NULL,
  `finished_at` DATETIME NULL,
  `ingested`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `published`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `skipped`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `errors`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `duration_ms` INT UNSIGNED NULL,
  `status`      ENUM('ok','partial','locked','error') NOT NULL DEFAULT 'ok',
  `note`        VARCHAR(255) NULL,
  KEY idx_cron_started (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key`      VARCHAR(64) PRIMARY KEY,
  `value`      TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locks (
  `name`        VARCHAR(64) PRIMARY KEY,
  `holder`      VARCHAR(64) NOT NULL,
  `acquired_at` DATETIME NOT NULL,
  `expires_at`  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migrations (
  `version`    VARCHAR(64) PRIMARY KEY,
  `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

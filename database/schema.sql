-- VortexPan database schema
-- MySQL 5.7+ / MariaDB 10.3+  (默认前缀 vp_)

DROP TABLE IF EXISTS `vp_nodes`;
DROP TABLE IF EXISTS `vp_shares`;
DROP TABLE IF EXISTS `vp_users`;
DROP TABLE IF EXISTS `vp_groups`;
DROP TABLE IF EXISTS `vp_settings`;
DROP TABLE IF EXISTS `vp_logs`;
DROP TABLE IF EXISTS `vp_tokens`;

CREATE TABLE `vp_groups` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL DEFAULT '',
  `quota` BIGINT UNSIGNED NOT NULL DEFAULT 10737418240 COMMENT '空间配额，字节',
  `upload_speed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上传限速 KB/s，0不限',
  `download_speed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载限速 KB/s，0不限',
  `max_filesize` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '单文件大小上限，0继承全局',
  `can_share` TINYINT(1) NOT NULL DEFAULT 1,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `sort` INT NOT NULL DEFAULT 0,
  `remark` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户组/等级';

CREATE TABLE `vp_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id` INT UNSIGNED NOT NULL DEFAULT 1,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `email` VARCHAR(120) NOT NULL DEFAULT '',
  `phone` VARCHAR(30) NOT NULL DEFAULT '',
  `password` VARCHAR(255) NOT NULL DEFAULT '',
  `nickname` VARCHAR(64) NOT NULL DEFAULT '',
  `avatar` VARCHAR(255) NOT NULL DEFAULT '',
  `quota` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '独立配额，0继承用户组',
  `used` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `upload_speed` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_speed` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_group` (`group_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户';

CREATE TABLE `vp_nodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=根目录',
  `id_path` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '祖先链 如 0/12/33/',
  `name` VARCHAR(255) NOT NULL DEFAULT '',
  `type` ENUM('folder','file') NOT NULL DEFAULT 'file',
  `mime` VARCHAR(160) NOT NULL DEFAULT '',
  `ext` VARCHAR(20) NOT NULL DEFAULT '',
  `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `storage` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '相对 storage 的路径',
  `hash` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'sha256，用于秒传/去重',
  `star` TINYINT(1) NOT NULL DEFAULT 0,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `deleted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '回收站标记',
  `deleted_at` DATETIME NULL DEFAULT NULL,
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`uid`,`parent_id`,`deleted`),
  KEY `idx_path` (`uid`,`id_path`(255),`deleted`),
  KEY `idx_hash` (`uid`,`hash`),
  KEY `idx_name` (`uid`,`name`(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='文件/文件夹节点';

CREATE TABLE `vp_shares` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(24) NOT NULL DEFAULT '' COMMENT '短链码',
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `node_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `password` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '访问密码(hashed)',
  `pickcode` VARCHAR(24) NOT NULL DEFAULT '' COMMENT '提取码(明文短码)',
  `expire_at` DATETIME NULL DEFAULT NULL COMMENT 'NULL=永久',
  `allow_preview` TINYINT(1) NOT NULL DEFAULT 1,
  `allow_download` TINYINT(1) NOT NULL DEFAULT 1,
  `view_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `download_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `save_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_uid` (`uid`),
  KEY `idx_node` (`node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='分享';

CREATE TABLE `vp_settings` (
  `k` VARCHAR(64) NOT NULL DEFAULT '',
  `v` TEXT,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统设置';

CREATE TABLE `vp_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `type` VARCHAR(24) NOT NULL DEFAULT '',
  `action` VARCHAR(64) NOT NULL DEFAULT '',
  `target` VARCHAR(255) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_uid` (`uid`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='日志';

CREATE TABLE `vp_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `token_hash` VARCHAR(64) NOT NULL DEFAULT '',
  `expire_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='记住我令牌';

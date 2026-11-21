-- ForumHub schema
-- Требования: MySQL >= 8.0.14 (LATERAL в index.php, FULLTEXT WITH PARSER ngram).
-- MariaDB НЕ поддерживается (нет LATERAL/ngram). PHP >= 8.0, расширения: pdo_mysql, gd, fileinfo, mbstring.
--
-- Счётчики (forums.topics_count/posts_count, topics.replies_count, users.*_total):
-- INSERT обслуживают триггеры; DELETE — явный пересчёт в коде
-- (MySQL не активирует триггеры на FK-каскадах). Страховка: /admin/maintenance.php -> Пересчитать.
--
-- Первый администратор: admin / ChangeMe_123!  — СМЕНИТЕ ПАРОЛЬ СРАЗУ ПОСЛЕ ВХОДА.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
CREATE TABLE `attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `target_type` enum('topic','post') NOT NULL,
  `target_id` int NOT NULL,
  `user_id` int NOT NULL,
  `orig_name` varchar(255) NOT NULL,
  `stored_name` varchar(64) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `size_bytes` int NOT NULL,
  `is_image` tinyint(1) DEFAULT '0',
  `downloads` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_stored` (`stored_name`),
  KEY `idx_target` (`target_type`,`target_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `description` text,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort_order`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `forums` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text,
  `icon` varchar(10) DEFAULT '💬',
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `topics_count` int DEFAULT '0',
  `posts_count` int DEFAULT '0',
  `last_post_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `forum_stats_daily` (
  `day` date NOT NULL,
  `topics` int DEFAULT '0',
  `posts` int DEFAULT '0',
  `users` int DEFAULT '0',
  PRIMARY KEY (`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `moderation_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `target_type` enum('topic','post','user','forum','category') NOT NULL,
  `target_id` int NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_target` (`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` enum('reply','mention','quote','moderation') NOT NULL,
  `actor_id` int DEFAULT NULL,
  `topic_id` int DEFAULT NULL,
  `post_id` int DEFAULT NULL,
  `excerpt` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_event` (`user_id`,`type`,`post_id`,`topic_id`),
  KEY `actor_id` (`actor_id`),
  KEY `topic_id` (`topic_id`),
  KEY `post_id` (`post_id`),
  KEY `idx_user_read` (`user_id`,`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `topic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `content` mediumtext NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `edited_by` int DEFAULT NULL,
  `edit_count` int DEFAULT '0',
  `is_hidden` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_topic_created` (`topic_id`,`created_at`),
  KEY `idx_user` (`user_id`),
  FULLTEXT KEY `ft_post` (`content`) WITH PARSER `ngram`
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER `trg_posts_after_insert` AFTER INSERT ON `posts` FOR EACH ROW BEGIN
    UPDATE topics
    SET replies_count = replies_count + 1,
        last_post_at = NEW.created_at
    WHERE id = NEW.topic_id;

    UPDATE forums f
    JOIN topics t ON t.forum_id = f.id
    SET f.posts_count = f.posts_count + 1,
        f.last_post_id = NEW.id
    WHERE t.id = NEW.topic_id;
END$$
CREATE TRIGGER `trg_posts_count_insert` AFTER INSERT ON `posts` FOR EACH ROW BEGIN
    UPDATE users SET posts_total = posts_total + 1 WHERE id = NEW.user_id;
END$$
CREATE TRIGGER `trg_stats_posts` AFTER INSERT ON `posts` FOR EACH ROW BEGIN
    INSERT INTO forum_stats_daily (day, posts) VALUES (CURDATE(), 1)
    ON DUPLICATE KEY UPDATE posts = posts + 1;
END$$
DELIMITER ;

-- --------------------------------------------------------
CREATE TABLE `post_edits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `target_type` enum('topic','post') NOT NULL,
  `target_id` int NOT NULL,
  `editor_id` int NOT NULL,
  `old_title` varchar(200) DEFAULT NULL,
  `old_content` mediumtext NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `editor_id` (`editor_id`),
  KEY `idx_target` (`target_type`,`target_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action` varchar(30) NOT NULL,
  `actor_key` varchar(120) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action_actor_time` (`action`,`actor_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `target_type` enum('topic','post') NOT NULL,
  `target_id` int NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum('new','resolved','rejected') DEFAULT 'new',
  `handled_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `handled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_report` (`user_id`,`target_type`,`target_id`),
  KEY `handled_by` (`handled_by`),
  KEY `idx_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `search_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `query` varchar(200) NOT NULL,
  `results` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_query` (`query`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `settings` (
  `key` varchar(64) NOT NULL,
  `value` text NOT NULL,
  `type` enum('int','string','bool','json') NOT NULL DEFAULT 'string',
  `group` varchar(32) NOT NULL DEFAULT 'general',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `topics` (
  `id` int NOT NULL AUTO_INCREMENT,
  `forum_id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` mediumtext NOT NULL,
  `views_count` int DEFAULT '0',
  `replies_count` int DEFAULT '0',
  `is_pinned` tinyint(1) DEFAULT '0',
  `is_closed` tinyint(1) DEFAULT '0',
  `is_hidden` tinyint(1) DEFAULT '0',
  `last_post_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `edited_at` timestamp NULL DEFAULT NULL,
  `edited_by` int DEFAULT NULL,
  `edit_count` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_forum_list` (`forum_id`,`is_pinned`,`last_post_at`),
  KEY `idx_user` (`user_id`),
  FULLTEXT KEY `ft_topic` (`title`,`content`) WITH PARSER `ngram`
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER `trg_stats_topics` AFTER INSERT ON `topics` FOR EACH ROW BEGIN
    INSERT INTO forum_stats_daily (day, topics) VALUES (CURDATE(), 1)
    ON DUPLICATE KEY UPDATE topics = topics + 1;
END$$
CREATE TRIGGER `trg_topics_after_insert` AFTER INSERT ON `topics` FOR EACH ROW BEGIN
    UPDATE forums
    SET topics_count = topics_count + 1,
        posts_count = posts_count + 1
    WHERE id = NEW.forum_id;
END$$
CREATE TRIGGER `trg_topics_count_insert` AFTER INSERT ON `topics` FOR EACH ROW BEGIN
    UPDATE users SET topics_total = topics_total + 1 WHERE id = NEW.user_id;
END$$
DELIMITER ;

-- --------------------------------------------------------
CREATE TABLE `topic_subscriptions` (
  `user_id` int NOT NULL,
  `topic_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`topic_id`),
  KEY `topic_id` (`topic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` text,
  `location` varchar(100) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `signature` varchar(500) DEFAULT NULL,
  `notify_replies` tinyint(1) DEFAULT '1',
  `notify_mentions` tinyint(1) DEFAULT '1',
  `notify_email` tinyint(1) DEFAULT '0',
  `posts_total` int DEFAULT '0',
  `topics_total` int DEFAULT '0',
  `role` enum('user','moderator','admin') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_activity` (`last_activity`),
  KEY `idx_posts_total` (`posts_total`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER `trg_stats_users` AFTER INSERT ON `users` FOR EACH ROW BEGIN
    INSERT INTO forum_stats_daily (day, users) VALUES (CURDATE(), 1)
    ON DUPLICATE KEY UPDATE users = users + 1;
END$$
DELIMITER ;

-- --------------------------------------------------------
CREATE TABLE `user_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` mediumtext,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `idx_guest_seen` (`user_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
CREATE TABLE `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Внешние ключи
ALTER TABLE `attachments`
  ADD CONSTRAINT `attachments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `forums`
  ADD CONSTRAINT `forums_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;
ALTER TABLE `moderation_log`
  ADD CONSTRAINT `moderation_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_ibfk_3` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_4` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE;
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `post_edits`
  ADD CONSTRAINT `post_edits_ibfk_1` FOREIGN KEY (`editor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `search_log`
  ADD CONSTRAINT `search_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `topics`
  ADD CONSTRAINT `topics_ibfk_1` FOREIGN KEY (`forum_id`) REFERENCES `forums` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `topics_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `topic_subscriptions`
  ADD CONSTRAINT `topic_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `topic_subscriptions_ibfk_2` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE;
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------
-- Сид: первый администратор. Пароль: ChangeMe_123! — сменить сразу после входа.
INSERT INTO `users` (`username`, `email`, `password_hash`, `role`) VALUES
('admin', 'admin@example.com', '$2y$10$wH6ZkFQvJ8mYq3sXKz0OeO7rN1cT5uV9wX2yZ4aB6cD8eF0gH2iJ4', 'admin');
-- Хэш выше — заглушка формата bcrypt; реальный хэш генерируется install-скриптом ниже.
-- Если импортируете вручную, замените password_hash результатом:
--   php -r "echo password_hash('ChangeMe_123!', PASSWORD_DEFAULT);"

-- Стартовая структура
INSERT INTO `categories` (`title`, `description`, `sort_order`) VALUES
('Общий', 'Общие обсуждения', 0);
INSERT INTO `forums` (`category_id`, `title`, `description`, `icon`, `sort_order`) VALUES
(1, 'Новости и объявления', 'Официальные сообщения администрации', '📢', 0),
(1, 'Общение', 'Свободные темы', '💬', 1);

COMMIT;

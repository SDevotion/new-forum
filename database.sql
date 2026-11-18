-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Хост: localhost
-- Время создания: Ноя 18 2026 г., 23:18
-- Версия сервера: 8.4.11-11
-- Версия PHP: 8.3.36

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `kamikaze_lnf`
--

-- --------------------------------------------------------

--
-- Структура таблицы `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `forums`
--

CREATE TABLE `forums` (
  `id` int NOT NULL,
  `category_id` int NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text,
  `icon` varchar(10) DEFAULT 0xF09F92AC,
  `sort_order` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `min_role_to_view` enum('guest','user','moderator','admin') DEFAULT 'guest',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `topics_count` int DEFAULT '0',
  `posts_count` int DEFAULT '0',
  `last_post_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `forum_stats_daily`
--

CREATE TABLE `forum_stats_daily` (
  `day` date NOT NULL,
  `topics` int DEFAULT '0',
  `posts` int DEFAULT '0',
  `users` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `moderation_log`
--

CREATE TABLE `moderation_log` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `target_type` enum('topic','post','user','forum','category') NOT NULL,
  `target_id` int NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `type` enum('reply','mention','quote','moderation') NOT NULL,
  `actor_id` int DEFAULT NULL,
  `topic_id` int DEFAULT NULL,
  `post_id` int DEFAULT NULL,
  `excerpt` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `posts`
--

CREATE TABLE `posts` (
  `id` int NOT NULL,
  `topic_id` int NOT NULL,
  `user_id` int NOT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `edited_by` int DEFAULT NULL,
  `edit_count` int DEFAULT '0',
  `is_deleted` tinyint(1) DEFAULT '0',
  `is_hidden` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Триггеры `posts`
--
DELIMITER $$
CREATE TRIGGER `trg_posts_after_delete` AFTER DELETE ON `posts` FOR EACH ROW BEGIN
    DECLARE v_forum_id INT;
    DECLARE v_last DATETIME;
    DECLARE v_last_id INT;

    SELECT forum_id INTO v_forum_id FROM topics WHERE id = OLD.topic_id;

    UPDATE topics
    SET replies_count = GREATEST(replies_count - 1, 0)
    WHERE id = OLD.topic_id;

    SELECT MAX(created_at) INTO v_last FROM posts WHERE topic_id = OLD.topic_id;

    UPDATE topics
    SET last_post_at = COALESCE(v_last, created_at)
    WHERE id = OLD.topic_id;

    IF v_forum_id IS NOT NULL THEN
        UPDATE forums
        SET posts_count = GREATEST(posts_count - 1, 0)
        WHERE id = v_forum_id;

        SELECT p.id INTO v_last_id
        FROM posts p
        JOIN topics t ON t.id = p.topic_id
        WHERE t.forum_id = v_forum_id
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT 1;

        UPDATE forums SET last_post_id = v_last_id WHERE id = v_forum_id;
    END IF;
END
$$
DELIMITER ;
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_posts_count_delete` AFTER DELETE ON `posts` FOR EACH ROW BEGIN
    UPDATE users SET posts_total = GREATEST(posts_total - 1, 0) WHERE id = OLD.user_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_posts_count_insert` AFTER INSERT ON `posts` FOR EACH ROW BEGIN
    UPDATE users SET posts_total = posts_total + 1 WHERE id = NEW.user_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_stats_posts` AFTER INSERT ON `posts` FOR EACH ROW BEGIN
    INSERT INTO forum_stats_daily (day, posts) VALUES (CURDATE(), 1)
    ON DUPLICATE KEY UPDATE posts = posts + 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `post_edits`
--

CREATE TABLE `post_edits` (
  `id` int NOT NULL,
  `target_type` enum('topic','post') NOT NULL,
  `target_id` int NOT NULL,
  `editor_id` int NOT NULL,
  `old_title` varchar(200) DEFAULT NULL,
  `old_content` text NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `reports`
--

CREATE TABLE `reports` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `target_type` enum('topic','post') NOT NULL,
  `target_id` int NOT NULL,
  `reason` varchar(500) NOT NULL,
  `status` enum('new','resolved','rejected') DEFAULT 'new',
  `handled_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `handled_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `search_log`
--

CREATE TABLE `search_log` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `query` varchar(200) NOT NULL,
  `results` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `topics`
--

CREATE TABLE `topics` (
  `id` int NOT NULL,
  `forum_id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `views_count` int DEFAULT '0',
  `replies_count` int DEFAULT '0',
  `is_pinned` tinyint(1) DEFAULT '0',
  `is_closed` tinyint(1) DEFAULT '0',
  `is_hidden` tinyint(1) DEFAULT '0',
  `last_post_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `edited_at` timestamp NULL DEFAULT NULL,
  `edited_by` int DEFAULT NULL,
  `edit_count` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Триггеры `topics`
--
DELIMITER $$
CREATE TRIGGER `trg_stats_topics` AFTER INSERT ON `topics` FOR EACH ROW BEGIN
    INSERT INTO forum_stats_daily (day, topics) VALUES (CURDATE(), 1)
    ON DUPLICATE KEY UPDATE topics = topics + 1;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_topics_after_delete` AFTER DELETE ON `topics` FOR EACH ROW BEGIN
    UPDATE forums 
    SET topics_count = GREATEST(topics_count - 1, 0),
        posts_count = GREATEST(posts_count - OLD.replies_count - 1, 0)
    WHERE id = OLD.forum_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_topics_after_insert` AFTER INSERT ON `topics` FOR EACH ROW BEGIN
    UPDATE forums 
    SET topics_count = topics_count + 1,
        posts_count = posts_count + 1
    WHERE id = NEW.forum_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_topics_count_delete` AFTER DELETE ON `topics` FOR EACH ROW BEGIN
    UPDATE users SET topics_total = GREATEST(topics_total - 1, 0) WHERE id = OLD.user_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_topics_count_insert` AFTER INSERT ON `topics` FOR EACH ROW BEGIN
    UPDATE users SET topics_total = topics_total + 1 WHERE id = NEW.user_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `topic_subscriptions`
--

CREATE TABLE `topic_subscriptions` (
  `user_id` int NOT NULL,
  `topic_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
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
  `posts_total` int DEFAULT '0',
  `topics_total` int DEFAULT '0',
  `role` enum('user','moderator','admin') DEFAULT 'user',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Триггеры `users`
--
DELIMITER $$
CREATE TRIGGER `trg_stats_users` AFTER INSERT ON `users` FOR EACH ROW BEGIN
    INSERT INTO forum_stats_daily (day, users) VALUES (CURDATE(), 1)
    ON DUPLICATE KEY UPDATE users = users + 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Структура таблицы `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sort` (`sort_order`),
  ADD KEY `idx_active` (`is_active`);

--
-- Индексы таблицы `forums`
--
ALTER TABLE `forums`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category_id`),
  ADD KEY `idx_sort` (`sort_order`);

--
-- Индексы таблицы `forum_stats_daily`
--
ALTER TABLE `forum_stats_daily`
  ADD PRIMARY KEY (`day`);

--
-- Индексы таблицы `moderation_log`
--
ALTER TABLE `moderation_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_target` (`target_type`,`target_id`);

--
-- Индексы таблицы `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_event` (`user_id`,`type`,`post_id`,`topic_id`),
  ADD KEY `actor_id` (`actor_id`),
  ADD KEY `topic_id` (`topic_id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`,`created_at`);

--
-- Индексы таблицы `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_topic_created` (`topic_id`,`created_at`),
  ADD KEY `idx_user` (`user_id`);
ALTER TABLE `posts` ADD FULLTEXT KEY `ft_post` (`content`) WITH PARSER `ngram`;

--
-- Индексы таблицы `post_edits`
--
ALTER TABLE `post_edits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `editor_id` (`editor_id`),
  ADD KEY `idx_target` (`target_type`,`target_id`,`created_at`);

--
-- Индексы таблицы `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_report` (`user_id`,`target_type`,`target_id`),
  ADD KEY `handled_by` (`handled_by`),
  ADD KEY `idx_status` (`status`,`created_at`);

--
-- Индексы таблицы `search_log`
--
ALTER TABLE `search_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_query` (`query`),
  ADD KEY `idx_created` (`created_at`);

--
-- Индексы таблицы `topics`
--
ALTER TABLE `topics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_forum_list` (`forum_id`,`is_pinned`,`last_post_at`),
  ADD KEY `idx_user` (`user_id`);
ALTER TABLE `topics` ADD FULLTEXT KEY `ft_topic` (`title`,`content`) WITH PARSER `ngram`;

--
-- Индексы таблицы `topic_subscriptions`
--
ALTER TABLE `topic_subscriptions`
  ADD PRIMARY KEY (`user_id`,`topic_id`),
  ADD KEY `topic_id` (`topic_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_activity` (`last_activity`),
  ADD KEY `idx_posts_total` (`posts_total`);

--
-- Индексы таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`session_token`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `forums`
--
ALTER TABLE `forums`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `moderation_log`
--
ALTER TABLE `moderation_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `posts`
--
ALTER TABLE `posts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `post_edits`
--
ALTER TABLE `post_edits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `search_log`
--
ALTER TABLE `search_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `topics`
--
ALTER TABLE `topics`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `forums`
--
ALTER TABLE `forums`
  ADD CONSTRAINT `forums_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `moderation_log`
--
ALTER TABLE `moderation_log`
  ADD CONSTRAINT `moderation_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notifications_ibfk_3` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_4` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `post_edits`
--
ALTER TABLE `post_edits`
  ADD CONSTRAINT `post_edits_ibfk_1` FOREIGN KEY (`editor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `search_log`
--
ALTER TABLE `search_log`
  ADD CONSTRAINT `search_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `topics`
--
ALTER TABLE `topics`
  ADD CONSTRAINT `topics_ibfk_1` FOREIGN KEY (`forum_id`) REFERENCES `forums` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `topics_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `topic_subscriptions`
--
ALTER TABLE `topic_subscriptions`
  ADD CONSTRAINT `topic_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `topic_subscriptions_ibfk_2` FOREIGN KEY (`topic_id`) REFERENCES `topics` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- MySQL/MariaDB equivalent of the Laravel migration
-- 2026_10_02_190233_create_support_conversations_and_messages_tables.php.
-- Select the existing DroopNexa database in phpMyAdmin before importing.
-- Take a backup first. The users and migrations tables must already exist.
-- Safe to import again if these tables and this migration already exist.

CREATE TABLE IF NOT EXISTS `support_conversations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `customer_last_read_message_id` BIGINT UNSIGNED NULL,
    `team_last_read_message_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `support_conversations_customer_id_unique` (`customer_id`),
    CONSTRAINT `support_conversations_customer_id_foreign`
        FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `support_conversation_id` BIGINT UNSIGNED NOT NULL,
    `sender_id` BIGINT UNSIGNED NULL,
    `sender_role` VARCHAR(12) NOT NULL,
    `body` TEXT NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `support_messages_support_conversation_id_id_index` (`support_conversation_id`, `id`),
    KEY `support_messages_sender_id_foreign` (`sender_id`),
    CONSTRAINT `support_messages_support_conversation_id_foreign`
        FOREIGN KEY (`support_conversation_id`) REFERENCES `support_conversations` (`id`) ON DELETE CASCADE,
    CONSTRAINT `support_messages_sender_id_foreign`
        FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep Laravel from trying to create these tables again on the next deploy.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_02_190233_create_support_conversations_and_messages_tables', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
HAVING COUNT(CASE WHEN `migration` = '2026_10_02_190233_create_support_conversations_and_messages_tables' THEN 1 END) = 0;

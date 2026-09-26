-- Import into the production MySQL database used by DroopNexa.
-- This matches backend/database/migrations/2026_09_26_181525_create_site_contact_settings_table.php.
-- The final statement records the migration so a later cPanel deployment will not recreate the table.

CREATE TABLE IF NOT EXISTS `site_contact_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(40) DEFAULT NULL,
  `whatsapp` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_26_181525_create_site_contact_settings_table', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
HAVING COALESCE(SUM(`migration` = '2026_09_26_181525_create_site_contact_settings_table'), 0) = 0;

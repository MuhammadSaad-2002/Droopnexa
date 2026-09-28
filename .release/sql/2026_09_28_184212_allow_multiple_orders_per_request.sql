-- MySQL/MariaDB equivalent of the Laravel migration
-- 2026_09_28_184212_allow_multiple_orders_per_request.php.
-- Import into the existing DroopNexa database after taking a backup.
-- The earlier application migrations must already be installed.
-- Each step can be rerun if an import stops partway through.

SET @add_request_index = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND INDEX_NAME = 'orders_order_request_id_index') = 0,
    'ALTER TABLE `orders` ADD INDEX `orders_order_request_id_index` (`order_request_id`)',
    'DO 0'
);
PREPARE droopnexa_statement FROM @add_request_index;
EXECUTE droopnexa_statement;
DEALLOCATE PREPARE droopnexa_statement;

SET @remove_request_unique = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'orders'
       AND INDEX_NAME = 'orders_order_request_id_unique') > 0,
    'ALTER TABLE `orders` DROP INDEX `orders_order_request_id_unique`',
    'DO 0'
);
PREPARE droopnexa_statement FROM @remove_request_unique;
EXECUTE droopnexa_statement;
DEALLOCATE PREPARE droopnexa_statement;

-- Reopen only finalized requests with selected products that do not yet have
-- an order. Cancelled and rejected orders do not count as processed products.
UPDATE `order_requests` AS `request`
LEFT JOIN (
    SELECT `order_request_id`, COUNT(*) AS `selected_count`
    FROM `order_request_items`
    GROUP BY `order_request_id`
) AS `selected` ON `selected`.`order_request_id` = `request`.`id`
LEFT JOIN (
    SELECT `orders`.`order_request_id`, COUNT(DISTINCT `orders`.`product_id`) AS `ordered_count`
    FROM `orders`
    INNER JOIN `order_request_items` AS `item`
        ON `item`.`order_request_id` = `orders`.`order_request_id`
       AND `item`.`product_id` = `orders`.`product_id`
    WHERE `orders`.`status` NOT IN ('cancelled', 'rejected')
    GROUP BY `orders`.`order_request_id`
) AS `ordered` ON `ordered`.`order_request_id` = `request`.`id`
SET `request`.`status` = CASE
    WHEN COALESCE(`ordered`.`ordered_count`, 0) = 0 THEN 'under_review'
    ELSE 'partially_ordered'
END
WHERE `request`.`status` = 'product_finalized'
  AND COALESCE(`selected`.`selected_count`, 0) > COALESCE(`ordered`.`ordered_count`, 0);

-- Record the migration so a later `php artisan migrate` does not run it again.
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_09_28_184212_allow_multiple_orders_per_request', COALESCE(MAX(`batch`), 0) + 1
FROM `migrations`
HAVING COUNT(CASE WHEN `migration` = '2026_09_28_184212_allow_multiple_orders_per_request' THEN 1 END) = 0;

-- ============================================================
-- 3-bosqich migratsiyasi: Olib ketish muddati va avtomatik rad etish
-- ============================================================

ALTER TABLE `reservations`
  ADD COLUMN IF NOT EXISTS `pickup_days` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `reserved_date`,
  ADD COLUMN IF NOT EXISTS `pickup_deadline` DATETIME NULL AFTER `pickup_days`,
  ADD KEY IF NOT EXISTS `idx_res_deadline` (`status`, `pickup_deadline`);

-- Mavjud yozuvlar uchun muddatni to'ldiramiz
UPDATE `reservations`
SET `pickup_deadline` = DATE_ADD(`created_at`, INTERVAL `pickup_days` DAY)
WHERE `pickup_deadline` IS NULL;

ALTER TABLE `reservations`
  MODIFY COLUMN `pickup_deadline` DATETIME NOT NULL;


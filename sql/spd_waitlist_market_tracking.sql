-- Deeper Than Skin market-day waitlist tracking metadata.
-- Safe to run more than once on MySQL/MariaDB. Existing rows remain valid.

DELIMITER $$

DROP PROCEDURE IF EXISTS spd_add_waitlist_market_tracking $$
CREATE PROCEDURE spd_add_waitlist_market_tracking()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND COLUMN_NAME = 'source_device'
  ) THEN
    ALTER TABLE spd_waitlist ADD COLUMN source_device VARCHAR(80) NULL AFTER source;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND COLUMN_NAME = 'event_slug'
  ) THEN
    ALTER TABLE spd_waitlist ADD COLUMN event_slug VARCHAR(120) NULL AFTER source_device;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND COLUMN_NAME = 'event_date'
  ) THEN
    ALTER TABLE spd_waitlist ADD COLUMN event_date DATE NULL AFTER event_slug;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND COLUMN_NAME = 'campaign'
  ) THEN
    ALTER TABLE spd_waitlist ADD COLUMN campaign VARCHAR(120) NULL AFTER event_date;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND COLUMN_NAME = 'interest'
  ) THEN
    ALTER TABLE spd_waitlist ADD COLUMN interest VARCHAR(120) NULL AFTER campaign;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND COLUMN_NAME = 'sms_opt_in'
  ) THEN
    ALTER TABLE spd_waitlist ADD COLUMN sms_opt_in TINYINT(1) NOT NULL DEFAULT 0 AFTER consent;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'spd_waitlist'
      AND INDEX_NAME = 'idx_spd_waitlist_market_sync'
  ) THEN
    CREATE INDEX idx_spd_waitlist_market_sync
      ON spd_waitlist (app_slug, source, source_device, event_slug, square_sync_status);
  END IF;
END $$

CALL spd_add_waitlist_market_tracking() $$
DROP PROCEDURE IF EXISTS spd_add_waitlist_market_tracking $$

DELIMITER ;


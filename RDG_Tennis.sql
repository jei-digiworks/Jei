-- ============================================================================
-- RDG BOOKING & TRAINING MANAGEMENT SYSTEM  v2 (CORRECTED)
-- Database: MySQL 8.0+ / MariaDB 10.5+
--
-- TWO-SYSTEM ARCHITECTURE:
--   CLIENT SIDE  → users  table (clients who book, guests for walk-ins)
--   ADMIN SIDE   → admins table (single administrator, manages everything)
--
-- Key fixes from v1:
--   1. bookings     : added cancelled_at TIMESTAMP
--   2. schedules    : status 'booked' renamed to 'confirmed'
--   3. bookings     : added processed_by_admin_id FK
--   4. attendance   : added optional user_id FK for registered pax
--   5. system_config: new table (24h rule, 7-day advance, game night config)
--   6. dashboard    : trainees_today added to v_dashboard_today view
--   7. events       : auto-mark overdue payments + release expired reservations
--   8. procedure    : sp_generate_weekly_schedule (auto Tue/Thu game night)
--   9. seed         : reserved_until and pay_later due_date use dynamic NOW()
--  10. admins       : role column removed (only one type: admin)
-- ============================================================================
DROP DATABASE IF EXISTS rdg_booking;
CREATE DATABASE rdg_booking
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE rdg_booking;
-- ============================================================================
-- 0. SYSTEM CONFIG
-- ============================================================================
CREATE TABLE system_config (
  config_key    VARCHAR(100) NOT NULL,
  config_value  VARCHAR(255) NOT NULL,
  description   VARCHAR(255) NULL,
  PRIMARY KEY (config_key)
) ENGINE=InnoDB;
INSERT INTO system_config (config_key, config_value, description) VALUES
  ('pay_later_deadline_hours',  '24', 'Hours after booking before Pay Later becomes overdue'),
  ('min_booking_advance_days',   '7', 'Minimum days ahead a client can book a slot'),
  ('game_night_days',          '2,4', 'ISO weekday numbers for game night: 2=Tue,4=Thu'),
  ('game_night_start',      '19:00', 'Game night start time'),
  ('game_night_end',        '21:00', 'Game night end time'),
  ('sunday_locked',              '1', 'If 1, Sunday slots are locked; if 0, they are unlocked');
-- ============================================================================
-- 1. IDENTITY TABLES
--    TWO-SYSTEM DESIGN:
--      users  → CLIENT SIDE  (clients who book, guests for walk-ins)
--      admins → ADMIN SIDE   (single administrator who manages everything)
-- ============================================================================
CREATE TABLE users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(150) NOT NULL,
  password_hash   VARCHAR(255) NULL,
  full_name       VARCHAR(150) NOT NULL,
  phone           VARCHAR(30)  NULL,
  role            ENUM('client','guest') NOT NULL DEFAULT 'client',
  email_verified  BOOLEAN NOT NULL DEFAULT FALSE,
  is_temp_password BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB;
CREATE TABLE admins (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(150) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(150) NOT NULL,
  is_active     BOOLEAN NOT NULL DEFAULT TRUE,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;
-- ============================================================================
-- 2. PRICING & PACKAGES
-- ============================================================================
CREATE TABLE pricing_config (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coaching_rate_per_pax_hour DECIMAL(10,2) NOT NULL,
  court_rate_per_hour        DECIMAL(10,2) NOT NULL,
  is_active                  BOOLEAN NOT NULL DEFAULT TRUE,
  effective_from             DATE NOT NULL,
  effective_to               DATE NULL,
  notes                      VARCHAR(255) NULL,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pricing_active (is_active, effective_from)
) ENGINE=InnoDB;
CREATE TABLE packages (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name             VARCHAR(100) NOT NULL,
  description      VARCHAR(500) NULL,
  total_sessions   INT UNSIGNED NOT NULL,
  validity_days    INT UNSIGNED NOT NULL DEFAULT 90,
  price            DECIMAL(10,2) NOT NULL,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  is_active        BOOLEAN NOT NULL DEFAULT TRUE,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_packages_active (is_active)
) ENGINE=InnoDB;
CREATE TABLE user_packages (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  package_id         BIGINT UNSIGNED NOT NULL,
  sessions_remaining INT UNSIGNED NOT NULL,
  purchased_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at         TIMESTAMP NOT NULL,
  status             ENUM('active','expired','consumed','refunded') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_user_packages_user (user_id, status),
  CONSTRAINT fk_user_packages_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_packages_package
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- ============================================================================
-- 3. SCHEDULES  (FIX: status 'booked' renamed to 'confirmed')
-- ============================================================================
CREATE TABLE schedules (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id       BIGINT UNSIGNED NOT NULL,
  session_date   DATE NOT NULL,
  start_time     TIME NOT NULL,
  end_time       TIME NOT NULL,
  duration_hours DECIMAL(4,2) NOT NULL,
  -- 'confirmed' replaces old 'booked' to align with booking.status naming
  status         ENUM('available','reserved','confirmed','locked') NOT NULL DEFAULT 'available',
  is_game_night  BOOLEAN NOT NULL DEFAULT FALSE,
  reserved_until TIMESTAMP NULL,
  notes          VARCHAR(255) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schedules_slot (session_date, start_time),
  KEY idx_schedules_status (status, session_date),
  KEY idx_schedules_date (session_date),
  CONSTRAINT fk_schedules_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- ============================================================================
-- 4. BOOKINGS  (FIX: added cancelled_at, processed_by_admin_id)
-- ============================================================================
CREATE TABLE bookings (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_code          VARCHAR(20) NOT NULL,
  user_id               BIGINT UNSIGNED NOT NULL,
  schedule_id           BIGINT UNSIGNED NOT NULL,
  pricing_config_id     BIGINT UNSIGNED NOT NULL,
  user_package_id       BIGINT UNSIGNED NULL,
  -- admin who manually processed the booking (optional)
  processed_by_admin_id BIGINT UNSIGNED NULL,
  pax                   INT UNSIGNED NOT NULL,
  duration_hours        DECIMAL(4,2) NOT NULL,
  coaching_fee          DECIMAL(10,2) NOT NULL,
  court_fee             DECIMAL(10,2) NOT NULL,
  total_fee             DECIMAL(10,2) NOT NULL,
  status                ENUM('reserved','confirmed','completed','cancelled') NOT NULL DEFAULT 'reserved',
  cancelled_reason      VARCHAR(255) NULL,
  booked_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at          TIMESTAMP NULL,
  completed_at          TIMESTAMP NULL,
  cancelled_at          TIMESTAMP NULL,   -- FIX #1: track when cancelled
  reminded_1day_admin   TINYINT(1) NOT NULL DEFAULT 0,
  reminded_3hr_admin    TINYINT(1) NOT NULL DEFAULT 0,
  reminded_1day_player  TINYINT(1) NOT NULL DEFAULT 0,
  reminded_3hr_player   TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_code (booking_code),
  KEY idx_bookings_schedule (schedule_id),
  KEY idx_bookings_user (user_id, status),
  KEY idx_bookings_status (status),
  CONSTRAINT fk_bookings_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_schedule
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_pricing
    FOREIGN KEY (pricing_config_id) REFERENCES pricing_config(id) ON DELETE RESTRICT,
  CONSTRAINT fk_bookings_user_package
    FOREIGN KEY (user_package_id) REFERENCES user_packages(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_admin
    FOREIGN KEY (processed_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- ============================================================================
-- 5. PAYMENTS (PayMongo integration)
-- ============================================================================
CREATE TABLE payments (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id         BIGINT UNSIGNED NOT NULL,
  method             ENUM('pay_now','pay_later','package') NOT NULL,
  status             ENUM('paid','unpaid','overdue','refunded') NOT NULL DEFAULT 'unpaid',
  amount             DECIMAL(10,2) NOT NULL,
  due_date           TIMESTAMP NULL,
  paid_at            TIMESTAMP NULL,
  paymongo_intent_id VARCHAR(100) NULL,
  paymongo_source_id VARCHAR(100) NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_booking (booking_id),
  KEY idx_payments_status (status),
  KEY idx_payments_due (due_date, status),
  CONSTRAINT fk_payments_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE payment_transactions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id   BIGINT UNSIGNED NOT NULL,
  paymongo_ref VARCHAR(100) NULL,
  event_type   ENUM('checkout','success','failed','webhook','refund') NOT NULL,
  payload      JSON NULL,
  occurred_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_tx_payment (payment_id, occurred_at),
  CONSTRAINT fk_pay_tx_payment
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB;
-- ============================================================================
-- 6. SESSIONS & ATTENDANCE  (FIX: attendance now has optional user_id FK)
-- ============================================================================
CREATE TABLE sessions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id BIGINT UNSIGNED NOT NULL,
  admin_id   BIGINT UNSIGNED NOT NULL,
  status     ENUM('scheduled','in_progress','completed','no_show') NOT NULL DEFAULT 'scheduled',
  started_at TIMESTAMP NULL,
  ended_at   TIMESTAMP NULL,
  notes      TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_booking (booking_id),
  KEY idx_sessions_status (status),
  CONSTRAINT fk_sessions_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE attendance (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id    BIGINT UNSIGNED NOT NULL,
  booking_id    BIGINT UNSIGNED NOT NULL,
  -- FIX #4: optional FK for registered attendees; NULL for walk-ins
  user_id       BIGINT UNSIGNED NULL,
  attendee_name VARCHAR(150) NOT NULL,
  status        ENUM('present','absent') NOT NULL DEFAULT 'absent',
  marked_at     TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_attendance_session (session_id),
  CONSTRAINT fk_attendance_session
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- ============================================================================
-- 7. NOTIFICATIONS
-- ============================================================================
CREATE TABLE notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NULL,
  admin_id   BIGINT UNSIGNED NULL,
  booking_id BIGINT UNSIGNED NULL,
  type       ENUM('booking_confirmed','payment_reminder','session_reminder',
                  'overdue','completed','cancelled') NOT NULL,
  channel    ENUM('email','sms','in_app') NOT NULL DEFAULT 'in_app',
  message    VARCHAR(500) NOT NULL,
  is_read    BOOLEAN NOT NULL DEFAULT FALSE,
  sent_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read),
  KEY idx_notif_admin (admin_id, is_read),
  CONSTRAINT fk_notif_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- ============================================================================
-- 8. ADMIN AUDIT LOG
-- ============================================================================
CREATE TABLE admin_audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id     BIGINT UNSIGNED NOT NULL,
  action       VARCHAR(100) NOT NULL,
  entity_type  VARCHAR(50)  NOT NULL,
  entity_id    BIGINT UNSIGNED NULL,
  old_values   JSON NULL,
  new_values   JSON NULL,
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin (admin_id, performed_at),
  KEY idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
-- ============================================================================
-- 9. PASSWORD RESETS
-- ============================================================================
CREATE TABLE password_resets (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email        VARCHAR(150) NOT NULL,
  token        VARCHAR(6) NOT NULL,
  user_type    ENUM('client', 'admin') NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at   TIMESTAMP NULL
) ENGINE=InnoDB;
-- ============================================================================
-- SEED DATA
-- ============================================================================
-- Single admin account (replace password_hash with real bcrypt before production)
INSERT INTO admins (email, password_hash, full_name) VALUES
  ('rdgtennislesson@gmail.com', '$2y$10$aYCg.AUgComDHKK.Fj5bEuekBtyZJYACRXDaCR0geZUcoTH9JLPjG', 'RDG ADMIN');
-- Client-side users (role: 'client' = registered, 'guest' = walk-in/no account)
INSERT INTO users (email, password_hash, full_name, phone, role, email_verified) VALUES
  ('juan.cruz@example.com',    '$2y$10$placeholderhash4', 'Juan Cruz',    '+639171234567', 'client', TRUE),
  ('maria.santos@example.com', '$2y$10$placeholderhash5', 'Maria Santos', '+639179876543', 'client', TRUE),
  ('pedro.reyes@example.com',  '$2y$10$placeholderhash6', 'Pedro Reyes',  '+639175551234', 'client', TRUE),
  ('guest1@temp.com',          NULL,                      'Walk-in Guest', NULL,            'guest',  FALSE);
INSERT INTO pricing_config (coaching_rate_per_pax_hour, court_rate_per_hour, is_active, effective_from, notes) VALUES
  (350.00, 500.00, TRUE,  '2026-01-01', 'Standard 2026 rates'),
  (300.00, 450.00, FALSE, '2025-01-01', 'Archived 2025 rates');
INSERT INTO packages (name, description, total_sessions, validity_days, price, discount_percent) VALUES
  ('Starter Pack', '5 sessions, valid 60 days',   5,  60,  4500.00,  5.00),
  ('Pro Pack',     '10 sessions, valid 90 days',  10, 90,  8500.00, 10.00),
  ('Elite Pack',   '20 sessions, valid 180 days', 20, 180, 16000.00, 15.00);
-- Schedules — week of April 27, 2026
-- NOTE: status uses 'confirmed' instead of 'booked' (FIX #2)
INSERT INTO schedules (admin_id, session_date, start_time, end_time, duration_hours, status, is_game_night) VALUES
  (1, '2026-04-27', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-27', '10:00:00', '12:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-27', '14:00:00', '16:00:00', 2.00, 'confirmed', FALSE),
  (1, '2026-04-27', '16:00:00', '18:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-28', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-28', '10:00:00', '12:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-28', '18:00:00', '21:00:00', 3.00, 'locked',    TRUE),
  (1, '2026-04-29', '08:00:00', '10:00:00', 2.00, 'reserved',  FALSE),
  (1, '2026-04-29', '14:00:00', '16:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-30', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-30', '18:00:00', '21:00:00', 3.00, 'locked',    TRUE),
  (1, '2026-05-01', '10:00:00', '12:00:00', 2.00, 'available', FALSE),
  (1, '2026-05-01', '14:00:00', '16:00:00', 2.00, 'available', FALSE),
  (1, '2026-05-02', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (1, '2026-05-02', '10:00:00', '12:00:00', 2.00, 'available', FALSE);
-- FIX #9: reserved_until set to a future time (24h from now)
UPDATE schedules
   SET reserved_until = DATE_ADD(NOW(), INTERVAL 24 HOUR)
 WHERE session_date = '2026-04-29' AND start_time = '08:00:00';
INSERT INTO user_packages (user_id, package_id, sessions_remaining, expires_at, status) VALUES
  (2, 2, 8, '2026-07-15 23:59:59', 'active');
-- Bookings
INSERT INTO bookings
  (booking_code, user_id, schedule_id, pricing_config_id, pax, duration_hours,
   coaching_fee, court_fee, total_fee, status, confirmed_at)
VALUES
  ('BK-2026-0001', 1, 3, 1, 2, 2.00, 1400.00, 1000.00, 2400.00, 'confirmed', '2026-04-20 14:30:00');
INSERT INTO bookings
  (booking_code, user_id, schedule_id, pricing_config_id, user_package_id,
   pax, duration_hours, coaching_fee, court_fee, total_fee, status)
VALUES
  ('BK-2026-0002', 2, 8, 1, 1, 1, 2.00, 700.00, 1000.00, 1700.00, 'reserved');
INSERT INTO payments (booking_id, method, status, amount, paid_at, paymongo_intent_id) VALUES
  (1, 'pay_now', 'paid', 2400.00, '2026-04-20 14:32:15', 'pi_test_abc123xyz');
-- FIX: due_date = 24h from booked_at (not session time)
INSERT INTO payments (booking_id, method, status, amount, due_date) VALUES
  (2, 'pay_later', 'unpaid', 1700.00, DATE_ADD(NOW(), INTERVAL 24 HOUR));
INSERT INTO payment_transactions (payment_id, paymongo_ref, event_type, payload) VALUES
  (1, 'pi_test_abc123xyz', 'checkout', JSON_OBJECT('amount', 240000, 'currency', 'PHP', 'status', 'awaiting_payment')),
  (1, 'pi_test_abc123xyz', 'success',  JSON_OBJECT('amount', 240000, 'currency', 'PHP', 'status', 'succeeded', 'paid_at', '2026-04-20T14:32:15Z'));
INSERT INTO sessions (booking_id, admin_id, status) VALUES
  (1, 1, 'scheduled');
INSERT INTO attendance (session_id, booking_id, user_id, attendee_name, status) VALUES
  (1, 1, 1,    'Juan Cruz',    'absent'),
  (1, 1, NULL, 'Juan Cruz +1', 'absent');
INSERT INTO notifications (user_id, booking_id, type, channel, message) VALUES
  (1, 1, 'booking_confirmed', 'email', 'Your booking BK-2026-0001 is confirmed for Apr 27, 2-4pm.'),
  (2, 2, 'booking_confirmed', 'email', 'Your booking BK-2026-0002 is reserved. Pay within 24h to confirm.'),
  (2, 2, 'payment_reminder',  'sms',   'Reminder: Payment for BK-2026-0002 is due in 24 hours.');
INSERT INTO notifications (admin_id, booking_id, type, channel, message) VALUES
  (1, 1, 'booking_confirmed', 'in_app', 'New paid booking: BK-2026-0001 (Juan Cruz)'),
  (1, 2, 'booking_confirmed', 'in_app', 'New reserved booking: BK-2026-0002 (Maria Santos) — Pay Later');
INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, new_values, ip_address, user_agent) VALUES
  (1, 'create_schedule',  'schedules', 1,
   JSON_OBJECT('session_date', '2026-04-27', 'start_time', '08:00:00', 'duration_hours', 2.00),
   '192.168.1.10', 'Mozilla/5.0'),
  (1, 'lock_game_night',  'schedules', 7,
   JSON_OBJECT('is_game_night', TRUE, 'status', 'locked'),
   '192.168.1.10', 'Mozilla/5.0'),
  (1, 'start_session',    'sessions',  1,
   JSON_OBJECT('status', 'scheduled', 'booking_id', 1),
   '192.168.1.10', 'Mozilla/5.0');
-- ============================================================================
-- VIEWS
-- ============================================================================
-- FIX #6: added trainees_today
CREATE OR REPLACE VIEW v_dashboard_today AS
SELECT
  (SELECT COUNT(*)
     FROM schedules WHERE session_date = CURDATE())                                     AS slots_today,
  (SELECT COUNT(*)
     FROM schedules WHERE session_date = CURDATE() AND status = 'confirmed')            AS booked_today,
  (SELECT COUNT(*)
     FROM schedules WHERE session_date = CURDATE() AND status = 'reserved')             AS reserved_today,
  (SELECT COUNT(*)
     FROM schedules WHERE session_date = CURDATE() AND status = 'available')            AS available_today,
  (SELECT COUNT(*)
     FROM bookings  WHERE DATE(booked_at) = CURDATE())                                  AS new_bookings_today,
  (SELECT COALESCE(SUM(b.pax), 0)
     FROM bookings b
     JOIN schedules s ON s.id = b.schedule_id
    WHERE s.session_date = CURDATE()
      AND b.status IN ('confirmed', 'reserved'))                                        AS trainees_today,
  (SELECT COALESCE(SUM(amount), 0)
     FROM payments WHERE status = 'paid' AND DATE(paid_at) = CURDATE())                AS revenue_today;
-- Payment monitoring view
CREATE OR REPLACE VIEW v_payment_monitoring AS
SELECT
  p.id            AS payment_id,
  b.booking_code,
  u.full_name     AS client_name,
  s.session_date,
  s.start_time,
  p.method,
  p.status,
  p.amount,
  p.due_date,
  p.paid_at,
  CASE
    WHEN p.status = 'unpaid' AND p.due_date < NOW() THEN 'overdue'
    ELSE p.status
  END             AS effective_status
FROM payments  p
JOIN bookings  b ON b.id = p.booking_id
JOIN users     u ON u.id = b.user_id
JOIN schedules s ON s.id = b.schedule_id;
-- ============================================================================
-- SCHEDULED EVENT: auto-mark overdue payments  (FIX #7)
-- Requires: SET GLOBAL event_scheduler = ON;
-- ============================================================================
SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS evt_mark_overdue_payments
  ON SCHEDULE EVERY 1 HOUR
  COMMENT 'Flips unpaid payments to overdue when due_date has passed'
  DO
    UPDATE payments
       SET status = 'overdue'
     WHERE status = 'unpaid'
       AND due_date IS NOT NULL
       AND due_date < NOW();
-- Also release expired reserved slots back to available
CREATE EVENT IF NOT EXISTS evt_release_expired_reservations
  ON SCHEDULE EVERY 1 HOUR
  COMMENT 'Releases reserved slots whose reserved_until has passed'
  DO
    UPDATE schedules
       SET status = 'available', reserved_until = NULL
     WHERE status = 'reserved'
       AND reserved_until IS NOT NULL
       AND reserved_until < NOW();
-- ============================================================================
-- STORED PROCEDURE: auto-generate weekly schedule  (FIX #8)
-- Usage: CALL sp_generate_weekly_schedule('2026-05-04', 1);
-- ============================================================================
DELIMITER //
CREATE PROCEDURE sp_generate_weekly_schedule(
  IN p_week_monday DATE,
  IN p_admin_id    BIGINT UNSIGNED
)
BEGIN
  DECLARE v_day   INT DEFAULT 0;
  DECLARE v_date  DATE;
  DECLARE v_hour  INT;
  DECLARE v_start TIME;
  DECLARE v_end   TIME;
  DECLARE v_sun_locked VARCHAR(10) DEFAULT '1';
  
  SELECT config_value INTO v_sun_locked FROM system_config WHERE config_key = 'sunday_locked' LIMIT 1;
  
  WHILE v_day < 7 DO
    SET v_date = DATE_ADD(p_week_monday, INTERVAL v_day DAY);
    -- Generate 1-hour slots for 8:00 AM through 11:00 PM (08:00-23:00)
    SET v_hour = 8;
    WHILE v_hour < 24 DO
      SET v_start = SEC_TO_TIME(v_hour * 3600);
      SET v_end = SEC_TO_TIME((v_hour + 1) * 3600);
      
      IF WEEKDAY(v_date) = 6 AND (v_sun_locked = '1' OR v_sun_locked = 1) THEN
        -- Sunday is locked
        INSERT IGNORE INTO schedules
          (admin_id, session_date, start_time, end_time, duration_hours, status, is_game_night)
        VALUES
          (p_admin_id, v_date, v_start, v_end, 1.00, 'locked', FALSE);
      ELSEIF WEEKDAY(v_date) IN (1, 3) AND v_hour >= 19 THEN
        -- Game Night: Tuesday (WEEKDAY=1) and Thursday (WEEKDAY=3) from 7pm onward
        INSERT IGNORE INTO schedules
          (admin_id, session_date, start_time, end_time, duration_hours, status, is_game_night)
        VALUES
          (p_admin_id, v_date, v_start, v_end, 1.00, 'locked', TRUE);
      ELSE
        INSERT IGNORE INTO schedules
          (admin_id, session_date, start_time, end_time, duration_hours, status, is_game_night)
        VALUES
          (p_admin_id, v_date, v_start, v_end, 1.00, 'available', FALSE);
      END IF;
      SET v_hour = v_hour + 1;
    END WHILE;
    SET v_day = v_day + 1;
  END WHILE;
END //
DELIMITER ;
-- ============================================================================
-- RDG BOOKING & TRAINING MANAGEMENT SYSTEM
-- Database: MySQL 8.0+ / MariaDB 10.5+
-- ============================================================================

DROP DATABASE IF EXISTS rdg_booking;
CREATE DATABASE rdg_booking
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE rdg_booking;

-- ============================================================================
-- 1. IDENTITY TABLES
-- ============================================================================

CREATE TABLE users (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(150) NOT NULL,
  password_hash   VARCHAR(255) NULL,
  full_name       VARCHAR(150) NOT NULL,
  phone           VARCHAR(30) NULL,
  role            ENUM('client', 'guest') NOT NULL DEFAULT 'client',
  email_verified  BOOLEAN NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB;

CREATE TABLE admins (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(150) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  full_name       VARCHAR(150) NOT NULL,
  role            ENUM('admin', 'coach') NOT NULL DEFAULT 'admin',
  is_active       BOOLEAN NOT NULL DEFAULT TRUE,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

-- ============================================================================
-- 2. PRICING & PACKAGES (auto-computation engine source)
-- ============================================================================

CREATE TABLE pricing_config (
  id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  coaching_rate_per_pax_hour  DECIMAL(10,2) NOT NULL,
  court_rate_per_hour         DECIMAL(10,2) NOT NULL,
  is_active                   BOOLEAN NOT NULL DEFAULT TRUE,
  effective_from              DATE NOT NULL,
  effective_to                DATE NULL,
  notes                       VARCHAR(255) NULL,
  updated_at                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pricing_active (is_active, effective_from)
) ENGINE=InnoDB;

CREATE TABLE packages (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name              VARCHAR(100) NOT NULL,
  description       VARCHAR(500) NULL,
  total_sessions    INT UNSIGNED NOT NULL,
  validity_days     INT UNSIGNED NOT NULL DEFAULT 90,
  price             DECIMAL(10,2) NOT NULL,
  discount_percent  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  is_active         BOOLEAN NOT NULL DEFAULT TRUE,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_packages_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE user_packages (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  package_id          BIGINT UNSIGNED NOT NULL,
  sessions_remaining  INT UNSIGNED NOT NULL,
  purchased_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at          TIMESTAMP NOT NULL,
  status              ENUM('active', 'expired', 'consumed', 'refunded') NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_user_packages_user (user_id, status),
  CONSTRAINT fk_user_packages_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_packages_package
    FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================================
-- 3. SCHEDULES (slot locking core)
-- ============================================================================

CREATE TABLE schedules (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id        BIGINT UNSIGNED NOT NULL,
  session_date    DATE NOT NULL,
  start_time      TIME NOT NULL,
  end_time        TIME NOT NULL,
  duration_hours  DECIMAL(4,2) NOT NULL,
  status          ENUM('available', 'reserved', 'booked', 'locked') NOT NULL DEFAULT 'available',
  is_game_night   BOOLEAN NOT NULL DEFAULT FALSE,
  reserved_until  TIMESTAMP NULL,
  notes           VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schedules_slot (session_date, start_time),
  KEY idx_schedules_status (status, session_date),
  KEY idx_schedules_date (session_date),
  CONSTRAINT fk_schedules_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================================
-- 4. BOOKINGS (with computed fees snapshot)
-- ============================================================================

CREATE TABLE bookings (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_code        VARCHAR(20) NOT NULL,
  user_id             BIGINT UNSIGNED NOT NULL,
  schedule_id         BIGINT UNSIGNED NOT NULL,
  pricing_config_id   BIGINT UNSIGNED NOT NULL,
  user_package_id     BIGINT UNSIGNED NULL,
  pax                 INT UNSIGNED NOT NULL,
  duration_hours      DECIMAL(4,2) NOT NULL,
  coaching_fee        DECIMAL(10,2) NOT NULL,
  court_fee           DECIMAL(10,2) NOT NULL,
  total_fee           DECIMAL(10,2) NOT NULL,
  status              ENUM('reserved', 'confirmed', 'completed', 'cancelled') NOT NULL DEFAULT 'reserved',
  cancelled_reason    VARCHAR(255) NULL,
  booked_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at        TIMESTAMP NULL,
  completed_at        TIMESTAMP NULL,
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
    FOREIGN KEY (user_package_id) REFERENCES user_packages(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================================
-- 5. PAYMENTS (PayMongo integration)
-- ============================================================================

CREATE TABLE payments (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id          BIGINT UNSIGNED NOT NULL,
  method              ENUM('pay_now', 'pay_later', 'package') NOT NULL,
  status              ENUM('paid', 'unpaid', 'overdue', 'refunded') NOT NULL DEFAULT 'unpaid',
  amount              DECIMAL(10,2) NOT NULL,
  due_date            TIMESTAMP NULL,
  paid_at             TIMESTAMP NULL,
  paymongo_intent_id  VARCHAR(100) NULL,
  paymongo_source_id  VARCHAR(100) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_booking (booking_id),
  KEY idx_payments_status (status),
  KEY idx_payments_due (due_date, status),
  CONSTRAINT fk_payments_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE payment_transactions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id    BIGINT UNSIGNED NOT NULL,
  paymongo_ref  VARCHAR(100) NULL,
  event_type    ENUM('checkout', 'success', 'failed', 'webhook', 'refund') NOT NULL,
  payload       JSON NULL,
  occurred_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_tx_payment (payment_id, occurred_at),
  CONSTRAINT fk_pay_tx_payment
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 6. SESSIONS & ATTENDANCE
-- ============================================================================

CREATE TABLE sessions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  BIGINT UNSIGNED NOT NULL,
  admin_id    BIGINT UNSIGNED NOT NULL,
  status      ENUM('scheduled', 'in_progress', 'completed', 'no_show') NOT NULL DEFAULT 'scheduled',
  started_at  TIMESTAMP NULL,
  ended_at    TIMESTAMP NULL,
  notes       TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_booking (booking_id),
  KEY idx_sessions_status (status),
  CONSTRAINT fk_sessions_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE attendance (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id      BIGINT UNSIGNED NOT NULL,
  booking_id      BIGINT UNSIGNED NOT NULL,
  attendee_name   VARCHAR(150) NOT NULL,
  status          ENUM('present', 'absent') NOT NULL DEFAULT 'absent',
  marked_at       TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_attendance_session (session_id),
  CONSTRAINT fk_attendance_session
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_attendance_booking
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================================
-- 7. NOTIFICATIONS
-- ============================================================================

CREATE TABLE notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  admin_id    BIGINT UNSIGNED NULL,
  booking_id  BIGINT UNSIGNED NULL,
  type        ENUM('booking_confirmed', 'payment_reminder', 'session_reminder', 'overdue', 'completed', 'cancelled') NOT NULL,
  channel     ENUM('email', 'sms', 'in_app') NOT NULL DEFAULT 'in_app',
  message     VARCHAR(500) NOT NULL,
  is_read     BOOLEAN NOT NULL DEFAULT FALSE,
  sent_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id      BIGINT UNSIGNED NOT NULL,
  action        VARCHAR(100) NOT NULL,
  entity_type   VARCHAR(50) NOT NULL,
  entity_id     BIGINT UNSIGNED NULL,
  old_values    JSON NULL,
  new_values    JSON NULL,
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  performed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin (admin_id, performed_at),
  KEY idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- Admins (passwords are bcrypt placeholders — replace before production)
INSERT INTO admins (email, password_hash, full_name, role) VALUES
  ('rdgtennislesson@gmail.com',  '$2y$10$aYCg.AUgComDHKK.Fj5bEuekBtyZJYACRXDaCR0geZUcoTH9JLPjG', 'RDG ADMIN',  'admin'),
  ('coach1@rdg.com', '$2y$10$placeholderhash2', 'Coach Mike',     'coach'),
  ('coach2@rdg.com', '$2y$10$placeholderhash3', 'Coach Sarah',    'coach');

-- Users
INSERT INTO users (email, password_hash, full_name, phone, role, email_verified) VALUES
  ('juan.cruz@example.com',   '$2y$10$placeholderhash4', 'Juan Cruz',      '+639171234567', 'client', TRUE),
  ('maria.santos@example.com','$2y$10$placeholderhash5', 'Maria Santos',   '+639179876543', 'client', TRUE),
  ('pedro.reyes@example.com', '$2y$10$placeholderhash6', 'Pedro Reyes',    '+639175551234', 'client', TRUE),
  ('guest1@temp.com',         NULL,                      'Walk-in Guest',   NULL,           'guest',  FALSE);

-- Pricing config (current rates in PHP)
INSERT INTO pricing_config (coaching_rate_per_pax_hour, court_rate_per_hour, is_active, effective_from, notes) VALUES
  (350.00, 500.00, TRUE,  '2026-01-01', 'Standard 2026 rates'),
  (300.00, 450.00, FALSE, '2025-01-01', 'Archived 2025 rates');

-- Packages
INSERT INTO packages (name, description, total_sessions, validity_days, price, discount_percent) VALUES
  ('Starter Pack',     '5 sessions, valid 60 days',   5,  60,  4500.00,  5.00),
  ('Pro Pack',         '10 sessions, valid 90 days',  10, 90,  8500.00, 10.00),
  ('Elite Pack',       '20 sessions, valid 180 days', 20, 180, 16000.00, 15.00);

-- Schedules — week of April 27, 2026 (Mon-Sun)
-- Tue (Apr 28) and Thu (Apr 30) marked as game night = LOCKED
INSERT INTO schedules (admin_id, session_date, start_time, end_time, duration_hours, status, is_game_night) VALUES
  -- Monday Apr 27
  (2, '2026-04-27', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (2, '2026-04-27', '10:00:00', '12:00:00', 2.00, 'available', FALSE),
  (3, '2026-04-27', '14:00:00', '16:00:00', 2.00, 'booked',    FALSE),
  (3, '2026-04-27', '16:00:00', '18:00:00', 2.00, 'available', FALSE),
  -- Tuesday Apr 28 — GAME NIGHT (locked)
  (2, '2026-04-28', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (2, '2026-04-28', '10:00:00', '12:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-28', '18:00:00', '21:00:00', 3.00, 'locked',    TRUE),
  -- Wednesday Apr 29
  (2, '2026-04-29', '08:00:00', '10:00:00', 2.00, 'reserved',  FALSE),
  (3, '2026-04-29', '14:00:00', '16:00:00', 2.00, 'available', FALSE),
  -- Thursday Apr 30 — GAME NIGHT
  (2, '2026-04-30', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (1, '2026-04-30', '18:00:00', '21:00:00', 3.00, 'locked',    TRUE),
  -- Friday May 1
  (3, '2026-05-01', '10:00:00', '12:00:00', 2.00, 'available', FALSE),
  (3, '2026-05-01', '14:00:00', '16:00:00', 2.00, 'available', FALSE),
  -- Saturday May 2
  (2, '2026-05-02', '08:00:00', '10:00:00', 2.00, 'available', FALSE),
  (2, '2026-05-02', '10:00:00', '12:00:00', 2.00, 'available', FALSE);

-- Set the reserved slot's expiry (24h from booking)
UPDATE schedules
   SET reserved_until = '2026-04-28 09:30:00'
 WHERE session_date = '2026-04-29' AND start_time = '08:00:00';

-- User package purchase
INSERT INTO user_packages (user_id, package_id, sessions_remaining, expires_at, status) VALUES
  (2, 2, 8, '2026-07-15 23:59:59', 'active');

-- Bookings
-- Booking 1: Juan, paid (Pay Now via PayMongo) — Mon 2-4pm
INSERT INTO bookings
  (booking_code, user_id, schedule_id, pricing_config_id, pax, duration_hours,
   coaching_fee, court_fee, total_fee, status, confirmed_at)
VALUES
  ('BK-2026-0001', 1, 3, 1, 2, 2.00,
   1400.00, 1000.00, 2400.00, 'confirmed', '2026-04-20 14:30:00');

-- Booking 2: Maria, pay later (unpaid) — Wed 8-10am, slot reserved
INSERT INTO bookings
  (booking_code, user_id, schedule_id, pricing_config_id, user_package_id,
   pax, duration_hours, coaching_fee, court_fee, total_fee, status)
VALUES
  ('BK-2026-0002', 2, 8, 1, 1, 1, 2.00,
   700.00, 1000.00, 1700.00, 'reserved');

-- Payments
INSERT INTO payments (booking_id, method, status, amount, paid_at, paymongo_intent_id) VALUES
  (1, 'pay_now',   'paid',   2400.00, '2026-04-20 14:32:15', 'pi_test_abc123xyz');

INSERT INTO payments (booking_id, method, status, amount, due_date) VALUES
  (2, 'pay_later', 'unpaid', 1700.00, '2026-04-30 08:00:00');

-- PayMongo transaction log for booking 1
INSERT INTO payment_transactions (payment_id, paymongo_ref, event_type, payload) VALUES
  (1, 'pi_test_abc123xyz', 'checkout', JSON_OBJECT('amount', 240000, 'currency', 'PHP', 'status', 'awaiting_payment')),
  (1, 'pi_test_abc123xyz', 'success',  JSON_OBJECT('amount', 240000, 'currency', 'PHP', 'status', 'succeeded', 'paid_at', '2026-04-20T14:32:15Z'));

-- Sessions (only created for confirmed bookings; booking 1 is upcoming)
INSERT INTO sessions (booking_id, admin_id, status) VALUES
  (1, 3, 'scheduled');

-- Attendance rows pre-created for the 2 pax in booking 1
INSERT INTO attendance (session_id, booking_id, attendee_name, status) VALUES
  (1, 1, 'Juan Cruz',       'absent'),
  (1, 1, 'Juan Cruz +1',    'absent');

-- Notifications
INSERT INTO notifications (user_id, booking_id, type, channel, message) VALUES
  (1, 1, 'booking_confirmed', 'email', 'Your booking BK-2026-0001 is confirmed for Apr 27, 2-4pm.'),
  (2, 2, 'booking_confirmed', 'email', 'Your booking BK-2026-0002 is reserved. Pay within 24h to confirm.'),
  (2, 2, 'payment_reminder',  'sms',   'Reminder: Payment for BK-2026-0002 is due Apr 30, 8am.');

INSERT INTO notifications (admin_id, booking_id, type, channel, message) VALUES
  (1, 1, 'booking_confirmed', 'in_app', 'New paid booking: BK-2026-0001 (Juan Cruz)'),
  (1, 2, 'booking_confirmed', 'in_app', 'New reserved booking: BK-2026-0002 (Maria Santos) — Pay Later');

-- Audit log examples
INSERT INTO admin_audit_log (admin_id, action, entity_type, entity_id, new_values, ip_address, user_agent) VALUES
  (1, 'create_schedule', 'schedules', 1,
   JSON_OBJECT('session_date', '2026-04-27', 'start_time', '08:00:00', 'duration_hours', 2.00),
   '192.168.1.10', 'Mozilla/5.0'),
  (1, 'lock_game_night', 'schedules', 7,
   JSON_OBJECT('is_game_night', TRUE, 'status', 'locked'),
   '192.168.1.10', 'Mozilla/5.0'),
  (3, 'mark_attendance', 'attendance', 1,
   JSON_OBJECT('status', 'present', 'marked_at', '2026-04-27 14:05:00'),
   '192.168.1.15', 'Mozilla/5.0');

-- ============================================================================
-- USEFUL VIEWS
-- ============================================================================

-- Admin dashboard summary
CREATE OR REPLACE VIEW v_dashboard_today AS
SELECT
  (SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE())                                      AS slots_today,
  (SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE() AND status = 'booked')                AS booked_today,
  (SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE() AND status = 'reserved')              AS reserved_today,
  (SELECT COUNT(*) FROM schedules WHERE session_date = CURDATE() AND status = 'available')             AS available_today,
  (SELECT COUNT(*) FROM bookings  WHERE DATE(booked_at) = CURDATE())                                   AS new_bookings_today,
  (SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'paid' AND DATE(paid_at) = CURDATE())   AS revenue_today;

-- Payment monitoring view
CREATE OR REPLACE VIEW v_payment_monitoring AS
SELECT
  p.id              AS payment_id,
  b.booking_code,
  u.full_name       AS client_name,
  s.session_date,
  s.start_time,
  p.method,
  p.status,
  p.amount,
  p.due_date,
  p.paid_at,
  CASE
    WHEN p.status = 'unpaid' AND p.due_date < NOW() THEN 'OVERDUE'
    ELSE p.status
  END               AS effective_status
FROM payments p
JOIN bookings  b ON b.id = p.booking_id
JOIN users     u ON u.id = b.user_id
JOIN schedules s ON s.id = b.schedule_id;

-- Initial schema for Simple Event Checkout

-- Tracks applied migrations (manual for now).
CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Key/value settings (tax, SMTP, Square, etc.).
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin users for the portal.
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'full',
  password_hash VARCHAR(255) NOT NULL,
  totp_secret VARCHAR(255) NULL,
  app_2fa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Persistent admin login sessions.
CREATE TABLE IF NOT EXISTS admin_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
  INDEX (admin_id),
  UNIQUE KEY token_hash_unique (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log events (logins, purchases, lockouts, etc.).
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  event_type VARCHAR(100) NOT NULL,
  message TEXT NULL,
  ip VARCHAR(45) NULL,
  user_agent TEXT NULL,
  referrer TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (event_type),
  INDEX (admin_id),
  INDEX (created_at),
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IP-based admin login lockouts.
CREATE TABLE IF NOT EXISTS login_lockouts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  banned_until TIMESTAMP NULL,
  last_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ip_unique (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Event tickets/products.
CREATE TABLE IF NOT EXISTS events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  short_description VARCHAR(500) NOT NULL,
  long_description MEDIUMTEXT NULL,
  location VARCHAR(255) NULL,
  start_time DATETIME NULL,
  end_time DATETIME NULL,
  image_path VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  price_cents INT UNSIGNED NULL,
  stock_limit INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status),
  INDEX (start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-dimension variations for events (e.g., menu selection).
CREATE TABLE IF NOT EXISTS event_variations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  stock_limit INT UNSIGNED NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extra info collected per ticket (checkboxes, text fields, etc.).
CREATE TABLE IF NOT EXISTS event_modifiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  modifier_type VARCHAR(30) NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  options_json TEXT NULL,
  min_selected INT UNSIGNED NULL,
  max_selected INT UNSIGNED NULL,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add-on products attached to specific events.
CREATE TABLE IF NOT EXISTS addon_products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  short_description VARCHAR(500) NOT NULL,
  price_cents INT UNSIGNED NULL,
  stock_limit INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Session-backed carts.
CREATE TABLE IF NOT EXISTS carts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(64) NOT NULL,
  reserved_until TIMESTAMP NULL,
  expires_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY session_unique (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tickets added to carts.
CREATE TABLE IF NOT EXISTS cart_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (variation_id) REFERENCES event_variations(id) ON DELETE SET NULL,
  INDEX (cart_id),
  INDEX (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Modifier values captured per cart item.
CREATE TABLE IF NOT EXISTS cart_item_modifiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_item_id BIGINT UNSIGNED NOT NULL,
  modifier_id INT UNSIGNED NOT NULL,
  value TEXT NULL,
  FOREIGN KEY (cart_item_id) REFERENCES cart_items(id) ON DELETE CASCADE,
  FOREIGN KEY (modifier_id) REFERENCES event_modifiers(id) ON DELETE CASCADE,
  INDEX (cart_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add-ons attached to cart items.
CREATE TABLE IF NOT EXISTS cart_item_addons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_item_id BIGINT UNSIGNED NOT NULL,
  addon_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (cart_item_id) REFERENCES cart_items(id) ON DELETE CASCADE,
  FOREIGN KEY (addon_id) REFERENCES addon_products(id) ON DELETE CASCADE,
  INDEX (cart_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Completed checkouts.
CREATE TABLE IF NOT EXISTS purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  total_cents INT UNSIGNED NOT NULL,
  tax1_name VARCHAR(100) NULL,
  tax1_rate DECIMAL(7,4) NULL,
  tax1_amount INT UNSIGNED NULL,
  tax2_name VARCHAR(100) NULL,
  tax2_rate DECIMAL(7,4) NULL,
  tax2_amount INT UNSIGNED NULL,
  billing_name VARCHAR(200) NULL,
  billing_line1 VARCHAR(200) NULL,
  billing_line2 VARCHAR(200) NULL,
  billing_city VARCHAR(120) NULL,
  billing_region VARCHAR(120) NULL,
  billing_postal VARCHAR(20) NULL,
  billing_country CHAR(2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CAD',
  payment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  payment_provider VARCHAR(30) NOT NULL DEFAULT 'square',
  provider_reference VARCHAR(255) NULL,
  receipt_email_sent_at TIMESTAMP NULL,
  receipt_email_error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (email),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Individual purchased tickets with QR tokens.
CREATE TABLE IF NOT EXISTS purchase_tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  qr_token CHAR(64) NOT NULL,
  checked_in_at TIMESTAMP NULL,
  checked_in_by_admin_id INT UNSIGNED NULL,
  refunded_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (variation_id) REFERENCES event_variations(id) ON DELETE SET NULL,
  FOREIGN KEY (checked_in_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  UNIQUE KEY qr_token_unique (qr_token),
  INDEX (purchase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Modifier values captured per purchased ticket.
CREATE TABLE IF NOT EXISTS purchase_ticket_modifiers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_ticket_id BIGINT UNSIGNED NOT NULL,
  modifier_id INT UNSIGNED NOT NULL,
  value TEXT NULL,
  FOREIGN KEY (purchase_ticket_id) REFERENCES purchase_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (modifier_id) REFERENCES event_modifiers(id) ON DELETE CASCADE,
  INDEX (purchase_ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add-ons attached to purchased tickets.
CREATE TABLE IF NOT EXISTS purchase_ticket_addons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_ticket_id BIGINT UNSIGNED NOT NULL,
  addon_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price_cents INT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (purchase_ticket_id) REFERENCES purchase_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (addon_id) REFERENCES addon_products(id) ON DELETE CASCADE,
  INDEX (purchase_ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Materialized stock counters for fast availability checks.
-- Regenerate from purchases/cart reservations if needed.
CREATE TABLE IF NOT EXISTS stock_counters (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  variation_id INT UNSIGNED NULL,
  stock_sold INT UNSIGNED NOT NULL DEFAULT 0,
  stock_reserved INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY stock_unique (event_id, variation_id),
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (variation_id) REFERENCES event_variations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Simple IP-based rate limiting counters.
CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  route_key VARCHAR(64) NOT NULL,
  window_start INT UNSIGNED NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY rate_limit_unique (ip, route_key, window_start),
  INDEX (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

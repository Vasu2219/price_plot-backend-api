-- ============================================================
-- PricePlot Database Schema
-- Run: mysql -u root -p < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS price_plot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE price_plot;

SET foreign_key_checks = 0;

-- ============================================================
-- 1. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    user_id       INT PRIMARY KEY AUTO_INCREMENT,
    username      VARCHAR(50)  NOT NULL,
    email         VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    auth_token    VARCHAR(64)  NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    last_login    TIMESTAMP    NULL,
    is_active     TINYINT(1)   DEFAULT 1,
    INDEX idx_email       (email),
    INDEX idx_username    (username),
    INDEX idx_auth_token  (auth_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. PRODUCTS
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    product_id        INT PRIMARY KEY AUTO_INCREMENT,
    original_url      TEXT         NOT NULL,
    url_hash          VARCHAR(64)  UNIQUE NOT NULL,
    product_name      VARCHAR(255) NOT NULL,
    product_image_url TEXT         NULL,
    category          VARCHAR(100) NULL,
    first_scraped_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    last_scraped_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    scrape_count      INT          DEFAULT 1,
    is_active         TINYINT(1)   DEFAULT 1,
    INDEX idx_url_hash    (url_hash),
    INDEX idx_category    (category),
    INDEX idx_last_scraped (last_scraped_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. PRICES  (price history per platform)
-- ============================================================
CREATE TABLE IF NOT EXISTS prices (
    price_id       INT PRIMARY KEY AUTO_INCREMENT,
    product_id     INT           NOT NULL,
    platform       VARCHAR(50)   NOT NULL,
    price          DECIMAL(10,2) NOT NULL,
    currency       VARCHAR(3)    DEFAULT 'INR',
    availability   VARCHAR(50)   NULL,
    product_link   TEXT          NULL,
    scraped_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    INDEX idx_product_id (product_id),
    INDEX idx_platform   (platform),
    INDEX idx_scraped_at (scraped_at),
    INDEX idx_price      (price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. CART
-- ============================================================
CREATE TABLE IF NOT EXISTS cart (
    cart_id    INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NOT NULL,
    product_id INT NOT NULL,
    quantity   INT DEFAULT 1,
    added_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(user_id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_product_cart (user_id, product_id),
    INDEX idx_user_id_cart (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. WISHLIST / FAVORITES
-- ============================================================
CREATE TABLE IF NOT EXISTS wishlist (
    wishlist_id    INT PRIMARY KEY AUTO_INCREMENT,
    user_id        INT           NOT NULL,
    product_id     INT           NOT NULL,
    target_price   DECIMAL(10,2) NULL,
    alert_enabled  TINYINT(1)    DEFAULT 1,
    added_at       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(user_id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_product_wish (user_id, product_id),
    INDEX idx_user_id_wish (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. NOTIFICATIONS (price drop alerts)
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id         INT           NOT NULL,
    product_id      INT           NOT NULL,
    message         TEXT          NOT NULL,
    old_price       DECIMAL(10,2) NULL,
    new_price       DECIMAL(10,2) NULL,
    is_read         TINYINT(1)    DEFAULT 0,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)    REFERENCES users(user_id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    INDEX idx_user_notif   (user_id),
    INDEX idx_notif_read   (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. PRICE CACHE (1-hour scraped result cache)
-- ============================================================
CREATE TABLE IF NOT EXISTS price_cache (
    cache_id     INT PRIMARY KEY AUTO_INCREMENT,
    url_hash     VARCHAR(64) UNIQUE NOT NULL,
    scraped_data JSON        NOT NULL,
    cached_at    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    expires_at   TIMESTAMP   NULL DEFAULT NULL,
    hit_count    INT         DEFAULT 0,
    INDEX idx_cache_hash    (url_hash),
    INDEX idx_cache_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. SCRAPE REQUEST LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS scrape_requests (
    request_id       INT PRIMARY KEY AUTO_INCREMENT,
    user_id          INT     NULL,
    product_url      TEXT    NOT NULL,
    request_ip       VARCHAR(45) NULL,
    success          TINYINT(1)  DEFAULT 1,
    error_message    TEXT    NULL,
    response_time_ms INT     NULL,
    requested_at     TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_req_user (user_id),
    INDEX idx_req_at   (requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 9. FLASH DEALS  (static fixture data managed from DB)
-- ============================================================
CREATE TABLE IF NOT EXISTS flash_deals (
    deal_id        INT PRIMARY KEY AUTO_INCREMENT,
    title          VARCHAR(255)  NOT NULL,
    emoji          VARCHAR(10)   NOT NULL,
    platform       VARCHAR(50)   NOT NULL,
    price          DECIMAL(10,2) NOT NULL,
    original_price DECIMAL(10,2) NOT NULL,
    discount_pct   INT           NOT NULL,
    deal_url       TEXT          NULL,
    badge          VARCHAR(20)   DEFAULT 'NONE',
    is_active      TINYINT(1)    DEFAULT 1,
    created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED: Flash deals fixture data
-- ============================================================
INSERT INTO flash_deals (title, emoji, platform, price, original_price, discount_pct, badge) VALUES
('Philips Air Fryer HD9200 Digital',   '🍲', 'Amazon',   6999,  9999,  30, 'WAIT'),
('Milton Thermosteel Flask 1L',        '🧴', 'Flipkart', 899,   1299,  31, 'MONITOR'),
('Prestige Pressure Cooker 5L',        '🥘', 'Amazon',   2499,  3999,  27, 'BUY_NOW'),
('Hawkins Futura Stick Fry Pan 26cm',  '🍳', 'Myntra',   1599,  2799,  27, 'BUY_NOW'),
('Wonderchef Granite Cookware Set 5pc','🫕', 'Flipkart', 5999,  8999,  33, 'WAIT'),
('boAt Airdopes 141 TWS Earbuds',      '🎧', 'Amazon',   1299,  2990,  57, 'BUY_NOW'),
('Samsung 25L Convection Microwave',   '📡', 'Flipkart', 10999, 18000, 39, 'MONITOR'),
('Skullcandy Crusher ANC 2 Headphones','🎵', 'Amazon',   8499,  13999, 39, 'MONITOR');
SET foreign_key_checks = 1;
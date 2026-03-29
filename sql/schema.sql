-- Run this SQL after selecting your database in phpMyAdmin
-- On cPanel shared hosting, the database name will be prefixed (e.g. seanw2_council_radar)

-- Municipalities being monitored
CREATE TABLE municipalities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    province CHAR(2) DEFAULT 'BC',
    platform ENUM('civicweb','escribe','custom') NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    scrape_config JSON,
    population INT DEFAULT 0,
    active TINYINT DEFAULT 1,
    last_scraped_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Individual meetings detected
CREATE TABLE meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    municipality_id INT NOT NULL,
    meeting_type VARCHAR(100),
    meeting_date DATE,
    source_url VARCHAR(500),
    raw_html LONGTEXT,
    parsed TINYINT DEFAULT 0,
    scraped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_source_url (source_url(400)),
    FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE CASCADE,
    INDEX idx_meeting_date (meeting_date),
    INDEX idx_parsed (parsed)
) ENGINE=InnoDB;

-- Parsed agenda items with keyword matches
CREATE TABLE agenda_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    item_number VARCHAR(20),
    title VARCHAR(500),
    description TEXT,
    keywords_matched JSON,
    relevance_score TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    INDEX idx_relevance (relevance_score),
    FULLTEXT idx_search (title, description)
) ENGINE=InnoDB;

-- Subscriber accounts
CREATE TABLE subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255),
    name VARCHAR(200),
    organization VARCHAR(200),
    tier ENUM('free','professional','firm') DEFAULT 'free',
    municipalities_filter JSON,
    keywords_filter JSON,
    frequency ENUM('daily','weekly') DEFAULT 'weekly',
    consent_date TIMESTAMP NOT NULL,
    consent_method VARCHAR(50) NOT NULL,
    consent_ip VARCHAR(45),
    consent_text TEXT,
    stripe_customer_id VARCHAR(100),
    stripe_subscription_id VARCHAR(100),
    active TINYINT DEFAULT 1,
    email_verified TINYINT DEFAULT 0,
    verify_token VARCHAR(64),
    reset_token VARCHAR(64),
    reset_expires TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at TIMESTAMP NULL,
    INDEX idx_tier (tier),
    INDEX idx_active (active)
) ENGINE=InnoDB;

-- Track what was sent to whom
CREATE TABLE alerts_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscriber_id INT NOT NULL,
    alert_type ENUM('daily','weekly') NOT NULL,
    subject VARCHAR(255),
    items_count INT DEFAULT 0,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    postmark_message_id VARCHAR(100),
    FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
    INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB;

-- Scrape activity log
CREATE TABLE scrape_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    municipality_id INT NOT NULL,
    status ENUM('success','error','no_new') NOT NULL,
    meetings_found INT DEFAULT 0,
    items_parsed INT DEFAULT 0,
    error_message TEXT,
    duration_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE CASCADE,
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- Rate limiting for login/signup
CREATE TABLE rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    action VARCHAR(50) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_action (ip_address, action),
    INDEX idx_attempted (attempted_at)
) ENGINE=InnoDB;

-- Seed Phase 1 municipalities
INSERT INTO municipalities (name, slug, province, platform, base_url, population) VALUES
('Parksville', 'parksville', 'BC', 'civicweb', 'https://parksville.civicweb.net', 13642),
('Kamloops', 'kamloops', 'BC', 'civicweb', 'https://kamloops.civicweb.net', 100046),
('Cranbrook', 'cranbrook', 'BC', 'civicweb', 'https://cranbrook.civicweb.net', 21286),
('Colwood', 'colwood', 'BC', 'civicweb', 'https://colwood.civicweb.net', 18961),
('Smithers', 'smithers', 'BC', 'civicweb', 'https://smithers.civicweb.net', 5401),
('Quesnel', 'quesnel', 'BC', 'civicweb', 'https://quesnel.civicweb.net', 10383),
('Trail', 'trail', 'BC', 'civicweb', 'https://trail.civicweb.net', 8043),
('Revelstoke', 'revelstoke', 'BC', 'civicweb', 'https://revelstoke.civicweb.net', 8275),
('Clearwater', 'clearwater', 'BC', 'civicweb', 'https://districtofclearwater.civicweb.net', 2458),
('Mackenzie', 'mackenzie', 'BC', 'civicweb', 'https://mackenzie.civicweb.net', 3714),
('Sun Peaks', 'sun-peaks', 'BC', 'civicweb', 'https://sunpeaks.civicweb.net', 616),
('Houston', 'houston-bc', 'BC', 'civicweb', 'https://houston.civicweb.net', 2993),
('Stewart', 'stewart', 'BC', 'civicweb', 'https://districtofstewart.civicweb.net', 594),
('Nanaimo', 'nanaimo', 'BC', 'escribe', 'https://pub-nanaimo.escribemeetings.com', 99863),
('Victoria', 'victoria', 'BC', 'escribe', 'https://pub-victoria.escribemeetings.com', 91867),
('Kelowna', 'kelowna', 'BC', 'escribe', 'https://kelownapublishing.escribemeetings.com', 144576);

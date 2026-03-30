-- BC Elected Officials tables
-- Run this SQL after the main schema.sql has been applied

-- All elected officials across all government levels
CREATE TABLE elected_officials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    government_level ENUM('provincial','municipal','regional_district','school_board') NOT NULL,
    jurisdiction_name VARCHAR(200) NOT NULL,
    municipality_id INT NULL,
    name VARCHAR(200) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role VARCHAR(100) NOT NULL,
    party VARCHAR(100) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(50) NULL,
    fax VARCHAR(50) NULL,
    office_address TEXT NULL,
    constituency_office_address TEXT NULL,
    photo_url VARCHAR(500) NULL,
    source_url VARCHAR(500) NOT NULL,
    source_name VARCHAR(100) NOT NULL,
    confidence_score TINYINT DEFAULT 1,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (municipality_id) REFERENCES municipalities(id) ON DELETE SET NULL,
    INDEX idx_gov_level (government_level),
    INDEX idx_jurisdiction (jurisdiction_name),
    INDEX idx_role (role),
    INDEX idx_confidence (confidence_score),
    INDEX idx_source (source_name),
    UNIQUE KEY uk_person_jurisdiction (name, jurisdiction_name, government_level)
) ENGINE=InnoDB;

-- Audit trail for cross-reference verification
CREATE TABLE official_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    official_id INT NOT NULL,
    source_name VARCHAR(100) NOT NULL,
    source_url VARCHAR(500),
    fields_matched JSON,
    fields_mismatched JSON NULL,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (official_id) REFERENCES elected_officials(id) ON DELETE CASCADE,
    INDEX idx_official (official_id),
    INDEX idx_verified (verified_at)
) ENGINE=InnoDB;

-- Log for officials scraping (separate from meeting scrape_log)
CREATE TABLE officials_scrape_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scraper VARCHAR(50) NOT NULL,
    government_level ENUM('provincial','municipal','regional_district','school_board') NOT NULL,
    status ENUM('success','error','partial') NOT NULL,
    officials_found INT DEFAULT 0,
    officials_inserted INT DEFAULT 0,
    officials_updated INT DEFAULT 0,
    error_message TEXT NULL,
    duration_ms INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scraper (scraper),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

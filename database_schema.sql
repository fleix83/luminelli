-- LUMINELLI Database Schema
-- UTF-8 Database with full MySQL support

USE luminelli_db;

-- Main table: sections
CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    internal_name VARCHAR(255) NOT NULL,
    position INT NOT NULL,
    media_type ENUM('image', 'video', 'youtube') NOT NULL,
    media_url VARCHAR(500) NOT NULL,
    thumbnail_url VARCHAR(500),
    has_title BOOLEAN DEFAULT 0,
    title VARCHAR(255),
    title_color VARCHAR(50) DEFAULT '#FFFFFF',
    banner_color VARCHAR(50) DEFAULT 'rgba(0,0,0,0.5)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Tags table
CREATE TABLE tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
);

-- Many-to-many relationship table for section tags
CREATE TABLE section_tags (
    section_id INT,
    tag_id INT,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id),
    PRIMARY KEY (section_id, tag_id)
);

-- Indexes for performance
CREATE INDEX idx_sections_position ON sections(position);
CREATE INDEX idx_sections_media_type ON sections(media_type);
CREATE INDEX idx_tags_name ON tags(name);
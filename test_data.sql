-- LUMINELLI Test Data
USE luminelli_db;

-- Insert test sections
INSERT INTO sections (internal_name, position, media_type, media_url, thumbnail_url, has_title, title, title_color, banner_color) VALUES
('Landscape Sunrise', 1, 'image', 'uploads/images/landscape1.jpg', 'uploads/thumbnails/landscape1_thumb.jpg', 1, 'Golden Hour', '#FFFFFF', 'rgba(0,0,0,0.4)'),
('City Night', 2, 'image', 'uploads/images/city1.jpg', 'uploads/thumbnails/city1_thumb.jpg', 1, 'Urban Dreams', '#F5CA6A', 'rgba(0,0,0,0.6)'),
('Nature Video', 3, 'video', 'uploads/videos/nature1.mp4', 'uploads/thumbnails/nature1_thumb.jpg', 0, NULL, '#FFFFFF', 'rgba(0,0,0,0.5)'),
('Architecture', 4, 'image', 'uploads/images/architecture1.jpg', 'uploads/thumbnails/architecture1_thumb.jpg', 1, 'Modern Lines', '#FFFFFF', 'rgba(0,0,0,0.3)'),
('YouTube Demo', 5, 'youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'https://img.youtube.com/vi/dQw4w9WgXcQ/maxresdefault.jpg', 1, 'Sample Video', '#FFFFFF', 'rgba(0,0,0,0.5)');

-- Insert test tags
INSERT INTO tags (name) VALUES
('nature'),
('landscape'),
('city'),
('urban'),
('architecture'),
('video'),
('golden-hour'),
('night');

-- Connect sections to tags
INSERT INTO section_tags (section_id, tag_id) VALUES
(1, 1), (1, 2), (1, 7),  -- Landscape: nature, landscape, golden-hour
(2, 3), (2, 4), (2, 8),  -- City: city, urban, night
(3, 1), (3, 6),          -- Nature Video: nature, video
(4, 5),                  -- Architecture: architecture
(5, 6);                  -- YouTube: video
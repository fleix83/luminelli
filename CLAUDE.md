# LUMINELLI - Gallery Projekt Konzept

## 🎯 Projektvision
Eine elegante, minimalistische Online-Galerie für Bilder und Videos mit immersiver Fullscreen-Präsentation und intuitivem Backend zur Content-Verwaltung.

## 🏗 Architektur-Entscheidung
**Vanilla HTML/CSS/JavaScript** Frontend mit **PHP/MySQL** Backend
- Läuft auf jedem Standard-Webhoster
- Keine komplexen Dependencies
- Einfaches Deployment via FTP
- Langfristige Wartbarkeit

## 📁 Projektstruktur
```
luminelli/
├── index.html              # Frontend Galerie
├── admin.html              # Backend Admin-Interface
├── css/
│   ├── gallery.css        # Frontend Styles
│   └── admin.css          # Backend Styles
├── js/
│   ├── gallery.js         # Frontend Logik
│   └── admin.js           # Backend Logik
├── api/
│   ├── config.php         # Datenbank-Konfiguration
│   ├── db.php             # Datenbank-Verbindung
│   ├── auth.php           # Authentication Handler
│   ├── sections.php       # Sections CRUD Operationen
│   ├── tags.php           # Tag-Verwaltung
│   ├── upload.php         # File Upload Handler
│   └── reorder.php        # Drag & Drop Reorder Handler
├── uploads/
│   ├── images/
│   ├── videos/
│   └── thumbnails/
├── includes/
│   ├── functions.php      # Helper Functions
│   └── ImageProcessor.php # Bild-Verarbeitung Klasse
└── .htaccess              # Security & URL Rewriting
```

## 🎨 Frontend Features

### Fullscreen Sections
- Jedes Bild/Video als eigene 100vh/100vw Section
- Smooth Scroll mit Snap-Points
- Keine Lightbox - direktes immersives Erlebnis

### Media-Präsentation
- **Bilder**: Responsive mit Lazy Loading
- **Videos**: Autoplay (muted) beim Eintritt in Viewport
- **YouTube**: Eingebettete iFrames mit Custom Controls

### Text-Overlays
- Optionale Titel/Banner über Medien
- Anpassbare Text- und Hintergrundfarben (mit Alpha)
- Elegante Positionierung

### Navigation
- Scroll-basierte Navigation
- Keyboard Support (Pfeiltasten)
- Touch-Gesten für Mobile
- Tag-Filter (dezent am Rand)

## 🔧 Backend Features

### Admin-Übersicht
- Liste aller Sections mit Thumbnails
- Drag & Drop zur Neuanordnung
- Quick-Delete Funktion
- Preview bei Klick
- "New Section" Button

### Section-Editor
- **Media Upload**: Bilder, Videos oder YouTube-Link
- **Titel-System**: 
  - Toggle für Titel an/aus
  - Colorpicker für Textfarbe (mit Alpha)
  - Colorpicker für Banner-Hintergrund (mit Alpha)
- **Tag-Verwaltung**: Komma-getrennte Tags
- **Interner Name**: Zur Organisation (nicht öffentlich)

## 💾 Datenbank-Schema (MySQL)

### Haupt-Tabelle: sections
```sql
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
```

### Tag-System
```sql
CREATE TABLE tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL
);

CREATE TABLE section_tags (
    section_id INT,
    tag_id INT,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES tags(id),
    PRIMARY KEY (section_id, tag_id)
);
```

## 🚀 Technologie-Stack

### Frontend
- Vanilla JavaScript (ES6+)
- CSS3 mit Custom Properties
- Intersection Observer API
- Fetch API für Backend-Kommunikation

### Backend
- PHP 7.4+ 
- MySQL 5.7+
- GD Library oder ImageMagick für Bildverarbeitung
- Session-basierte Authentication
- PDO für sichere Datenbank-Verbindungen

## 📱 Responsive Design
- Mobile-First Approach
- Touch-optimiert
- Angepasste Video-Qualitäten
- Portrait/Landscape Support

## ⚡ Performance
- Lazy Loading für Medien
- Thumbnail-Generierung mit PHP GD
- WebP mit JPEG Fallback (wenn GD/ImageMagick unterstützt)
- Gzip Kompression via .htaccess
- Browser-Caching Headers

## 🔐 Sicherheit
- Session-basierte Authentication
- Prepared Statements (PDO) gegen SQL Injection
- File-Type Validierung
- Upload-Größen Limits
- CSRF-Token für Forms
- XSS-Schutz durch htmlspecialchars()
- .htaccess Schutz für sensitive Ordner

## 📦 Deployment
1. XAMPP für lokale Entwicklung (PHP + MySQL integriert)
2. Upload via FTP zu Webhoster
3. MySQL Datenbank beim Hoster anlegen
4. config.php mit Datenbank-Zugangsdaten anpassen
5. Uploads-Ordner Schreibrechte geben (755/775)

## 🎯 MVP (Phase 1)
✅ Fullscreen Image/Video Display
✅ Smooth Scrolling
✅ Admin Upload & Management
✅ Basic Tag System
✅ Drag & Drop Reordering
✅ Title Overlays mit Farbwahl

## 🔮 Future Features (Phase 2)
- [ ] Multi-User Support mit Rollen
- [ ] Analytics Integration
- [ ] Social Sharing Meta-Tags
- [ ] Automatisches Backup System
- [ ] SEO Optimierung mit Sitemap
- [ ] PWA Support
- [ ] Bildgrößen-Varianten für verschiedene Devices
- [ ] WebP Konvertierung
- [ ] Cache-System für bessere Performance
# LUMINELLI - Implementierungsplan (PHP/MySQL)

## 📋 Phase 1: Setup & Grundstruktur (Tag 1)

### ✅ Task 1.1: Projekt-Setup
- [ ] XAMPP starten (Apache + MySQL)
- [ ] Ordnerstruktur in `/Applications/XAMPP/xamppfiles/htdocs/luminelli/` anlegen
- [ ] Git Repository initialisieren
- [ ] `.gitignore` erstellen (uploads/, config.php mit Credentials)

### ✅ Task 1.2: Datenbank Setup
- [ ] MySQL Datenbank `luminelli_db` in phpMyAdmin erstellen
- [ ] Tabellen-Schema implementieren:
  ```sql
  -- sections Tabelle
  -- tags Tabelle  
  -- section_tags Tabelle
  ```
- [ ] Test-Daten über phpMyAdmin einfügen
- [ ] Datenbank-Backup exportieren

### ✅ Task 1.3: PHP Grundstruktur
- [ ] `api/config.php` mit Datenbank-Credentials erstellen
- [ ] `api/db.php` für PDO-Verbindung
- [ ] `.htaccess` für Security und Clean URLs
- [ ] Error Reporting für Development aktivieren

---

## 📋 Phase 2: Backend API (Tag 2-3)

### ✅ Task 2.1: Database Connection
```php
// api/config.php
define('DB_HOST', 'localhost');
define('DB_NAME', 'luminelli_db');
define('DB_USER', 'root');
define('DB_PASS', '');
```
- [ ] PDO Connection Class erstellen
- [ ] Error Handling für DB-Verbindung
- [ ] UTF-8 Encoding sicherstellen

### ✅ Task 2.2: Sections API Endpoints
- [ ] `api/sections.php`:
  - [ ] `GET` - Alle Sections abrufen (mit ORDER BY position)
  - [ ] `GET` mit ID - Einzelne Section
  - [ ] `POST` - Neue Section erstellen
  - [ ] `PUT` - Section updaten
  - [ ] `DELETE` - Section löschen
- [ ] JSON Response Headers setzen
- [ ] Error Responses standardisieren

### ✅ Task 2.3: Upload Handler
- [ ] `api/upload.php`:
  - [ ] File Type Validierung (jpg, png, mp4, etc.)
  - [ ] Größen-Limits prüfen
  - [ ] Unique Filenames generieren
  - [ ] Files in uploads/ speichern
- [ ] Thumbnail-Generierung mit GD:
  - [ ] Bilder auf 400x300 resizen
  - [ ] Video-Thumbnails (erstes Frame oder Placeholder)
  - [ ] YouTube Thumbnail via API abrufen

### ✅ Task 2.4: Reorder Handler
- [ ] `api/reorder.php`:
  - [ ] POST Request mit neuer Reihenfolge
  - [ ] Batch Update der position Felder
  - [ ] Transaction für Konsistenz

### ✅ Task 2.5: Tag System
- [ ] `api/tags.php`:
  - [ ] Tags aus String extrahieren
  - [ ] Neue Tags automatisch erstellen
  - [ ] Section-Tag Verknüpfungen verwalten
  - [ ] Unused Tags cleanup (optional)

### ✅ Task 2.6: Helper Functions
- [ ] `includes/functions.php`:
  - [ ] sanitizeInput() - XSS Schutz
  - [ ] validateImageFile()
  - [ ] validateVideoFile()
  - [ ] getYouTubeID() - ID aus URL extrahieren
  - [ ] generateThumbnail() - mit GD Library

---

## 📋 Phase 3: Admin Interface (Tag 4-5)

### ✅ Task 3.1: Admin HTML Struktur
- [ ] `admin.html` Basis-Layout
- [ ] Sections-Liste Container
- [ ] Modal für Section-Editor
- [ ] Responsive Grid Layout
- [ ] Loading States

### ✅ Task 3.2: Admin CSS
- [ ] CSS Variables für Theming
- [ ] Card-Design für Sections
- [ ] Modal Overlay und Animation
- [ ] Drag & Drop Visual States
- [ ] Form Styling
- [ ] Native Color Input Styling

### ✅ Task 3.3: Admin JavaScript - Core
```javascript
// admin.js Struktur
const API_URL = '/luminelli/api/';
let sections = [];
let draggedElement = null;
```
- [ ] fetchSections() - Daten laden
- [ ] renderSections() - DOM Updates
- [ ] handleDelete() mit confirm()
- [ ] Loading/Error States

### ✅ Task 3.4: Drag & Drop Implementation
- [ ] HTML5 Drag & Drop Events
- [ ] Visual Feedback (Opacity, Cursor)
- [ ] Drop-Zone Highlighting
- [ ] Reorder Array lokal
- [ ] saveOrder() - an Backend senden
- [ ] Optimistic UI Updates

### ✅ Task 3.5: Section Editor Modal
- [ ] Modal öffnen/schließen Funktionen
- [ ] Form Validation
- [ ] File Input mit Preview:
  - [ ] Image Preview direkt anzeigen
  - [ ] Video Preview mit HTML5 Video
  - [ ] YouTube Preview via Thumbnail
- [ ] Title Toggle Show/Hide Fields
- [ ] Color Picker Implementation:
  - [ ] Native HTML5 color input
  - [ ] Alpha Channel Slider extra
- [ ] Tags Input (Komma-getrennt)
- [ ] Save via AJAX/Fetch
- [ ] FormData für File Upload

---

## 📋 Phase 4: Frontend Galerie (Tag 6-7)

### ✅ Task 4.1: HTML Struktur
- [ ] `index.html` Grundgerüst
- [ ] SEO Meta Tags
- [ ] Open Graph Tags für Sharing
- [ ] Viewport Settings für Mobile
- [ ] Noscript Fallback

### ✅ Task 4.2: Gallery CSS
- [ ] Modern CSS Reset
- [ ] Fullscreen Sections:
  ```css
  .section {
    height: 100vh;
    width: 100vw;
    scroll-snap-align: start;
  }
  ```
- [ ] Object-fit für Medien
- [ ] Title Overlay Positioning
- [ ] CSS Grid/Flexbox für Layout
- [ ] Mobile-First Media Queries

### ✅ Task 4.3: Gallery JavaScript Core
```javascript
// gallery.js
async function loadSections() {
  const response = await fetch('api/sections.php');
  const sections = await response.json();
  renderGallery(sections);
}
```
- [ ] Sections von API abrufen
- [ ] DOM dynamisch generieren
- [ ] Error Handling

### ✅ Task 4.4: Lazy Loading
- [ ] Intersection Observer Setup
- [ ] Bilder erst bei Annäherung laden
- [ ] Loading Placeholder/Skeleton
- [ ] Fade-in Animation nach Load

### ✅ Task 4.5: Media Handling
- [ ] Responsive Images:
  ```html
  <img srcset="small.jpg 480w, 
               medium.jpg 1024w,
               large.jpg 1920w"
       sizes="100vw">
  ```
- [ ] Video Autoplay bei Viewport Entry
- [ ] YouTube Lazy Embed
- [ ] Pause Video bei Viewport Exit

### ✅ Task 4.6: Navigation & Interaction
- [ ] Keyboard Event Listener (Arrow Keys)
- [ ] Touch/Swipe Detection
- [ ] Smooth Scroll to Section
- [ ] Video Click for Play/Pause
- [ ] Optional: Progress Dots

---

## 📋 Phase 5: Tag-System Frontend (Tag 8)

### ✅ Task 5.1: Tag Filter UI
- [ ] Filter-Container HTML
- [ ] Tag-Buttons generieren
- [ ] Active State Styling
- [ ] Show/Hide Animation

### ✅ Task 5.2: Filter Logic
- [ ] URL Parameters für Filter (?tags=nature,urban)
- [ ] filterSections() Funktion
- [ ] Smooth Hide/Show Transitions
- [ ] "Show All" Button
- [ ] Deep-Linking Support

---

## 📋 Phase 6: Authentifizierung (Tag 9)

### ✅ Task 6.1: PHP Session Auth
- [ ] `api/auth.php`:
  ```php
  session_start();
  if (!isset($_SESSION['admin'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
  }
  ```
- [ ] Login Form in `admin.html`
- [ ] Password mit password_hash() speichern
- [ ] Session-Check in allen Admin-APIs

### ✅ Task 6.2: Security Measures
- [ ] CSRF Token Implementation
- [ ] Session Timeout (30 Min)
- [ ] Brute Force Protection
- [ ] Secure Session Cookies
- [ ] HTTPS Enforcement (.htaccess)

---

## 📋 Phase 7: Testing & Optimierung (Tag 10)

### ✅ Task 7.1: Browser Testing
- [ ] Desktop: Chrome, Firefox, Safari
- [ ] Mobile: iOS Safari, Chrome Android
- [ ] Responsive Breakpoints testen
- [ ] Touch-Gesten auf echten Geräten

### ✅ Task 7.2: Performance Optimierung
- [ ] PageSpeed Insights Test
- [ ] Bilder komprimieren (TinyPNG)
- [ ] PHP OpCache aktivieren
- [ ] Browser Caching (.htaccess):
  ```apache
  # Cache static assets
  <FilesMatch "\.(jpg|jpeg|png|gif|ico|css|js)$">
    Header set Cache-Control "max-age=31536000, public"
  </FilesMatch>
  ```
- [ ] Gzip Compression aktivieren

### ✅ Task 7.3: Database Optimierung
- [ ] Indizes auf häufig abgefragte Spalten
- [ ] Query Optimierung (EXPLAIN)
- [ ] Prepared Statements überall

### ✅ Task 7.4: Bug Fixes
- [ ] Error Logging implementieren
- [ ] Edge Cases abfangen
- [ ] Graceful Degradation
- [ ] User Feedback bei Fehlern

---

## 📋 Phase 8: Deployment (Tag 11)

### ✅ Task 8.1: Production Vorbereitung
- [ ] Error Reporting ausschalten
- [ ] Debug-Code entfernen
- [ ] CSS/JS minifizieren
- [ ] config.php.example erstellen (ohne Credentials)

### ✅ Task 8.2: Server Upload
- [ ] Files via FTP hochladen
- [ ] Datenbank beim Hoster anlegen
- [ ] SQL Import durchführen
- [ ] config.php mit Live-Credentials
- [ ] Uploads-Ordner Permissions (755)

### ✅ Task 8.3: Documentation
- [ ] README.md schreiben:
  - [ ] Installation Steps
  - [ ] Server Requirements
  - [ ] Configuration
- [ ] Admin-Anleitung
- [ ] Troubleshooting Guide

### ✅ Task 8.4: Backup Strategy
- [ ] Datenbank-Backup Script
- [ ] Uploads-Ordner Backup
- [ ] Automatisierung via Cron (optional)

---

## 🚀 Quick Start Commands

```bash
# XAMPP starten (macOS)
sudo /Applications/XAMPP/xamppfiles/xampp start

# MySQL via Terminal
/Applications/XAMPP/xamppfiles/bin/mysql -u root -p

# Datenbank erstellen
CREATE DATABASE luminelli_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# PHP Development Server (Alternative zu XAMPP)
php -S localhost:8000

# Permissions für Uploads
chmod -R 755 uploads/
```

---

## 📝 Wichtige Dateien

### config.php Template
```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'luminelli_db');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '$2y$10$...');  // password_hash('your_password', PASSWORD_DEFAULT)
?>
```

### .htaccess Security
```apache
# Prevent directory listing
Options -Indexes

# Protect sensitive files
<FilesMatch "\.(ini|log|sh|inc|bak|config|php)$">
    Order Allow,Deny
    Deny from all
</FilesMatch>

# Protect uploads
<Directory "uploads">
    php_flag engine off
</Directory>
```

---

## ⏱ Geschätzter Zeitaufwand

- **Phase 1**: 2-3 Stunden
- **Phase 2**: 5-6 Stunden (PHP API Development)
- **Phase 3**: 6-8 Stunden (Admin Interface)
- **Phase 4**: 5-6 Stunden (Frontend Gallery)
- **Phase 5**: 2-3 Stunden (Tag System)
- **Phase 6**: 2-3 Stunden (Authentication)
- **Phase 7**: 3-4 Stunden (Testing)
- **Phase 8**: 2-3 Stunden (Deployment)

**Gesamt**: ~30-40 Stunden für MVP

---

## 🎯 Prioritäten

1. **Kritisch**: Upload-System + Thumbnail-Generierung
2. **Wichtig**: Drag & Drop Reordering
3. **Nice-to-have**: Smooth Animations & Transitions

---

## 🐛 Häufige Probleme & Lösungen

- **Upload fehlschlägt**: php.ini `upload_max_filesize` und `post_max_size` erhöhen
- **Thumbnails nicht generiert**: GD Library installieren
- **Session funktioniert nicht**: session_save_path prüfen
- **MySQL Connection refused**: XAMPP MySQL läuft nicht
- **404 auf API Calls**: .htaccess RewriteBase anpassen
# LUMINELLI Gallery System

An elegant, minimalistic online gallery for images and videos with immersive fullscreen presentation and intuitive backend for content management.

## 🚀 Quick Start

### Local Development (XAMPP)
1. Clone this repository to your XAMPP htdocs folder
2. Start XAMPP (Apache + MySQL)
3. Create database `luminelli_db` in phpMyAdmin
4. Import `database_schema.sql` and `test_data.sql`
5. Copy `api/config.php.example` to `api/config.php`
6. Update database credentials in `api/config.php`
7. Visit `http://localhost/luminelli/`

### Web Host Deployment
1. Upload all files to your web server
2. Create MySQL database on your host
3. Import `database_schema.sql` via phpMyAdmin
4. Copy `api/config.php.example` to `api/config.php`
5. Update `api/config.php` with your database credentials
6. Set directory permissions:
   ```
   chmod 755 api/ includes/
   chmod 777 uploads/ uploads/images/ uploads/videos/ uploads/thumbnails/
   ```
7. If you get Error 500, run diagnostic: `yourdomain.com/debug_deployment.php`

## 📁 Directory Structure
```
luminelli/
├── index.html              # Frontend Gallery
├── admin.html              # Backend Admin Interface  
├── css/                    # Stylesheets
├── js/                     # JavaScript files
├── api/                    # PHP API endpoints
├── includes/               # PHP helper classes
├── uploads/                # User uploaded content
│   ├── images/            # Uploaded images
│   ├── videos/            # Uploaded videos
│   └── thumbnails/        # Generated thumbnails
└── database_schema.sql     # MySQL database structure
```

## 🎯 Features

### Frontend Gallery
- **Fullscreen sections** (100vh/100vw) for immersive viewing
- **Smooth scrolling** with snap points between sections
- **Keyboard navigation** (arrow keys, space, home/end)
- **Touch gestures** for mobile devices (swipe up/down)
- **Tag filtering** system with dropdown interface
- **Title overlays** with customizable colors and transparency
- **Click-to-hide titles** for distraction-free viewing
- **Lazy loading** for optimal performance
- **Video autoplay** on viewport entry (muted)
- **YouTube embed** support

### Admin Panel
- **Drag & drop** section reordering
- **File upload** with real-time preview
- **Multi-format support** (images, videos, YouTube)
- **Thumbnail generation** with GD library
- **Title configuration** with color pickers
- **Tag management** (comma-separated)
- **Responsive design** for all devices

## 🔧 Requirements
- **PHP 7.4+** (PHP 8.0+ recommended)
- **MySQL 5.7+** or **MariaDB 10.2+**
- **GD Library** (for thumbnail generation)
- **Web server** (Apache/Nginx) with PHP support

## 📱 Browser Support
- **Chrome** 60+
- **Firefox** 60+  
- **Safari** 12+
- **Edge** 79+
- **Mobile browsers** (iOS Safari, Chrome Android)

## 🔐 Security Features
- **Input validation** and sanitization
- **SQL injection** protection (PDO prepared statements)
- **File type validation** for uploads
- **XSS protection** with htmlspecialchars()
- **Rate limiting** for API endpoints
- **Secure session handling**

## 🎨 Customization
- Modify `css/gallery.css` for frontend styling
- Edit `css/admin.css` for admin panel appearance
- Update `STYLEGUIDE.md` for design system reference
- Colors and fonts are defined in CSS custom properties

## 🐛 Troubleshooting

### Error 500 on deployment?
Run the diagnostic tool: `yourdomain.com/debug_deployment.php`

### Upload not working?
1. Check upload directory permissions (should be 777)
2. Verify GD library is installed
3. Check PHP upload limits in hosting panel

### Database connection failed?
1. Verify credentials in `api/config.php`
2. Check if database exists
3. Ensure MySQL service is running

## 📄 License
This project was built with Claude Code. Feel free to use and modify for your projects.

## 🤝 Contributing
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly  
5. Submit a pull request

---
**Built with ❤️ using vanilla PHP, JavaScript, and MySQL**
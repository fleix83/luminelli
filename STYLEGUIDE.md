# LUMINELLI - Visual Styleguide

## 🎨 Farbpalette

```css
:root {
  /* Primärfarben */
  --black: #000000;
  --white: #FAFAF8;        /* Offset-White (warm) */
  --accent: #F5CA6A;       /* Gold-Gelb Akzent */
  
  /* Hintergründe */
  --bg-primary: #FAFAF8;
  --bg-secondary: #F5F5F3;  /* Subtiler Kontrast */
  --bg-dark: #0A0A0A;       /* Fast-Schwarz */
  
  /* Grautöne */
  --gray-50: #FAFAFA;
  --gray-100: #F0F0EE;
  --gray-200: #E5E5E3;
  --gray-600: #525252;
  --gray-900: #171717;
  
  /* Funktional */
  --error: #DC2626;
  --success: #16A34A;
  --overlay: rgba(0, 0, 0, 0.6);
}
```

## 📐 Layout & Spacing

```css
/* Border Radius */
--radius-sm: 12px;
--radius-md: 20px;    /* Standard für Container */
--radius-lg: 28px;
--radius-full: 9999px;

/* Spacing Scale */
--space-xs: 8px;
--space-sm: 16px;
--space-md: 24px;
--space-lg: 32px;
--space-xl: 48px;
--space-2xl: 64px;
```

## 🔤 Typografie

```css
/* Font Import */
@import url('https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;900&display=swap');

/* Font Stack */
--font-primary: 'Nunito', -apple-system, sans-serif;

/* Sizes */
--text-xs: 12px;
--text-sm: 14px;
--text-base: 16px;
--text-lg: 18px;      /* Banner Titel */
--text-xl: 24px;
--text-2xl: 32px;
--text-3xl: 48px;

/* Weights */
--font-normal: 400;
--font-semibold: 600;
--font-bold: 700;
--font-black: 900;
```

## 🎯 Komponenten

### Container mit subtilem Kontrast
```css
.card {
  background: var(--bg-secondary);
  border-radius: var(--radius-md);
  padding: var(--space-md);
  /* Sehr subtiler Schatten für Tiefe */
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}

/* Dark Mode Variante */
.card-dark {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.06);
}
```

### Buttons
```css
/* Primär (Akzent) */
.btn-primary {
  background: var(--accent);
  color: var(--black);
  padding: 12px 24px;
  border-radius: var(--radius-full);
  font-weight: var(--font-semibold);
  font-size: var(--text-sm);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  transition: all 0.2s ease;
}

.btn-primary:hover {
  background: #E6B858;  /* 10% dunkler */
  transform: translateY(-1px);
}

/* Sekundär */
.btn-secondary {
  background: transparent;
  color: var(--gray-900);
  border: 1.5px solid var(--gray-200);
  padding: 12px 24px;
  border-radius: var(--radius-full);
}

/* Ghost */
.btn-ghost {
  background: transparent;
  color: var(--gray-600);
  padding: 8px 16px;
}
```

### Banner Titel (Frontend)
```css
.section-title {
  font-size: var(--text-lg);
  font-weight: var(--font-bold);
  text-transform: uppercase;
  letter-spacing: 1.2px;
  color: var(--white);
  
  /* Mit Banner Background */
  padding: var(--space-sm) var(--space-md);
  background: var(--banner-color, rgba(0, 0, 0, 0.4));
  backdrop-filter: blur(8px);
  border-radius: var(--radius-sm);
  display: inline-block;
}
```

### Form Elements
```css
.input {
  background: var(--white);
  border: 1.5px solid var(--gray-200);
  border-radius: var(--radius-sm);
  padding: 10px 16px;
  font-size: var(--text-base);
  transition: all 0.2s ease;
}

.input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(245, 202, 106, 0.1);
}

/* Toggle Switch */
.toggle {
  width: 48px;
  height: 26px;
  background: var(--gray-200);
  border-radius: var(--radius-full);
  transition: background 0.3s ease;
}

.toggle.active {
  background: var(--accent);
}
```

### Admin Cards (Backend)
```css
.section-card {
  background: var(--white);
  border-radius: var(--radius-md);
  padding: var(--space-sm);
  border: 1px solid var(--gray-100);
  position: relative;
  transition: all 0.2s ease;
}

.section-card:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
  transform: translateY(-2px);
}

/* Media Type Badge */
.badge {
  background: var(--gray-100);
  color: var(--gray-600);
  padding: 4px 8px;
  border-radius: var(--radius-sm);
  font-size: var(--text-xs);
  font-weight: var(--font-semibold);
  text-transform: uppercase;
}
```

## ✨ Animationen

```css
/* Smooth Transitions */
--transition-fast: 0.2s ease;
--transition-base: 0.3s ease;
--transition-slow: 0.5s ease;

/* Hover States */
@media (hover: hover) {
  .interactive:hover {
    transform: translateY(-2px);
    transition: transform var(--transition-fast);
  }
}

/* Fade In */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.fade-in {
  animation: fadeIn 0.4s ease forwards;
}
```

## 🌗 Dark Mode (Admin)

```css
[data-theme="dark"] {
  --bg-primary: #0A0A0A;
  --bg-secondary: #141414;
  --text-primary: var(--white);
  --gray-200: rgba(255, 255, 255, 0.1);
  --gray-600: rgba(255, 255, 255, 0.6);
}
```

## 📱 Responsive Breakpoints

```css
/* Mobile First */
--mobile: 640px;
--tablet: 768px;
--desktop: 1024px;
--wide: 1280px;

@media (min-width: 768px) {
  --text-lg: 20px;
  --text-2xl: 40px;
  --text-3xl: 56px;
}
```

## 🎯 Anwendungsbeispiele

### Admin Header
```css
.admin-header {
  background: var(--white);
  padding: var(--space-lg) var(--space-xl);
  border-bottom: 1px solid var(--gray-100);
}

.admin-title {
  font-size: var(--text-2xl);
  font-weight: var(--font-black);
  color: var(--black);
}
```

### Gallery Section
```css
.gallery-section {
  height: 100vh;
  width: 100vw;
  position: relative;
  background: var(--black);
}

.media-overlay {
  position: absolute;
  bottom: var(--space-xl);
  left: var(--space-xl);
  z-index: 10;
}
```

## 🚀 Quick CSS Reset

```css
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: var(--font-primary);
  font-size: var(--text-base);
  line-height: 1.6;
  color: var(--gray-900);
  background: var(--bg-primary);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
```
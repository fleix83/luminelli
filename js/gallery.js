// LUMINELLI Gallery JavaScript

// Configuration
const API_BASE = 'api/';
const UPLOAD_BASE = 'uploads/';

// Global State
let sections = [];
let filteredSections = [];
let currentSectionIndex = 0;
let intersectionObserver = null;
let isScrolling = false;
let scrollTimeout = null;
let availableTags = [];

// DOM Elements
const elements = {
    // Screens
    loadingScreen: document.getElementById('loading-screen'),
    errorScreen: document.getElementById('error-screen'),
    emptyScreen: document.getElementById('empty-screen'),
    gallery: document.getElementById('gallery'),
    
    // Navigation
    navHints: document.getElementById('nav-hints'),
    progressIndicator: document.getElementById('progress-indicator'),
    
    // Filter
    tagFilter: document.getElementById('tag-filter'),
    filterToggle: document.getElementById('btn-filter-toggle'),
    filterDropdown: document.getElementById('filter-dropdown'),
    tagList: document.getElementById('tag-list'),
    btnClearFilter: document.getElementById('btn-clear-filter'),
    
    // Error handling
    btnRetry: document.getElementById('btn-retry')
};

// Initialize Gallery
document.addEventListener('DOMContentLoaded', function() {
    initializeGallery();
});

// Main Initialization
async function initializeGallery() {
    try {
        showLoadingScreen();
        
        // Load sections from API
        const response = await fetch(API_BASE + 'sections.php');
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'Failed to load gallery');
        }
        
        sections = data.data || [];
        filteredSections = [...sections];
        
        if (sections.length === 0) {
            showEmptyScreen();
            return;
        }
        
        // Extract and setup tags
        extractTags();
        setupTagFilter();
        
        // Render gallery
        renderGallery();
        
        // Initialize features
        setupIntersectionObserver();
        setupKeyboardNavigation();
        setupProgressIndicator();
        setupEventListeners();
        
        // Show gallery
        hideLoadingScreen();
        showGallery();
        
        // Hide hints after 5 seconds
        setTimeout(hideNavigationHints, 5000);
        
    } catch (error) {
        console.error('Gallery initialization error:', error);
        showErrorScreen(error.message);
    }
}

// Screen Management
function showLoadingScreen() {
    elements.loadingScreen.style.display = 'flex';
    hideOtherScreens(['loading']);
}

function showErrorScreen(message) {
    elements.errorScreen.style.display = 'flex';
    document.getElementById('error-message').textContent = message;
    hideOtherScreens(['error']);
}

function showEmptyScreen() {
    elements.emptyScreen.style.display = 'flex';
    hideOtherScreens(['empty']);
}

function showGallery() {
    elements.gallery.style.display = 'block';
    elements.tagFilter.style.display = 'block';
    elements.progressIndicator.style.display = 'block';
    hideOtherScreens(['gallery']);
}

function hideLoadingScreen() {
    elements.loadingScreen.style.display = 'none';
}

function hideOtherScreens(except = []) {
    const screens = ['loading', 'error', 'empty'];
    screens.forEach(screen => {
        if (!except.includes(screen)) {
            const element = document.getElementById(screen + '-screen');
            if (element) element.style.display = 'none';
        }
    });
}

// Gallery Rendering
function renderGallery() {
    const gallery = elements.gallery;
    gallery.innerHTML = '';
    
    filteredSections.forEach((section, index) => {
        const sectionElement = createGallerySection(section, index);
        gallery.appendChild(sectionElement);
    });
    
    updateProgressIndicator();
}

function createGallerySection(section, index) {
    const sectionDiv = document.createElement('div');
    sectionDiv.className = 'gallery-section';
    sectionDiv.dataset.sectionId = section.id;
    sectionDiv.dataset.index = index;
    
    // Create media element based on type
    let mediaElement = '';
    let mediaOverlay = '';
    
    switch (section.media_type) {
        case 'image':
            mediaElement = createImageElement(section);
            break;
        case 'video':
            mediaElement = createVideoElement(section);
            mediaOverlay = createVideoOverlay();
            break;
        case 'youtube':
            mediaElement = createYouTubeElement(section);
            break;
    }
    
    // Create title overlay if enabled
    let titleOverlay = '';
    if (section.has_title && section.title) {
        titleOverlay = `
            <div class="media-overlay">
                <div class="section-title" style="color: ${section.title_color}; background-color: ${section.banner_color};">
                    ${escapeHtml(section.title)}
                </div>
            </div>
        `;
    }
    
    sectionDiv.innerHTML = mediaElement + mediaOverlay + titleOverlay;
    
    // Add title toggle functionality
    const titleElement = sectionDiv.querySelector('.section-title');
    if (titleElement) {
        setupTitleToggle(sectionDiv, titleElement);
    }
    
    return sectionDiv;
}

function createImageElement(section) {
    const imageUrl = section.media_url;
    const thumbnailUrl = section.thumbnail_url || imageUrl;
    
    return `
        <img 
            src="${thumbnailUrl}" 
            data-src="${imageUrl}"
            alt="${escapeHtml(section.title || section.internal_name)}"
            class="section-media lazy-loading"
            loading="lazy"
        >
    `;
}

function createVideoElement(section) {
    const videoUrl = section.media_url;
    const thumbnailUrl = section.thumbnail_url;
    
    return `
        <video 
            class="section-video section-media"
            data-src="${videoUrl}"
            ${thumbnailUrl ? `poster="${thumbnailUrl}"` : ''}
            loop
            muted
            playsinline
            preload="none"
        >
            <source data-src="${videoUrl}" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    `;
}

function createYouTubeElement(section) {
    const videoId = extractYouTubeId(section.media_url);
    if (!videoId) return createImageElement(section); // Fallback
    
    return `
        <iframe 
            class="section-youtube section-media"
            data-src="https://www.youtube.com/embed/${videoId}?autoplay=1&mute=1&loop=1&controls=0&showinfo=0&rel=0&modestbranding=1&playsinline=1"
            frameborder="0"
            allow="autoplay; encrypted-media; picture-in-picture"
            allowfullscreen
            loading="lazy"
        ></iframe>
    `;
}

function createVideoOverlay() {
    return `
        <div class="video-overlay">
            <div class="play-pause-icon">▶️</div>
        </div>
    `;
}

// Intersection Observer for Lazy Loading and Auto-play
function setupIntersectionObserver() {
    const options = {
        root: null,
        rootMargin: '10% 0px',
        threshold: 0.1
    };
    
    intersectionObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            const section = entry.target;
            const media = section.querySelector('.section-media');
            
            if (entry.isIntersecting) {
                // Load media lazily
                loadMediaLazily(media);
                
                // Auto-play videos when in viewport
                if (media.tagName === 'VIDEO') {
                    setTimeout(() => {
                        playVideo(media);
                    }, 300);
                }
                
                // Update current section index
                const index = parseInt(section.dataset.index);
                if (!isNaN(index)) {
                    currentSectionIndex = index;
                    updateProgressIndicator();
                }
            } else {
                // Pause videos when out of viewport
                if (media.tagName === 'VIDEO') {
                    pauseVideo(media);
                }
            }
        });
    }, options);
    
    // Observe all sections
    const sections = document.querySelectorAll('.gallery-section');
    sections.forEach(section => {
        intersectionObserver.observe(section);
    });
}

// Lazy Loading
function loadMediaLazily(media) {
    if (!media || media.classList.contains('loaded')) return;
    
    const dataSrc = media.dataset.src;
    if (!dataSrc) return;
    
    if (media.tagName === 'IMG') {
        const img = new Image();
        img.onload = () => {
            media.src = dataSrc;
            media.classList.remove('lazy-loading');
            media.classList.add('loaded');
        };
        img.onerror = () => {
            media.classList.remove('lazy-loading');
            media.classList.add('error');
        };
        img.src = dataSrc;
    } else if (media.tagName === 'VIDEO') {
        const source = media.querySelector('source');
        if (source) {
            source.src = source.dataset.src;
            media.src = dataSrc;
            media.load();
            media.classList.add('loaded');
        }
    } else if (media.tagName === 'IFRAME') {
        media.src = dataSrc;
        media.classList.add('loaded');
    }
}

// Video Controls
function playVideo(video) {
    if (video && video.tagName === 'VIDEO') {
        video.play().catch(error => {
            console.log('Auto-play failed:', error);
        });
        
        const overlay = video.parentNode.querySelector('.video-overlay');
        if (overlay) {
            overlay.classList.add('show');
            setTimeout(() => overlay.classList.remove('show'), 1000);
        }
    }
}

function pauseVideo(video) {
    if (video && video.tagName === 'VIDEO') {
        video.pause();
    }
}

// Keyboard Navigation
function setupKeyboardNavigation() {
    document.addEventListener('keydown', (e) => {
        switch (e.key) {
            case 'ArrowDown':
            case 'ArrowRight':
            case ' ':
                e.preventDefault();
                scrollToNextSection();
                break;
            case 'ArrowUp':
            case 'ArrowLeft':
                e.preventDefault();
                scrollToPrevSection();
                break;
            case 'Home':
                e.preventDefault();
                scrollToSection(0);
                break;
            case 'End':
                e.preventDefault();
                scrollToSection(filteredSections.length - 1);
                break;
            case 'Escape':
                hideTagFilter();
                break;
        }
        
        // Show keyboard hint
        const keyboardHint = document.querySelector('.nav-hint-keyboard');
        if (keyboardHint) {
            keyboardHint.style.display = 'flex';
            document.querySelector('.nav-hint-scroll').style.display = 'none';
        }
    });
}

function scrollToNextSection() {
    if (currentSectionIndex < filteredSections.length - 1) {
        scrollToSection(currentSectionIndex + 1);
    }
}

function scrollToPrevSection() {
    if (currentSectionIndex > 0) {
        scrollToSection(currentSectionIndex - 1);
    }
}

function scrollToSection(index) {
    const sections = document.querySelectorAll('.gallery-section');
    if (sections[index]) {
        sections[index].scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
        currentSectionIndex = index;
        updateProgressIndicator();
    }
}

// Progress Indicator
function setupProgressIndicator() {
    const container = elements.progressIndicator.querySelector('.progress-dots');
    container.innerHTML = '';
    
    filteredSections.forEach((_, index) => {
        const dot = document.createElement('div');
        dot.className = 'progress-dot';
        dot.dataset.index = index;
        dot.addEventListener('click', () => scrollToSection(index));
        container.appendChild(dot);
    });
    
    updateProgressIndicator();
}

function updateProgressIndicator() {
    const dots = document.querySelectorAll('.progress-dot');
    dots.forEach((dot, index) => {
        if (index === currentSectionIndex) {
            dot.classList.add('active');
        } else {
            dot.classList.remove('active');
        }
    });
}

// Tag System
function extractTags() {
    const tagSet = new Set();
    const tagCounts = {};
    
    sections.forEach(section => {
        if (section.tags && Array.isArray(section.tags)) {
            section.tags.forEach(tag => {
                tagSet.add(tag);
                tagCounts[tag] = (tagCounts[tag] || 0) + 1;
            });
        }
    });
    
    availableTags = Array.from(tagSet).map(tag => ({
        name: tag,
        count: tagCounts[tag]
    })).sort((a, b) => b.count - a.count);
}

function setupTagFilter() {
    if (availableTags.length === 0) {
        elements.tagFilter.style.display = 'none';
        return;
    }
    
    renderTagList();
    setupTagFilterEvents();
}

function renderTagList() {
    const tagList = elements.tagList;
    tagList.innerHTML = '';
    
    availableTags.forEach(tag => {
        const tagItem = document.createElement('div');
        tagItem.className = 'tag-item';
        
        tagItem.innerHTML = `
            <input type="checkbox" id="tag-${tag.name}" value="${tag.name}">
            <label for="tag-${tag.name}">${escapeHtml(tag.name)}</label>
            <span class="tag-count">(${tag.count})</span>
        `;
        
        tagList.appendChild(tagItem);
    });
}

function setupTagFilterEvents() {
    // Toggle dropdown
    elements.filterToggle.addEventListener('click', toggleTagFilter);
    
    // Clear all filters
    elements.btnClearFilter.addEventListener('click', clearAllFilters);
    
    // Tag selection
    elements.tagList.addEventListener('change', handleTagFilter);
    
    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!elements.tagFilter.contains(e.target)) {
            hideTagFilter();
        }
    });
}

function toggleTagFilter() {
    elements.filterDropdown.classList.toggle('show');
}

function hideTagFilter() {
    elements.filterDropdown.classList.remove('show');
}

function handleTagFilter() {
    const selectedTags = Array.from(elements.tagList.querySelectorAll('input:checked'))
        .map(input => input.value);
    
    if (selectedTags.length === 0) {
        filteredSections = [...sections];
    } else {
        filteredSections = sections.filter(section => {
            return section.tags && section.tags.some(tag => selectedTags.includes(tag));
        });
    }
    
    // Re-render gallery with filtered sections
    renderGallery();
    
    // Reinitialize observer
    if (intersectionObserver) {
        intersectionObserver.disconnect();
        setupIntersectionObserver();
    }
    
    // Reset to first section
    currentSectionIndex = 0;
    setupProgressIndicator();
    
    // Scroll to top
    if (filteredSections.length > 0) {
        elements.gallery.scrollTop = 0;
    }
}

function clearAllFilters() {
    elements.tagList.querySelectorAll('input:checked').forEach(input => {
        input.checked = false;
    });
    handleTagFilter();
    hideTagFilter();
}

// Event Listeners
function setupEventListeners() {
    // Retry button
    if (elements.btnRetry) {
        elements.btnRetry.addEventListener('click', initializeGallery);
    }
    
    // Scroll event for hiding hints
    elements.gallery.addEventListener('scroll', handleScroll);
    
    // Touch gestures for mobile
    setupTouchGestures();
    
    // Window resize
    window.addEventListener('resize', debounce(handleResize, 250));
    
    // Page visibility change
    document.addEventListener('visibilitychange', handleVisibilityChange);
}

function handleScroll() {
    hideNavigationHints();
    
    // Throttle scroll events
    if (isScrolling) return;
    isScrolling = true;
    
    setTimeout(() => {
        isScrolling = false;
    }, 100);
}

function setupTouchGestures() {
    let touchStartY = 0;
    let touchEndY = 0;
    
    elements.gallery.addEventListener('touchstart', (e) => {
        touchStartY = e.changedTouches[0].screenY;
    }, { passive: true });
    
    elements.gallery.addEventListener('touchend', (e) => {
        touchEndY = e.changedTouches[0].screenY;
        handleTouchGesture();
    }, { passive: true });
    
    function handleTouchGesture() {
        const swipeThreshold = 50;
        const diff = touchStartY - touchEndY;
        
        if (Math.abs(diff) > swipeThreshold) {
            if (diff > 0) {
                // Swipe up - next section
                scrollToNextSection();
            } else {
                // Swipe down - previous section
                scrollToPrevSection();
            }
        }
    }
}

function handleResize() {
    // Recalculate section positions if needed
    updateProgressIndicator();
}

function handleVisibilityChange() {
    if (document.hidden) {
        // Pause all videos when page is hidden
        document.querySelectorAll('video').forEach(pauseVideo);
    }
}

function hideNavigationHints() {
    if (elements.navHints) {
        elements.navHints.classList.add('hidden');
    }
}

// Title Toggle Functionality
function setupTitleToggle(sectionDiv, titleElement) {
    const mediaOverlay = sectionDiv.querySelector('.media-overlay');
    if (!mediaOverlay) return;
    
    let isHidden = false;
    
    // Toggle function
    const toggleTitle = () => {
        isHidden = !isHidden;
        
        if (isHidden) {
            mediaOverlay.classList.add('hidden');
        } else {
            mediaOverlay.classList.remove('hidden');
        }
        
        // Store state for this section
        sectionDiv.dataset.titleHidden = isHidden;
    };
    
    // Add click/tap listeners
    titleElement.addEventListener('click', toggleTitle);
    titleElement.addEventListener('touchend', (e) => {
        e.preventDefault(); // Prevent double-tap zoom
        toggleTitle();
    });
    
    // Add visual feedback
    titleElement.addEventListener('touchstart', () => {
        titleElement.style.transform = 'scale(0.98)';
    });
    
    titleElement.addEventListener('touchend', () => {
        setTimeout(() => {
            titleElement.style.transform = 'scale(1)';
        }, 150);
    });
    
    titleElement.addEventListener('touchcancel', () => {
        titleElement.style.transform = 'scale(1)';
    });
}

// Utility Functions
function extractYouTubeId(url) {
    const patterns = [
        /youtu\.be\/([a-zA-Z0-9_-]+)/,
        /youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/,
        /youtube\.com\/embed\/([a-zA-Z0-9_-]+)/,
        /youtube\.com\/v\/([a-zA-Z0-9_-]+)/
    ];
    
    for (const pattern of patterns) {
        const match = url.match(pattern);
        if (match) return match[1];
    }
    
    return null;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Performance Monitoring
function trackPerformance() {
    if ('performance' in window) {
        window.addEventListener('load', () => {
            const perfData = performance.getEntriesByType('navigation')[0];
            console.log('Gallery Load Time:', perfData.loadEventEnd - perfData.fetchStart + 'ms');
        });
    }
}

// Initialize performance tracking
trackPerformance();

// Expose API for debugging
window.LuminelliGallery = {
    sections,
    filteredSections,
    currentSectionIndex,
    scrollToSection,
    toggleTagFilter,
    initializeGallery
};
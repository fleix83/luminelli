// LUMINELLI Admin JavaScript

// Configuration
const API_BASE = 'api/';
const UPLOAD_BASE = 'uploads/';

// Global State
let sections = [];
let currentEditingSection = null;
let draggedElement = null;
let dragOverElement = null;

// DOM Elements
const elements = {
    // States
    loadingState: document.getElementById('loading-state'),
    errorState: document.getElementById('error-state'),
    emptyState: document.getElementById('empty-state'),
    sectionsContainer: document.getElementById('sections-container'),
    
    // Header
    btnNewSection: document.getElementById('btn-new-section'),
    btnPreview: document.getElementById('btn-preview'),
    
    // Sections
    sectionsGrid: document.getElementById('sections-grid'),
    sectionsCount: document.getElementById('sections-count'),
    
    // Modal
    modalOverlay: document.getElementById('modal-overlay'),
    modalTitle: document.getElementById('modal-title'),
    btnCloseModal: document.getElementById('btn-close-modal'),
    sectionForm: document.getElementById('section-form'),
    btnCancel: document.getElementById('btn-cancel'),
    btnSave: document.getElementById('btn-save'),
    btnSaveText: document.getElementById('btn-save-text'),
    btnSaveLoader: document.getElementById('btn-save-loader'),
    
    // Form Fields
    internalName: document.getElementById('internal-name'),
    mediaTypeRadios: document.querySelectorAll('input[name="media_type"]'),
    uploadSection: document.getElementById('upload-section'),
    youtubeSection: document.getElementById('youtube-section'),
    mediaFile: document.getElementById('media-file'),
    youtubeUrl: document.getElementById('youtube-url'),
    fileUploadArea: document.getElementById('file-upload-area'),
    uploadPreview: document.getElementById('upload-preview'),
    
    // Title Configuration
    hasTitle: document.getElementById('has-title'),
    titleConfig: document.getElementById('title-config'),
    titleText: document.getElementById('title-text'),
    titleColor: document.getElementById('title-color'),
    titleColorHex: document.getElementById('title-color-hex'),
    bannerColor: document.getElementById('banner-color'),
    bannerColorHex: document.getElementById('banner-color-hex'),
    bannerOpacity: document.getElementById('banner-opacity'),
    titlePreviewText: document.getElementById('title-preview-text'),
    
    // Tags
    tagsInput: document.getElementById('tags-input'),
    
    // Confirmation
    confirmOverlay: document.getElementById('confirm-overlay'),
    confirmTitle: document.getElementById('confirm-title'),
    confirmMessage: document.getElementById('confirm-message'),
    btnConfirmCancel: document.getElementById('btn-confirm-cancel'),
    btnConfirmOk: document.getElementById('btn-confirm-ok'),
    
    // Toast
    toastContainer: document.getElementById('toast-container')
};

// Initialize App
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    loadSections();
});

// Event Listeners
function initializeEventListeners() {
    // Header buttons
    elements.btnNewSection.addEventListener('click', () => openSectionEditor());
    elements.btnPreview.addEventListener('click', () => window.open('index.html', '_blank'));
    
    // Modal controls
    elements.btnCloseModal.addEventListener('click', closeSectionEditor);
    elements.btnCancel.addEventListener('click', closeSectionEditor);
    elements.modalOverlay.addEventListener('click', (e) => {
        if (e.target === elements.modalOverlay) closeSectionEditor();
    });
    
    // Form submission
    elements.sectionForm.addEventListener('submit', handleFormSubmit);
    
    // Media type change
    elements.mediaTypeRadios.forEach(radio => {
        radio.addEventListener('change', handleMediaTypeChange);
    });
    
    // File upload
    elements.mediaFile.addEventListener('change', handleFileSelect);
    elements.fileUploadArea.addEventListener('dragover', handleDragOver);
    elements.fileUploadArea.addEventListener('dragleave', handleDragLeave);
    elements.fileUploadArea.addEventListener('drop', handleFileDrop);
    elements.fileUploadArea.addEventListener('click', () => elements.mediaFile.click());
    
    // Title toggle
    elements.hasTitle.addEventListener('change', handleTitleToggle);
    
    // Color pickers
    elements.titleColor.addEventListener('change', updateTitlePreview);
    elements.titleColorHex.addEventListener('input', handleColorHexChange);
    elements.bannerColor.addEventListener('change', updateBannerColor);
    elements.bannerOpacity.addEventListener('input', updateBannerColor);
    elements.titleText.addEventListener('input', updateTitlePreview);
    
    // Confirmation dialog
    elements.btnConfirmCancel.addEventListener('click', closeConfirmDialog);
    
    // Retry button
    document.getElementById('btn-retry')?.addEventListener('click', loadSections);
    document.getElementById('btn-create-first')?.addEventListener('click', () => openSectionEditor());
    
    // Keyboard shortcuts
    document.addEventListener('keydown', handleKeydown);
}

// API Functions
async function apiRequest(endpoint, options = {}) {
    try {
        const response = await fetch(API_BASE + endpoint, {
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            ...options
        });
        
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'API request failed');
        }
        
        return data;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

// Load and display sections
async function loadSections() {
    try {
        showLoadingState();
        
        const response = await apiRequest('sections.php');
        sections = response.data || [];
        
        if (sections.length === 0) {
            showEmptyState();
        } else {
            renderSections();
            showSectionsContainer();
        }
        
    } catch (error) {
        showErrorState(error.message);
    }
}

// Render sections grid
function renderSections() {
    const grid = elements.sectionsGrid;
    grid.innerHTML = '';
    
    sections.forEach(section => {
        const card = createSectionCard(section);
        grid.appendChild(card);
    });
    
    // Update count
    elements.sectionsCount.textContent = `${sections.length} section${sections.length !== 1 ? 's' : ''}`;
    
    // Initialize drag & drop
    initializeDragAndDrop();
}

// Create section card
function createSectionCard(section) {
    const card = document.createElement('div');
    card.className = 'section-card';
    card.draggable = true;
    card.dataset.sectionId = section.id;
    card.dataset.position = section.position;
    
    const thumbnailUrl = section.thumbnail_url || 'https://via.placeholder.com/300x200?text=No+Thumbnail';
    const tags = Array.isArray(section.tags) ? section.tags.join(', ') : '';
    
    card.innerHTML = `
        <div class="drag-handle" title="Drag to reorder"></div>
        <img src="${thumbnailUrl}" alt="${section.internal_name}" class="card-thumbnail">
        <div class="card-content">
            <div class="card-info">
                <h3 class="card-title">${section.internal_name}</h3>
                <div class="card-meta">
                    <span class="badge badge-${section.media_type}">${section.media_type}</span>
                    ${section.has_title ? '<span class="badge">Title</span>' : ''}
                </div>
                ${tags ? `<div class="card-tags">Tags: ${tags}</div>` : ''}
                <div class="card-position">Position: ${section.position}</div>
            </div>
            <div class="card-actions">
                <button class="btn-icon-small btn-edit" title="Edit">✏️</button>
                <button class="btn-icon-small btn-delete" title="Delete">🗑️</button>
            </div>
        </div>
    `;
    
    // Add event listeners
    card.querySelector('.btn-edit').addEventListener('click', (e) => {
        e.stopPropagation();
        openSectionEditor(section);
    });
    
    card.querySelector('.btn-delete').addEventListener('click', (e) => {
        e.stopPropagation();
        confirmDeleteSection(section);
    });
    
    return card;
}

// State Management
function showLoadingState() {
    hideAllStates();
    elements.loadingState.style.display = 'flex';
}

function showErrorState(message) {
    hideAllStates();
    elements.errorState.style.display = 'flex';
    document.getElementById('error-message').textContent = message;
}

function showEmptyState() {
    hideAllStates();
    elements.emptyState.style.display = 'flex';
}

function showSectionsContainer() {
    hideAllStates();
    elements.sectionsContainer.style.display = 'block';
}

function hideAllStates() {
    elements.loadingState.style.display = 'none';
    elements.errorState.style.display = 'none';
    elements.emptyState.style.display = 'none';
    elements.sectionsContainer.style.display = 'none';
}

// Section Editor Modal
function openSectionEditor(section = null) {
    currentEditingSection = section;
    
    // Set modal title
    elements.modalTitle.textContent = section ? 'Edit Section' : 'New Section';
    elements.btnSaveText.textContent = section ? 'Update Section' : 'Save Section';
    
    // Reset form
    elements.sectionForm.reset();
    clearUploadPreview();
    
    // Fill form if editing
    if (section) {
        elements.internalName.value = section.internal_name || '';
        elements.tagsInput.value = Array.isArray(section.tags) ? section.tags.join(', ') : '';
        
        // Set media type
        const mediaTypeRadio = document.querySelector(`input[name="media_type"][value="${section.media_type}"]`);
        if (mediaTypeRadio) mediaTypeRadio.checked = true;
        
        // Set title configuration
        elements.hasTitle.checked = section.has_title;
        if (section.has_title) {
            elements.titleText.value = section.title || '';
            elements.titleColor.value = section.title_color || '#FFFFFF';
            elements.titleColorHex.value = section.title_color || '#FFFFFF';
            
            // Parse banner color and opacity
            const bannerColor = section.banner_color || 'rgba(0,0,0,0.5)';
            parseBannerColor(bannerColor);
        }
        
        handleTitleToggle();
        updateTitlePreview();
    }
    
    // Show appropriate upload section
    handleMediaTypeChange();
    
    // Show modal
    elements.modalOverlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Focus first input
    elements.internalName.focus();
}

function closeSectionEditor() {
    elements.modalOverlay.style.display = 'none';
    document.body.style.overflow = '';
    currentEditingSection = null;
    elements.sectionForm.reset();
    clearUploadPreview();
}

// Form Handling
async function handleFormSubmit(e) {
    e.preventDefault();
    
    // Show loading state
    elements.btnSave.disabled = true;
    elements.btnSaveText.style.display = 'none';
    elements.btnSaveLoader.style.display = 'block';
    
    try {
        const formData = new FormData(elements.sectionForm);
        const mediaType = formData.get('media_type');
        
        let mediaUrl = '';
        let thumbnailUrl = '';
        
        // Handle different media types
        if (mediaType === 'youtube') {
            const result = await handleYouTubeUpload(formData.get('youtube_url'));
            mediaUrl = result.media_url;
            thumbnailUrl = result.thumbnail_url;
        } else {
            // Handle file upload
            const file = elements.mediaFile.files[0];
            if (file || currentEditingSection) {
                if (file) {
                    const result = await handleFileUpload(file, mediaType);
                    mediaUrl = result.media_url;
                    thumbnailUrl = result.thumbnail_url;
                } else {
                    // Keep existing URLs when editing without new file
                    mediaUrl = currentEditingSection.media_url;
                    thumbnailUrl = currentEditingSection.thumbnail_url;
                }
            } else {
                throw new Error('Please select a file or enter a YouTube URL');
            }
        }
        
        // Prepare section data
        const sectionData = {
            internal_name: formData.get('internal_name'),
            media_type: mediaType,
            media_url: mediaUrl,
            thumbnail_url: thumbnailUrl,
            has_title: elements.hasTitle.checked,
            title: formData.get('title') || null,
            title_color: formData.get('title_color') || '#FFFFFF',
            banner_color: elements.bannerColorHex.value || 'rgba(0,0,0,0.5)',
            tags: formData.get('tags') ? formData.get('tags').split(',').map(t => t.trim()) : []
        };
        
        // Create or update section
        let response;
        if (currentEditingSection) {
            response = await apiRequest(`sections.php/${currentEditingSection.id}`, {
                method: 'PUT',
                body: JSON.stringify(sectionData)
            });
        } else {
            response = await apiRequest('sections.php', {
                method: 'POST',
                body: JSON.stringify(sectionData)
            });
        }
        
        // Show success message
        showToast(response.message, 'success');
        
        // Close modal and reload sections
        closeSectionEditor();
        loadSections();
        
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        // Reset loading state
        elements.btnSave.disabled = false;
        elements.btnSaveText.style.display = 'inline';
        elements.btnSaveLoader.style.display = 'none';
    }
}

// File Upload Handling
async function handleFileUpload(file, mediaType) {
    const uploadFormData = new FormData();
    uploadFormData.append('file', file);
    uploadFormData.append('type', mediaType);
    
    const response = await fetch(API_BASE + 'upload.php', {
        method: 'POST',
        body: uploadFormData
    });
    
    const result = await response.json();
    
    if (!response.ok) {
        throw new Error(result.message || 'Upload failed');
    }
    
    return result.data;
}

async function handleYouTubeUpload(url) {
    const uploadFormData = new FormData();
    uploadFormData.append('youtube_url', url);
    uploadFormData.append('type', 'youtube');
    
    const response = await fetch(API_BASE + 'upload.php', {
        method: 'POST',
        body: uploadFormData
    });
    
    const result = await response.json();
    
    if (!response.ok) {
        throw new Error(result.message || 'YouTube processing failed');
    }
    
    return result.data;
}

// Media Type Handling
function handleMediaTypeChange() {
    const selectedType = document.querySelector('input[name="media_type"]:checked').value;
    
    // Show/hide appropriate sections
    elements.uploadSection.style.display = selectedType !== 'youtube' ? 'block' : 'none';
    elements.youtubeSection.style.display = selectedType === 'youtube' ? 'block' : 'none';
    
    // Update file input accept attribute
    if (selectedType === 'image') {
        elements.mediaFile.accept = 'image/*';
        elements.fileUploadArea.querySelector('small').textContent = 'Max 50MB - JPG, PNG, GIF, WebP';
    } else if (selectedType === 'video') {
        elements.mediaFile.accept = 'video/*';
        elements.fileUploadArea.querySelector('small').textContent = 'Max 50MB - MP4, WebM, OGG';
    }
    
    clearUploadPreview();
}

// File Handling
function handleFileSelect(e) {
    const file = e.target.files[0];
    if (file) {
        displayFilePreview(file);
    }
}

function handleDragOver(e) {
    e.preventDefault();
    elements.fileUploadArea.classList.add('dragover');
}

function handleDragLeave(e) {
    e.preventDefault();
    elements.fileUploadArea.classList.remove('dragover');
}

function handleFileDrop(e) {
    e.preventDefault();
    elements.fileUploadArea.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        const file = files[0];
        elements.mediaFile.files = files;
        displayFilePreview(file);
    }
}

function displayFilePreview(file) {
    const preview = elements.uploadPreview;
    const placeholder = elements.fileUploadArea.querySelector('.upload-placeholder');
    
    placeholder.style.display = 'none';
    preview.style.display = 'block';
    
    const isImage = file.type.startsWith('image/');
    const isVideo = file.type.startsWith('video/');
    
    let previewHTML = '';
    
    if (isImage) {
        const imageUrl = URL.createObjectURL(file);
        previewHTML = `
            <img src="${imageUrl}" alt="Preview" class="preview-image">
            <div class="preview-info">
                <strong>${file.name}</strong><br>
                ${formatFileSize(file.size)} • ${file.type}
            </div>
        `;
    } else if (isVideo) {
        const videoUrl = URL.createObjectURL(file);
        previewHTML = `
            <video src="${videoUrl}" controls class="preview-video"></video>
            <div class="preview-info">
                <strong>${file.name}</strong><br>
                ${formatFileSize(file.size)} • ${file.type}
            </div>
        `;
    } else {
        previewHTML = `
            <div class="preview-info">
                <strong>${file.name}</strong><br>
                ${formatFileSize(file.size)} • ${file.type}
            </div>
        `;
    }
    
    preview.innerHTML = previewHTML;
}

function clearUploadPreview() {
    const preview = elements.uploadPreview;
    const placeholder = elements.fileUploadArea.querySelector('.upload-placeholder');
    
    preview.style.display = 'none';
    placeholder.style.display = 'flex';
    preview.innerHTML = '';
    elements.mediaFile.value = '';
}

// Title Configuration
function handleTitleToggle() {
    const isEnabled = elements.hasTitle.checked;
    elements.titleConfig.style.display = isEnabled ? 'block' : 'none';
    
    if (isEnabled) {
        updateTitlePreview();
    }
}

function handleColorHexChange() {
    const hexValue = elements.titleColorHex.value;
    if (isValidHexColor(hexValue)) {
        elements.titleColor.value = hexValue;
        updateTitlePreview();
    }
}

function updateTitlePreview() {
    const titleText = elements.titleText.value || 'Sample Title';
    const titleColor = elements.titleColor.value;
    const bannerColor = elements.bannerColorHex.value;
    
    elements.titlePreviewText.textContent = titleText;
    elements.titlePreviewText.style.color = titleColor;
    elements.titlePreviewText.style.backgroundColor = bannerColor;
    
    // Update hex input
    elements.titleColorHex.value = titleColor;
}

function updateBannerColor() {
    const color = elements.bannerColor.value;
    const opacity = elements.bannerOpacity.value / 100;
    
    // Convert hex to rgba
    const r = parseInt(color.substr(1, 2), 16);
    const g = parseInt(color.substr(3, 2), 16);
    const b = parseInt(color.substr(5, 2), 16);
    
    const rgba = `rgba(${r}, ${g}, ${b}, ${opacity})`;
    elements.bannerColorHex.value = rgba;
    
    updateTitlePreview();
}

function parseBannerColor(colorString) {
    if (colorString.startsWith('rgba')) {
        const match = colorString.match(/rgba\((\d+),\s*(\d+),\s*(\d+),\s*([\d.]+)\)/);
        if (match) {
            const r = parseInt(match[1]);
            const g = parseInt(match[2]);
            const b = parseInt(match[3]);
            const a = parseFloat(match[4]);
            
            const hex = '#' + [r, g, b].map(x => x.toString(16).padStart(2, '0')).join('');
            elements.bannerColor.value = hex;
            elements.bannerOpacity.value = Math.round(a * 100);
            elements.bannerColorHex.value = colorString;
        }
    } else if (colorString.startsWith('#')) {
        elements.bannerColor.value = colorString;
        elements.bannerColorHex.value = colorString;
    }
}

// Drag & Drop for Reordering
function initializeDragAndDrop() {
    const cards = document.querySelectorAll('.section-card');
    
    cards.forEach(card => {
        const dragHandle = card.querySelector('.drag-handle');
        
        // Make only the drag handle initiate drag on mobile
        if (dragHandle) {
            // Touch events for mobile
            dragHandle.addEventListener('touchstart', handleTouchStart, { passive: false });
            dragHandle.addEventListener('touchmove', handleTouchMove, { passive: false });
            dragHandle.addEventListener('touchend', handleTouchEnd, { passive: false });
            
            // Mouse events for desktop
            dragHandle.addEventListener('mousedown', () => {
                card.setAttribute('draggable', 'true');
            });
            
            dragHandle.addEventListener('mouseup', () => {
                setTimeout(() => card.setAttribute('draggable', 'false'), 100);
            });
        }
        
        card.addEventListener('dragstart', handleDragStart);
        card.addEventListener('dragover', handleDragOverCard);
        card.addEventListener('drop', handleDropCard);
        card.addEventListener('dragend', handleDragEnd);
    });
}

function handleDragStart(e) {
    draggedElement = e.target;
    e.target.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', e.target.outerHTML);
}

function handleDragOverCard(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    
    const card = e.target.closest('.section-card');
    if (card && card !== draggedElement) {
        // Remove existing drag-over classes
        document.querySelectorAll('.section-card.drag-over').forEach(el => {
            el.classList.remove('drag-over');
        });
        
        card.classList.add('drag-over');
        dragOverElement = card;
    }
}

async function handleDropCard(e) {
    e.preventDefault();
    
    const targetCard = e.target.closest('.section-card');
    if (!targetCard || !draggedElement || targetCard === draggedElement) return;
    
    try {
        const draggedId = parseInt(draggedElement.dataset.sectionId);
        const targetId = parseInt(targetCard.dataset.sectionId);
        
        // Swap sections in the API
        await apiRequest('reorder.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'swap_positions',
                section1_id: draggedId,
                section2_id: targetId
            })
        });
        
        showToast('Sections reordered successfully', 'success');
        loadSections(); // Reload to get updated positions
        
    } catch (error) {
        showToast('Failed to reorder sections: ' + error.message, 'error');
    }
}

function handleDragEnd(e) {
    e.target.classList.remove('dragging');
    document.querySelectorAll('.section-card.drag-over').forEach(el => {
        el.classList.remove('drag-over');
    });
    
    draggedElement = null;
    dragOverElement = null;
}

// Touch-based drag and drop for mobile
let touchDraggedElement = null;
let touchStartY = 0;
let touchCurrentY = 0;
let dragPreview = null;

function handleTouchStart(e) {
    e.preventDefault();
    
    const card = e.target.closest('.section-card');
    if (!card) return;
    
    touchDraggedElement = card;
    const touch = e.touches[0];
    touchStartY = touch.clientY;
    touchCurrentY = touch.clientY;
    
    // Create drag preview
    createDragPreview(card, touch);
    
    // Add visual feedback
    card.style.opacity = '0.5';
    card.classList.add('dragging');
}

function handleTouchMove(e) {
    if (!touchDraggedElement || !dragPreview) return;
    
    e.preventDefault();
    const touch = e.touches[0];
    touchCurrentY = touch.clientY;
    
    // Move the preview
    dragPreview.style.left = touch.clientX - (dragPreview.offsetWidth / 2) + 'px';
    dragPreview.style.top = touch.clientY - (dragPreview.offsetHeight / 2) + 'px';
    
    // Find the element we're hovering over
    const elementBelow = document.elementFromPoint(touch.clientX, touch.clientY);
    const cardBelow = elementBelow?.closest('.section-card');
    
    // Remove previous hover states
    document.querySelectorAll('.section-card.drag-over').forEach(el => {
        el.classList.remove('drag-over');
    });
    
    // Add hover state to target
    if (cardBelow && cardBelow !== touchDraggedElement) {
        cardBelow.classList.add('drag-over');
        dragOverElement = cardBelow;
    }
}

async function handleTouchEnd(e) {
    e.preventDefault();
    
    if (!touchDraggedElement) return;
    
    // Clean up preview
    if (dragPreview) {
        dragPreview.remove();
        dragPreview = null;
    }
    
    // Restore original card
    touchDraggedElement.style.opacity = '1';
    touchDraggedElement.classList.remove('dragging');
    
    // Clean up hover states
    document.querySelectorAll('.section-card.drag-over').forEach(el => {
        el.classList.remove('drag-over');
    });
    
    // Perform the swap if we have a target
    if (dragOverElement && dragOverElement !== touchDraggedElement) {
        try {
            const draggedId = parseInt(touchDraggedElement.dataset.sectionId);
            const targetId = parseInt(dragOverElement.dataset.sectionId);
            
            await apiRequest('reorder.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'swap_positions',
                    section1_id: draggedId,
                    section2_id: targetId
                })
            });
            
            showToast('Sections reordered successfully', 'success');
            loadSections();
            
        } catch (error) {
            showToast('Failed to reorder sections: ' + error.message, 'error');
        }
    }
    
    // Clean up
    touchDraggedElement = null;
    dragOverElement = null;
}

function createDragPreview(card, touch) {
    dragPreview = card.cloneNode(true);
    dragPreview.classList.add('drag-preview');
    dragPreview.style.position = 'fixed';
    dragPreview.style.left = touch.clientX - (card.offsetWidth / 2) + 'px';
    dragPreview.style.top = touch.clientY - (card.offsetHeight / 2) + 'px';
    dragPreview.style.width = card.offsetWidth + 'px';
    dragPreview.style.zIndex = '10000';
    dragPreview.style.opacity = '0.8';
    dragPreview.style.transform = 'rotate(5deg) scale(1.05)';
    dragPreview.style.pointerEvents = 'none';
    dragPreview.style.boxShadow = '0 10px 30px rgba(0,0,0,0.3)';
    
    document.body.appendChild(dragPreview);
}

// Delete Section
function confirmDeleteSection(section) {
    elements.confirmTitle.textContent = 'Delete Section';
    elements.confirmMessage.textContent = `Are you sure you want to delete "${section.internal_name}"? This action cannot be undone.`;
    
    elements.btnConfirmOk.onclick = async () => {
        try {
            await apiRequest(`sections.php/${section.id}`, {
                method: 'DELETE'
            });
            
            showToast('Section deleted successfully', 'success');
            closeConfirmDialog();
            loadSections();
            
        } catch (error) {
            showToast('Failed to delete section: ' + error.message, 'error');
        }
    };
    
    elements.confirmOverlay.style.display = 'flex';
}

function closeConfirmDialog() {
    elements.confirmOverlay.style.display = 'none';
    elements.btnConfirmOk.onclick = null;
}

// Toast Notifications
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : 'ℹ️';
    
    toast.innerHTML = `
        <span class="toast-icon">${icon}</span>
        <span class="toast-message">${message}</span>
    `;
    
    elements.toastContainer.appendChild(toast);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        toast.classList.add('toast-exit');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }, 5000);
}

// Utility Functions
function formatFileSize(bytes) {
    const sizes = ['B', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
}

function isValidHexColor(hex) {
    return /^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(hex);
}

// Keyboard Shortcuts
function handleKeydown(e) {
    // ESC to close modal
    if (e.key === 'Escape') {
        if (elements.modalOverlay.style.display === 'flex') {
            closeSectionEditor();
        } else if (elements.confirmOverlay.style.display === 'flex') {
            closeConfirmDialog();
        }
    }
    
    // Ctrl/Cmd + N for new section
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
        e.preventDefault();
        openSectionEditor();
    }
}

// Export for debugging
window.LuminelliAdmin = {
    loadSections,
    sections,
    showToast
};
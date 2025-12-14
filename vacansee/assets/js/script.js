// Main JavaScript file for VACANSEE System

// Global variables
let autoRefreshInterval = null;
let lastUpdateTime = Date.now();

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initializeComponents();
    startAutoRefresh();
    setupEventListeners();
});

// Initialize all components
function initializeComponents() {
    // Initialize tooltips
    initTooltips();
    
    // Initialize form validations
    initFormValidations();
    
    // Initialize real-time updates
    initRealTimeUpdates();
    
    // Set current year in footer
    setCurrentYear();
}

// Initialize tooltips
function initTooltips() {
    const tooltips = document.querySelectorAll('[data-toggle="tooltip"]');
    tooltips.forEach(tooltip => {
        tooltip.addEventListener('mouseenter', function() {
            const title = this.getAttribute('title');
            const tooltipEl = document.createElement('div');
            tooltipEl.className = 'custom-tooltip';
            tooltipEl.textContent = title;
            document.body.appendChild(tooltipEl);
            
            const rect = this.getBoundingClientRect();
            tooltipEl.style.position = 'fixed';
            tooltipEl.style.top = (rect.top - tooltipEl.offsetHeight - 10) + 'px';
            tooltipEl.style.left = (rect.left + rect.width / 2 - tooltipEl.offsetWidth / 2) + 'px';
            tooltipEl.style.zIndex = '9999';
            
            this._tooltip = tooltipEl;
            this.removeAttribute('title');
        });
        
        tooltip.addEventListener('mouseleave', function() {
            if (this._tooltip) {
                document.body.removeChild(this._tooltip);
                this.setAttribute('title', this._tooltip.textContent);
                delete this._tooltip;
            }
        });
    });
}

// Initialize form validations
function initFormValidations() {
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                showFormErrors(this);
            }
        });
    });
    
    // Real-time password validation
    const passwordInputs = document.querySelectorAll('input[type="password"]');
    passwordInputs.forEach(input => {
        if (input.minLength) {
            input.addEventListener('input', function() {
                validatePasswordStrength(this);
            });
        }
    });
}

// Validate form
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            isValid = false;
            highlightError(input, 'This field is required');
        } else if (input.type === 'email') {
            if (!validateEmail(input.value)) {
                isValid = false;
                highlightError(input, 'Please enter a valid email address');
            }
        } else if (input.type === 'password' && input.minLength) {
            if (input.value.length < input.minLength) {
                isValid = false;
                highlightError(input, `Password must be at least ${input.minLength} characters`);
            }
        }
    });
    
    return isValid;
}

// Validate email format
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Validate password strength
function validatePasswordStrength(input) {
    const password = input.value;
    const strengthMeter = input.parentElement.querySelector('.strength-meter');
    
    if (!strengthMeter) return;
    
    let strength = 0;
    
    // Length check
    if (password.length >= 8) strength += 25;
    
    // Number check
    if (/\d/.test(password)) strength += 25;
    
    // Uppercase check
    if (/[A-Z]/.test(password)) strength += 25;
    
    // Special character check
    if (/[^A-Za-z0-9]/.test(password)) strength += 25;
    
    // Update strength meter
    strengthMeter.style.width = strength + '%';
    
    // Update strength meter color
    strengthMeter.className = 'strength-meter';
    if (strength <= 25) {
        strengthMeter.classList.add('strength-weak');
    } else if (strength <= 50) {
        strengthMeter.classList.add('strength-fair');
    } else if (strength <= 75) {
        strengthMeter.classList.add('strength-good');
    } else {
        strengthMeter.classList.add('strength-strong');
    }
}

// Highlight error field
function highlightError(input, message) {
    input.classList.add('error');
    
    // Remove existing error message
    const existingError = input.parentElement.querySelector('.error-message');
    if (existingError) {
        existingError.remove();
    }
    
    // Add new error message
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message';
    errorDiv.textContent = message;
    errorDiv.style.color = '#dc3545';
    errorDiv.style.fontSize = '0.85rem';
    errorDiv.style.marginTop = '5px';
    
    input.parentElement.appendChild(errorDiv);
    
    // Auto-remove error on input
    input.addEventListener('input', function() {
        this.classList.remove('error');
        const errorMsg = this.parentElement.querySelector('.error-message');
        if (errorMsg) {
            errorMsg.remove();
        }
    }, { once: true });
}

// Show form errors
function showFormErrors(form) {
    const firstError = form.querySelector('.error');
    if (firstError) {
        firstError.focus();
        showNotification('Please fix the errors in the form', 'error');
    }
}

// Initialize real-time updates
function initRealTimeUpdates() {
    // Check if we're on a page that needs real-time updates
    const page = document.body.dataset.page;
    if (page && ['dashboard', 'reservations', 'rooms'].includes(page)) {
        setupWebSocket();
    }
}

// Setup WebSocket for real-time updates
function setupWebSocket() {
    // In a real implementation, you would connect to a WebSocket server
    // For now, we'll simulate with polling
    console.log('Real-time updates initialized');
}

// Start auto-refresh for real-time data
function startAutoRefresh() {
    // Only auto-refresh on certain pages
    const refreshablePages = ['dashboard', 'reservations', 'rooms', 'reports'];
    const currentPage = document.body.dataset.page;
    
    if (refreshablePages.includes(currentPage)) {
        autoRefreshInterval = setInterval(refreshData, 30000); // Every 30 seconds
    }
}

// Refresh data
function refreshData() {
    const currentPage = document.body.dataset.page;
    
    switch (currentPage) {
        case 'dashboard':
            refreshDashboard();
            break;
        case 'reservations':
            refreshReservations();
            break;
        case 'rooms':
            refreshRooms();
            break;
        case 'reports':
            refreshReports();
            break;
    }
    
    updateLastUpdateTime();
}

// Refresh dashboard data
function refreshDashboard() {
    fetch('includes/refresh_stats.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateDashboardStats(data.stats);
            }
        })
        .catch(error => console.error('Error refreshing dashboard:', error));
}

// Update dashboard statistics
function updateDashboardStats(stats) {
    for (const [key, value] of Object.entries(stats)) {
        const element = document.getElementById(`stat-${key}`);
        if (element) {
            // Animate number change
            animateValue(element, parseInt(element.textContent), value, 500);
        }
    }
}

// Animate number change
function animateValue(element, start, end, duration) {
    let startTimestamp = null;
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        const value = Math.floor(progress * (end - start) + start);
        element.textContent = value;
        if (progress < 1) {
            window.requestAnimationFrame(step);
        }
    };
    window.requestAnimationFrame(step);
}

// Refresh reservations
function refreshReservations() {
    fetch('includes/check_reservation_updates.php?last_check=' + lastUpdateTime)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.updated) {
                showNotification('New reservation updates available', 'info');
                // In a real implementation, you would update the reservations list
            }
            lastUpdateTime = data.timestamp;
        })
        .catch(error => console.error('Error refreshing reservations:', error));
}

// Refresh rooms
function refreshRooms() {
    fetch('includes/check_availability.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateRoomAvailability(data.rooms);
            }
        })
        .catch(error => console.error('Error refreshing rooms:', error));
}

// Update room availability display
function updateRoomAvailability(rooms) {
    // This would update the room cards with new availability status
    console.log('Rooms updated:', rooms.length);
}

// Refresh reports
function refreshReports() {
    // Reports might not need auto-refresh
    console.log('Reports refresh triggered');
}

// Update last update time display
function updateLastUpdateTime() {
    const updateElements = document.querySelectorAll('.last-update');
    const now = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    
    updateElements.forEach(element => {
        element.textContent = now;
    });
}

// Setup event listeners
function setupEventListeners() {
    // Toggle sidebar
    const menuToggles = document.querySelectorAll('.menu-toggle');
    menuToggles.forEach(toggle => {
        toggle.addEventListener('click', toggleSidebar);
    });
    
    // Toggle password visibility
    const passwordToggles = document.querySelectorAll('.toggle-password');
    passwordToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const input = this.parentElement.querySelector('input');
            togglePasswordVisibility(input, this);
        });
    });
    
    // Close modals when clicking outside
    document.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    });
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.style.display = 'none';
            });
        }
    });
    
    // Form submission handling
    document.addEventListener('submit', function(event) {
        const form = event.target;
        if (form.classList.contains('ajax-form')) {
            event.preventDefault();
            submitAjaxForm(form);
        }
    });
}

// Toggle sidebar
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (sidebar && mainContent) {
        sidebar.classList.toggle('active');
        
        if (sidebar.classList.contains('active')) {
            mainContent.style.marginLeft = '250px';
        } else {
            mainContent.style.marginLeft = '0';
        }
        
        // Store sidebar state in localStorage
        localStorage.setItem('sidebarState', sidebar.classList.contains('active') ? 'open' : 'closed');
    }
}

// Toggle password visibility
function togglePasswordVisibility(input, button) {
    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
    input.setAttribute('type', type);
    
    const icon = button.querySelector('i');
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

// Submit form via AJAX
function submitAjaxForm(form) {
    const formData = new FormData(form);
    const submitBtn = form.querySelector('button[type="submit"]');
    
    // Disable submit button
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
    }
    
    fetch(form.action, {
        method: form.method,
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            
            // Reset form if needed
            if (form.dataset.reset === 'true') {
                form.reset();
            }
            
            // Reload page or update content
            if (form.dataset.reload === 'true') {
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            }
        } else {
            showNotification(data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('An error occurred. Please try again.', 'error');
    })
    .finally(() => {
        // Re-enable submit button
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = submitBtn.dataset.originalText || 'Submit';
        }
    });
}

// Show notification
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.notification');
    existingNotifications.forEach(notification => {
        notification.remove();
    });
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas ${getNotificationIcon(type)}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add styles
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.padding = '15px 20px';
    notification.style.borderRadius = 'var(--border-radius)';
    notification.style.boxShadow = 'var(--shadow-hover)';
    notification.style.zIndex = '9999';
    notification.style.display = 'flex';
    notification.style.alignItems = 'center';
    notification.style.gap = '10px';
    notification.style.minWidth = '300px';
    notification.style.maxWidth = '400px';
    notification.style.animation = 'slideInRight 0.3s ease';
    
    // Set color based on type
    switch (type) {
        case 'success':
            notification.style.background = '#d4edda';
            notification.style.border = '1px solid #c3e6cb';
            notification.style.color = '#155724';
            break;
        case 'error':
            notification.style.background = '#f8d7da';
            notification.style.border = '1px solid #f5c6cb';
            notification.style.color = '#721c24';
            break;
        case 'warning':
            notification.style.background = '#fff3cd';
            notification.style.border = '1px solid #ffeaa7';
            notification.style.color = '#856404';
            break;
        default:
            notification.style.background = '#d1ecf1';
            notification.style.border = '1px solid #bee5eb';
            notification.style.color = '#0c5460';
    }
    
    // Add to document
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 300);
        }
    }, 5000);
}

// Get notification icon based on type
function getNotificationIcon(type) {
    switch (type) {
        case 'success': return 'fa-check-circle';
        case 'error': return 'fa-exclamation-circle';
        case 'warning': return 'fa-exclamation-triangle';
        default: return 'fa-info-circle';
    }
}

// Set current year in footer
function setCurrentYear() {
    const yearElements = document.querySelectorAll('.current-year');
    const currentYear = new Date().getFullYear();
    
    yearElements.forEach(element => {
        element.textContent = currentYear;
    });
}

// Open modal
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

// Close modal
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Confirm action
function confirmAction(message, callback) {
    if (confirm(message)) {
        if (typeof callback === 'function') {
            callback();
        }
    }
}

// Format date
function formatDate(date, format = 'MMM dd, yyyy') {
    const d = new Date(date);
    const options = {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    return d.toLocaleDateString('en-US', options);
}

// Debounce function for performance
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

// Throttle function for performance
function throttle(func, limit) {
    let inThrottle;
    return function() {
        const args = arguments;
        const context = this;
        if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

// Load more items (for pagination)
function loadMoreItems(containerId, url, params = {}) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const loader = container.querySelector('.loader') || createLoader();
    container.appendChild(loader);
    
    fetch(url + '?' + new URLSearchParams(params))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Append new items
                container.insertAdjacentHTML('beforeend', data.html);
                
                // Update load more button if exists
                const loadMoreBtn = container.querySelector('.load-more-btn');
                if (loadMoreBtn && !data.hasMore) {
                    loadMoreBtn.remove();
                }
            }
        })
        .catch(error => console.error('Error loading more items:', error))
        .finally(() => loader.remove());
}

// Create loader element
function createLoader() {
    const loader = document.createElement('div');
    loader.className = 'loader';
    loader.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
    loader.style.textAlign = 'center';
    loader.style.padding = '20px';
    loader.style.color = 'var(--text-light)';
    return loader;
}

// Search functionality
function searchItems(searchTerm, containerId, searchUrl) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    // Show loading state
    const originalContent = container.innerHTML;
    container.innerHTML = '<div class="loader"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
    
    fetch(searchUrl + '?q=' + encodeURIComponent(searchTerm))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                container.innerHTML = data.html;
            } else {
                container.innerHTML = originalContent;
                showNotification('Search failed', 'error');
            }
        })
        .catch(error => {
            console.error('Search error:', error);
            container.innerHTML = originalContent;
            showNotification('Search error occurred', 'error');
        });
}

// Export to Excel
function exportToExcel(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) {
        showNotification('Table not found', 'error');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [], cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            // Clean up the data
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, '').replace(/(\s\s)/gm, ' ');
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        
        csv.push(row.join(','));
    }
    
    // Download CSV file
    const csvString = csv.join('\n');
    const blob = new Blob([csvString], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.setAttribute('hidden', '');
    a.setAttribute('href', url);
    a.setAttribute('download', filename + '.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Print page
function printPage() {
    window.print();
}

// Copy to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text)
        .then(() => {
            showNotification('Copied to clipboard', 'success');
        })
        .catch(err => {
            console.error('Failed to copy: ', err);
            showNotification('Failed to copy', 'error');
        });
}

// Initialize tooltips for dynamically added elements
function initDynamicTooltips() {
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length) {
                initTooltips();
            }
        });
    });
    
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
}

// Initialize when page loads
window.addEventListener('load', function() {
    // Check for notifications in URL
    const urlParams = new URLSearchParams(window.location.search);
    const notification = urlParams.get('notification');
    const notificationType = urlParams.get('notification_type') || 'info';
    
    if (notification) {
        showNotification(decodeURIComponent(notification), notificationType);
        
        // Clean URL
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
    }
    
    // Restore sidebar state
    const sidebarState = localStorage.getItem('sidebarState');
    if (sidebarState === 'closed') {
        toggleSidebar();
    }
    
    // Initialize dynamic tooltips
    initDynamicTooltips();
});

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .notification-close {
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
        margin-left: auto;
        color: inherit;
    }
    
    .loader {
        text-align: center;
        padding: 20px;
        color: var(--text-light);
    }
    
    .error {
        border-color: #dc3545 !important;
    }
    
    .error-message {
        color: #dc3545;
        font-size: 0.85rem;
        margin-top: 5px;
    }
`;
document.head.appendChild(style);
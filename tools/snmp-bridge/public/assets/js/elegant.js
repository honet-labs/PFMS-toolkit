/**
 * SNMP Bridge - Elegant Theme JavaScript
 * Interactions, animations, and UI enhancements
 */

document.addEventListener('DOMContentLoaded', function () {
    // Initialize elegant UI components
    initializeSelectAllCheckbox();
    initializeTooltips();
    initializePageTransitions();
    initializeFormValidation();
});

/**
 * Initialize Select All functionality for inventory table
 */
function initializeSelectAllCheckbox() {
    const selectAllCheckbox = document.getElementById('selectAllCheck');
    const sensorCheckboxes = document.querySelectorAll('input.sensor-check');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            sensorCheckboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = this.checked;
                }
            });
            updateSelectAllState();
        });

        // Update select all checkbox based on individual selections
        sensorCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectAllState);
        });
    }

    // Handle Select All / Unselect All buttons
    const selectAllBtn = document.getElementById('selectAll');
    const unselectAllBtn = document.getElementById('unselectAll');

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            sensorCheckboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = true;
                }
            });
            if (selectAllCheckbox) selectAllCheckbox.checked = true;
            updateSelectAllState();
        });
    }

    if (unselectAllBtn) {
        unselectAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            sensorCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateSelectAllState();
        });
    }
}

/**
 * Update select all checkbox state based on individual checkbox selections
 */
function updateSelectAllState() {
    const selectAllCheckbox = document.getElementById('selectAllCheck');
    const sensorCheckboxes = document.querySelectorAll('input.sensor-check:not([disabled])');
    const checkedCheckboxes = document.querySelectorAll('input.sensor-check:checked:not([disabled])');

    if (selectAllCheckbox) {
        selectAllCheckbox.indeterminate = 
            checkedCheckboxes.length > 0 && checkedCheckboxes.length < sensorCheckboxes.length;
        selectAllCheckbox.checked = checkedCheckboxes.length === sensorCheckboxes.length;
    }
}

/**
 * Initialize Bootstrap tooltips
 */
function initializeTooltips() {
    // Initialize all tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Initialize all popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
}

/**
 * Initialize page transition animations
 */
function initializePageTransitions() {
    // Add fade-in animation to elements with fade-in class
    const fadeInElements = document.querySelectorAll('.fade-in');
    fadeInElements.forEach((element, index) => {
        element.style.animation = `fadeIn 0.3s ease-out ${index * 50}ms both`;
    });

    // Animate cards on scroll
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.animation = 'fadeIn 0.6s ease-out forwards';
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.card, .stat-card').forEach(card => {
            observer.observe(card);
        });
    }
}

/**
 * Initialize form validation
 */
function initializeFormValidation() {
    const forms = document.querySelectorAll('form');
    
    forms.forEach(form => {
        form.addEventListener('submit', function (e) {
            // Check if form has valid class set by Bootstrap
            if (!this.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            this.classList.add('was-validated');
        });
    });

    // Real-time validation feedback
    const inputs = document.querySelectorAll('.form-control, .form-select');
    inputs.forEach(input => {
        input.addEventListener('blur', function () {
            if (this.hasAttribute('required') && !this.value) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        input.addEventListener('input', function () {
            if (this.value) {
                this.classList.remove('is-invalid');
            }
        });
    });
}

/**
 * Show loading spinner on form submission
 */
function showLoadingSpinner(formElement) {
    const submitBtn = formElement.querySelector('button[type="submit"]');
    if (submitBtn) {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
        submitBtn.disabled = true;

        // Restore button after 5 seconds (in case of error)
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 5000);
    }
}

/**
 * Display alert message
 */
function showAlert(message, type = 'info') {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert" style="animation: slideDown 0.3s ease-out;">
            <i class="fas fa-info-circle me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;

    const mainElement = document.querySelector('main');
    if (mainElement) {
        const alertContainer = document.createElement('div');
        alertContainer.innerHTML = alertHtml;
        mainElement.insertBefore(alertContainer.firstElementChild, mainElement.firstChild);
    }
}

/**
 * Copy text to clipboard
 */
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showAlert('Copied to clipboard!', 'success');
    }).catch(() => {
        showAlert('Failed to copy to clipboard', 'danger');
    });
}

/**
 * Format date to readable format
 */
function formatDate(dateString) {
    const options = { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric', 
        hour: '2-digit', 
        minute: '2-digit' 
    };
    return new Date(dateString).toLocaleDateString('en-US', options);
}

/**
 * Debounce function for search/filter inputs
 */
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

/**
 * Live search functionality
 */
function initializeLiveSearch(inputSelector, tableSelector) {
    const searchInput = document.querySelector(inputSelector);
    const table = document.querySelector(tableSelector);

    if (!searchInput || !table) return;

    const debouncedSearch = debounce(function () {
        const searchTerm = searchInput.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    }, 300);

    searchInput.addEventListener('keyup', debouncedSearch);
}

/**
 * Export table to CSV
 */
function exportTableToCSV(tableSelector, filename = 'export.csv') {
    const table = document.querySelector(tableSelector);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const csvRow = Array.from(cols).map(col => {
            // Remove any HTML tags and clean the text
            let text = col.textContent.trim();
            text = text.replace(/"/g, '""');
            return `"${text}"`;
        }).join(',');
        csv.push(csvRow);
    });

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    window.URL.revokeObjectURL(url);
}

/**
 * Initialize DataTable with elegant styling
 */
function initializeDataTable(tableSelector, options = {}) {
    if (typeof $ === 'undefined' || typeof $.fn.dataTable === 'undefined') {
        console.warn('DataTables not loaded');
        return;
    }

    const defaultOptions = {
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        language: {
            search: 'Search sensors:',
            emptyTable: 'No sensors found',
            info: 'Showing _START_ to _END_ of _TOTAL_ sensors',
            infoEmpty: 'Showing 0 sensors',
            paginate: {
                first: '<i class="fas fa-step-backward"></i>',
                last: '<i class="fas fa-step-forward"></i>',
                next: '<i class="fas fa-chevron-right"></i>',
                previous: '<i class="fas fa-chevron-left"></i>'
            }
        },
        ...options
    };

    return $(tableSelector).DataTable(defaultOptions);
}

/**
 * Initialize progress animation
 */
function animateProgress(elementSelector, targetValue, duration = 1000) {
    const element = document.querySelector(elementSelector);
    if (!element) return;

    let currentValue = 0;
    const increment = targetValue / (duration / 16);
    const interval = setInterval(() => {
        currentValue += increment;
        if (currentValue >= targetValue) {
            currentValue = targetValue;
            clearInterval(interval);
        }
        element.style.width = currentValue + '%';
        element.textContent = Math.round(currentValue) + '%';
    }, 16);
}

/**
 * Add keyboard shortcuts
 */
function initializeKeyboardShortcuts() {
    document.addEventListener('keydown', function (e) {
        // Ctrl/Cmd + S for submitting forms
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            const form = document.querySelector('form');
            if (form) form.submit();
        }

        // Escape key to close modals
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                bootstrap.Modal.getInstance(modal)?.hide();
            });
        }
    });
}

// Initialize keyboard shortcuts on page load
document.addEventListener('DOMContentLoaded', initializeKeyboardShortcuts);

/**
 * Utility: Get query parameter from URL
 */
function getQueryParam(param) {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get(param);
}

/**
 * Utility: Update URL without page reload
 */
function updateURLParam(param, value) {
    const url = new URL(window.location);
    url.searchParams.set(param, value);
    window.history.pushState({}, '', url);
}

// Export for use in other scripts
window.SnmpBridge = {
    showAlert,
    copyToClipboard,
    formatDate,
    debounce,
    initializeLiveSearch,
    exportTableToCSV,
    initializeDataTable,
    animateProgress,
    getQueryParam,
    updateURLParam
};

/**
 * CouncilRadar - Main JavaScript
 * Vanilla JS - no dependencies
 */

(function () {
    'use strict';

    // --- Mobile Navigation Toggle ---
    function initMobileNav() {
        var toggle = document.querySelector('.nav-toggle');
        var menu = document.querySelector('.nav-menu');
        if (!toggle || !menu) return;

        toggle.addEventListener('click', function () {
            var isOpen = menu.classList.toggle('is-open');
            toggle.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close menu when a nav link is clicked
        menu.querySelectorAll('.nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                menu.classList.remove('is-open');
                toggle.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            });
        });

        // Close menu on outside click
        document.addEventListener('click', function (e) {
            if (!toggle.contains(e.target) && !menu.contains(e.target)) {
                menu.classList.remove('is-open');
                toggle.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // --- Smooth Scroll for Anchor Links ---
    function initSmoothScroll() {
        document.querySelectorAll('a[href*="#"]').forEach(function (link) {
            link.addEventListener('click', function (e) {
                var href = this.getAttribute('href');
                var hash = href.indexOf('#') !== -1 ? href.substring(href.indexOf('#')) : null;
                if (!hash || hash === '#') return;

                var target = document.querySelector(hash);
                if (!target) return;

                e.preventDefault();

                var headerHeight = document.querySelector('.site-header')
                    ? document.querySelector('.site-header').offsetHeight
                    : 0;

                var targetPosition = target.getBoundingClientRect().top + window.pageYOffset - headerHeight - 16;

                window.scrollTo({
                    top: targetPosition,
                    behavior: 'smooth'
                });

                // Update URL without jumping
                if (history.pushState) {
                    history.pushState(null, null, hash);
                }
            });
        });
    }

    // --- CSRF Token Handling ---
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) return meta.getAttribute('content');
        var input = document.querySelector('input[name="csrf_token"]');
        if (input) return input.value;
        return '';
    }

    // --- Flash Message Display ---
    function showFlash(message, type) {
        type = type || 'info';
        // Remove existing flash messages
        document.querySelectorAll('.alert-flash').forEach(function (el) {
            el.remove();
        });

        var alert = document.createElement('div');
        alert.className = 'alert alert-' + type + ' alert-flash';
        alert.setAttribute('role', 'alert');
        alert.innerHTML =
            '<span>' + escapeHtml(message) + '</span>' +
            '<button class="alert-dismiss" aria-label="Dismiss">&times;</button>';

        // Insert at top of main content
        var main = document.querySelector('main');
        if (main) {
            var container = document.createElement('div');
            container.className = 'container';
            container.style.paddingTop = '1rem';
            container.appendChild(alert);
            main.insertBefore(container, main.firstChild);
        }

        // Auto-dismiss after 6 seconds
        setTimeout(function () {
            if (alert.parentNode) {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.3s';
                setTimeout(function () {
                    if (alert.parentNode) {
                        alert.parentNode.parentNode.removeChild(alert.parentNode);
                    }
                }, 300);
            }
        }, 6000);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // --- Alert Dismiss Buttons ---
    function initAlertDismiss() {
        document.addEventListener('click', function (e) {
            if (e.target.classList.contains('alert-dismiss') || e.target.closest('.alert-dismiss')) {
                var alert = e.target.closest('.alert');
                if (alert) {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.3s';
                    setTimeout(function () {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 300);
                }
            }
        });
    }

    // --- Signup Form AJAX Submission ---
    function initSignupForm() {
        var form = document.getElementById('signupForm');
        if (!form) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Clear previous errors
            form.querySelectorAll('.form-error').forEach(function (el) {
                el.remove();
            });
            form.querySelectorAll('.is-error').forEach(function (el) {
                el.classList.remove('is-error');
            });

            // Gather form data
            var emailInput = form.querySelector('[name="email"]');
            var nameInput = form.querySelector('[name="name"]');
            var orgInput = form.querySelector('[name="organization"]');
            var consentInput = form.querySelector('[name="casl_consent"]');

            var email = emailInput ? emailInput.value.trim() : '';
            var name = nameInput ? nameInput.value.trim() : '';
            var organization = orgInput ? orgInput.value.trim() : '';
            var caslConsent = consentInput ? consentInput.checked : false;

            // Validate
            var hasError = false;

            if (!email) {
                showFieldError(emailInput, 'Email address is required.');
                hasError = true;
            } else if (!isValidEmail(email)) {
                showFieldError(emailInput, 'Please enter a valid email address.');
                hasError = true;
            }

            if (!caslConsent) {
                showFieldError(consentInput, 'You must consent to receive emails to sign up.');
                hasError = true;
            }

            if (hasError) return;

            // Disable submit button
            var submitBtn = form.querySelector('[type="submit"]');
            var originalText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Submitting...';

            // Build request payload
            var payload = {
                email: email,
                name: name,
                organization: organization,
                casl_consent: caslConsent,
                csrf_token: getCsrfToken()
            };

            // Send AJAX request
            fetch('/api/subscribe.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { status: response.status, data: data };
                });
            })
            .then(function (result) {
                if (result.data.success) {
                    showFlash(result.data.message || 'You have been signed up successfully. Check your inbox.', 'success');
                    form.reset();
                } else {
                    showFlash(result.data.message || 'Something went wrong. Please try again.', 'error');
                    // Show field-specific errors if provided
                    if (result.data.errors) {
                        Object.keys(result.data.errors).forEach(function (field) {
                            var input = form.querySelector('[name="' + field + '"]');
                            if (input) {
                                showFieldError(input, result.data.errors[field]);
                            }
                        });
                    }
                }
            })
            .catch(function () {
                showFlash('A network error occurred. Please check your connection and try again.', 'error');
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
        });
    }

    function showFieldError(input, message) {
        if (!input) return;
        var wrapper = input.closest('.form-group') || input.parentNode;
        if (input.type !== 'checkbox') {
            input.classList.add('is-error');
        }
        var errorEl = document.createElement('p');
        errorEl.className = 'form-error';
        errorEl.textContent = message;
        wrapper.appendChild(errorEl);
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // --- Initialize Everything on DOM Ready ---
    document.addEventListener('DOMContentLoaded', function () {
        initMobileNav();
        initSmoothScroll();
        initAlertDismiss();
        initSignupForm();
    });

})();

/**
 * Chicago Loft Search - Public JavaScript
 *
 * Enhances the Chicago Loft Search frontend with additional functionality
 * and utility functions that complement the inline JavaScript in the search interface.
 *
 * @package Chicago_Loft_Search
 */

(function($) {
    'use strict';

    /**
     * Chicago Loft Search Public Module
     */
    const ChicagoLoftSearch = {
        /**
         * Initialize the public functionality
         */
        init: function() {
            this.setupModalDialogs();
            this.setupLoftLinks();
            this.setupAccessibility();
            this.setupResponsiveBehavior();
            this.setupCopyToClipboard();
            this.setupShareLinks();
            this.setupTooltips();
            this.setupScrollToResults();
            this.setupLazyLoading();
        },

        /**
         * Set up modal dialogs for loft details
         */
        setupModalDialogs: function() {
            // Create modal container if it doesn't exist
            if ($('.chicago-loft-modal').length === 0) {
                $('body').append(`
                    <div class="chicago-loft-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-title">
                        <div class="chicago-loft-modal-content">
                            <span class="chicago-loft-modal-close" aria-label="Close">&times;</span>
                            <div class="chicago-loft-modal-body"></div>
                        </div>
                    </div>
                `);
            }

            // Handle loft link clicks to open modal
            $(document).on('click', '.chicago-loft-link', function(e) {
                e.preventDefault();
                const mlsId = $(this).data('mls-id');
                if (!mlsId) return;
                
                ChicagoLoftSearch.openLoftModal(mlsId);
            });

            // Close modal when clicking the close button or outside the modal
            $(document).on('click', '.chicago-loft-modal-close, .chicago-loft-modal', function(e) {
                if (e.target === this) {
                    ChicagoLoftSearch.closeModal();
                }
            });

            // Close modal with escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && $('.chicago-loft-modal').is(':visible')) {
                    ChicagoLoftSearch.closeModal();
                }
            });
        },

        /**
         * Open modal with loft details
         * 
         * @param {string} mlsId The MLS ID of the loft
         */
        openLoftModal: function(mlsId) {
            const modal = $('.chicago-loft-modal');
            const modalBody = modal.find('.chicago-loft-modal-body');
            
            // Show loading state
            modalBody.html('<div class="chicago-loft-modal-loading">Loading loft details...</div>');
            modal.show();
            
            // Prevent body scrolling
            $('body').css('overflow', 'hidden');
            
            // Set focus to modal for accessibility
            modal.attr('aria-hidden', 'false');
            modal.find('.chicago-loft-modal-close').focus();
            
            // Fetch loft details via AJAX
            $.ajax({
                url: chicago_loft_search.ajax_url,
                type: 'POST',
                data: {
                    action: 'chicago_loft_search_get_loft_details',
                    mls_id: mlsId,
                    nonce: chicago_loft_search.nonce
                },
                success: function(response) {
                    if (response.success) {
                        modalBody.html(response.data.html);
                        modal.find('#modal-title').focus();
                    } else {
                        modalBody.html('<div class="chicago-loft-modal-error">Error loading loft details. Please try again.</div>');
                    }
                },
                error: function() {
                    modalBody.html('<div class="chicago-loft-modal-error">Error loading loft details. Please try again.</div>');
                }
            });
        },

        /**
         * Close the modal dialog
         */
        closeModal: function() {
            const modal = $('.chicago-loft-modal');
            modal.hide();
            modal.attr('aria-hidden', 'true');
            
            // Re-enable body scrolling
            $('body').css('overflow', '');
            
            // Return focus to the element that opened the modal
            if (ChicagoLoftSearch.lastFocusedElement) {
                ChicagoLoftSearch.lastFocusedElement.focus();
            }
        },

        /**
         * Set up loft links behavior
         */
        setupLoftLinks: function() {
            // Store the last focused element before opening modal
            $(document).on('click', '.chicago-loft-link', function() {
                ChicagoLoftSearch.lastFocusedElement = $(this);
            });
            
            // Highlight loft links when hovering over related content
            $(document).on('mouseenter', '.loft-highlight', function() {
                const mlsId = $(this).data('mls-id');
                if (mlsId) {
                    $(`.chicago-loft-link[data-mls-id="${mlsId}"]`).addClass('highlight');
                }
            }).on('mouseleave', '.loft-highlight', function() {
                const mlsId = $(this).data('mls-id');
                if (mlsId) {
                    $(`.chicago-loft-link[data-mls-id="${mlsId}"]`).removeClass('highlight');
                }
            });
        },

        /**
         * Set up accessibility enhancements
         */
        setupAccessibility: function() {
            // Add skip to results link
            $('.chicago-loft-search-container').prepend(
                '<a href="#chicago-loft-search-results" class="chicago-loft-search-skip-link">Skip to search results</a>'
            );
            
            // Make sure all interactive elements are keyboard accessible
            $('.chicago-loft-search-container button, .chicago-loft-search-container a').each(function() {
                if (!$(this).attr('tabindex')) {
                    $(this).attr('tabindex', '0');
                }
            });
            
            // Announce screen reader messages
            $(document).on('chicago_loft_search_results_loaded', function() {
                const resultsCount = $('.chicago-loft-search-results .results-content').children().length;
                const message = `Search complete. ${resultsCount} results found.`;
                
                // Create and update aria-live region
                let liveRegion = $('#chicago-loft-search-live');
                if (liveRegion.length === 0) {
                    liveRegion = $('<div id="chicago-loft-search-live" class="sr-only" aria-live="polite"></div>');
                    $('body').append(liveRegion);
                }
                
                liveRegion.text(message);
            });
        },

        /**
         * Set up responsive behavior
         */
        setupResponsiveBehavior: function() {
            // Handle window resize events
            $(window).on('resize', function() {
                ChicagoLoftSearch.adjustLayoutForScreenSize();
            });
            
            // Initial adjustment
            ChicagoLoftSearch.adjustLayoutForScreenSize();
        },

        /**
         * Adjust layout based on screen size
         */
        adjustLayoutForScreenSize: function() {
            const windowWidth = $(window).width();
            
            // Adjust example questions display
            if (windowWidth < 768) {
                $('.example-question').each(function() {
                    const text = $(this).text();
                    if (text.length > 30) {
                        $(this).attr('data-full-text', text);
                        $(this).text(text.substring(0, 27) + '...');
                    }
                });
            } else {
                $('.example-question').each(function() {
                    const fullText = $(this).attr('data-full-text');
                    if (fullText) {
                        $(this).text(fullText);
                    }
                });
            }
            
            // Adjust modal size
            if (windowWidth < 768) {
                $('.chicago-loft-modal-content').css('width', '95%');
            } else {
                $('.chicago-loft-modal-content').css('width', '80%');
            }
        },

        /**
         * Set up copy to clipboard functionality
         */
        setupCopyToClipboard: function() {
            // Add copy buttons to search results
            $(document).on('chicago_loft_search_results_loaded', function() {
                $('.chicago-loft-search-results .results-content').append(
                    '<div class="copy-results-container">' +
                    '<button class="copy-results-button">Copy Results</button>' +
                    '<span class="copy-results-message"></span>' +
                    '</div>'
                );
            });
            
            // Handle copy button clicks
            $(document).on('click', '.copy-results-button', function() {
                const resultsText = $('.chicago-loft-search-results .results-content').text();
                
                // Create temporary textarea to copy from
                const textarea = $('<textarea>').val(resultsText).appendTo('body').select();
                
                try {
                    // Execute copy command
                    document.execCommand('copy');
                    $('.copy-results-message').text('Results copied to clipboard!').fadeIn().delay(2000).fadeOut();
                } catch (err) {
                    $('.copy-results-message').text('Failed to copy results. Please try again.').fadeIn().delay(2000).fadeOut();
                }
                
                // Remove temporary textarea
                textarea.remove();
            });
        },

        /**
         * Set up share links
         */
        setupShareLinks: function() {
            // Add share button to search results
            $(document).on('chicago_loft_search_results_loaded', function() {
                if (!$('.share-results-button').length) {
                    $('.chicago-loft-search-results .results-header').append(
                        '<div class="share-results-container">' +
                        '<button class="share-results-button">Share</button>' +
                        '<div class="share-dropdown">' +
                        '<a href="#" class="share-twitter">Twitter</a>' +
                        '<a href="#" class="share-facebook">Facebook</a>' +
                        '<a href="#" class="share-email">Email</a>' +
                        '</div>' +
                        '</div>'
                    );
                }
            });
            
            // Toggle share dropdown
            $(document).on('click', '.share-results-button', function(e) {
                e.preventDefault();
                $('.share-dropdown').toggleClass('active');
            });
            
            // Handle share clicks
            $(document).on('click', '.share-twitter', function(e) {
                e.preventDefault();
                const query = $('.chicago-loft-search-input').val();
                const shareText = `Check out these Chicago lofts: "${query}" via ${window.location.href}`;
                window.open(`https://twitter.com/intent/tweet?text=${encodeURIComponent(shareText)}`, '_blank');
            });
            
            $(document).on('click', '.share-facebook', function(e) {
                e.preventDefault();
                window.open(`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(window.location.href)}`, '_blank');
            });
            
            $(document).on('click', '.share-email', function(e) {
                e.preventDefault();
                const query = $('.chicago-loft-search-input').val();
                const subject = `Chicago Loft Search Results: ${query}`;
                const body = `Check out these Chicago lofts I found:\n\n${window.location.href}`;
                window.location.href = `mailto:?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
            });
            
            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.share-results-container').length) {
                    $('.share-dropdown').removeClass('active');
                }
            });
        },

        /**
         * Set up tooltips
         */
        setupTooltips: function() {
            // Add tooltips to elements with data-tooltip attribute
            $('.chicago-loft-search-container [data-tooltip]').each(function() {
                const tooltipText = $(this).data('tooltip');
                $(this).addClass('chicago-loft-tooltip');
                $(this).append(`<span class="tooltip-text">${tooltipText}</span>`);
            });
            
            // Make tooltips accessible via keyboard
            $('.chicago-loft-tooltip').attr('tabindex', '0');
        },

        /**
         * Set up scroll to results functionality
         */
        setupScrollToResults: function() {
            // Smooth scroll to results when they load
            $(document).on('chicago_loft_search_results_loaded', function() {
                if ($('.chicago-loft-search-results').is(':visible')) {
                    $('html, body').animate({
                        scrollTop: $('.chicago-loft-search-results').offset().top - 50
                    }, 500);
                }
            });
            
            // Scroll to top button
            $('.chicago-loft-search-container').append(
                '<button class="scroll-to-top" style="display:none;" aria-label="Scroll to top">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">' +
                '<path d="M8 15a.5.5 0 0 0 .5-.5V2.707l3.146 3.147a.5.5 0 0 0 .708-.708l-4-4a.5.5 0 0 0-.708 0l-4 4a.5.5 0 1 0 .708.708L7.5 2.707V14.5a.5.5 0 0 0 .5.5z"/>' +
                '</svg>' +
                '</button>'
            );
            
            // Show/hide scroll to top button
            $(window).on('scroll', function() {
                if ($(this).scrollTop() > 300) {
                    $('.scroll-to-top').fadeIn();
                } else {
                    $('.scroll-to-top').fadeOut();
                }
            });
            
            // Scroll to top when button clicked
            $(document).on('click', '.scroll-to-top', function() {
                $('html, body').animate({ scrollTop: 0 }, 500);
                return false;
            });
        },

        /**
         * Set up lazy loading for images
         */
        setupLazyLoading: function() {
            // Check if browser supports Intersection Observer
            if ('IntersectionObserver' in window) {
                // Create observer for lazy loading images
                const imageObserver = new IntersectionObserver(function(entries, observer) {
                    entries.forEach(function(entry) {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src;
                            img.classList.remove('lazy-load');
                            imageObserver.unobserve(img);
                        }
                    });
                });
                
                // Apply observer to lazy load images
                $(document).on('chicago_loft_search_results_loaded', function() {
                    $('.chicago-loft-search-results img.lazy-load').each(function() {
                        imageObserver.observe(this);
                    });
                });
            } else {
                // Fallback for browsers that don't support Intersection Observer
                $(document).on('chicago_loft_search_results_loaded', function() {
                    $('.chicago-loft-search-results img.lazy-load').each(function() {
                        $(this).attr('src', $(this).data('src'));
                    });
                });
            }
        },

        /**
         * Utility function to format currency
         * 
         * @param {number} amount The amount to format
         * @param {string} currency The currency code (default: USD)
         * @return {string} Formatted currency string
         */
        formatCurrency: function(amount, currency = 'USD') {
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency,
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            }).format(amount);
        },

        /**
         * Utility function to format number with commas
         * 
         * @param {number} number The number to format
         * @return {string} Formatted number string
         */
        formatNumber: function(number) {
            return new Intl.NumberFormat('en-US').format(number);
        },

        /**
         * Utility function to truncate text
         * 
         * @param {string} text The text to truncate
         * @param {number} length Maximum length
         * @param {string} suffix Suffix to add (default: '...')
         * @return {string} Truncated text
         */
        truncateText: function(text, length, suffix = '...') {
            if (text.length <= length) {
                return text;
            }
            return text.substring(0, length).trim() + suffix;
        },

        /**
         * Utility function to highlight search terms in text
         * 
         * @param {string} text The text to search in
         * @param {string} searchTerm The term to highlight
         * @return {string} Text with highlighted search terms
         */
        highlightSearchTerms: function(text, searchTerm) {
            if (!searchTerm || !text) {
                return text;
            }
            
            const terms = searchTerm.split(' ').filter(term => term.length > 2);
            let highlightedText = text;
            
            terms.forEach(term => {
                const regex = new RegExp(`(${term})`, 'gi');
                highlightedText = highlightedText.replace(regex, '<mark>$1</mark>');
            });
            
            return highlightedText;
        },

        /**
         * Utility function to debounce function calls
         * 
         * @param {Function} func The function to debounce
         * @param {number} wait Wait time in milliseconds
         * @return {Function} Debounced function
         */
        debounce: function(func, wait) {
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
    };

    // Initialize when document is ready
    $(document).ready(function() {
        ChicagoLoftSearch.init();
    });

    // Expose utility functions globally
    window.ChicagoLoftSearch = {
        formatCurrency: ChicagoLoftSearch.formatCurrency,
        formatNumber: ChicagoLoftSearch.formatNumber,
        truncateText: ChicagoLoftSearch.truncateText,
        highlightSearchTerms: ChicagoLoftSearch.highlightSearchTerms,
        debounce: ChicagoLoftSearch.debounce
    };

})(jQuery);

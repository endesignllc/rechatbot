/**
 * Chicago Loft Search - Admin JavaScript
 *
 * Handles all admin-side JavaScript functionality for the Chicago Loft Search plugin.
 *
 * @package Chicago_Loft_Search
 */

(function($) {
    'use strict';

    /**
     * Chicago Loft Search Admin Module
     */
    const ChicagoLoftSearchAdmin = {
        /**
         * Initialize the admin functionality
         */
        init: function() {
            this.setupTabNavigation();
            this.setupPasswordToggle();
            this.setupTemperatureSlider();
            this.setupConditionalSettings();
            this.setupResetButtons();
            this.setupAPIKeyVerification();
            this.setupExportSettings();
            this.setupImportSettings();
            this.setupManualSync();
            this.loadAPIUsageStats();
        },

        /**
         * Set up tab navigation
         */
        setupTabNavigation: function() {
            $('.chicago-loft-search-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                const target = $(this).attr('href');
                
                // Update active tab
                $('.chicago-loft-search-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                // Show target tab content
                $('.chicago-loft-search-tab-content').removeClass('active').hide();
                $(target).addClass('active').show();
                
                // Load API usage stats when API settings tab is shown
                if (target === '#api-settings') {
                    ChicagoLoftSearchAdmin.loadAPIUsageStats();
                }
            });
            
            // Initialize tabs
            $('.chicago-loft-search-tab-content').hide();
            $('#api-settings').show();
        },

        /**
         * Set up password visibility toggle
         */
        setupPasswordToggle: function() {
            $('.toggle-password').on('click', function() {
                const target = $(this).data('target');
                const input = $('#' + target);
                
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    $(this).text(chicago_loft_search_admin.hide_text);
                } else {
                    input.attr('type', 'password');
                    $(this).text(chicago_loft_search_admin.show_text);
                }
            });
        },

        /**
         * Set up temperature slider
         */
        setupTemperatureSlider: function() {
            $('#temperature').on('input', function() {
                $('.temperature-value').text($(this).val());
            });
        },

        /**
         * Set up conditional settings visibility
         */
        setupConditionalSettings: function() {
            // Throttle settings
            $('#throttle_searches').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.throttle-settings').show();
                } else {
                    $('.throttle-settings').hide();
                }
            });
            
            // CAPTCHA settings
            $('#enable_captcha').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.captcha-settings').show();
                } else {
                    $('.captcha-settings').hide();
                }
            });
            
            // Logging settings
            $('#enable_logging').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.logging-settings').show();
                } else {
                    $('.logging-settings').hide();
                }
            });
            
            // Auto sync settings
            $('#auto_sync').on('change', function() {
                if ($(this).is(':checked')) {
                    $('.auto-sync-settings').show();
                } else {
                    $('.auto-sync-settings').hide();
                }
            });
        },

        /**
         * Set up reset buttons
         */
        setupResetButtons: function() {
            // Reset system prompt
            $('.reset-system-prompt').on('click', function() {
                if (confirm(chicago_loft_search_admin.reset_prompt_confirm)) {
                    $('#system_prompt').val(chicago_loft_search_admin.default_system_prompt);
                }
            });
            
            // Reset all settings
            $('.reset-all-settings').on('click', function() {
                if (confirm(chicago_loft_search_admin.reset_all_confirm)) {
                    window.location.href = chicago_loft_search_admin.reset_url;
                }
            });
        },

        /**
         * Set up API key verification
         */
        setupAPIKeyVerification: function() {
            $('.verify-api-key').on('click', function() {
                const apiKey = $('#openai_api_key').val();
                if (!apiKey) {
                    $('.api-key-status-message').html('<span class="error">' + chicago_loft_search_admin.enter_api_key + '</span>');
                    return;
                }
                
                $('.api-key-status-message').html('<span class="verifying">' + chicago_loft_search_admin.verifying + '</span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'chicago_loft_search_verify_api_key',
                        api_key: apiKey,
                        nonce: chicago_loft_search_admin.verify_nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.api-key-status-message').html('<span class="success">' + chicago_loft_search_admin.api_key_valid + '</span>');
                        } else {
                            $('.api-key-status-message').html('<span class="error">' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $('.api-key-status-message').html('<span class="error">' + chicago_loft_search_admin.error_verifying + '</span>');
                    }
                });
            });
        },

        /**
         * Set up export settings
         */
        setupExportSettings: function() {
            $('.export-settings-btn').on('click', function() {
                window.location.href = ajaxurl + '?action=chicago_loft_search_export_settings&nonce=' + chicago_loft_search_admin.export_nonce;
            });
        },

        /**
         * Set up import settings
         */
        setupImportSettings: function() {
            $('.import-settings-btn').on('click', function() {
                const fileInput = $('#import-settings-file')[0];
                if (fileInput.files.length === 0) {
                    $('.import-result').html('<p class="error">' + chicago_loft_search_admin.select_file + '</p>');
                    return;
                }
                
                const formData = new FormData();
                formData.append('action', 'chicago_loft_search_import_settings');
                formData.append('nonce', chicago_loft_search_admin.import_nonce);
                formData.append('settings_file', fileInput.files[0]);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        if (response.success) {
                            $('.import-result').html('<p class="success">' + chicago_loft_search_admin.import_success + '</p>');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            $('.import-result').html('<p class="error">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $('.import-result').html('<p class="error">' + chicago_loft_search_admin.import_error + '</p>');
                    }
                });
            });
        },

        /**
         * Set up manual sync
         */
        setupManualSync: function() {
            $('.manual-sync-btn').on('click', function() {
                $('.sync-status').html('<p class="syncing">' + chicago_loft_search_admin.syncing + '</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'chicago_loft_search_manual_sync',
                        nonce: chicago_loft_search_admin.sync_nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $('.sync-status').html('<p class="success">' + response.data.message + '</p>');
                        } else {
                            $('.sync-status').html('<p class="error">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $('.sync-status').html('<p class="error">' + chicago_loft_search_admin.sync_error + '</p>');
                    }
                });
            });
        },

        /**
         * Load API usage statistics
         */
        loadAPIUsageStats: function() {
            $('.api-usage-loading').show();
            $('.api-usage-results').hide();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'chicago_loft_search_get_usage_stats',
                    nonce: chicago_loft_search_admin.stats_nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('.usage-today-queries').text(response.data.today_queries);
                        $('.usage-today-tokens').text(response.data.today_tokens);
                        $('.usage-today-cost').text('$' + response.data.today_cost);
                        $('.usage-month-queries').text(response.data.month_queries);
                        $('.usage-month-tokens').text(response.data.month_tokens);
                        $('.usage-month-cost').text('$' + response.data.month_cost);
                        
                        $('.api-usage-loading').hide();
                        $('.api-usage-results').show();
                    } else {
                        $('.api-usage-loading').text(response.data.message);
                    }
                },
                error: function() {
                    $('.api-usage-loading').text(chicago_loft_search_admin.stats_error);
                }
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        ChicagoLoftSearchAdmin.init();
    });

})(jQuery);

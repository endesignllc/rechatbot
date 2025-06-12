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
            this.setupCSVImport(); // Ensure this is called
        },

        /**
         * Set up tab navigation
         */
        setupTabNavigation: function() {
            $('.chicago-loft-search-tabs .nav-tab').on('click', function(e) {
                e.preventDefault();
                const target = $(this).attr('href');
                
                $('.chicago-loft-search-tabs .nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                $('.chicago-loft-search-tab-content').removeClass('active').hide();
                $(target).addClass('active').show();
                
                if (target === '#api-settings') {
                    ChicagoLoftSearchAdmin.loadAPIUsageStats();
                }
            });
            
            let initialTab = window.location.hash || '#api-settings';
            if ($(initialTab).length === 0 || !$(initialTab).hasClass('chicago-loft-search-tab-content')) {
                initialTab = $('.chicago-loft-search-tabs .nav-tab:first').attr('href') || '#api-settings';
            }
            
            $('.chicago-loft-search-tab-content').hide();
            $(initialTab).addClass('active').show();
            $('.chicago-loft-search-tabs .nav-tab[href="' + initialTab + '"]').addClass('nav-tab-active');
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
            const tempSlider = $('#temperature');
            if (tempSlider.length) {
                $('.temperature-value').text(tempSlider.val()); 
                tempSlider.on('input', function() {
                    $('.temperature-value').text($(this).val());
                });
            }
        },

        /**
         * Set up conditional settings visibility
         */
        setupConditionalSettings: function() {
            function toggleVisibility(checkboxId, targetClass) {
                const checkbox = $(checkboxId);
                const target = $(targetClass);
                if (checkbox.length) {
                    target.toggle(checkbox.is(':checked')); 
                    checkbox.on('change', function() {
                        target.toggle($(this).is(':checked'));
                    });
                }
            }

            toggleVisibility('#throttle_searches', '.throttle-settings');
            toggleVisibility('#enable_captcha', '.captcha-settings');
            toggleVisibility('#enable_logging', '.logging-settings');
            toggleVisibility('#auto_sync', '.auto-sync-settings');
        },

        /**
         * Set up reset buttons
         */
        setupResetButtons: function() {
            $('.reset-system-prompt').on('click', function() {
                if (confirm(chicago_loft_search_admin.reset_prompt_confirm)) {
                    $('#system_prompt').val(chicago_loft_search_admin.default_system_prompt);
                }
            });
            
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
                const $button = $(this);
                const originalText = $button.text();
                $button.prop('disabled', true).text(chicago_loft_search_admin.syncing);
                $('.sync-status').html('<p class="syncing">' + chicago_loft_search_admin.syncing + '</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'chicago_loft_search_manual_sync',
                        nonce: chicago_loft_search_admin.sync_nonce
                    },
                    success: function(response) {
                        $button.prop('disabled', false).text(originalText);
                        if (response.success) {
                            $('.sync-status').html('<p class="success">' + response.data.message + '</p>');
                        } else {
                            $('.sync-status').html('<p class="error">' + response.data.message + '</p>');
                        }
                    },
                    error: function() {
                        $button.prop('disabled', false).text(originalText);
                        $('.sync-status').html('<p class="error">' + chicago_loft_search_admin.sync_error + '</p>');
                    }
                });
            });
        },

        /**
         * Set up CSV import functionality
         */
        setupCSVImport: function() {
            // Handle file upload for preview
            $('#csv-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                const fileInput = $('#mls_csv_file')[0];
                if (fileInput.files.length === 0) {
                    $('.csv-upload-status').html('<p class="error">Please select a CSV file.</p>');
                    return;
                }
                
                const $submitButton = $(this).find('button[type="submit"]');
                const originalText = $submitButton.text();
                $submitButton.prop('disabled', true).text('Uploading...');
                $('.csv-preview-container').html(''); 
                $('.csv-upload-status').html(''); 
                
                const formData = new FormData(this);
                formData.append('action', 'chicago_loft_search_parse_csv_preview');
                formData.append('nonce', chicago_loft_search_admin.csv_import_nonce);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        $submitButton.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            window.csvRawData = response.data.raw_data_for_import || response.data.raw_data;
                            window.detectedListingType = response.data.listing_type || 'loft';
                            
                            const previewData = response.data.preview_data;
                            if (previewData && previewData.length > 0) {
                                ChicagoLoftSearchAdmin.buildPreviewTable(previewData);
                                $('.csv-upload-status').html('<p class="success notice notice-success is-dismissible">CSV preview loaded. Review the data and click "Confirm and Start Import".</p>');
                            } else {
                                $('.csv-preview-container').html('<p class="error notice notice-error">No preview data available. The CSV might be empty or incorrectly formatted.</p>');
                            }
                        } else {
                            $('.csv-preview-container').html('<p class="error notice notice-error">' + (response.data.message || 'Error parsing CSV.') + '</p>');
                        }
                    },
                    error: function(xhr) {
                        $submitButton.prop('disabled', false).text(originalText);
                        $('.csv-preview-container').html('<p class="error notice notice-error">Error processing CSV file. Server said: ' + (xhr.responseText || 'Unknown error') +'</p>');
                    }
                });
            });
            
            // Handle import button click (now with batching)
            // Ensuring this selector matches the button ID in import-page.php
            $(document).on('click', '#confirm-import-btn', function(e) { 
                e.preventDefault();
                
                if (!window.csvRawData || window.csvRawData.length === 0) {
                    if ($('.import-status').length === 0) {
                        $('.csv-preview-container').append('<div class="import-status" style="margin-top: 20px;"></div>');
                    }
                    $('.import-status').html('<div class="notice notice-error"><p>No data to import. Please upload a CSV file first.</p></div>');
                    return;
                }
                
                const $button = $(this);
                const originalButtonText = $button.text();
                $button.prop('disabled', true).text('Importing...');
                $('.csv-preview-container .preview-actions .button').not($button).prop('disabled', true);


                const $importStatusDiv = $('.import-status');
                $importStatusDiv.html(
                    '<div class="import-progress-summary"></div>' +
                    '<div class="import-progress-bar-container" style="width: 100%; background-color: #f3f3f3; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 10px; overflow: hidden;">' +
                        '<div class="import-progress-bar" style="width: 0%; height: 24px; background-color: #4CAF50; text-align: center; line-height: 24px; color: white; border-radius: 4px; transition: width 0.2s ease-in-out;">0%</div>' +
                    '</div>' +
                    '<ul class="import-results-log" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px; margin-top:10px; background:#f9f9f9;"></ul>' +
                    '<div class="import-final-actions" style="margin-top:15px;"></div>'
                );
                const $progressSummary = $importStatusDiv.find('.import-progress-summary');
                const $progressBar = $importStatusDiv.find('.import-progress-bar');
                const $resultsLog = $importStatusDiv.find('.import-results-log');
                const $finalActions = $importStatusDiv.find('.import-final-actions');
                
                $progressSummary.html('<p>Preparing import...</p>');
                
                const allDataToImport = [];
                $('.preview-row').each(function(index) {
                    const rowData = JSON.parse(JSON.stringify(window.csvRawData[index])); 
                    $(this).find('input[type="text"], textarea').each(function() {
                        const fieldName = $(this).data('field');
                        if (fieldName && rowData && rowData.preview_item_for_js) {
                            rowData.preview_item_for_js[fieldName] = $(this).val();
                        }
                    });
                    allDataToImport.push(rowData); 
                });
                
                const batchSize = 20; 
                let currentRecordIndex = 0;
                const totalRecords = allDataToImport.length;
                let successCount = 0;
                let errorCount = 0;

                function importNextBatch() {
                    if (currentRecordIndex >= totalRecords) {
                        let summaryMessage = 'Import process finished. Total records: ' + totalRecords + ', Successful: ' + successCount + ', Errors: ' + errorCount + '.';
                        $progressSummary.html('<p class="notice ' + (errorCount === 0 ? 'notice-success' : (successCount > 0 ? 'notice-warning' : 'notice-error')) +' is-dismissible">' + summaryMessage + '</p>');
                        $progressBar.css('width', '100%').text('100%');
                        $button.prop('disabled', false).text(originalButtonText);
                        $('.csv-preview-container .preview-actions .button').not($button).prop('disabled', false);
                        
                        if (successCount > 0) {
                            $finalActions.html('<p><a href="admin.php?page=chicago-loft-search-listings" class="button button-primary">View All Listings</a></p>');
                        }
                        return;
                    }

                    const batch = allDataToImport.slice(currentRecordIndex, currentRecordIndex + batchSize);
                    const currentBatchEndIndex = Math.min(currentRecordIndex + batch.length, totalRecords);
                    $progressSummary.html('<p>Importing records ' + (currentRecordIndex + 1) + ' to ' + currentBatchEndIndex + ' of ' + totalRecords + '...</p>');
                    
                    let progressPercent = Math.round(((currentRecordIndex) / totalRecords) * 100);
                    $progressBar.css('width', progressPercent + '%').text(progressPercent + '%');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'chicago_loft_search_import_listings_batch',
                            nonce: chicago_loft_search_admin.csv_import_nonce,
                            listings_to_import: JSON.stringify(batch),
                            listing_type: window.detectedListingType 
                        },
                        success: function(response) {
                            if (response.success && response.data.results && Array.isArray(response.data.results)) {
                                response.data.results.forEach(function(result) {
                                    const className = result.success ? 'success' : 'error';
                                    if(result.success) successCount++; else errorCount++;
                                    $resultsLog.prepend('<li class="' + className + '">' + (result.message || 'Unknown result') + '</li>');
                                });
                            } else {
                                errorCount += batch.length; 
                                $resultsLog.prepend('<li class="error">Batch error: ' + (response.data && response.data.message ? response.data.message : 'Unknown error processing batch.') + ' (Records ' + (currentRecordIndex + 1) + '-' + currentBatchEndIndex + ')</li>');
                            }
                        },
                        error: function(xhr) {
                            errorCount += batch.length;
                            $resultsLog.prepend('<li class="error">AJAX Error for batch (Records ' + (currentRecordIndex + 1) + '-' + currentBatchEndIndex + '). Server said: ' + (xhr.statusText || 'No response') + '</li>');
                        },
                        complete: function() {
                            currentRecordIndex += batch.length; 
                            let finalProgressPercent = Math.round((currentRecordIndex / totalRecords) * 100);
                            $progressBar.css('width', finalProgressPercent + '%').text(finalProgressPercent + '%');
                            importNextBatch();
                        }
                    });
                }
                importNextBatch(); 
            });
            
            $(document).on('click', '.import-final-actions .button.reload-page, .import-status .reload-page', function() {
                window.location.href = 'admin.php?page=chicago-loft-search-listings';
            });
        },

        /**
         * Build preview table based on actual CSV data
         */
        buildPreviewTable: function(previewData) {
            if (!previewData || previewData.length === 0) {
                $('.csv-preview-container').html('<p>No data to preview.</p>');
                return;
            }
            
            const allKeys = new Set();
            previewData.forEach(item => { 
                Object.keys(item).forEach(key => {
                    if (key !== 'listing_type' && key !== 'original_csv_data' && key !== 'preview_item_for_js') { 
                        allKeys.add(key);
                    }
                });
            });
            
            const priorityFields = ['mls_id', 'address', 'building_name', 'agent_name', 'neighborhood', 'price', 'bedrooms', 'bathrooms', 'square_feet', 'year_built', 'description'];
            const editableFields = ['mls_id', 'address', 'building_name', 'agent_name', 'neighborhood', 'description', 'price', 'bedrooms', 'bathrooms', 'square_feet', 'year_built', 'units', 'floors', 'hoa_fee', 'pet_policy', 'amenities', 'email', 'phone', 'specialty', 'license', 'image_urls', 'status'];
            
            const sortedKeys = [...allKeys].sort((a, b) => {
                const aIsPriority = priorityFields.includes(a);
                const bIsPriority = priorityFields.includes(b);
                if (aIsPriority && !bIsPriority) return -1;
                if (!aIsPriority && bIsPriority) return 1;
                if (aIsPriority && bIsPriority) return priorityFields.indexOf(a) - priorityFields.indexOf(b);
                return a.localeCompare(b); 
            });
            
            let tableHtml = '<div class="csv-preview-wrapper"><table class="wp-list-table widefat striped csv-preview-table">';
            tableHtml += '<thead><tr>';
            tableHtml += '<th>Row</th>';
            
            sortedKeys.forEach(key => {
                let headerText = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                tableHtml += '<th>' + headerText + (editableFields.includes(key) ? ' <span class="dashicons dashicons-edit" title="Editable"></span>' : '') + '</th>';
            });
            
            tableHtml += '</tr></thead><tbody>';
            
            previewData.forEach((item, index) => { 
                tableHtml += '<tr class="preview-row" data-row-id="' + index + '">';
                tableHtml += '<td>' + (index + 1) + '</td>';
                
                sortedKeys.forEach(key => {
                    const value = item[key] !== undefined && item[key] !== null ? String(item[key]) : '';
                    let cellValue;
                    if (key === 'description' && editableFields.includes(key)) {
                         cellValue = '<textarea data-field="' + key + '" class="regular-text" rows="2">' + value.replace(/"/g, '&quot;') + '</textarea>';
                    } else if (editableFields.includes(key)) {
                        cellValue = '<input type="text" data-field="' + key + '" value="' + value.replace(/"/g, '&quot;') + '" class="regular-text">';
                    } else {
                        cellValue = value.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); 
                    }
                    tableHtml += '<td>' + cellValue + '</td>';
                });
                
                tableHtml += '</tr>';
            });
            
            tableHtml += '</tbody></table></div>'; 
            
            tableHtml += '<div class="preview-actions" style="margin-top: 20px; padding-top:15px; border-top:1px solid #ddd;">';
            tableHtml += '<button id="confirm-import-btn" class="button button-primary">Confirm and Start Import</button>'; // This ID should match the event handler
            tableHtml += ' <a href="' + window.location.pathname + '?page=' + new URLSearchParams(window.location.search).get('page') + '" class="button">Cancel / Upload New File</a>';
            tableHtml += '</div>';
            
            tableHtml += '<div class="import-status" style="margin-top: 20px; padding-top:15px; border-top:1px solid #ddd;"></div>';
            
            $('.csv-preview-container').html(tableHtml);
        },

        /**
         * Load API usage statistics
         */
        loadAPIUsageStats: function() {
            const $loadingEl = $('.api-usage-loading');
            const $resultsEl = $('.api-usage-results');
            
            if (!$loadingEl.length || !$resultsEl.length) return; 

            $loadingEl.show();
            $resultsEl.hide();
            
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
                        
                        $loadingEl.hide();
                        $resultsEl.show();
                    } else {
                        $loadingEl.html('<p class="error">' + (response.data.message || chicago_loft_search_admin.stats_error) + '</p>');
                    }
                },
                error: function() {
                    $loadingEl.html('<p class="error">' + chicago_loft_search_admin.stats_error + '</p>');
                }
            });
        }
    };

    $(document).ready(function() {
        ChicagoLoftSearchAdmin.init();
    });

})(jQuery);

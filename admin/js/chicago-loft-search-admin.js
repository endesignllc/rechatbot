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
        // Store CSV data at a scope accessible by different methods within this module
        csvHeaders: [],
        previewDisplayData: [], // For building the preview table: [{ mls_id, original_data_preview: {H1:V1, ...}}, ...]
        batchImportPayloadTemplate: [], // For building the final import payload: [{ preview_item_for_js:{mls_id}, original_csv_data:{H1:V1,...}, listing_type }, ...]

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
            // Handle file upload for preview (button ID from import-page.php)
            $('#process-csv-button').on('click', function(e) {
                e.preventDefault();
                
                const fileInput = $('#mls_csv_file')[0];
                if (fileInput.files.length === 0) {
                    $('.csv-upload-status').html('<p class="error">Please select a CSV file.</p>');
                     // Make sure preview section is hidden if no file
                    $('#preview-section').hide();
                    $('#progress-section').hide();
                    return;
                }
                
                const $submitButton = $(this);
                const originalText = $submitButton.text();
                $submitButton.prop('disabled', true).text('Processing...');
                $('#preview-section').hide(); // Hide preview while processing new file
                $('.csv-preview-container').html(''); 
                $('.csv-upload-status').html(''); 
                
                const formData = new FormData();
                formData.append('action', 'chicago_loft_search_parse_csv_preview');
                formData.append('nonce', chicago_loft_search_admin.csv_import_nonce);
                formData.append('mls_csv_file', fileInput.files[0]);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    success: function(response) {
                        $submitButton.prop('disabled', false).text(originalText);
                        
                        if (response.success) {
                            ChicagoLoftSearchAdmin.csvHeaders = response.data.headers || [];
                            ChicagoLoftSearchAdmin.previewDisplayData = response.data.preview_data || [];
                            ChicagoLoftSearchAdmin.batchImportPayloadTemplate = response.data.raw_data_for_import || [];
                            
                            if (ChicagoLoftSearchAdmin.previewDisplayData.length > 0 && ChicagoLoftSearchAdmin.csvHeaders.length > 0) {
                                ChicagoLoftSearchAdmin.buildPreviewTable(ChicagoLoftSearchAdmin.csvHeaders, ChicagoLoftSearchAdmin.previewDisplayData);
                                $('.csv-upload-status').html('<p class="success notice notice-success is-dismissible">CSV preview loaded. Review the data and click "Confirm and Start Import".</p>');
                                $('#upload-section').slideUp();
                                $('#preview-section').slideDown(); // Show preview section
                            } else {
                                $('.csv-preview-container').html('<p class="error notice notice-error">No data or headers found in CSV to preview.</p>');
                                $('#preview-section').hide(); // Keep it hidden
                            }
                        } else {
                            $('.csv-preview-container').html('<p class="error notice notice-error">' + (response.data.message || 'Error parsing CSV.') + '</p>');
                            $('#preview-section').show(); // Show section to display error
                        }
                    },
                    error: function(xhr) {
                        $submitButton.prop('disabled', false).text(originalText);
                        $('.csv-preview-container').html('<p class="error notice notice-error">Error processing CSV file. Server said: ' + (xhr.responseText || 'Unknown error') +'</p>');
                        $('#preview-section').show(); // Show section to display error
                    },
                    complete: function() {
                         // Ensure process button is re-enabled
                        $('#process-csv-button').prop('disabled', false).text('Preview CSV Data');
                    }
                });
            });
            
            // Handle import button click
            $(document).on('click', '#confirm-import-btn', function(e) {
                e.preventDefault();
                
                if (!ChicagoLoftSearchAdmin.batchImportPayloadTemplate || ChicagoLoftSearchAdmin.batchImportPayloadTemplate.length === 0) {
                    const $statusDiv = $('#preview-section .import-status').length ? $('#preview-section .import-status') : ($('.csv-preview-container').append('<div class="import-status" style="margin-top: 20px;"></div>'), $('#preview-section .import-status'));
                    $statusDiv.html('<div class="notice notice-error"><p>No data to import. Please upload and preview a CSV file first.</p></div>');
                    return;
                }
                
                const $button = $(this);
                const originalButtonText = $button.text();
                $button.prop('disabled', true).text('Importing...');
                $('#preview-section .preview-actions .button').not($button).prop('disabled', true); // Disable cancel button too

                const $importStatusContainer = $('#progress-section'); // Use the dedicated progress section
                const $importStatusDiv = $importStatusContainer.find('#import-status-message'); // For summary messages
                const $progressBarContainer = $importStatusContainer.find('#import-progress-bar-container');
                const $progressBar = $importStatusContainer.find('#import-progress-bar');
                const $resultsLog = $importStatusContainer.find('#import-log');
                
                $('#preview-section').slideUp();
                $importStatusContainer.slideDown();

                $importStatusDiv.html('<p>Preparing import...</p>');
                $progressBar.css('width', '0%').text('0%');
                $resultsLog.empty();
                
                const listingsToImport = [];
                $('#data-preview-table tbody tr').each(function(index) {
                    const editedMlsId = $(this).find('.editable-mls-id').val();
                    
                    // Clone the template item for this row
                    const importItemPayload = JSON.parse(JSON.stringify(ChicagoLoftSearchAdmin.batchImportPayloadTemplate[index]));
                    
                    // Update the MLS ID in the preview_item_for_js part of the payload
                    if (importItemPayload && importItemPayload.preview_item_for_js) {
                        importItemPayload.preview_item_for_js.mls_id = editedMlsId;
                    } else {
                        // Fallback or error if structure is not as expected
                        console.error("Payload structure error for item at index: ", index, importItemPayload);
                        // Ensure mls_id is at least set if preview_item_for_js was missing
                        if(!importItemPayload.preview_item_for_js) importItemPayload.preview_item_for_js = {};
                        importItemPayload.preview_item_for_js.mls_id = editedMlsId;
                    }
                    listingsToImport.push(importItemPayload); 
                });
                
                const batchSize = 20; 
                let currentRecordIndex = 0;
                const totalRecords = listingsToImport.length;
                let successCount = 0;
                let errorCount = 0;

                function importNextBatch() {
                    if (currentRecordIndex >= totalRecords) {
                        let summaryMessage = 'Import process finished. Total records: ' + totalRecords + ', Successful: ' + successCount + ', Errors: ' + errorCount + '.';
                        $importStatusDiv.html('<p class="notice ' + (errorCount === 0 ? 'notice-success' : (successCount > 0 ? 'notice-warning' : 'notice-error')) +' is-dismissible">' + summaryMessage + '</p>');
                        $progressBar.css('width', '100%').text('100%');
                        $button.prop('disabled', false).text(originalButtonText);
                        $('#preview-section .preview-actions .button').not($button).prop('disabled', false); // Re-enable cancel
                        
                        if (successCount > 0) {
                             $resultsLog.append('<li><a href="admin.php?page=chicago-loft-search-listings" class="button button-secondary">View Imported Listings</a></li>');
                        }
                        // Option to upload another file
                        $('#process-csv-button').prop('disabled', false).text('Preview Another CSV');
                        $('#upload-section').slideDown();

                        return;
                    }

                    const batch = listingsToImport.slice(currentRecordIndex, currentRecordIndex + batchSize);
                    const currentBatchEndIndex = Math.min(currentRecordIndex + batch.length, totalRecords);
                    $importStatusDiv.html('<p>Importing records ' + (currentRecordIndex + 1) + ' to ' + currentBatchEndIndex + ' of ' + totalRecords + '...</p>');
                    
                    let progressPercent = Math.round(((currentRecordIndex) / totalRecords) * 100);
                    $progressBar.css('width', progressPercent + '%').text(progressPercent + '%');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'chicago_loft_search_import_listings_batch',
                            nonce: chicago_loft_search_admin.csv_import_nonce,
                            listings_to_import: JSON.stringify(batch), // This is the array of {preview_item_for_js, original_csv_data, listing_type}
                            listing_type: ChicagoLoftSearchAdmin.batchImportPayloadTemplate[0] ? ChicagoLoftSearchAdmin.batchImportPayloadTemplate[0].listing_type : 'imported_csv_item' // Send overall type
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
            
            // Handle Cancel / Upload New File button on preview screen
            $(document).on('click', '#cancel-preview-button', function() {
                $('#preview-section').slideUp();
                $('#upload-section').slideDown();
                $('#mls_csv_file').val(''); // Clear file input
                ChicagoLoftSearchAdmin.csvHeaders = [];
                ChicagoLoftSearchAdmin.previewDisplayData = [];
                ChicagoLoftSearchAdmin.batchImportPayloadTemplate = [];
                $('.csv-preview-container').html('');
                $('.csv-upload-status').html('');
            });
        },

        /**
         * Build preview table based on actual CSV data
         */
        buildPreviewTable: function(headers, previewItems) {
            const $previewContainer = $('.csv-preview-container');
            $previewContainer.html(''); // Clear previous preview

            if (!headers || headers.length === 0 || !previewItems || previewItems.length === 0) {
                $previewContainer.html('<p class="notice notice-warning">No data to preview or headers are missing.</p>');
                return;
            }
            
            let tableHtml = '<div class="csv-preview-wrapper" style="max-height: 600px; overflow: auto;"><table id="data-preview-table" class="wp-list-table widefat fixed striped csv-preview-table">';
            
            // Build table header
            tableHtml += '<thead><tr>';
            tableHtml += '<th>MLS ID (Editable)</th>'; // First column is always editable MLS ID
            headers.forEach(headerName => {
                tableHtml += '<th>' + headerName.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</th>';
            });
            tableHtml += '</tr></thead><tbody>';
            
            // Build table rows
            previewItems.forEach((item, index) => { // item here is { mls_id, original_data_preview: {H1:V1,...} }
                tableHtml += '<tr class="preview-row" data-row-index="' + index + '">';
                // MLS ID cell (editable)
                tableHtml += '<td><input type="text" class="editable-mls-id regular-text" value="' + (item.mls_id || '').replace(/"/g, '&quot;') + '"></td>';
                
                // Original data cells (read-only)
                headers.forEach(headerName => {
                    const value = item.original_data_preview[headerName] !== undefined && item.original_data_preview[headerName] !== null ? String(item.original_data_preview[headerName]) : '';
                    tableHtml += '<td>' + value.replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') + '</td>';
                });
                
                tableHtml += '</tr>';
            });
            
            tableHtml += '</tbody></table></div>'; 
            
            // Add action buttons and status area if not already part of the static HTML structure targeted by selectors
            // Assuming #preview-section contains these or similar elements.
            // If not, they should be appended here or ensured they exist in import-page.php.
            // For this example, assuming the structure in import-page.php is sufficient and we just fill the table.
            
            $previewContainer.html(tableHtml);

            // Ensure the import status div exists within the preview section for messages
            if ($('#preview-section .import-status').length === 0) {
                 $('#preview-section .preview-actions').after('<div class="import-status" style="margin-top: 20px; padding-top:15px; border-top:1px solid #ddd;"></div>');
            }
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

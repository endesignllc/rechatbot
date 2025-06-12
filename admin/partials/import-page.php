<?php
/**
 * Admin page for importing MLS data for Chicago Loft Search plugin.
 *
 * @package Chicago_Loft_Search
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<div class="wrap chicago-loft-search-import-page">
    <h1><?php esc_html_e( 'Import Chicago Loft Listings (Flexible CSV)', 'chicago-loft-search' ); ?></h1>
    <p><?php esc_html_e( 'Upload a CSV file with your loft data. The plugin will import available information, and ChatGPT will intelligently use this data for searches. Fields like Price, Bedrooms, etc., are optional. The original CSV row data will be stored for context.', 'chicago-loft-search' ); ?></p>

    <div class="import-section" id="upload-section">
        <h2><?php esc_html_e( 'Step 1: Upload CSV File', 'chicago-loft-search' ); ?></h2>
        <form id="mls-csv-upload-form">
            <?php wp_nonce_field( 'chicago_loft_search_import_nonce', 'import_nonce_field' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="mls_csv_file"><?php esc_html_e( 'Select CSV File', 'chicago-loft-search' ); ?></label>
                    </th>
                    <td>
                        <input type="file" id="mls_csv_file" name="mls_csv_file" accept=".csv" required>
                        <p class="description"><?php esc_html_e( 'The CSV file should include a header row. The plugin will attempt to map known columns (Address, YearBuilt, etc.) and store all other data for ChatGPT context.', 'chicago-loft-search' ); ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="button" id="process-csv-button" class="button button-primary"><?php esc_html_e( 'Preview CSV Data', 'chicago-loft-search' ); ?></button>
            </p>
        </form>
    </div>

    <div class="import-section" id="preview-section" style="display:none;">
        <h2><?php esc_html_e( 'Step 2: Preview Data to be Imported', 'chicago-loft-search' ); ?></h2>
        <p><?php esc_html_e( 'Review the data that will be imported. You can make minor corrections to MLS ID, Address, Neighborhood, Description, or Image URLs if needed. Other fields will be imported as-is or left blank if not present in your CSV. ChatGPT will use all available data.', 'chicago-loft-search' ); ?></p>
        <div class="table-wrapper">
            <table id="data-preview-table" class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Original Address (from CSV)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'MLS ID (Editable)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Address (Editable)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Neighborhood (Editable)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Price (from CSV, or blank)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Beds (from CSV, or blank)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Baths (from CSV, or blank)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'SqFt (from CSV, or blank)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Year Built (from CSV)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Description (Auto-generated/Editable)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Image URLs (Editable, CSV)', 'chicago-loft-search' ); ?></th>
                        <th><?php esc_html_e( 'Features (Auto-generated)', 'chicago-loft-search' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Data rows will be populated by JavaScript -->
                </tbody>
            </table>
        </div>
        <p class="submit">
            <button type="button" id="confirm-import-btn" class="button button-primary"><?php esc_html_e( 'Confirm and Start Import', 'chicago-loft-search' ); ?></button>
            <button type="button" id="cancel-preview-button" class="button button-secondary"><?php esc_html_e( 'Cancel / Upload New File', 'chicago-loft-search' ); ?></button>
        </p>
    </div>

    <div class="import-section" id="progress-section" style="display:none;">
        <h2><?php esc_html_e( 'Step 3: Import Progress', 'chicago-loft-search' ); ?></h2>
        <div id="import-progress-bar-container">
            <div id="import-progress-bar"></div>
        </div>
        <div id="import-status-message"></div>
        <div id="import-log-container">
            <h3><?php esc_html_e( 'Import Log:', 'chicago-loft-search' ); ?></h3>
            <ul id="import-log"></ul>
        </div>
    </div>

</div>

<style>
    .chicago-loft-search-import-page .import-section {
        margin-bottom: 30px;
        padding: 20px;
        background: #fff;
        border: 1px solid #ccd0d4;
        box-shadow: 0 1px 1px rgba(0,0,0,.04);
    }
    .chicago-loft-search-import-page .table-wrapper {
        max-height: 600px;
        overflow-x: auto;
        overflow-y: auto;
        border: 1px solid #ccd0d4;
    }
    .chicago-loft-search-import-page #data-preview-table th,
    .chicago-loft-search-import-page #data-preview-table td {
        padding: 8px;
        vertical-align: top;
        font-size: 12px;
    }
    .chicago-loft-search-import-page #data-preview-table input[type="text"],
    .chicago-loft-search-import-page #data-preview-table input[type="number"],
    .chicago-loft-search-import-page #data-preview-table textarea {
        width: 98%;
        padding: 4px;
    }
    .chicago-loft-search-import-page #data-preview-table .display-only {
        background-color: #f0f0f0;
        color: #333;
        padding: 6px;
        display: block;
        min-height: 28px; /* Match input height */
        border: 1px solid #ddd;
    }
    .chicago-loft-search-import-page #data-preview-table textarea {
        min-height: 60px;
    }
    .chicago-loft-search-import-page #import-progress-bar-container {
        width: 100%;
        background-color: #f3f3f3;
        border: 1px solid #ccc;
        border-radius: 4px;
        margin-bottom: 10px;
    }
    .chicago-loft-search-import-page #import-progress-bar {
        width: 0%;
        height: 20px;
        background-color: #4CAF50;
        text-align: center;
        line-height: 20px;
        color: white;
        border-radius: 4px;
        transition: width 0.3s ease-in-out;
    }
    .chicago-loft-search-import-page #import-log-container {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #ccd0d4;
        padding: 10px;
        background: #f9f9f9;
        margin-top: 10px;
    }
    .chicago-loft-search-import-page #import-log li {
        padding: 3px 0;
        border-bottom: 1px dotted #eee;
        font-size: 12px;
    }
    .chicago-loft-search-import-page #import-log li.error {
        color: red;
    }
    .chicago-loft-search-import-page #import-log li.success {
        color: green;
    }
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
    const nonce = $('#import_nonce_field').val();
    let rawCsvData = []; // To store initially parsed data for resubmission

    $('#process-csv-button').on('click', function() {
        const fileInput = $('#mls_csv_file')[0];
        if (!fileInput.files.length) {
            alert('<?php esc_html_e( 'Please select a CSV file to process.', 'chicago-loft-search' ); ?>');
            return;
        }

        const file = fileInput.files[0];
        const formData = new FormData();
        formData.append('action', 'chicago_loft_search_parse_csv_preview');
        formData.append('nonce', nonce);
        formData.append('mls_csv_file', file);

        $(this).prop('disabled', true).text('<?php esc_html_e( 'Processing...', 'chicago-loft-search' ); ?>');
        $('#preview-section').hide();
        $('#progress-section').hide();
        $('#import-log').empty();
        $('#import-status-message').text('');

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    rawCsvData = response.data.raw_data; // Store raw data
                    populatePreviewTable(response.data.preview_data);
                    $('#upload-section').slideUp();
                    $('#preview-section').slideDown();
                } else {
                    alert('<?php esc_html_e( 'Error processing CSV: ', 'chicago-loft-search' ); ?>' + response.data.message);
                }
            },
            error: function(xhr, status, error) {
                alert('<?php esc_html_e( 'AJAX Error: Could not process CSV. Check console for details.', 'chicago-loft-search' ); ?>');
                console.error(xhr.responseText);
            },
            complete: function() {
                $('#process-csv-button').prop('disabled', false).text('<?php esc_html_e( 'Preview CSV Data', 'chicago-loft-search' ); ?>');
            }
        });
    });

    function populatePreviewTable(data) {
        const tbody = $('#data-preview-table tbody');
        tbody.empty();

        data.forEach(function(row, index) {
            const tr = $('<tr>').attr('data-row-index', index);
            tr.append($('<td>').text(row.original_address || 'N/A'));
            tr.append($('<td>').append($('<input type="text" class="mls-id-input">').val(row.mls_id || '')));
            tr.append($('<td>').append($('<input type="text" class="address-input">').val(row.address || '')));
            tr.append($('<td>').append($('<input type="text" class="neighborhood-input">').val(row.neighborhood || '')));
            
            // Display-only for potentially missing critical data
            tr.append($('<td>').append($('<span class="display-only">').text(row.price || 'N/A')));
            tr.append($('<td>').append($('<span class="display-only">').text(row.bedrooms || 'N/A')));
            tr.append($('<td>').append($('<span class="display-only">').text(row.bathrooms || 'N/A')));
            tr.append($('<td>').append($('<span class="display-only">').text(row.square_feet || 'N/A')));
            
            tr.append($('<td>').text(row.year_built || 'N/A'));
            tr.append($('<td>').append($('<textarea class="description-input">').val(row.description || '')));
            tr.append($('<td>').append($('<input type="text" placeholder="Comma-separated URLs" class="image-urls-input">').val(row.image_urls || '')));
            tr.append($('<td>').text(row.features_preview || 'N/A'));
            tbody.append(tr);
        });
    }

    $('#start-import-button').on('click', function() {
        const listingsToImport = [];
        $('#data-preview-table tbody tr').each(function(index) {
            const tr = $(this);
            const originalRowData = rawCsvData[index] || {};
            
            const listing = {
                original_csv_data: originalRowData, 
                mls_id: tr.find('.mls-id-input').val(),
                address: tr.find('.address-input').val(),
                neighborhood: tr.find('.neighborhood-input').val(),
                // These will be taken from original_csv_data on the backend if not directly editable or present
                // For this simplified version, we're not re-collecting them from display-only spans
                price: originalRowData['Price'] || null, // Example: assuming 'Price' is a header in your CSV
                bedrooms: originalRowData['Bedrooms'] || null, // Example
                bathrooms: originalRowData['Bathrooms'] || null, // Example
                square_feet: originalRowData['SquareFeet'] || null, // Example
                year_built: originalRowData['YearBuilt'] || null,
                description: tr.find('.description-input').val(),
                image_urls: tr.find('.image-urls-input').val().split(',').map(url => url.trim()).filter(url => url),
                status: 'active'
            };
            listingsToImport.push(listing);
        });

        if (listingsToImport.length === 0) {
            alert('<?php esc_html_e( 'No data to import.', 'chicago-loft-search' ); ?>');
            return;
        }

        $(this).prop('disabled', true).text('<?php esc_html_e( 'Importing...', 'chicago-loft-search' ); ?>');
        $('#cancel-preview-button').prop('disabled', true);
        $('#preview-section').slideUp();
        $('#progress-section').slideDown();
        $('#import-progress-bar').css('width', '0%').text('0%');
        $('#import-log').empty();
        $('#import-status-message').text('<?php esc_html_e( 'Starting import...', 'chicago-loft-search' ); ?>');

        const batchSize = 10;
        let currentBatch = 0;
        const totalListings = listingsToImport.length;

        function importBatch() {
            const start = currentBatch * batchSize;
            const end = Math.min(start + batchSize, totalListings);
            const batchData = listingsToImport.slice(start, end);

            if (batchData.length === 0) {
                $('#import-status-message').text('<?php esc_html_e( 'Import completed!', 'chicago-loft-search' ); ?>');
                $('#start-import-button').prop('disabled', false).text('<?php esc_html_e( 'Confirm and Start Import', 'chicago-loft-search' ); ?>');
                $('#cancel-preview-button').prop('disabled', false);
                $('#process-csv-button').prop('disabled', false).text('<?php esc_html_e( 'Preview Another CSV File', 'chicago-loft-search' ); ?>');
                $('#upload-section').slideDown();
                return;
            }

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'chicago_loft_search_import_listings_batch',
                    nonce: nonce,
                    listings: JSON.stringify(batchData)
                },
                success: function(response) {
                    if (response.success && response.data.results) {
                        response.data.results.forEach(function(result) {
                            const logClass = result.success ? 'success' : 'error';
                            $('#import-log').append($('<li class="' + logClass + '">').text(result.message));
                        });
                    } else {
                         const errorMsg = response.data && response.data.message ? response.data.message : '<?php esc_html_e( 'Unknown error in batch.', 'chicago-loft-search' ); ?>';
                        $('#import-log').append($('<li class="error">').text('<?php esc_html_e( 'Batch error: ', 'chicago-loft-search' ); ?>' + errorMsg));
                    }
                },
                error: function(xhr) {
                    $('#import-log').append($('<li class="error">').text('<?php esc_html_e( 'AJAX Error for batch. Check console.', 'chicago-loft-search' ); ?>'));
                     console.error(xhr.responseText);
                },
                complete: function() {
                    currentBatch++;
                    const progress = Math.round((end / totalListings) * 100);
                    $('#import-progress-bar').css('width', progress + '%').text(progress + '%');
                    $('#import-status-message').text('<?php esc_html_e( 'Imported ', 'chicago-loft-search' ); ?>' + end + ' <?php esc_html_e( 'of', 'chicago-loft-search' ); ?> ' + totalListings + ' <?php esc_html_e( 'listings...', 'chicago-loft-search' ); ?>');
                    importBatch();
                }
            });
        }
        importBatch();
    });

    $('#cancel-preview-button').on('click', function() {
        $('#preview-section').slideUp();
        $('#upload-section').slideDown();
        $('#mls_csv_file').val('');
        rawCsvData = [];
    });

});
</script>

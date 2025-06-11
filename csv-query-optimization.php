<?php

if (!defined('WPINC')) {
    die;
}

/**
 * Optimized function to query CSV files directly.
 * Reads all CSVs from the designated folder, extracts relevant rows based on keywords,
 * and returns a structured array of this data, optimized for token usage.
 *
 * @param string $query The user's search query.
 * @param int $max_chars_for_csv_data Approximate character limit for the CSV data payload.
 *                                     This is a proxy for token limits.
 * @return array An array of structured data from CSVs, or an empty array if no relevant data found or fits.
 */
function chicago_loft_search_query_csv_documents_optimized($query, $max_chars_for_csv_data = 15000) {
    $upload_dir = wp_upload_dir();
    $csv_dir_path = $upload_dir['basedir'] . '/csv-documents';

    if (!file_exists($csv_dir_path) || !is_dir($csv_dir_path)) {
        // error_log('CSV_QUERY: CSV directory not found: ' . $csv_dir_path);
        return []; // No CSV directory, no results
    }

    $files = glob($csv_dir_path . '/*.csv');
    if (empty($files)) {
        // error_log('CSV_QUERY: No CSV files found in ' . $csv_dir_path);
        return []; // No CSV files found
    }

    $keywords = preg_split('/\\s+/', strtolower(trim($query)));
    $keywords = array_filter($keywords, function($kw) {
        return strlen(trim($kw)) > 2; // Filter out very short or empty keywords
    });

    if (empty($keywords)) {
        // error_log('CSV_QUERY: No meaningful keywords after filtering from query: ' . $query);
        return []; // No meaningful keywords to search
    }

    $relevant_data_payload = [];
    $current_total_chars_count = 0;

    // error_log('CSV_QUERY: Starting search with keywords: ' . implode(', ', $keywords) . ' and char limit: ' . $max_chars_for_csv_data);

    foreach ($files as $file_path) {
        if ($current_total_chars_count >= $max_chars_for_csv_data) {
            // error_log('CSV_QUERY: Character limit reached before processing file: ' . basename($file_path));
            break; // Stop if we've already hit the limit
        }

        if (($handle = fopen($file_path, 'r')) !== false) {
            $header = fgetcsv($handle);
            if (!$header || empty(array_filter($header, 'strlen'))) { // Ensure header is not empty or just empty strings
                fclose($handle);
                // error_log('CSV_QUERY: Skipped empty or invalid header in file: ' . basename($file_path));
                continue; // Skip empty or invalid CSV
            }
            $header = array_map('trim', $header);

            $current_file_matched_rows = [];
            $current_file_rows_chars_count = 0;

            // Estimate characters for this file's metadata (filename + column headers)
            // This is a rough estimate; actual JSON encoding might vary slightly.
            $file_metadata_for_estimation = ['source_file' => basename($file_path), 'columns' => $header, 'rows' => []];
            $file_metadata_chars_estimate = strlen(json_encode($file_metadata_for_estimation)) - strlen(json_encode([])); // Subtract empty rows part

            while (($row_values = fgetcsv($handle)) !== false) {
                if (count($header) !== count($row_values)) {
                    // error_log('CSV_QUERY: Malformed row in ' . basename($file_path) . '. Header count: ' . count($header) . ', Row count: ' . count($row_values));
                    continue; // Skip malformed rows
                }
                // Ensure row is not just empty strings
                if (empty(array_filter($row_values, 'strlen'))) {
                    continue;
                }

                $row_assoc = array_combine($header, $row_values);
                $row_text_lower = strtolower(implode(' ', $row_assoc));

                $matches_this_row = false;
                foreach ($keywords as $keyword) {
                    if (stripos($row_text_lower, $keyword) !== false) {
                        $matches_this_row = true;
                        break;
                    }
                }

                if ($matches_this_row) {
                    $row_json_for_estimation = json_encode($row_assoc);
                    $row_chars_estimate = strlen($row_json_for_estimation);

                    // Calculate potential new total character count if this row is added
                    $potential_new_total_chars = $current_total_chars_count + $row_chars_estimate;
                    if (empty($current_file_matched_rows)) { // If this is the first matched row for this file
                        $potential_new_total_chars += $file_metadata_chars_estimate;
                    }


                    if ($potential_new_total_chars <= $max_chars_for_csv_data) {
                        $current_file_matched_rows[] = $row_assoc;
                        $current_file_rows_chars_count += $row_chars_estimate;
                        // error_log('CSV_QUERY: Added row from ' . basename($file_path) . '. Row chars: ' . $row_chars_estimate . '. File rows chars: ' . $current_file_rows_chars_count);
                    } else {
                        // error_log('CSV_QUERY: Row from ' . basename($file_path) . ' skipped, adding it would exceed char limit. Potential: ' . $potential_new_total_chars);
                        // Not enough space for this row. Stop processing this file.
                        // We could also decide to stop processing all files if we are very close to the limit.
                        goto next_file; // Break from inner while loop, continue to next file or end.
                    }
                }
            }
            
            next_file: // Label for goto
            fclose($handle);

            if (!empty($current_file_matched_rows)) {
                // We have matched rows for this file. Check again if the file block fits.
                $this_file_block_chars = $file_metadata_chars_estimate + $current_file_rows_chars_count;
                if (($current_total_chars_count + $this_file_block_chars) <= $max_chars_for_csv_data) {
                    $relevant_data_payload[] = [
                        'source_file' => basename($file_path),
                        'columns' => $header,
                        'rows' => $current_file_matched_rows
                    ];
                    $current_total_chars_count += $this_file_block_chars;
                    // error_log('CSV_QUERY: Added file block for ' . basename($file_path) . '. Block chars: ' . $this_file_block_chars . '. Total chars: ' . $current_total_chars_count);
                } else {
                    // error_log('CSV_QUERY: File block for ' . basename($file_path) . ' skipped, total data would exceed char limit. Current total: ' . $current_total_chars_count . ', this block: ' . $this_file_block_chars);
                    // If this file's content (even after row-level filtering) doesn't fit,
                    // and we've already accumulated some data, it's better to stop to avoid exceeding the limit.
                    if ($current_total_chars_count > 0) break; // Stop processing more files if we already have some data.
                }
            }
        } else {
            // error_log('CSV_QUERY: Could not open file: ' . $file_path);
        }
    }

    // error_log('CSV_QUERY: Finished processing. Total chars for payload: ' . $current_total_chars_count . '. Payload items: ' . count($relevant_data_payload));
    return $relevant_data_payload;
}


/**
 * =======================================================================================
 * Example of how this optimized function would be called in chicago_loft_search_ajax_handler.
 * This part is for illustration and would replace the existing CSV handling in the main plugin file.
 * DO NOT UNCOMMENT THIS IN THIS FILE. THIS IS FOR REFERENCE ONLY.
 * =======================================================================================
 */
/*
function chicago_loft_search_ajax_handler_example_usage() {
    // ... (nonce checks, user checks, query sanitization as in the original handler) ...
    $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    
    if (empty($query)) {
        wp_send_json_error(array('message' => __('Please enter a search query.', 'chicago-loft-search')));
        return; // Important to exit after wp_send_json_error
    }

    $options = get_option('chicago_loft_search_options');
    $api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
    $model = isset($options['model']) ? $options['model'] : 'gpt-4o';
    $system_prompt_template = isset($options['system_prompt']) ? $options['system_prompt'] : 'You are a helpful assistant specializing in Chicago loft and high rise properties. Provide accurate information based on the data available. Keep responses concise and relevant to real estate inquiries only. Please keep users on this site and never mention competitor real estate sites in the result messaging.';
    
    if (empty($api_key)) {
        wp_send_json_error(array('message' => __('API key not configured. Please contact the administrator.', 'chicago-loft-search')));
        return;
    }

    // Define a character limit for CSV data context.
    // This could be made a plugin setting. 15000 chars is a starting point.
    $max_chars_for_csv_context = apply_filters('chicago_loft_search_csv_context_max_chars', 15000); 
    
    // Get optimized, structured CSV data
    $csv_data_for_gpt_structured = chicago_loft_search_query_csv_documents_optimized($query, $max_chars_for_csv_context);
    
    $data_context_for_api = ""; // This will be the string passed to the API

    if (!empty($csv_data_for_gpt_structured)) {
        // Convert the structured CSV data to a JSON string for the prompt.
        $json_encoded_csv_data = json_encode($csv_data_for_gpt_structured);

        // Although chicago_loft_search_query_csv_documents_optimized tries to stay within limits,
        // json_encode itself can add characters (e.g. escaping).
        // A final check and truncation if absolutely necessary.
        if (strlen($json_encoded_csv_data) > $max_chars_for_csv_context) {
            // This truncation is a fallback and might break JSON, ideally the inner function handles it well.
            // A more robust way would be to remove last elements from $csv_data_for_gpt_structured and re-encode
            // until it fits, but that adds complexity.
            $data_context_for_api = substr($json_encoded_csv_data, 0, $max_chars_for_csv_context);
            // Try to find the last complete JSON object or array if truncated.
            $last_brace = strrpos($data_context_for_api, '}');
            $last_bracket = strrpos($data_context_for_api, ']');
            $cut_off_point = max($last_brace, $last_bracket);
            if ($cut_off_point !== false && $cut_off_point > 0) {
                 $data_context_for_api = substr($data_context_for_api, 0, $cut_off_point + 1);
            }
            // Append a note about truncation if it happened.
            if (strlen($json_encoded_csv_data) > strlen($data_context_for_api)) {
                 $data_context_for_api .= '...[Note: CSV data context was truncated to fit limits]';
            }
        } else {
            $data_context_for_api = $json_encoded_csv_data;
        }
        $final_system_prompt = $system_prompt_template . "\n\nUse the following data from CSV documents to answer the user's query. This data is specific to their search:\n" . $data_context_for_api;

    } else {
        // No relevant CSV data found or fits within limits.
        // The AI will rely on its general knowledge or the base system prompt.
        $final_system_prompt = $system_prompt_template . "\n\nNo specific data was found in local CSV documents matching the query: \"$query\". Please use your general knowledge or ask for clarification if needed.";
    }

    $request_data = array(
        'model' => $model,
        'messages' => array(
            array(
                'role' => 'system',
                'content' => $final_system_prompt
            ),
            array(
                'role' => 'user',
                'content' => $query
            )
        ),
        'temperature' => isset($options['temperature']) ? floatval($options['temperature']) : 0.7,
        // Consider adjusting max_tokens for the completion based on how much context is sent.
        'max_tokens' => isset($options['max_tokens']) ? intval($options['max_tokens']) : 1000 
    );
    
    // ... (The rest of the wp_remote_post call, response handling, logging, etc.) ...
    // For example:
    // $response = wp_remote_post('https://api.openai.com/v1/chat/completions', ...);
    // ... handle $response ...
    // wp_send_json_success(...); or wp_send_json_error(...);
}
*/

?>

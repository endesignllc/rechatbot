<?php
/**
 * Search interface template for Chicago Loft Search plugin
 *
 * @package Chicago_Loft_Search
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get shortcode attributes
$title = isset($atts['title']) ? sanitize_text_field($atts['title']) : __('Chicago Loft Search', 'chicago-loft-search');
$placeholder = isset($atts['placeholder']) ? sanitize_text_field($atts['placeholder']) : __('Search for lofts in Chicago...', 'chicago-loft-search');
$button_text = isset($atts['button_text']) ? sanitize_text_field($atts['button_text']) : __('Search', 'chicago-loft-search');
$show_examples = isset($atts['show_examples']) ? $atts['show_examples'] === 'yes' : true;

// Get plugin options
$options = get_option('chicago_loft_search_options', array());
// Corrected example questions handling
$example_questions_setting = isset($options['example_questions']) ? $options['example_questions'] : "Show me lofts in West Loop under $500,000\nWhat are the largest lofts in River North?\nFind 2 bedroom lofts in South Loop with exposed brick";
$example_questions = array_map('trim', preg_split('/\r\n|\r|\n/', $example_questions_setting));
$example_questions = array_filter($example_questions); // Remove empty lines

$enable_history = isset($options['enable_search_history']) && $options['enable_search_history'];
$enable_captcha = isset($options['enable_captcha']) && $options['enable_captcha'];
$captcha_site_key = isset($options['captcha_site_key']) ? $options['captcha_site_key'] : '';
$security_level = isset($options['security_level']) ? $options['security_level'] : 'medium';

// Get user's remaining searches
$user_id = get_current_user_id();
$ip_address = chicago_loft_search_get_client_ip();
global $wpdb;
$table_name = $wpdb->prefix . 'chicago_loft_search_usage';
$usage = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND ip_address = %s",
        $user_id,
        $ip_address
    )
);

$daily_limit = isset($options['daily_query_limit']) ? (int)$options['daily_query_limit'] : 50;
$daily_used = $usage ? (int)$usage->daily_count : 0;
$daily_remaining = $daily_limit - $daily_used;

// If high security, load reCAPTCHA script
if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) {
    wp_enqueue_script('recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true);
}

// Generate a unique ID for this search instance
$search_id = 'chicago-loft-search-' . uniqid();
?>

<div class="chicago-loft-search-container" id="<?php echo esc_attr($search_id); ?>">
    <div class="chicago-loft-search-header">
        <h2 class="chicago-loft-search-title"><?php echo esc_html($title); ?></h2>
        <?php if ($daily_remaining > 0) : ?>
            <div class="chicago-loft-search-remaining">
                <span class="searches-remaining"><?php echo sprintf(__('%d searches remaining today', 'chicago-loft-search'), $daily_remaining); ?></span>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="chicago-loft-search-form-container">
        <form class="chicago-loft-search-form" action="" method="post">
            <div class="search-input-group">
                <input 
                    type="text" 
                    class="chicago-loft-search-input" 
                    name="query" 
                    placeholder="<?php echo esc_attr($placeholder); ?>" 
                    aria-label="<?php echo esc_attr($placeholder); ?>"
                    autocomplete="off"
                    required
                >
                <button 
                    type="submit" 
                    class="chicago-loft-search-button"
                    aria-label="<?php echo esc_attr($button_text); ?>"
                >
                    <span class="button-text"><?php echo esc_html($button_text); ?></span>
                    <span class="button-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="search-icon" viewBox="0 0 16 16">
                            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/>
                        </svg>
                    </span>
                </button>
            </div>
            
            <?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
                <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($captcha_site_key); ?>" data-callback="enableSearchButton_<?php echo esc_js($search_id); ?>" data-expired-callback="disableSearchButton_<?php echo esc_js($search_id); ?>"></div>
                <div class="captcha-message"><?php _e('Please complete the CAPTCHA to search', 'chicago-loft-search'); ?></div>
            <?php endif; ?>
            
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('chicago_loft_search_public_nonce'); ?>">
        </form>
        
        <?php if ($show_examples && !empty($example_questions)) : ?>
            <div class="chicago-loft-search-examples">
                <div class="examples-label"><?php _e('Try asking about:', 'chicago-loft-search'); ?></div>
                <div class="examples-buttons">
                    <?php foreach ($example_questions as $question) : ?>
                        <button type="button" class="example-question" data-query="<?php echo esc_attr($question); ?>">
                            <?php echo esc_html($question); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="chicago-loft-search-results-container">
        <div class="chicago-loft-search-loading" style="display: none;">
            <div class="loading-spinner">
                <div class="spinner-border" role="status">
                    <span class="visually-hidden"><?php _e('Loading...', 'chicago-loft-search'); ?></span>
                </div>
            </div>
            <div class="loading-text"><?php _e('Searching for lofts...', 'chicago-loft-search'); ?></div>
        </div>
        
        <div class="chicago-loft-search-error" style="display: none;">
            <div class="error-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 16 16">
                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/>
                    <path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/>
                </svg>
            </div>
            <div class="error-message"></div>
        </div>
        
        <div class="chicago-loft-search-results" style="display: none;">
            <div class="results-header">
                <h3 class="results-title"><?php _e('Search Results', 'chicago-loft-search'); ?></h3>
                <div class="results-meta">
                    <span class="query-time"></span>
                    <span class="tokens-used"></span>
                </div>
            </div>
            <div class="results-content"></div>
        </div>
        
        <?php if ($enable_history) : ?>
            <div class="chicago-loft-search-history" style="display: none;">
                <div class="history-header">
                    <h3 class="history-title"><?php _e('Recent Searches', 'chicago-loft-search'); ?></h3>
                    <button type="button" class="clear-history-button" aria-label="<?php _e('Clear history', 'chicago-loft-search'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                            <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                        </svg>
                    </button>
                </div>
                <ul class="history-list"></ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Base Styles */
.chicago-loft-search-container {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
    background-color: #ffffff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

/* Header Styles */
.chicago-loft-search-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chicago-loft-search-title {
    font-size: 24px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.chicago-loft-search-remaining {
    font-size: 14px;
    color: #666;
    background-color: #f8f9fa;
    padding: 5px 10px;
    border-radius: 4px;
}

/* Form Styles */
.chicago-loft-search-form-container {
    margin-bottom: 30px;
}

.chicago-loft-search-form {
    margin-bottom: 15px;
}

.search-input-group {
    display: flex;
    position: relative;
}

.chicago-loft-search-input {
    flex: 1;
    padding: 12px 15px;
    font-size: 16px;
    border: 2px solid #e1e1e1;
    border-radius: 6px 0 0 6px;
    transition: border-color 0.2s ease;
    outline: none;
    position: relative; /* Ensure z-index applies */
    z-index: 2; /* Higher than button */
    pointer-events: auto !important; /* Ensure clickable */
}

.chicago-loft-search-input:focus {
    border-color: #4a90e2;
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.1);
}

.chicago-loft-search-button {
    background-color: #4a90e2;
    color: white;
    border: none;
    border-radius: 0 6px 6px 0;
    padding: 0 20px;
    font-size: 16px;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative; /* For z-index context */
    z-index: 1; /* Lower than input */
}

.chicago-loft-search-button:hover {
    background-color: #3a80d2;
}

.chicago-loft-search-button:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.3);
}

.button-text {
    margin-right: 8px;
}

.search-icon {
    width: 16px;
    height: 16px;
}

/* CAPTCHA Styles */
.g-recaptcha {
    margin-top: 15px;
    margin-bottom: 10px;
}

.captcha-message {
    font-size: 14px;
    color: #666;
    margin-top: 5px;
}

/* Example Questions Styles */
.chicago-loft-search-examples {
    margin-top: 20px;
}

.examples-label {
    font-size: 14px;
    color: #666;
    margin-bottom: 10px;
}

.examples-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.example-question {
    background-color: #f0f7ff;
    border: 1px solid #d0e3ff;
    border-radius: 20px;
    padding: 6px 12px;
    font-size: 14px;
    color: #4a90e2;
    cursor: pointer;
    transition: all 0.2s ease;
}

.example-question:hover {
    background-color: #e0f0ff;
    transform: translateY(-1px);
}

/* Loading Styles */
.chicago-loft-search-loading {
    text-align: center;
    padding: 30px 0;
}

.loading-spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    margin-bottom: 15px;
}

.spinner-border {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid rgba(74, 144, 226, 0.25);
    border-right-color: #4a90e2;
    border-radius: 50%;
    animation: spinner-border 0.75s linear infinite;
}

@keyframes spinner-border {
    to { transform: rotate(360deg); }
}

.loading-text {
    color: #666;
    font-size: 16px;
}

.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}

/* Error Styles */
.chicago-loft-search-error {
    background-color: #fff8f8;
    border: 1px solid #ffdddd;
    border-radius: 6px;
    padding: 20px;
    display: flex;
    align-items: center;
}

.error-icon {
    color: #e74c3c;
    margin-right: 15px;
    flex-shrink: 0;
}

.error-message {
    color: #c0392b;
    font-size: 16px;
}

/* Results Styles */
.chicago-loft-search-results {
    background-color: #f9f9f9;
    border-radius: 6px;
    padding: 20px;
    margin-top: 20px;
}

.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #e1e1e1;
}

.results-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.results-meta {
    font-size: 13px;
    color: #777;
}

.query-time, .tokens-used {
    margin-left: 10px;
}

.results-content {
    font-size: 16px;
    line-height: 1.6;
    color: #333;
}

.results-content h2 {
    font-size: 22px;
    margin-top: 25px;
    margin-bottom: 15px;
    color: #222;
}

.results-content h3 {
    font-size: 18px;
    margin-top: 20px;
    margin-bottom: 10px;
    color: #333;
}

.results-content h4 {
    font-size: 16px;
    margin-top: 15px;
    margin-bottom: 10px;
    color: #444;
}

.results-content ul, 
.results-content ol {
    margin-left: 20px;
    margin-bottom: 15px;
}

.results-content li {
    margin-bottom: 5px;
}

.results-content a {
    color: #4a90e2;
    text-decoration: none;
    border-bottom: 1px dotted #4a90e2;
}

.results-content a:hover {
    border-bottom: 1px solid #4a90e2;
}

.chicago-loft-link {
    display: inline-block;
    background-color: #f0f7ff;
    padding: 2px 6px;
    border-radius: 4px;
    margin: 0 2px;
    font-family: monospace;
    font-size: 14px;
    color: #4a90e2;
    border-bottom: none !important;
}

.chicago-loft-link:hover {
    background-color: #e0f0ff;
    border-bottom: none !important;
}

/* History Styles */
.chicago-loft-search-history {
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e1e1e1;
}

.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.history-title {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin: 0;
}

.clear-history-button {
    background: none;
    border: none;
    color: #999;
    cursor: pointer;
    padding: 5px;
}

.clear-history-button:hover {
    color: #e74c3c;
}

.history-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.history-list li {
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
}

.history-list li:last-child {
    border-bottom: none;
}

.history-query {
    flex: 1;
    color: #333;
    cursor: pointer;
    padding: 5px;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.history-query:hover {
    background-color: #f0f7ff;
}

.history-time {
    font-size: 12px;
    color: #999;
    margin-left: 10px;
}

/* Responsive Styles */
@media (max-width: 768px) {
    .chicago-loft-search-container {
        padding: 15px;
    }
    
    .chicago-loft-search-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .chicago-loft-search-remaining {
        margin-top: 10px;
    }
    
    .chicago-loft-search-title {
        font-size: 20px;
    }
    
    .examples-buttons {
        flex-direction: column;
        gap: 8px;
    }
    
    .example-question {
        width: 100%;
        text-align: left;
    }
    
    .results-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .results-meta {
        margin-top: 10px;
    }
    
    .query-time, .tokens-used {
        margin-left: 0;
        display: block;
        margin-top: 5px;
    }
}

/* Accessibility Focus Styles */
.chicago-loft-search-input:focus,
.chicago-loft-search-button:focus,
.example-question:focus,
.clear-history-button:focus,
.history-query:focus {
    outline: 2px solid #4a90e2;
    outline-offset: 2px;
}

/* Dark Mode Support */
@media (prefers-color-scheme: dark) {
    .chicago-loft-search-container {
        background-color: #222;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
    }
    
    .chicago-loft-search-title {
        color: #eee;
    }
    
    .chicago-loft-search-remaining {
        background-color: #333;
        color: #ccc;
    }
    
    .chicago-loft-search-input {
        background-color: #333;
        border-color: #444;
        color: #eee;
    }
    
    .chicago-loft-search-input:focus {
        border-color: #5a9ae2;
        box-shadow: 0 0 0 3px rgba(90, 154, 226, 0.2);
    }
    
    .example-question {
        background-color: #2a3a4a;
        border-color: #345678;
        color: #9ac0e2;
    }
    
    .example-question:hover {
        background-color: #345678;
    }
    
    .chicago-loft-search-results {
        background-color: #2a2a2a;
    }
    
    .results-header {
        border-bottom-color: #444;
    }
    
    .results-title {
        color: #eee;
    }
    
    .results-content {
        color: #ddd;
    }
    
    .results-content h2,
    .results-content h3,
    .results-content h4 {
        color: #eee;
    }
    
    .chicago-loft-link {
        background-color: #2a3a4a;
        color: #9ac0e2;
    }
    
    .chicago-loft-link:hover {
        background-color: #345678;
    }
    
    .chicago-loft-search-history {
        border-top-color: #444;
    }
    
    .history-title {
        color: #eee;
    }
    
    .history-list li {
        border-bottom-color: #333;
    }
    
    .history-query {
        color: #ddd;
    }
    
    .history-query:hover {
        background-color: #2a3a4a;
    }
}

/* Loft Listing Styles */
.loft-listings {
  padding-left: 0;
  margin-left: 0;
  list-style: none;
}

.loft-listing-item {
  position: relative;
  padding-left: 28px; /* Space for the icon */
  margin-bottom: 12px;
  list-style-type: none; /* Remove default bullet */
}

.loft-listing-item::before {
  content: "";
  position: absolute;
  left: 0;
  top: 2px; /* Adjust vertical alignment as needed */
  width: 20px; /* SVG width */
  height: 20px; /* SVG height */
  background-image: url("<?php echo esc_url(plugins_url('../images/loft-vector.svg', __FILE__)); ?>");
  background-size: contain;
  background-repeat: no-repeat;
  background-position: center;
}
</style>

<script>
jQuery(document).ready(function($) {
    const searchContainer = $('#<?php echo esc_js($search_id); ?>');
    const searchForm = searchContainer.find('.chicago-loft-search-form');
    const searchInput = searchContainer.find('.chicago-loft-search-input');
    const searchButton = searchContainer.find('.chicago-loft-search-button');
    const exampleQuestions = searchContainer.find('.example-question');
    const loadingElement = searchContainer.find('.chicago-loft-search-loading');
    const errorElement = searchContainer.find('.chicago-loft-search-error');
    const errorMessage = searchContainer.find('.error-message');
    const resultsElement = searchContainer.find('.chicago-loft-search-results');
    const resultsContent = searchContainer.find('.results-content');
    const queryTimeElement = searchContainer.find('.query-time');
    const tokensUsedElement = searchContainer.find('.tokens-used');
    
    <?php if ($enable_history) : ?>
    const historyElement = searchContainer.find('.chicago-loft-search-history');
    const historyList = searchContainer.find('.history-list');
    const clearHistoryButton = searchContainer.find('.clear-history-button');
    
    // Load search history from local storage
    function loadSearchHistory() {
        const history = JSON.parse(localStorage.getItem('chicagoLoftSearchHistory_<?php echo esc_js($search_id); ?>') || '[]');
        
        if (history.length > 0) {
            historyElement.show();
            historyList.empty();
            
            history.forEach(item => {
                const li = $('<li></li>');
                const querySpan = $('<span class="history-query"></span>').text(item.query);
                const timeSpan = $('<span class="history-time"></span>').text(formatTime(item.time));
                
                querySpan.on('click', function() {
                    searchInput.val(item.query);
                    searchForm.submit();
                });
                
                li.append(querySpan).append(timeSpan);
                historyList.append(li);
            });
        } else {
            historyElement.hide();
        }
    }
    
    // Save search to history
    function saveSearchToHistory(query) {
        const history = JSON.parse(localStorage.getItem('chicagoLoftSearchHistory_<?php echo esc_js($search_id); ?>') || '[]');
        
        // Check if query already exists
        const existingIndex = history.findIndex(item => item.query === query);
        if (existingIndex !== -1) {
            history.splice(existingIndex, 1);
        }
        
        // Add new query to beginning of array
        history.unshift({
            query: query,
            time: Date.now()
        });
        
        // Keep only the last 10 searches
        const limitedHistory = history.slice(0, 10);
        
        localStorage.setItem('chicagoLoftSearchHistory_<?php echo esc_js($search_id); ?>', JSON.stringify(limitedHistory));
        loadSearchHistory();
    }
    
    // Format timestamp to relative time
    function formatTime(timestamp) {
        const now = Date.now();
        const diff = now - timestamp;
        
        if (diff < 60000) { // less than 1 minute
            return '<?php _e('just now', 'chicago-loft-search'); ?>';
        } else if (diff < 3600000) { // less than 1 hour
            const minutes = Math.floor(diff / 60000);
            return minutes + ' <?php echo _n('min ago', 'mins ago', "' + minutes + '", 'chicago-loft-search'); ?>'.replace("'+minutes+'", minutes);
        } else if (diff < 86400000) { // less than 1 day
            const hours = Math.floor(diff / 3600000);
            return hours + ' <?php echo _n('hr ago', 'hrs ago', "' + hours + '", 'chicago-loft-search'); ?>'.replace("'+hours+'", hours);
        } else {
            const date = new Date(timestamp);
            return date.toLocaleDateString();
        }
    }
    
    // Clear search history
    clearHistoryButton.on('click', function() {
        if (confirm('<?php _e('Are you sure you want to clear your search history?', 'chicago-loft-search'); ?>')) {
            localStorage.removeItem('chicagoLoftSearchHistory_<?php echo esc_js($search_id); ?>');
            historyElement.hide();
        }
    });
    
    // Load history on page load
    loadSearchHistory();
    <?php endif; ?>
    
    // Handle example question clicks
    exampleQuestions.on('click', function() {
        const query = $(this).data('query');
        searchInput.val(query);
        searchForm.submit();
    });
    
    // Handle form submission
    searchForm.on('submit', function(e) {
        e.preventDefault();
        
        const query = searchInput.val().trim();
        
        if (!query) {
            return;
        }
        
        <?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
        // Check if CAPTCHA is completed
        const captchaResponse = grecaptcha.getResponse();
        if (!captchaResponse) {
            errorMessage.text('<?php _e('Please complete the CAPTCHA verification.', 'chicago-loft-search'); ?>');
            errorElement.show();
            loadingElement.hide();
            resultsElement.hide();
            return;
        }
        <?php endif; ?>
        
        // Show loading state
        loadingElement.show();
        errorElement.hide();
        resultsElement.hide();
        
        // Disable search button
        searchButton.prop('disabled', true);
        
        // Get form data
        const formData = {
            action: 'chicago_loft_search',
            query: query,
            nonce: searchForm.find('input[name="nonce"]').val(),
            <?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
            captcha: grecaptcha.getResponse(),
            <?php endif; ?>
        };
        
        // Send AJAX request
        $.ajax({
            url: chicago_loft_search.ajax_url,
            type: 'POST',
            data: formData,
            success: function(response) {
                // Hide loading state
                loadingElement.hide();
                
                // Re-enable search button
                searchButton.prop('disabled', false);
                
                <?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
                // Reset CAPTCHA
                grecaptcha.reset();
                // Re-disable button if CAPTCHA is required and not solved
                // disableSearchButton_<?php echo esc_js($search_id); ?>(); 
                <?php endif; ?>
                
                if (response.success) {
                    // Show results
                    resultsContent.html(response.data.response);
                    
                    // Update metadata
                    const now = new Date();
                    queryTimeElement.text('<?php _e('Time:', 'chicago-loft-search'); ?> ' + now.toLocaleTimeString());
                    tokensUsedElement.text('<?php _e('Tokens:', 'chicago-loft-search'); ?> ' + response.data.tokens_used);
                    
                    // Show results container
                    resultsElement.show();
                    
                    // Update remaining searches count
                    const remainingElement = searchContainer.find('.searches-remaining');
                    if (remainingElement.length) {
                        const currentRemainingText = remainingElement.text();
                        const currentRemainingMatch = currentRemainingText.match(/(\d+)/);
                        if (currentRemainingMatch) {
                            const currentRemaining = parseInt(currentRemainingMatch[1]);
                            if (!isNaN(currentRemaining) && currentRemaining > 0) {
                                remainingElement.text('<?php echo sprintf(__('%d searches remaining today', 'chicago-loft-search'), ''); ?>'.replace('%d', (currentRemaining - 1)));
                            } else if (currentRemaining === 1) {
                                remainingElement.text('<?php _e('No searches remaining today', 'chicago-loft-search'); ?>');
                            }
                        }
                    }
                    
                    <?php if ($enable_history) : ?>
                    // Save search to history
                    saveSearchToHistory(query);
                    <?php endif; ?>
                    
                    // Scroll to results
                    $('html, body').animate({
                        scrollTop: resultsElement.offset().top - 50
                    }, 500);
                } else {
                    // Show error
                    errorMessage.text(response.data.message);
                    errorElement.show();
                }
            },
            error: function() {
                // Hide loading state
                loadingElement.hide();
                
                // Re-enable search button
                searchButton.prop('disabled', false);
                
                <?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
                // Reset CAPTCHA
                grecaptcha.reset();
                 // Re-disable button if CAPTCHA is required and not solved
                // disableSearchButton_<?php echo esc_js($search_id); ?>();
                <?php endif; ?>
                
                // Show error
                errorMessage.text('<?php _e('Error processing your request. Please try again.', 'chicago-loft-search'); ?>');
                errorElement.show();
            }
        });
    });
    
    <?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
    // Initially disable the search button until CAPTCHA is completed
    // Commenting this out to ensure button is enabled by default, CAPTCHA check happens on submit
    // searchButton.prop('disabled', true); 
    <?php endif; ?>
});

<?php if ($enable_captcha && $security_level === 'high' && !empty($captcha_site_key)) : ?>
// CAPTCHA callback functions (make them unique per instance)
function enableSearchButton_<?php echo esc_js($search_id); ?>() {
    jQuery('#<?php echo esc_js($search_id); ?> .chicago-loft-search-button').prop('disabled', false);
}

function disableSearchButton_<?php echo esc_js($search_id); ?>() {
    // Only disable if CAPTCHA is truly required and not yet solved.
    // For now, let's not disable it here to avoid conflicts, check on submit.
    // jQuery('#<?php echo esc_js($search_id); ?> .chicago-loft-search-button').prop('disabled', true);
}
<?php endif; ?>
</script>

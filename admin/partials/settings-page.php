<?php
/**
 * Admin settings page template for Chicago Loft Search plugin
 *
 * @package Chicago_Loft_Search
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get current options
$options = get_option('chicago_loft_search_options', array());

// Default values
$openai_api_key = isset($options['openai_api_key']) ? $options['openai_api_key'] : '';
$daily_query_limit = isset($options['daily_query_limit']) ? $options['daily_query_limit'] : 50;
$monthly_query_limit = isset($options['monthly_query_limit']) ? $options['monthly_query_limit'] : 1000;
$allowed_user_roles = isset($options['allowed_user_roles']) ? $options['allowed_user_roles'] : array('administrator', 'editor', 'subscriber');
$model = isset($options['model']) ? $options['model'] : 'gpt-4o';
$system_prompt = isset($options['system_prompt']) ? $options['system_prompt'] : 'You are a helpful assistant specializing in Chicago loft properties. Provide accurate information based on the data available. Keep responses concise and relevant to real estate inquiries only.';
$last_sync_date = isset($options['last_sync_date']) ? $options['last_sync_date'] : '';
$security_level = isset($options['security_level']) ? $options['security_level'] : 'medium';
$enable_logging = isset($options['enable_logging']) ? $options['enable_logging'] : true;
$enable_captcha = isset($options['enable_captcha']) ? $options['enable_captcha'] : false;
$captcha_site_key = isset($options['captcha_site_key']) ? $options['captcha_site_key'] : '';
$captcha_secret_key = isset($options['captcha_secret_key']) ? $options['captcha_secret_key'] : '';
$example_questions_value = isset($options['example_questions']) ? $options['example_questions'] : "Show me lofts in West Loop under $500,000\nWhat are the largest lofts in River North?\nFind 2 bedroom lofts in South Loop with exposed brick";


// Get available user roles
$wp_roles = wp_roles();
$available_roles = $wp_roles->get_names();
?>

<div class="wrap chicago-loft-search-settings">
    <h1><?php _e('Chicago Loft Search Settings', 'chicago-loft-search'); ?></h1>
    
    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Settings saved successfully!', 'chicago-loft-search'); ?></p>
        </div>
    <?php endif; ?>
    
    <div class="chicago-loft-search-header-info">
        <div class="chicago-loft-search-version">
            <span><?php _e('Version:', 'chicago-loft-search'); ?> <?php echo CHICAGO_LOFT_SEARCH_VERSION; ?></span>
        </div>
        <?php if (!empty($last_sync_date)) : ?>
            <div class="chicago-loft-search-last-sync">
                <span><?php _e('Last MLS Data Sync:', 'chicago-loft-search'); ?> <?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync_date)); ?></span>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="chicago-loft-search-tabs">
        <nav class="nav-tab-wrapper">
            <a href="#api-settings" class="nav-tab nav-tab-active"><?php _e('API Settings', 'chicago-loft-search'); ?></a>
            <a href="#rate-limiting" class="nav-tab"><?php _e('Rate Limiting', 'chicago-loft-search'); ?></a>
            <a href="#user-permissions" class="nav-tab"><?php _e('User Permissions', 'chicago-loft-search'); ?></a>
            <a href="#advanced-settings" class="nav-tab"><?php _e('Advanced Settings', 'chicago-loft-search'); ?></a>
            <a href="#security-settings" class="nav-tab"><?php _e('Security', 'chicago-loft-search'); ?></a>
            <a href="#import-export" class="nav-tab"><?php _e('Import/Export', 'chicago-loft-search'); ?></a>
        </nav>
    </div>
    
    <form method="post" action="" id="chicago-loft-search-settings-form">
        <?php wp_nonce_field('chicago_loft_search_settings', 'chicago_loft_search_nonce'); ?>
        
        <!-- API Settings Tab -->
        <div id="api-settings" class="chicago-loft-search-tab-content active">
            <h2><?php _e('OpenAI API Configuration', 'chicago-loft-search'); ?></h2>
            <p class="description"><?php _e('Configure your OpenAI API credentials to enable ChatGPT-powered search functionality.', 'chicago-loft-search'); ?></p>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="openai_api_key"><?php _e('OpenAI API Key', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               id="openai_api_key" 
                               name="openai_api_key" 
                               value="<?php echo esc_attr($openai_api_key); ?>" 
                               class="regular-text"
                               autocomplete="off" />
                        <p class="description">
                            <?php _e('Enter your OpenAI API key. You can get one from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI\'s website</a>.', 'chicago-loft-search'); ?>
                        </p>
                        <div class="api-key-status">
                            <button type="button" class="button button-secondary verify-api-key">
                                <?php _e('Verify API Key', 'chicago-loft-search'); ?>
                            </button>
                            <span class="api-key-status-message"></span>
                        </div>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="model"><?php _e('ChatGPT Model', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <select id="model" name="model">
                            <option value="gpt-4o" <?php selected($model, 'gpt-4o'); ?>><?php _e('GPT-4o (Recommended)', 'chicago-loft-search'); ?></option>
                            <option value="gpt-4-turbo" <?php selected($model, 'gpt-4-turbo'); ?>><?php _e('GPT-4 Turbo', 'chicago-loft-search'); ?></option>
                            <option value="gpt-4" <?php selected($model, 'gpt-4'); ?>><?php _e('GPT-4', 'chicago-loft-search'); ?></option>
                            <option value="gpt-3.5-turbo" <?php selected($model, 'gpt-3.5-turbo'); ?>><?php _e('GPT-3.5 Turbo (Faster, less accurate)', 'chicago-loft-search'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('Select which OpenAI model to use. GPT-4o provides the best results for real estate queries but costs more per query.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <div class="api-usage-stats">
                <h3><?php _e('API Usage Statistics', 'chicago-loft-search'); ?></h3>
                <p class="description"><?php _e('Monitor your OpenAI API usage to manage costs.', 'chicago-loft-search'); ?></p>
                
                <div class="api-usage-data">
                    <div class="api-usage-loading">
                        <?php _e('Loading usage data...', 'chicago-loft-search'); ?>
                    </div>
                    <div class="api-usage-results" style="display: none;">
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Period', 'chicago-loft-search'); ?></th>
                                    <th><?php _e('Queries', 'chicago-loft-search'); ?></th>
                                    <th><?php _e('Tokens Used', 'chicago-loft-search'); ?></th>
                                    <th><?php _e('Estimated Cost', 'chicago-loft-search'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php _e('Today', 'chicago-loft-search'); ?></td>
                                    <td class="usage-today-queries">-</td>
                                    <td class="usage-today-tokens">-</td>
                                    <td class="usage-today-cost">-</td>
                                </tr>
                                <tr>
                                    <td><?php _e('This Month', 'chicago-loft-search'); ?></td>
                                    <td class="usage-month-queries">-</td>
                                    <td class="usage-month-tokens">-</td>
                                    <td class="usage-month-cost">-</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Rate Limiting Tab -->
        <div id="rate-limiting" class="chicago-loft-search-tab-content">
            <h2><?php _e('Rate Limiting Settings', 'chicago-loft-search'); ?></h2>
            <p class="description"><?php _e('Configure usage limits to prevent abuse and control API costs.', 'chicago-loft-search'); ?></p>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="daily_query_limit"><?php _e('Daily Query Limit', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               id="daily_query_limit" 
                               name="daily_query_limit" 
                               value="<?php echo esc_attr($daily_query_limit); ?>" 
                               min="1" 
                               max="1000" 
                               class="small-text" />
                        <p class="description">
                            <?php _e('Maximum number of searches a user can perform per day. Recommended: 50-100.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="monthly_query_limit"><?php _e('Monthly Query Limit', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               id="monthly_query_limit" 
                               name="monthly_query_limit" 
                               value="<?php echo esc_attr($monthly_query_limit); ?>" 
                               min="1" 
                               max="10000" 
                               class="small-text" />
                        <p class="description">
                            <?php _e('Maximum number of searches a user can perform per month. Recommended: 500-1000.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label><?php _e('Rate Limit Exceptions', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php _e('Rate Limit Exceptions', 'chicago-loft-search'); ?></legend>
                            <label for="rate_limit_admin_exempt">
                                <input type="checkbox" 
                                       id="rate_limit_admin_exempt" 
                                       name="rate_limit_exceptions[]" 
                                       value="admin_exempt" 
                                       <?php checked(isset($options['rate_limit_exceptions']) && in_array('admin_exempt', $options['rate_limit_exceptions'])); ?> />
                                <?php _e('Administrators are exempt from rate limits', 'chicago-loft-search'); ?>
                            </label><br>
                            
                            <label for="rate_limit_editor_exempt">
                                <input type="checkbox" 
                                       id="rate_limit_editor_exempt" 
                                       name="rate_limit_exceptions[]" 
                                       value="editor_exempt" 
                                       <?php checked(isset($options['rate_limit_exceptions']) && in_array('editor_exempt', $options['rate_limit_exceptions'])); ?> />
                                <?php _e('Editors are exempt from rate limits', 'chicago-loft-search'); ?>
                            </label><br>
                            
                            <p class="description">
                                <?php _e('Select which user roles should be exempt from rate limiting.', 'chicago-loft-search'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Throttling Settings', 'chicago-loft-search'); ?></h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="throttle_searches"><?php _e('Throttle Searches', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="throttle_searches" 
                               name="throttle_searches" 
                               value="1" 
                               <?php checked(isset($options['throttle_searches']) && $options['throttle_searches']); ?> />
                        <label for="throttle_searches">
                            <?php _e('Enable search throttling', 'chicago-loft-search'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Limit how quickly users can perform consecutive searches to prevent abuse.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top" class="throttle-settings" <?php echo (!isset($options['throttle_searches']) || !$options['throttle_searches']) ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="throttle_interval"><?php _e('Throttle Interval (seconds)', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               id="throttle_interval" 
                               name="throttle_interval" 
                               value="<?php echo isset($options['throttle_interval']) ? esc_attr($options['throttle_interval']) : 5; ?>" 
                               min="1" 
                               max="60" 
                               class="small-text" />
                        <p class="description">
                            <?php _e('Minimum time (in seconds) between consecutive searches from the same user. Recommended: 5-10 seconds.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- User Permissions Tab -->
        <div id="user-permissions" class="chicago-loft-search-tab-content">
            <h2><?php _e('User Permissions', 'chicago-loft-search'); ?></h2>
            <p class="description"><?php _e('Control which user roles can access the Chicago Loft Search functionality.', 'chicago-loft-search'); ?></p>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label><?php _e('Allowed User Roles', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php _e('Allowed User Roles', 'chicago-loft-search'); ?></legend>
                            
                            <label for="allowed_user_roles_visitor">
                                <input type="checkbox" 
                                       id="allowed_user_roles_visitor" 
                                       name="allowed_user_roles[]" 
                                       value="visitor" 
                                       <?php checked(in_array('visitor', $allowed_user_roles)); ?> />
                                <?php _e('Visitors (non-logged in users)', 'chicago-loft-search'); ?>
                            </label><br>
                            
                            <?php foreach ($available_roles as $role_key => $role_name) : ?>
                                <label for="allowed_user_roles_<?php echo esc_attr($role_key); ?>">
                                    <input type="checkbox" 
                                           id="allowed_user_roles_<?php echo esc_attr($role_key); ?>" 
                                           name="allowed_user_roles[]" 
                                           value="<?php echo esc_attr($role_key); ?>" 
                                           <?php checked(in_array($role_key, $allowed_user_roles)); ?> />
                                    <?php echo esc_html($role_name); ?>
                                </label><br>
                            <?php endforeach; ?>
                            
                            <p class="description">
                                <?php _e('Select which user roles can use the Chicago Loft Search functionality.', 'chicago-loft-search'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="require_login"><?php _e('Require Login', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="require_login" 
                               name="require_login" 
                               value="1" 
                               <?php checked(isset($options['require_login']) && $options['require_login']); ?> />
                        <label for="require_login">
                            <?php _e('Require users to be logged in to use search', 'chicago-loft-search'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If enabled, only logged-in users will be able to use the search functionality.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="login_redirect_url"><?php _e('Login Redirect URL', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="login_redirect_url" 
                               name="login_redirect_url" 
                               value="<?php echo isset($options['login_redirect_url']) ? esc_attr($options['login_redirect_url']) : ''; ?>" 
                               class="regular-text" />
                        <p class="description">
                            <?php _e('URL to redirect non-logged in users to. Leave empty to use the default WordPress login page.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Advanced Settings Tab -->
        <div id="advanced-settings" class="chicago-loft-search-tab-content">
            <h2><?php _e('Advanced Settings', 'chicago-loft-search'); ?></h2>
            <p class="description"><?php _e('Configure advanced options for the Chicago Loft Search plugin.', 'chicago-loft-search'); ?></p>
            
            <h3><?php _e('ChatGPT Prompt Settings', 'chicago-loft-search'); ?></h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="system_prompt"><?php _e('System Prompt', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <textarea id="system_prompt" 
                                  name="system_prompt" 
                                  rows="6" 
                                  class="large-text code"><?php echo esc_textarea($system_prompt); ?></textarea>
                        <p class="description">
                            <?php _e('This is the system prompt that guides how ChatGPT responds to user queries. Customize it to control the AI\'s behavior and knowledge.', 'chicago-loft-search'); ?>
                        </p>
                        <button type="button" class="button button-secondary reset-system-prompt">
                            <?php _e('Reset to Default', 'chicago-loft-search'); ?>
                        </button>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="temperature"><?php _e('Temperature', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="range" 
                               id="temperature" 
                               name="temperature" 
                               min="0" 
                               max="1" 
                               step="0.1" 
                               value="<?php echo isset($options['temperature']) ? esc_attr($options['temperature']) : '0.7'; ?>" />
                        <span class="temperature-value"><?php echo isset($options['temperature']) ? esc_html($options['temperature']) : '0.7'; ?></span>
                        <p class="description">
                            <?php _e('Controls randomness: Lower values (0.1-0.4) are more focused and deterministic. Higher values (0.7-1.0) are more creative but less predictable.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="max_tokens"><?php _e('Max Response Tokens', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               id="max_tokens" 
                               name="max_tokens" 
                               value="<?php echo isset($options['max_tokens']) ? esc_attr($options['max_tokens']) : '1000'; ?>" 
                               min="100" 
                               max="4000" 
                               class="small-text" />
                        <p class="description">
                            <?php _e('Maximum length of the AI\'s response. Higher values allow for more detailed responses but cost more. Recommended: 800-1200.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Search Interface Settings', 'chicago-loft-search'); ?></h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="default_placeholder"><?php _e('Default Search Placeholder', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="default_placeholder" 
                               name="default_placeholder" 
                               value="<?php echo isset($options['default_placeholder']) ? esc_attr($options['default_placeholder']) : 'Search for lofts in Chicago...'; ?>" 
                               class="regular-text" />
                        <p class="description">
                            <?php _e('Default placeholder text for the search input field.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="example_questions"><?php _e('Example Questions', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <textarea id="example_questions" 
                                  name="example_questions" 
                                  rows="5" 
                                  class="large-text"><?php echo esc_textarea($example_questions_value); ?></textarea>
                        <p class="description">
                            <?php _e('Enter example search questions, one per line. These will be displayed as clickable suggestions below the search box.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="results_per_page"><?php _e('Results Per Page', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               id="results_per_page" 
                               name="results_per_page" 
                               value="<?php echo isset($options['results_per_page']) ? esc_attr($options['results_per_page']) : '10'; ?>" 
                               min="1" 
                               max="50" 
                               class="small-text" />
                        <p class="description">
                            <?php _e('Number of loft listings to display per page in search results.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <h3><?php _e('Logging Settings', 'chicago-loft-search'); ?></h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="enable_logging"><?php _e('Enable Logging', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="enable_logging" 
                               name="enable_logging" 
                               value="1" 
                               <?php checked($enable_logging); ?> />
                        <label for="enable_logging">
                            <?php _e('Log search queries and responses', 'chicago-loft-search'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If enabled, all search queries and responses will be logged for analysis.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top" class="logging-settings" <?php echo (!$enable_logging) ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="log_retention"><?php _e('Log Retention (days)', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="number" 
                               id="log_retention" 
                               name="log_retention" 
                               value="<?php echo isset($options['log_retention']) ? esc_attr($options['log_retention']) : '30'; ?>" 
                               min="1" 
                               max="365" 
                               class="small-text" />
                        <p class="description">
                            <?php _e('Number of days to keep logs before automatic deletion. Recommended: 30-90 days.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Security Settings Tab -->
        <div id="security-settings" class="chicago-loft-search-tab-content">
            <h2><?php _e('Security Settings', 'chicago-loft-search'); ?></h2>
            <p class="description"><?php _e('Configure security options to protect your search functionality from abuse.', 'chicago-loft-search'); ?></p>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="security_level"><?php _e('Security Level', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <select id="security_level" name="security_level">
                            <option value="low" <?php selected($security_level, 'low'); ?>><?php _e('Low - Basic protection', 'chicago-loft-search'); ?></option>
                            <option value="medium" <?php selected($security_level, 'medium'); ?>><?php _e('Medium - Recommended', 'chicago-loft-search'); ?></option>
                            <option value="high" <?php selected($security_level, 'high'); ?>><?php _e('High - Strict protection', 'chicago-loft-search'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('Select the security level for the search functionality:', 'chicago-loft-search'); ?>
                        </p>
                        <ul class="security-level-description">
                            <li><strong><?php _e('Low:', 'chicago-loft-search'); ?></strong> <?php _e('Basic IP-based rate limiting only', 'chicago-loft-search'); ?></li>
                            <li><strong><?php _e('Medium:', 'chicago-loft-search'); ?></strong> <?php _e('IP + User-based rate limiting, simple query filtering', 'chicago-loft-search'); ?></li>
                            <li><strong><?php _e('High:', 'chicago-loft-search'); ?></strong> <?php _e('Strict rate limiting, advanced query filtering, CAPTCHA for non-logged in users', 'chicago-loft-search'); ?></li>
                        </ul>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="enable_captcha"><?php _e('Enable CAPTCHA', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="enable_captcha" 
                               name="enable_captcha" 
                               value="1" 
                               <?php checked($enable_captcha); ?> />
                        <label for="enable_captcha">
                            <?php _e('Require CAPTCHA verification for searches', 'chicago-loft-search'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If enabled, users will need to complete a CAPTCHA before submitting a search query.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top" class="captcha-settings" <?php echo (!$enable_captcha) ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="captcha_site_key"><?php _e('reCAPTCHA Site Key', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="captcha_site_key" 
                               name="captcha_site_key" 
                               value="<?php echo esc_attr($captcha_site_key); ?>" 
                               class="regular-text" />
                        <p class="description">
                            <?php _e('Enter your Google reCAPTCHA v2 site key. Get one from <a href="https://www.google.com/recaptcha/admin" target="_blank">Google reCAPTCHA</a>.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top" class="captcha-settings" <?php echo (!$enable_captcha) ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="captcha_secret_key"><?php _e('reCAPTCHA Secret Key', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               id="captcha_secret_key" 
                               name="captcha_secret_key" 
                               value="<?php echo esc_attr($captcha_secret_key); ?>" 
                               class="regular-text"
                               autocomplete="off" />
                        <p class="description">
                            <?php _e('Enter your Google reCAPTCHA v2 secret key.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="blocked_ips"><?php _e('Blocked IP Addresses', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <textarea id="blocked_ips" 
                                  name="blocked_ips" 
                                  rows="4" 
                                  class="large-text code"><?php 
                            if (isset($options['blocked_ips']) && is_array($options['blocked_ips'])) {
                                echo esc_textarea(implode("\n", $options['blocked_ips']));
                            }
                        ?></textarea>
                        <p class="description">
                            <?php _e('Enter IP addresses to block, one per line. These IPs will not be able to use the search functionality.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="blocked_keywords"><?php _e('Blocked Keywords', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <textarea id="blocked_keywords" 
                                  name="blocked_keywords" 
                                  rows="4" 
                                  class="large-text code"><?php 
                            if (isset($options['blocked_keywords']) && is_array($options['blocked_keywords'])) {
                                echo esc_textarea(implode("\n", $options['blocked_keywords']));
                            }
                        ?></textarea>
                        <p class="description">
                            <?php _e('Enter keywords to block, one per line. Queries containing these keywords will be rejected.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Import/Export Tab -->
        <div id="import-export" class="chicago-loft-search-tab-content">
            <h2><?php _e('Import/Export Settings', 'chicago-loft-search'); ?></h2>
            <p class="description"><?php _e('Import or export your Chicago Loft Search plugin settings.', 'chicago-loft-search'); ?></p>
            
            <div class="import-export-container">
                <div class="export-settings">
                    <h3><?php _e('Export Settings', 'chicago-loft-search'); ?></h3>
                    <p><?php _e('Export your current plugin settings as a JSON file.', 'chicago-loft-search'); ?></p>
                    <button type="button" class="button button-primary export-settings-btn">
                        <?php _e('Export Settings', 'chicago-loft-search'); ?>
                    </button>
                </div>
                
                <div class="import-settings">
                    <h3><?php _e('Import Settings', 'chicago-loft-search'); ?></h3>
                    <p><?php _e('Import plugin settings from a JSON file.', 'chicago-loft-search'); ?></p>
                    <input type="file" id="import-settings-file" name="import_settings_file" accept=".json" />
                    <button type="button" class="button button-primary import-settings-btn">
                        <?php _e('Import Settings', 'chicago-loft-search'); ?>
                    </button>
                    <div class="import-result"></div>
                </div>
            </div>
            
            <hr>
            
            <h3><?php _e('MLS Data Import', 'chicago-loft-search'); ?></h3>
            <p class="description"><?php _e('Configure settings for importing MLS data.', 'chicago-loft-search'); ?></p>
            
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="mls_api_endpoint"><?php _e('MLS API Endpoint', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="mls_api_endpoint" 
                               name="mls_api_endpoint" 
                               value="<?php echo isset($options['mls_api_endpoint']) ? esc_attr($options['mls_api_endpoint']) : ''; ?>" 
                               class="regular-text" />
                        <p class="description">
                            <?php _e('Enter the URL of your MLS API endpoint.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="mls_api_key"><?php _e('MLS API Key', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                               id="mls_api_key" 
                               name="mls_api_key" 
                               value="<?php echo isset($options['mls_api_key']) ? esc_attr($options['mls_api_key']) : ''; ?>" 
                               class="regular-text"
                               autocomplete="off" />
                        <p class="description">
                            <?php _e('Enter your MLS API key for authentication.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="auto_sync"><?php _e('Automatic Sync', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                               id="auto_sync" 
                               name="auto_sync" 
                               value="1" 
                               <?php checked(isset($options['auto_sync']) && $options['auto_sync']); ?> />
                        <label for="auto_sync">
                            <?php _e('Enable automatic MLS data synchronization', 'chicago-loft-search'); ?>
                        </label>
                        <p class="description">
                            <?php _e('If enabled, MLS data will be automatically synchronized on a schedule.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top" class="auto-sync-settings" <?php echo (!isset($options['auto_sync']) || !$options['auto_sync']) ? 'style="display:none;"' : ''; ?>>
                    <th scope="row">
                        <label for="sync_frequency"><?php _e('Sync Frequency', 'chicago-loft-search'); ?></label>
                    </th>
                    <td>
                        <select id="sync_frequency" name="sync_frequency">
                            <option value="hourly" <?php selected(isset($options['sync_frequency']) && $options['sync_frequency'] === 'hourly'); ?>><?php _e('Hourly', 'chicago-loft-search'); ?></option>
                            <option value="twice_daily" <?php selected(isset($options['sync_frequency']) && $options['sync_frequency'] === 'twice_daily'); ?>><?php _e('Twice Daily', 'chicago-loft-search'); ?></option>
                            <option value="daily" <?php selected(!isset($options['sync_frequency']) || $options['sync_frequency'] === 'daily'); ?>><?php _e('Daily', 'chicago-loft-search'); ?></option>
                            <option value="weekly" <?php selected(isset($options['sync_frequency']) && $options['sync_frequency'] === 'weekly'); ?>><?php _e('Weekly', 'chicago-loft-search'); ?></option>
                        </select>
                        <p class="description">
                            <?php _e('Select how often to synchronize MLS data.', 'chicago-loft-search'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <div class="manual-sync-container">
                <h3><?php _e('Manual Sync', 'chicago-loft-search'); ?></h3>
                <p><?php _e('Manually synchronize MLS data now.', 'chicago-loft-search'); ?></p>
                <button type="button" class="button button-secondary manual-sync-btn">
                    <?php _e('Sync MLS Data Now', 'chicago-loft-search'); ?>
                </button>
                <div class="sync-status"></div>
            </div>
        </div>
        
        <p class="submit">
            <input type="submit" name="chicago_loft_search_save_settings" class="button button-primary" value="<?php _e('Save Settings', 'chicago-loft-search'); ?>" />
            <button type="button" class="button button-secondary reset-all-settings">
                <?php _e('Reset All Settings', 'chicago-loft-search'); ?>
            </button>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab navigation
    $('.chicago-loft-search-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();
        var target = $(this).attr('href');
        
        // Update active tab
        $('.chicago-loft-search-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show target tab content
        $('.chicago-loft-search-tab-content').removeClass('active').hide();
        $(target).addClass('active').show();
    });
    
    // Initialize tabs
    $('.chicago-loft-search-tab-content').hide();
    $('#api-settings').show();
    
    // Temperature slider
    $('#temperature').on('input', function() {
        $('.temperature-value').text($(this).val());
    });
    
    // Show/hide throttle settings
    $('#throttle_searches').on('change', function() {
        if ($(this).is(':checked')) {
            $('.throttle-settings').show();
        } else {
            $('.throttle-settings').hide();
        }
    });
    
    // Show/hide captcha settings
    $('#enable_captcha').on('change', function() {
        if ($(this).is(':checked')) {
            $('.captcha-settings').show();
        } else {
            $('.captcha-settings').hide();
        }
    });
    
    // Show/hide logging settings
    $('#enable_logging').on('change', function() {
        if ($(this).is(':checked')) {
            $('.logging-settings').show();
        } else {
            $('.logging-settings').hide();
        }
    });
    
    // Show/hide auto sync settings
    $('#auto_sync').on('change', function() {
        if ($(this).is(':checked')) {
            $('.auto-sync-settings').show();
        } else {
            $('.auto-sync-settings').hide();
        }
    });
    
    // Reset system prompt
    $('.reset-system-prompt').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to reset the system prompt to default?', 'chicago-loft-search'); ?>')) {
            $('#system_prompt').val('<?php echo esc_js('You are a helpful assistant specializing in Chicago loft properties. Provide accurate information based on the data available. Keep responses concise and relevant to real estate inquiries only.'); ?>');
        }
    });
    
    // Reset all settings
    $('.reset-all-settings').on('click', function() {
        if (confirm('<?php _e('Are you sure you want to reset ALL settings to default? This cannot be undone!', 'chicago-loft-search'); ?>')) {
            window.location.href = '<?php echo admin_url('options-general.php?page=chicago-loft-search&reset=true'); ?>';
        }
    });
    
    // Verify API key
    $('.verify-api-key').on('click', function() {
        var apiKey = $('#openai_api_key').val();
        if (!apiKey) {
            $('.api-key-status-message').html('<span class="error"><?php _e('Please enter an API key first', 'chicago-loft-search'); ?></span>');
            return;
        }
        
        $('.api-key-status-message').html('<span class="verifying"><?php _e('Verifying...', 'chicago-loft-search'); ?></span>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chicago_loft_search_verify_api_key',
                api_key: apiKey,
                nonce: '<?php echo wp_create_nonce('chicago_loft_search_verify_api_key'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('.api-key-status-message').html('<span class="success"><?php _e('API key is valid!', 'chicago-loft-search'); ?></span>');
                } else {
                    $('.api-key-status-message').html('<span class="error">' + response.data.message + '</span>');
                }
            },
            error: function() {
                $('.api-key-status-message').html('<span class="error"><?php _e('Error verifying API key', 'chicago-loft-search'); ?></span>');
            }
        });
    });
    
    // Export settings
    $('.export-settings-btn').on('click', function() {
        window.location.href = '<?php echo admin_url('admin-ajax.php?action=chicago_loft_search_export_settings&nonce=' . wp_create_nonce('chicago_loft_search_export_settings')); ?>';
    });
    
    // Import settings
    $('.import-settings-btn').on('click', function() {
        var fileInput = $('#import-settings-file')[0];
        if (fileInput.files.length === 0) {
            $('.import-result').html('<p class="error"><?php _e('Please select a file to import', 'chicago-loft-search'); ?></p>');
            return;
        }
        
        var formData = new FormData();
        formData.append('action', 'chicago_loft_search_import_settings');
        formData.append('nonce', '<?php echo wp_create_nonce('chicago_loft_search_import_settings'); ?>');
        formData.append('settings_file', fileInput.files[0]);
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    $('.import-result').html('<p class="success"><?php _e('Settings imported successfully! Reloading page...', 'chicago-loft-search'); ?></p>');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                } else {
                    $('.import-result').html('<p class="error">' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('.import-result').html('<p class="error"><?php _e('Error importing settings', 'chicago-loft-search'); ?></p>');
            }
        });
    });
    
    // Manual sync
    $('.manual-sync-btn').on('click', function() {
        $('.sync-status').html('<p class="syncing"><?php _e('Synchronizing MLS data...', 'chicago-loft-search'); ?></p>');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chicago_loft_search_manual_sync',
                nonce: '<?php echo wp_create_nonce('chicago_loft_search_manual_sync'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('.sync-status').html('<p class="success">' + response.data.message + '</p>');
                } else {
                    $('.sync-status').html('<p class="error">' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('.sync-status').html('<p class="error"><?php _e('Error synchronizing MLS data', 'chicago-loft-search'); ?></p>');
            }
        });
    });
    
    // Load API usage stats
    function loadApiUsageStats() {
        $('.api-usage-loading').show();
        $('.api-usage-results').hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'chicago_loft_search_get_usage_stats',
                nonce: '<?php echo wp_create_nonce('chicago_loft_search_get_usage_stats'); ?>'
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
                $('.api-usage-loading').text('<?php _e('Error loading usage data', 'chicago-loft-search'); ?>');
            }
        });
    }
    
    // Load API usage stats when API settings tab is shown
    $('.chicago-loft-search-tabs .nav-tab[href="#api-settings"]').on('click', function() {
        loadApiUsageStats();
    });
    
    // Initial load of API usage stats
    loadApiUsageStats();
});
</script>

<style>
.chicago-loft-search-settings {
    margin: 20px 0;
}

.chicago-loft-search-header-info {
    display: flex;
    justify-content: space-between;
    margin-bottom: 20px;
    color: #666;
    font-size: 13px;
}

.chicago-loft-search-tab-content {
    display: none;
    padding: 20px;
    background: #fff;
    border: 1px solid #ccc;
    border-top: none;
}

.chicago-loft-search-tab-content.active {
    display: block;
}

.security-level-description {
    margin-top: 10px;
    margin-left: 20px;
}

.import-export-container {
    display: flex;
    flex-wrap: wrap;
    gap: 40px;
    margin-bottom: 30px;
}

.export-settings, .import-settings {
    flex: 1;
    min-width: 300px;
}

.import-result, .sync-status {
    margin-top: 15px;
}

.success {
    color: green;
    font-weight: bold;
}

.error {
    color: red;
    font-weight: bold;
}

.verifying, .syncing {
    color: #f90;
    font-weight: bold;
}

.api-key-status {
    margin-top: 10px;
}

.api-key-status-message {
    display: inline-block;
    margin-left: 10px;
    vertical-align: middle;
}

.manual-sync-container {
    margin-top: 30px;
}

#import-settings-file {
    margin-bottom: 10px;
    display: block;
}

.temperature-value {
    display: inline-block;
    margin-left: 10px;
    font-weight: bold;
}

.api-usage-stats {
    margin-top: 30px;
}

.api-usage-data {
    background: #f9f9f9;
    padding: 15px;
    border: 1px solid #e5e5e5;
    border-radius: 4px;
}

.api-usage-loading {
    text-align: center;
    padding: 20px;
    color: #666;
}

.api-usage-results table {
    margin-top: 10px;
}

hr {
    margin: 30px 0;
    border: none;
    border-top: 1px solid #ddd;
}
</style>

<?php
/*
Plugin Name: IP Informant Logger
Plugin URI: https://jeremywhittaker.com/
Description: Logs and displays visitor IP addresses.
Version: 1.27
Author: Jeremy R Whittaker
Author URI: https://JeremyWhittaker.com/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once(ABSPATH . 'wp-admin/includes/file.php');
WP_Filesystem();
global $wp_filesystem;

register_activation_hook(__FILE__, 'ip_informant_logger_activate');


/**
 * Function to run upon plugin activation.
 * It creates the logs directory and an initial log file.
 */
function ip_informant_logger_activate() {
    global $wp_filesystem;
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();

    $log_dir = plugin_dir_path(__FILE__) . 'logs/';

    // Ensure the logs directory exists
    if (!$wp_filesystem->is_dir($log_dir)) {
        $wp_filesystem->mkdir($log_dir, 0755);
    }

    // Create an initial empty log file with today's date
    $today_log_file = $log_dir . 'visitor_ips_' . gmdate("Y-m-d") . '.log';
    if (!$wp_filesystem->exists($today_log_file)) {
        $wp_filesystem->put_contents($today_log_file, '', FS_CHMOD_FILE);
    }
}

function ip_informant_logger_settings_init() {
	// Register a new setting for "ip-logger" page
    register_setting('ip-informant-logger', 'ip_informant_logger_settings');

	// Register a new section in the "ip-logger" page
	add_settings_section(
        'ip_informant_logger_section_developers',
        __('API Settings', 'ip-informant-logger'),
        'ip_informant_logger_section_developers_cb',
        'ip-informant-logger'
	);

	// Register a new field in the "ip_logger_section_developers" section, inside the "ip-logger" page
    add_settings_field(
        'ip_informant_logger_field_api_key',
        __('IPinfo.io API Key', 'ip-informant-logger'),
        'ip_informant_logger_field_api_key_cb',
        'ip-informant-logger',
        'ip_informant_logger_section_developers',
        [
            'label_for' => 'ip_informant_logger_field_api_key',
            'class' => 'ip_informant_logger_row',
            'ip_informant_logger_custom_data' => 'custom',
            'description' => 'Enter your IPinfo.io API key here. You can obtain an API key by signing up at https://ipinfo.io/'
        ]
    );
	
    add_settings_field(
        'ip_informant_logger_max_log_days',
        __('Max Log Days', 'ip-informant-logger'),
        'ip_informant_logger_max_log_days_cb',
        'ip-informant-logger',
        'ip_informant_logger_section_developers',
        [
            'label_for' => 'ip_informant_logger_max_log_days',
            'class' => 'ip_informant_logger_row',
            'description' => 'Enter the maximum number of days to keep logs.'
        ]
    );


}


add_action('admin_init', 'ip_informant_logger_settings_init');

/**
 * Custom option and settings:
 *  - Callback functions
 */

// Developers section callback function
function ip_informant_logger_section_developers_cb($args) {
    ?>
    <p id="<?php echo esc_attr($args['id']); ?>"><?php esc_html_e('Enter your API key here.', 'ip-informant-logger'); ?></p>
    <?php
}

// API Key field callback function
function ip_informant_logger_field_api_key_cb($args) {
    $options = get_option('ip_informant_logger_settings');
    ?>
    <input id="<?php echo esc_attr($args['label_for']); ?>"
           data-custom="<?php echo esc_attr($args['ip_informant_logger_custom_data']); ?>"
           name="ip_informant_logger_settings[<?php echo esc_attr($args['label_for']); ?>]"
           type="text"
           value="<?php echo esc_attr($options[$args['label_for']] ?? ''); ?>">
    <?php
    if (!empty($args['description'])) {
        // Update the description here to include a clickable link
		echo wp_kses(
    __('<p class="description">Enter your IPinfo.io API key here. You can obtain an API key by signing up at <a href="https://ipinfo.io/account/token" target="_blank">IPinfo.io</a>.</p>', 'ip-informant-logger'),
    array(
        'p' => array('class' => array()),
        'a' => array('href' => array(), 'target' => array()),
    )
);
	}
}
/**
 * Add the top level menu page.
 */
function ip_informant_logger_options_page() {
    add_menu_page(
        'IP Informant Logger',
        'IP Informant Logger Options',
        'manage_options',
        'ip-informant-logger',
        'ip_informant_logger_options_page_html'
    );
}


/**
 * Register our ip_logger_options_page to the admin_menu action hook.
 */
add_action('admin_menu', 'ip_informant_logger_options_page');
function ip_informant_logger_options_page_html() {
    // Check user capabilities
    if (!current_user_can('manage_options')) {
        return;
    }

    // Handle settings form submission
    if (isset($_GET['settings-updated'])) {
        add_settings_error('ip_informant_logger_messages', 'ip_informant_logger_message', __('Settings Saved', 'ip-informant-logger'), 'updated');
    }

    // Show error/update messages
    settings_errors('ip_informant_logger_messages');

    // Display the settings form
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('ip-informant-logger');
            do_settings_sections('ip-informant-logger');
            submit_button('Save Settings');
            ?>
        </form>
    </div>

    <?php
    // Check for log search or view requests
    $search_query = filter_input(INPUT_POST, 'search_log', FILTER_SANITIZE_STRING);
    $selected_log_file = filter_input(INPUT_POST, 'log_file', FILTER_SANITIZE_STRING);

    // Verify nonce for log search/view actions
	if ( isset( $_POST['ip_informant_logger_nonce'] ) ) {
		$nonce_verified = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ip_informant_logger_nonce'] ) ), 'ip_informant_logger_action' );
	} else {
		$nonce_verified = false;
	}

    // If nonce is verified or not required for the current request, process the log search or view action
    if ($nonce_verified || (!isset($_POST['search_submit']) && !isset($_POST['view_log']))) {
        ?>
        <form method="post">
            <?php wp_nonce_field('ip_informant_logger_action', 'ip_informant_logger_nonce'); ?>
            <p><label for="search_log">Search Logs:</label>
            <input type="text" id="search_log" name="search_log" placeholder="Enter search term...">
            <input type="submit" name="search_submit" value="Search">
            <?php
            // Dropdown for selecting a log file, similar to the original implementation
            $log_dir_path = plugin_dir_path(__FILE__) . 'logs/';
			if (!file_exists($log_dir_path) || !is_readable($log_dir_path)) {
				// Attempt to create the directory if it doesn't exist
				if (!wp_mkdir_p($log_dir_path)) {
					// Directory creation failed, handle the error, e.g., log it or display an admin notice
					error_log("Unable to create directory: " . $log_dir_path);
					return; // Exit the function to avoid further errors
				}
			}

			// Now it's safe to call scandir() since we've ensured the directory exists and is readable
			$log_files = scandir($log_dir_path);

			// Check if scandir() was successful before proceeding
			if ($log_files === false) {
				// Handle the error appropriately if scandir() failed
				error_log("Unable to open directory: " . $log_dir_path);
				return; // Exit the function to avoid further errors
			}

			// Continue with your code, e.g., filtering log files
			$log_files = array_diff($log_files, array('..', '.'));
            $log_files = array_filter($log_files, function($filename) {
                return strpos($filename, 'visitor_ips_') === 0;
            });

            if (!empty($log_files)) {
                echo '<select name="log_file">';
                foreach ($log_files as $file) {
                    echo '<option value="' . esc_attr($file) . '">' . esc_html($file) . '</option>';
                }
                echo '</select>';
            }
            ?>
            <input type="submit" name="view_log" value="View Log">
            </p>
        </form>
        <?php
        // Process log search or view actions if requested and nonce verified
        if ($nonce_verified) {
            if (isset($_POST['search_submit']) && $search_query) {
				echo "<h2>" . esc_html__('Search Results for ', 'ip-informant-logger') . esc_html($search_query) . "</h2>";
                ipil_search_through_all_logs($log_dir_path, $search_query);
            } elseif (isset($_POST['view_log']) && $selected_log_file) {
                echo '<h2>Log File Contents</h2>';
                $current_log_file_path = $log_dir_path . $selected_log_file;
                ipil_display_log_contents($current_log_file_path);
            }
        }
    } else {
        // Handle nonce verification failure for log search/view actions
        echo '<p>Sorry, your request could not be verified.</p>';
    }
}


/**
 * Search through all logs for a given query and display the results.
 * 
 * @param string $log_dir_path The path to the log directory.
 * @param string $search_query The search query.
 */
function ipil_search_through_all_logs($log_dir_path, $search_query) {
    $log_files = new DirectoryIterator($log_dir_path);
    $matches = [];

    foreach ($log_files as $file) {
        if ($file->isDot() || !$file->isFile()) continue;

        $log_contents = file($file->getPathname(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($log_contents as $line) {
            if (stripos($line, $search_query) !== false) {
                $matches[] = $file->getFilename() . ": " . $line; // Prepend filename to each matching line
            }
        }
    }

    if (!empty($matches)) {
        echo '<textarea readonly style="width:100%; height:200px;">' . esc_textarea(implode("\n", $matches)) . '</textarea>';
    } else {
        echo '<p>No matching log entries found.</p>';
    }
}

/**
 * Displays the log file contents or search results.
 * 
 * @param string $log_file_path The path to the current log file.
 * @param string|null $search_query The search query, if any.
 */
function ipil_display_log_contents($log_file_path, $search_query = null) {
	if (file_exists($log_file_path)) {
		$log_contents = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); // Read into array

		if (!empty($search_query)) {
			// Filter log contents based on search query
			$log_contents = array_filter($log_contents, function($line) use ($search_query) {
				return stripos($line, $search_query) !== false;
			});
		}

		if (count($log_contents) > 0) {
			echo '<textarea readonly style="width:100%; height:200px;">' . esc_textarea(implode("\n", $log_contents)) . '</textarea>';
		} else {
			echo '<p>No matching log entries found.</p>';
		}
	} else {
		echo '<p>No log file found.</p>';
	}
}

function ipil_get_ip_details($visitor_ip) {
	$options = get_option('ip_informant_logger_settings'); 
    if (empty($options['ip_informant_logger_field_api_key'])) { // Corrected the option array key
        return "API key not set.";
    }
    $api_token = $options['ip_informant_logger_field_api_key']; // Corrected the option array key


    $today = gmdate("Y-m-d");
    $log_file_path = plugin_dir_path(__FILE__) . 'logs/visitor_ips_' . $today . '.log';

    // Search for cached IP details in today's log file
    $cached_details = ipil_search_ip_in_log($visitor_ip, $log_file_path);
    if ($cached_details) {
        return $cached_details . " (retrieved from cache)";
    }

    // Fetch new details from IPinfo.io
    $ipinfo_url = "https://ipinfo.io/{$visitor_ip}?token={$api_token}";
    $context = stream_context_create(['http' => ['header' => 'Accept: application/json']]);
	$response = wp_remote_get($ipinfo_url, array('headers' => array('Accept' => 'application/json')));
	if (is_wp_error($response)) {
		return "Details: Unable to retrieve from IPinfo.io";
	}
	$ipinfo_json = wp_remote_retrieve_body($response);
    if ($ipinfo_json === false) {
        return "Details: Unable to retrieve from IPinfo.io";
    } else {
        $ipinfo = json_decode($ipinfo_json, true);
        $ipinfo_string = array_reduce(array_keys($ipinfo), function ($carry, $key) use ($ipinfo) {
            return $carry . (empty($carry) ? '' : ', ') . ucfirst($key) . ': ' . $ipinfo[$key];
        }, '');

        return $ipinfo_string;
    }
}

function ipil_search_ip_in_log($visitor_ip, $log_file_path) {
    if (file_exists($log_file_path)) {
        $log_contents = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($log_contents as $log_entry) {
            if (strpos($log_entry, $visitor_ip) !== false) {
                // Assuming the IP details are logged in a specific format after the IP address
                $parts = explode(' - ', $log_entry);
                if (count($parts) > 1) {
                    return $parts[1]; // Return the details part if found
                }
            }
        }
    }
    return false; // Return false if not found
}

function ipil_log_visitor_ip() {
    global $wp_filesystem;

    $visitor_ip = filter_var(sanitize_text_field($_SERVER['REMOTE_ADDR']), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

    if (!$visitor_ip) {
        return;
    }

    $log_dir = plugin_dir_path(__FILE__) . 'logs/';
    $today = gmdate("Y-m-d");
    $log_file_name = "visitor_ips_{$today}.log";
    $log_file_path = $log_dir . $log_file_name;

    // Ensure the logs directory exists
    if (!$wp_filesystem->is_dir($log_dir)) {
        $wp_filesystem->mkdir($log_dir, 0755);
    }

    // Ensure today's log file exists, create if it does not
    if (!$wp_filesystem->exists($log_file_path)) {
        $wp_filesystem->put_contents($log_file_path, '', FS_CHMOD_FILE);
    }

    $ipinfo_string = ipil_get_ip_details($visitor_ip);
    $log_entry = gmdate("Y-m-d H:i:s") . " - IP: " . $visitor_ip . ", " . $ipinfo_string . "\n";

    // Append the new log entry to today's file. Note that appending is handled by reading and then writing due to WP_Filesystem limitations.
    $existing_contents = $wp_filesystem->get_contents($log_file_path);
    $new_contents = $existing_contents . $log_entry;
    $wp_filesystem->put_contents($log_file_path, $new_contents, FS_CHMOD_FILE);
}


add_action('init', 'ipil_log_visitor_ip');
add_action('wp', 'ipil_log_visitor_ip');



/**
 * Check and append the last log entry to the appropriate file based on its date.
 * 
 * @param string $log_file_path The current log file path.
 */
function ipil_check_and_append_last_log_entry($log_file_path) {
    global $wp_filesystem;
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    WP_Filesystem();

    if ($wp_filesystem->exists($log_file_path)) {
        $last_line = ''; // Initialize variable to store the last line

        // Use the WP_Filesystem to read the last line of the file
        $file_data = explode("\n", $wp_filesystem->get_contents($log_file_path));
        $last_line = end($file_data);

        // Extract the date from the last line
        $last_date = ipil_extract_date_from_log_entry($last_line);

        // Check if the last log entry is not from today
        if ($last_date !== gmdate('Y-m-d')) {
            // Determine the file name based on the last entry's date
            $new_file_name = plugin_dir_path(__FILE__) . 'logs/visitor_ips_' . $last_date . '.log';

            // Append the last line to the new file
            if ($wp_filesystem->exists($new_file_name)) {
                $existing_data = $wp_filesystem->get_contents($new_file_name);
                $wp_filesystem->put_contents($new_file_name, $existing_data . $last_line . "\n", FS_CHMOD_FILE);
            } else {
                $wp_filesystem->put_contents($new_file_name, $last_line . "\n", FS_CHMOD_FILE);
            }
        }
    }
}

function ipil_extract_date_from_log_entry($log_entry) {
    // Assuming the log entry starts with a date in 'Y-m-d' format, adjust accordingly if not
    $date_part = substr($log_entry, 0, 10);
    return $date_part;
}


function ipil_setup_daily_cleanup_task() {
    if (!wp_next_scheduled('ip_informant_logger_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'ip_informant_logger_daily_cleanup');
    }
}

add_action('ip_informant_logger_daily_cleanup', 'ipil_cleanup_old_log_files');
/**
 * Deletes log files older than the specified retention period.
 */
function ipil_cleanup_old_log_files() {
    $retention_days = get_option('ip_informant_logger_retention_days', 30);
    $log_dir = plugin_dir_path(__FILE__) . 'logs/';

    $log_files = $wp_filesystem->dirlist($log_dir);
    foreach ($log_files as $file) {
        if ('f' === $file['type']) {
            $file_path = $log_dir . $file['name'];
            $fileDate = DateTime::createFromFormat('Y-m-d', substr($file['name'], -14, 10));
            $now = new DateTime();
            if ($fileDate && $now->diff($fileDate)->days >= $retention_days) {
                wp_delete_file($file_path);
            }
        }
    }
}


// Callback function for rendering the max log days field
function ip_informant_logger_max_log_days_cb($args) {
    $options = get_option('ip_informant_logger_settings'); // Correct option name
    ?>
    <input id="<?php echo esc_attr($args['label_for']); ?>"
           name="ip_informant_logger_settings[<?php echo esc_attr($args['label_for']); ?>]"
           type="number"
           value="<?php echo esc_attr($options[$args['label_for']] ?? ''); ?>">
    <?php
    if (!empty($args['description'])) {
        echo '<p class="description">' . esc_html($args['description']) . '</p>';
    }
}

function ipil_archive_and_cleanup_logs() {
    global $wp_filesystem;
    $log_dir_path = plugin_dir_path(__FILE__) . 'logs/';
    $current_log_file = $log_dir_path . 'visitor_ips.log';
    $archive_dir_path = $log_dir_path . 'archive/';
    $options = get_option('ip_informant_logger_settings'); 
    $max_log_days = (isset($options['ip_informant_logger_max_log_days']) ? $options['ip_informant_logger_max_log_days'] : 30);

    // Check if current log file exists and determine if archiving is needed
    if ($wp_filesystem->exists($current_log_file)) {
        $last_modified = gmdate('Y-m-d', $wp_filesystem->mtime($current_log_file));
        $current_date = gmdate('Y-m-d');
        if ($last_modified < $current_date) {
            // Move current log file to archive with date prefix
            if (!$wp_filesystem->is_dir($archive_dir_path)) {
                $wp_filesystem->mkdir($archive_dir_path, 0755);
            }
            $archive_file_path = $archive_dir_path . $last_modified . '-visitor_ips.log';
            $wp_filesystem->move($current_log_file, $archive_file_path);
        }
    }

    // Cleanup old log files in the archive
    $files = $wp_filesystem->dirlist($archive_dir_path);
    foreach ($files as $file) {
        if ('f' === $file['type']) {
            $file_path = $archive_dir_path . $file['name'];
            $file_date = substr($file['name'], 0, 10); // Assuming file format is "Y-m-d-visitor_ips.log"
            $file_age_days = (strtotime($current_date) - strtotime($file_date)) / (60 * 60 * 24);
            if ($file_age_days > $max_log_days) {
                // Use wp_delete_file() for file deletion
                wp_delete_file($file_path);
            }
        }
    }
}

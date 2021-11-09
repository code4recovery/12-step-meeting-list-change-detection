<?php
/*
Plugin Name: 12 Step Meeting List Change Detection
Plugin URI: https://wordpress.org/plugins/12-step-meeting-list-change-detection/
Description: This '12 Step Meeting List' plugin add-on augments the existing data import utility by sensing data changes in enabled data source feeds and generating email notifications for Change Notification Email receipients registered on the Import & Settings page. 
Version: 1.0.0
Requires PHP: 5.6
Author: Code4Recovery
Author URI: https://github.com/code4recovery/12-step-meeting-list-change-detection
Text Domain: 12-step-meeting-list-change-detection
Updated: November 9, 2021
 */

 //define constants
if (!defined('TSMLCD_CONTACT_EMAIL')) {
    define('TSMLCD_CONTACT_EMAIL', 'tsml@code4recovery.org');
}

if (!defined('TSMLCD_VERSION')) {
    define('TSMLCD_VERSION', '1.0.0');
}

if (!defined('TSMLCD_PLUGIN_DIR')) {
    define('TSMLCD_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('TSMLCD_PLUGIN_INCLUDES_DIR')) {
    define( 'TSMLCD_PLUGIN_INCLUDES_DIR', TSMLCD_PLUGIN_DIR . '/includes/' );
}

// force use of include files from this plugin folder
include TSMLCD_PLUGIN_INCLUDES_DIR . 'admin_import_override.php';
//include TSMLCD_PLUGIN_INCLUDES_DIR . 'functions.php';


/* ******************** start of data_source_change_detection ****************** */
//called by register_activation_hook in admin_import
function tsml_activate_data_source_scan() {
	//Use wp_next_scheduled to check if the event is already scheduled
	$timestamp = wp_next_scheduled( 'tsml_scan_data_source' );

	//If $timestamp == false schedule scan since it hasn't been done previously
	if( $timestamp == false ){
		//Schedule the event for right now, then to reoccur daily using the hook 'tsml_scan_data_source'
		wp_schedule_event( time(), 'daily', 'tsml_scan_data_source' );
	}
}
//called by register_deactivation_hook in admin_import
//removes the cron-job set by tsml_activate_daily_refresh()
function tsml_deactivate_data_source_scan() {
	wp_clear_scheduled_hook( 'tsml_scan_data_source' );
}

//function: scans passed data source url looking for recent updates
//used:		fired by cron job tsml_scan_data_source
add_action('tsml_scan_data_source', 'tsml_scan_data_source', 10, 1);
if (!function_exists('tsml_scan_data_source')) {
	function tsml_scan_data_source($data_source_url) {

		$errors = array();
		$data_source_name = null;
		$data_source_parent_region_id = -1;
		$data_source_change_detect = 'disabled';
		$data_source_count_meetings = 0;
		$data_source_last_import = null;

		$tsml_notification_addresses = get_option('tsml_notification_addresses', array());
		$tsml_data_sources = get_option('tsml_data_sources', array());
		$data_source_count_meetings = (int) $tsml_data_sources[$data_source_url]['count_meetings'];

		if ( !empty($tsml_notification_addresses) && $data_source_count_meetings !== 0) {

			if ( array_key_exists( $data_source_url, $tsml_data_sources ) ) {
				$data_source_name = $tsml_data_sources[$data_source_url]['name'];
				$data_source_parent_region_id = $tsml_data_sources[$data_source_url]['parent_region_id'];
				$data_source_change_detect = $tsml_data_sources[$data_source_url]['change_detect'];
				$data_source_last_import = (int) $tsml_data_sources[$data_source_url]['last_import'];
			} else {
				$errors .= "Data Source not registered in tsml_data_sources of the options table!";
				return;
			}

			//try fetching	
			$response = wp_remote_get($data_source_url, array(
				'timeout' => 30,
				'sslverify' => false,
			));

			if (is_array($response) && !empty($response['body']) && ($body = json_decode($response['body'], true))) {			

				//allow theme-defined function to reformat prior to import 
				if (function_exists('tsml_import_reformat')) {
					$meetings = tsml_import_reformat($body);
				}

				// check import feed for changes
				$meetings_updated = tsml_import_has_changes($meetings, $data_source_count_meetings, $data_source_last_import);

				if ($meetings_updated) {

					// Send Email notifying Admins that this Data Source needs updating

					$message = "Data Source changes were detected during a scheduled sychronization check with this feed: $data_source_url. Your website meeting list details based on the $data_source_name feed are no longer in sync. <br><br>Please sign-in to your website and refresh the $data_source_name Data Source feed found on the Meetings Import & Settings page.<br><br>";
					$message .= "data_source_name: $data_source_name <br>";
					$term = get_term_by('term_id', $data_source_parent_region_id, 'tsml_region');
					$parent_region = $term->name;
					$message .= "parent_region: $parent_region <br>";
					$message .= "change_detect: $data_source_change_detect <br>";
					$message .= " database count: $data_source_count_meetings <br>";
					$feedCount = count($meetings);
					$message .= "import feed cnt: $feedCount<br>";
					$message .= 'Last Refresh: ' . Date("l F j, Y  h:i a", $data_source_last_import) . '<br>';
					if ($meetings_updated) { 
						$message .= "<br><b><u>Detected Difference</b></u><br>";
						foreach ($meetings_updated as $updated_group) {
							$message .=  "$updated_group <br>";
						}
					}

					// send Changes Detected email 
					$subject = __('Data Source Changes Detected', '12-step-meeting-list') . ': ' . $data_source_name;
					if (tsml_email($tsml_notification_addresses, str_replace("'s", "s", $subject), $message)) {
						_e("<div class='bg-success text-light'>Data Source changes were detected during the daily sychronization check with this feed: $data_source_url.<br></div>", '12-step-meeting-list');
					} 
					else {
						global $phpmailer;
						if (!empty($phpmailer->ErrorInfo)) {
							printf(__('Error: %s', '12-step-meeting-list'), $phpmailer->ErrorInfo);
						} 
						else {
							_e("<div class='bg-warning text-dark'>An error occurred while sending email!</div>", '12-step-meeting-list');
						}
					}
					remove_filter('wp_mail_content_type', 'tsml_email_content_type_html');
					tsml_alert(__('Send Email: Data Source Changes Detected.', '12-step-meeting-list'));

				} 

			} elseif (!is_array($response)) {
			
				tsml_alert(__('Invalid response, <pre>' . print_r($response, true) . '</pre>.', '12-step-meeting-list'), 'error');

			} elseif (empty($response['body'])) {
			
				tsml_alert(__('Data source gave an empty response, you might need to try again.', '12-step-meeting-list'), 'error');

			} else {

				switch (json_last_error()) {
					case JSON_ERROR_NONE:
						tsml_alert(__('JSON: no errors.', '12-step-meeting-list'), 'error');
						break;
					case JSON_ERROR_DEPTH:
						tsml_alert(__('JSON: Maximum stack depth exceeded.', '12-step-meeting-list'), 'error');
						break;
					case JSON_ERROR_STATE_MISMATCH:
						tsml_alert(__('JSON: Underflow or the modes mismatch.', '12-step-meeting-list'), 'error');
						break;
					case JSON_ERROR_CTRL_CHAR:
						tsml_alert(__('JSON: Unexpected control character found.', '12-step-meeting-list'), 'error');
						break;
					case JSON_ERROR_SYNTAX:
						tsml_alert(__('JSON: Syntax error, malformed JSON.', '12-step-meeting-list'), 'error');
						break;
					case JSON_ERROR_UTF8:
						tsml_alert(__('JSON: Malformed UTF-8 characters, possibly incorrectly encoded.', '12-step-meeting-list'), 'error');
						break;
					default:
						tsml_alert(__('JSON: Unknown error.', '12-step-meeting-list'), 'error');
						break;
				}
			}
		}
	}	
}

//function:	Returns boolean indicator when data source changes detected
function tsml_import_has_changes($meetings, $db_count, $data_source_last_refresh) {

	$meetings_updated = array();

	//allow theme-defined function to reformat import - issue #439
	if (function_exists('tsml_import_reformat')) {
		$meetings = tsml_import_reformat($meetings);
	}

	if ( count($meetings) !== $db_count ) {
		$meetings_updated[] = 'Record count different';
	} 

	foreach ($meetings as $meeting) { 
		// has meeting been updated?
		$updated = $meeting['updated'];
		$cur_meeting_lastupdate = strtotime( $updated );
		if ($cur_meeting_lastupdate > $data_source_last_refresh) {
			$mtg_name = $meeting['name'];
			$mtg_updte = date("l F j, Y  h:i a", $cur_meeting_lastupdate);
			$meetings_updated[] = "$mtg_name updated: $mtg_updte";
		}
	}
	return $meetings_updated;
}	

//function:	Creates and configures cron job to run a scheduled data source scan
//used:		admin-import.php 
function tsml_CreateAndScheduleCronJob($data_source_url, $data_source_name) {

	$timestamp = tsml_strtotime('tomorrow midnight'); // Use tsml_strtotime to incorporate local site timezone with UTC. 

	// Get the timestamp for the next event when found.
	$ts = wp_next_scheduled( "tsml_scan_data_source", array( $data_source_url ) );
	if ($ts) {
		$mydisplaytime = tsml_date_localised(get_option('date_format') . ' ' . get_option('time_format'), $ts); // Use tsml_date_localised to convert to specified format with local site timezone included.
		tsml_alert ("The $data_source_name data source's next scheduled run is $mydisplaytime.  You can adjust the recurrences and the times that the job ('<b>tsml_scan_data_source</b>') runs with the WP_Crontrol plugin.");
	} else {
		// When adding a data source we schedule its daily cron job
		register_activation_hook( __FILE__, 'tsml_activate_data_source_scan' );
							
		//Schedule the refresh  
		if ( wp_schedule_event( $timestamp, "daily", "tsml_scan_data_source", array( $data_source_url ) ) === false ) {
			tsml_debug ("$data_source_name data source scan scheduling failed!");
		} else {
			$mydisplaytime = tsml_date_localised(get_option('date_format') . ' ' . get_option('time_format'), $timestamp); // Use tsml_date_localised to convert to specified format with local site timezone included.
			tsml_alert ("The $data_source_name data source's next scheduled run is $mydisplaytime.  You can adjust the recurrences and the times that the job ('<b>tsml_scan_data_source</b>') runs with the WP_Crontrol plugin.");
		}
	}
}

//function:	incorporates wp timezone into php's StrToTime() function 
//used:		here, admin-import.php 
function tsml_strtotime($str) {
  // This function behaves a bit like PHP's StrToTime() function, but taking into account the Wordpress site's timezone
  // CAUTION: It will throw an exception when it receives invalid input - please catch it accordingly
  // From https://mediarealm.com.au/
  
  $tz_string = get_option('timezone_string');
  $tz_offset = get_option('gmt_offset', 0);
  
  if (!empty($tz_string)) {
      // If site timezone option string exists, use it
      $timezone = $tz_string;
  
  } elseif ($tz_offset == 0) {
      // get UTC offset, if it isn’t set then return UTC
      $timezone = 'UTC';
  
  } else {
      $timezone = $tz_offset;
      
      if(substr($tz_offset, 0, 1) != "-" && substr($tz_offset, 0, 1) != "+" && substr($tz_offset, 0, 1) != "U") {
          $timezone = "+" . $tz_offset;
      }
  }
  
  $datetime = new DateTime($str, new DateTimeZone($timezone));
  return $datetime->format('U');
}

//function:	incorporates wp timezone into php's date() function 
//used:		here, admin-import.php 
function tsml_date_localised($format, $timestamp = null) {
  // This function behaves a bit like PHP's Date() function, but taking into account the Wordpress site's timezone
  // CAUTION: It will throw an exception when it receives invalid input - please catch it accordingly
  // From https://mediarealm.com.au/
  
  $tz_string = get_option('timezone_string');
  $tz_offset = get_option('gmt_offset', 0);
  
  if (!empty($tz_string)) {
      // If site timezone option string exists, use it
      $timezone = $tz_string;
  
  } elseif ($tz_offset == 0) {
      // get UTC offset, if it isn’t set then return UTC
      $timezone = 'UTC';
  
  } else {
      $timezone = $tz_offset;
      
      if(substr($tz_offset, 0, 1) != "-" && substr($tz_offset, 0, 1) != "+" && substr($tz_offset, 0, 1) != "U") {
          $timezone = "+" . $tz_offset;
      }
  }
  
  if($timestamp === null) {
    $timestamp = time();
  }
  
  $datetime = new DateTime();
  $datetime->setTimestamp($timestamp);
  $datetime->setTimezone(new DateTimeZone($timezone));
  return $datetime->format($format);
}
/* ******************** end of data_source_change_detection ******************** */


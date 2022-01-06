<?php
namespace DataSourceChangeDetection {
// Overload of tsml_import_buffer_set

	//sanitize and import an array of meetings to an 'import buffer' (an wp_option that's iterated on progressively)
	//called from admin_import.php (both CSV and JSON)
	function tsml_import_buffer_set($meetings, $data_source_url = null, $data_source_parent_region_id = null) {
		global $tsml_programs, $tsml_program, $tsml_days, $tsml_meeting_attendance_options;

		if (strpos($data_source_url, "spreadsheets.google.com") !== false){
			$meetings = tsml_import_reformat_googlesheet($meetings);
		}

		//allow theme-defined function to reformat import - issue #439
		if (function_exists('tsml_import_reformat')) {
			$meetings = tsml_import_reformat($meetings);
		}

		//uppercasing for value matching later
		$upper_types = array_map('strtoupper', $tsml_programs[$tsml_program]['types']);
		$upper_days = array_map('strtoupper', $tsml_days);

		//get users, keyed by username
		$users = tsml_get_users(false);
		$user_id = get_current_user_id();

		//convert the array to UTF-8
		array_walk_recursive($meetings, 'tsml_format_utf8');

		//trim everything
		array_walk_recursive($meetings, 'tsml_import_sanitize_field');

		//check for any meetings with arrays of days and creates an individual meeting for each day in array
		$meetings_to_add = array();
		$indexes_to_remove = array();

		for ($i = 0; $i < count($meetings); $i++) {
			if (isset($meetings[$i]['day']) && is_array($meetings[$i]['day'])) {
				array_push($indexes_to_remove, $i);
				foreach ($meetings[$i]['day'] as $single_day) {
					$temp_meeting = $meetings[$i];
					$temp_meeting['day'] = $single_day;
					$temp_meeting['slug'] = $meetings[$i]['slug'] . "-" . $single_day;
					array_push($meetings_to_add, $temp_meeting);
				}
			}
		}

		for ($i = 0; $i < count($indexes_to_remove); $i++) {
			unset($meetings[$indexes_to_remove[$i]]);
		}

		$meetings = array_merge($meetings, $meetings_to_add);

		//prepare array for import buffer
		$count_meetings = count($meetings);
		for ($i = 0; $i < $count_meetings; $i++) {

			$meetings[$i]['data_source'] = $data_source_url;
			$meetings[$i]['data_source_parent_region_id'] = $data_source_parent_region_id;

			//do wordpress sanitization
			foreach ($meetings[$i] as $key => $value) {

				//have to compress types down real quick (only happens with json)
				if (is_array($value)) $value = implode(',', $value);

				if (tsml_string_ends($key, 'notes')) {
					$meetings[$i][$key] = sanitize_text_area($value);
				} else {
					$meetings[$i][$key] = sanitize_text_field($value);
				}
			}

			//column aliases
			if (empty($meetings[$i]['postal_code']) && !empty($meetings[$i]['zip'])) {
				$meetings[$i]['postal_code'] = $meetings[$i]['zip'];
			}
			if (empty($meetings[$i]['name']) && !empty($meetings[$i]['meeting'])) {
				$meetings[$i]['name'] = $meetings[$i]['meeting'];
			}
			if (empty($meetings[$i]['location']) && !empty($meetings[$i]['location_name'])) {
				$meetings[$i]['location'] = $meetings[$i]['location_name'];
			}
			if (empty($meetings[$i]['time']) && !empty($meetings[$i]['start_time'])) {
				$meetings[$i]['time'] = $meetings[$i]['start_time'];
			}

			//if '@' is in address, remove it and everything after
			if (!empty($meetings[$i]['address']) && $pos = strpos($meetings[$i]['address'], '@')) $meetings[$i]['address'] = trim(substr($meetings[$i]['address'], 0, $pos));

			//if location name is missing, use address
			if (empty($meetings[$i]['location'])) {
				$meetings[$i]['location'] = empty($meetings[$i]['address']) ? __('Meeting Location', '12-step-meeting-list') : $meetings[$i]['address'];
			}

			//day can either be 0, 1, 2, 3 or Sunday, Monday, or empty
			if (isset($meetings[$i]['day']) && !array_key_exists($meetings[$i]['day'], $upper_days)) {
				$meetings[$i]['day'] = array_search(strtoupper($meetings[$i]['day']), $upper_days);
			}

			//sanitize time & day
			if (empty($meetings[$i]['time']) || ($meetings[$i]['day'] === false)) {
				$meetings[$i]['time'] = $meetings[$i]['end_time'] = $meetings[$i]['day'] = false; //by appointment

				//if meeting name missing, use location
				if (empty($meetings[$i]['name'])) $meetings[$i]['name'] = sprintf(__('%s by Appointment', '12-step-meeting-list'), $meetings[$i]['location']);
			} else {
				//if meeting name missing, use location, day, and time
				if (empty($meetings[$i]['name'])) {
					$meetings[$i]['name'] = sprintf(__('%s %ss at %s', '12-step-meeting-list'), $meetings[$i]['location'], $tsml_days[$meetings[$i]['day']], $meetings[$i]['time']);
				}

				$meetings[$i]['time'] = tsml_format_time_reverse($meetings[$i]['time']);
				if (!empty($meetings[$i]['end_time'])) $meetings[$i]['end_time'] = tsml_format_time_reverse($meetings[$i]['end_time']);
			}

			//google prefers USA for geocoding
			if (!empty($meetings[$i]['country']) && $meetings[$i]['country'] == 'US') $meetings[$i]['country'] = 'USA';

			//build address
			if (empty($meetings[$i]['formatted_address'])) {
				$address = array();
				if (!empty($meetings[$i]['address'])) $address[] = $meetings[$i]['address'];
				if (!empty($meetings[$i]['city'])) $address[] = $meetings[$i]['city'];
				if (!empty($meetings[$i]['state'])) $address[] = $meetings[$i]['state'];
				if (!empty($meetings[$i]['postal_code'])) {
					if ((strlen($meetings[$i]['postal_code']) < 5) && ($meetings[$i]['country'] == 'USA')) $meetings[$i]['postal_code'] = str_pad($meetings[$i]['postal_code'], 5, '0', STR_PAD_LEFT);
					$address[] = $meetings[$i]['postal_code'];
				}
				if (!empty($meetings[$i]['country'])) $address[] = $meetings[$i]['country'];
				$meetings[$i]['formatted_address'] = implode(', ', $address);
			}

			//notes
			if (empty($meetings[$i]['notes'])) $meetings[$i]['notes'] = '';
			if (empty($meetings[$i]['location_notes'])) $meetings[$i]['location_notes'] = '';
			if (empty($meetings[$i]['group_notes'])) $meetings[$i]['group_notes'] = '';

			//updated
			if (empty($meetings[$i]['updated']) || (!$meetings[$i]['updated'] = strtotime($meetings[$i]['updated']))) $meetings[$i]['updated'] = time();
			$meetings[$i]['post_modified'] = date('Y-m-d H:i:s', $meetings[$i]['updated']);
			$meetings[$i]['post_modified_gmt'] = get_gmt_from_date($meetings[$i]['post_modified']);

			//author
			if (!empty($meetings[$i]['author']) && array_key_exists($meetings[$i]['author'], $users)) {
				$meetings[$i]['post_author'] = $users[$meetings[$i]['author']];
			} else {
				$meetings[$i]['post_author'] = $user_id;
			}

			//default region to city if not specified
			if (empty($meetings[$i]['region']) && !empty($meetings[$i]['city'])) $meetings[$i]['region'] = $meetings[$i]['city'];

			//sanitize types (they can be Closed or C)
			if (empty($meetings[$i]['types'])) $meetings[$i]['types'] = '';
			$types = explode(',', $meetings[$i]['types']);
			$meetings[$i]['types'] = $unused_types = array();
			foreach ($types as $type) {
				$upper_type = trim(strtoupper($type));
				if (array_key_exists($upper_type, $upper_types)) {
					$meetings[$i]['types'][] = $upper_type;
				} elseif (in_array($upper_type, array_values($upper_types))) {
					$meetings[$i]['types'][] = array_search($upper_type, $upper_types);
				} else {
					$unused_types[] = $type;
				}
			}

			//if a meeting is both open and closed, make it closed
			if (in_array('C', $meetings[$i]['types']) && in_array('O', $meetings[$i]['types'])) {
				$meetings[$i]['types'] = array_diff($meetings[$i]['types'], array('O'));
			}

			//append unused types to notes
			if (count($unused_types)) {
				if (!empty($meetings[$i]['notes'])) $meetings[$i]['notes'] .= str_repeat(PHP_EOL, 2);
				$meetings[$i]['notes'] .= implode(', ', $unused_types);
			}

			// If Conference URL, validate; or if phone, force 'ONL' type, else remove 'ONL'
			$meetings[$i]['types'] = array_values(array_diff($meetings[$i]['types'], array('ONL')));
			if (!empty($meetings[$i]['conference_url'])) {
				$url = esc_url_raw($meetings[$i]['conference_url'], array('http', 'https'));
				if (tsml_conference_provider($url)) {
					$meetings[$i]['conference_url'] = $url;
					$meetings[$i]['types'][] = 'ONL';
				} else {
					$meetings[$i]['conference_url'] = null;
					$meetings[$i]['conference_url_notes'] = null;
				}
			}
			if (!empty($meetings[$i]['conference_phone']) && empty($meetings[$i]['conference_url'])) {
				$meetings[$i]['types'][] = 'ONL';
			}
			if (empty($meetings[$i]['conference_phone'])) {
				$meetings[$i]['conference_phone_notes'] = null;
			}

			//Clean up attendance options
			if (!empty($meetings[$i]['attendance_option'])) {
				$meetings[$i]['attendance_option'] = trim(strtolower($meetings[$i]['attendance_option']));
				if (!array_key_exists($meetings[$i]['attendance_option'], $tsml_meeting_attendance_options)) {
					$meetings[$i]['attendance_option'] = '';
				}
			}

			//make sure we're not double-listing types
			$meetings[$i]['types'] = array_unique($meetings[$i]['types']);

			//clean up
			foreach(array('address', 'city', 'state', 'postal_code', 'country', 'updated') as $key) {
				if (isset($meetings[$i][$key])) unset($meetings[$i][$key]);
			}

			//preserve row number for errors later
			$meetings[$i]['row'] = $i + 2;
		}

		//allow user-defined function to filter the meetings (for gal-aa.org)
		if (function_exists('tsml_import_filter')) {
			$meetings = array_filter($meetings, 'tsml_import_filter');
		}

		//prepare import buffer in wp_options
		update_option('tsml_import_buffer', $meetings, false);
	}
}

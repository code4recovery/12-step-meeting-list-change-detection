# 12-step-meeting-list-change-detection
This '12 Step Meeting List' plugin add-on augments the existing data import utility by sensing data changes in enabled data source feeds and generating email notifications to Change Notification Email recipients registered on the Import &amp; Settings page. 

## Cron Job Registration
Re-registering an existing data source is necessary if Change Detection is desired. This includes:
	1. Removal of the data source (click on the X next to it's Last Refresh timestamp).
	2. Add a new parent Region on the Meetings/Regions page (i.e. Name: District 1, Slug: district-1).
	3. Set data source options: enter a name for your feed, set the feed URL, select the parent region from the Parent Region dropdown, and lastly choose the "Change Detection Enabled" option.
	4. Pressing the "Add Data Source" button will register a WordPress Cron Job (tsml_scan_data_source) for the newly added and enabled data source. By default, this cron job is scheduled to run "Once Daily" starting at midnight (12:00 AM). The frequency and scheduled time that the cron job runs is completely configurable by you if the "WP Crontrol" plugin has been installed.

## Dev Note
This "develop" version plugin currently only works when used with the GitHub code4recovery "develop" branch code of the 12 Step Meeting List plugin.

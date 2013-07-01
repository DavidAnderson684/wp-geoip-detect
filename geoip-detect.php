<?php
/*
Plugin Name: GeoIP Detection
Plugin URI: http://www.yellowtree.de
Description: Retrieving Geo-Information using the Maxmind GeoIP (Lite) Database.
Author: YellowTree (Benjamin Pick)
Version: 1.0
Author URI: http://www.yellowtree.de
Licence: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

require_once(dirname(__FILE__) . '/vendor/geoip/geoip/geoipcity.inc');

define('GEOIP_DETECT_DATA_FOLDER', ABSPATH . '/wp-content/uploads/');
define('GEOIP_DETECT_DATA_FILENAME', 'GeoLiteCity.dat');

//define('GEOIP_DETECT_AUTO_UPDATE_DEACTIVATED', true);

function geoip_detect_get_abs_db_filename()
{
	$data_file = GEOIP_DETECT_DATA_FOLDER . '/' . GEOIP_DETECT_DATA_FILENAME;
	if (!file_exists($data_file))
		$data_file = __DIR__. '/' . GEOIP_DETECT_DATA_FILENAME;
	
	return $data_file;
}

/**
 * Get Geo-Information for a specific IP
 * @param string $ip (IPv4)
 * @return geoiprecord	GeoInformation. (0 / NULL: no infos found.)
 */
function geoip_detect_get_info_from_ip($ip)
{
	$data_file = geoip_detect_get_abs_db_filename();
	
	$gi = geoip_open($data_file, GEOIP_STANDARD);
	
	$record = geoip_record_by_addr($gi, $ip);
	
	geoip_close($gi);

	$record = apply_filters('geoip_detect_record_information', $record);
	
	if ($record && $record->latitude < -90 || $record && $record->longitude < -90)
	{
		// File corrupted? Use empty defaults
		$record->latitude = 0;
		$record->longitude = 0;
		$record->city = 'Unknown';
	}
	
	return $record;
}

/**
 * Get Geo-Information for the current IP
 * @param string $ip (IPv4)
 * @return geoiprecord	GeoInformation. (0 / NULL: no infos found.)
 */
function geoip_detect_get_info_from_current_ip()
{
	// TODO: Use Proxy IP if available
	return geoip_detect_get_info_from_ip($_SERVER['REMOTE_ADDR']);
}

function geoip_detect_add_verbose_information_to_record($record)
{
	require_once(dirname(__FILE__) . '/vendor/geoip/geoip/geoipregionvars.php');
	
	if ($record)
	{
		global $GEOIP_REGION_NAME;
		$record->region_name = $GEOIP_REGION_NAME[$record->country_code][$record->region];
	}
	
	return $record;
}
add_filter('geoip_detect_record_information', 'geoip_detect_add_verbose_information_to_record');

function geoip_detect_add_timezone_information_to_record($record)
{
	require_once(dirname(__FILE__) . '/vendor/geoip/geoip/timezone/timezone.php');

	if ($record)
	{
		global $GEOIP_REGION_NAME;
		$record->timezone =  get_time_zone($record->country_code, $record->region);
	}

	return $record;
}
add_filter('geoip_detect_record_information', 'geoip_detect_add_timezone_information_to_record');



function geoip_detect_update()
{
	wp_upload_dir();
	
	$download_url = 'http://geolite.maxmind.com/download/geoip/database/GeoLiteCity.dat.gz';
	
	$outFile = GEOIP_DETECT_DATA_FOLDER . GEOIP_DETECT_DATA_FILENAME;
	
	// Download
	$tmpFile = download_url($download_url);
	if (is_wp_error($tmpFile))
		return $tmpFile->get_error_message();
	
	// Ungzip File
	$zh = gzopen($tmpFile, 'r');
	$h = fopen($outFile, 'w');
	
	if (!$zh)
		return __('Downloaded file could not be opened for reading.');
	if (!$h)
		return sprintf(__('Database could not be written (%s).'), $outFile);

	/*
	while (!gzeof($h)) {
		$string = gzgets($h, 4096);
		fwrite($h, $string, strlen($string));
	}
	*/
	while ( ($string = gzread($zh, 4096)) != false )
		fwrite($h, $string, strlen($string));
	
	gzclose($zh);
	fclose($h);
	
	//unlink($tmpFile);
	
	return true;
}

if (!defined('GEOIP_DETECT_AUTO_UPDATE_DEACTIVATED'))
	add_action('geoipdetectupdate', 'geoip_detect_update');


// ------------- Admin --------------------

function geoip_detect_plugin_page()
{
	$ip_lookup_result = false;
	$last_update = 0;
	$message = '';
	
	switch($_POST['action'])
	{
		case 'update':
			$ret = geoip_detect_update();
			if ($ret === true)
				$message .= __('Updated successfully.');
			else
				$message .= __('Update failed.') .' '. $ret;

			break;
			
		case 'lookup':
			if (isset($_POST['ip']))
			{
				$ip = $_POST['ip'];
				$ip_lookup_result = geoip_detect_get_info_from_ip($ip);
			}
			break;
	}
	
	if (file_exists(geoip_detect_get_abs_db_filename()))
	{
		$last_update = filemtime(geoip_detect_get_abs_db_filename());
	}
	else 
	{
		$message .= __('No GeoIP Database found. Click on the button "Update now" or follow the installation instructions.');
		$last_update = 0;
	}
	
	include_once(dirname(__FILE__) . '/views/plugin_page.php');	
}

function geoip_detect_menu() {
	require_once ABSPATH . '/wp-admin/admin.php';
	add_submenu_page('tools.php', 'GeoIP Detect', 'GeoIP Detect', 'activate_plugins', __FILE__, 'geoip_detect_plugin_page');
}
add_action('admin_menu', 'geoip_detect_menu');

function geoip_detect_add_settings_link( $links ) {
	$settings_link = '<a href="tools.php?page=geoip-detect/geoip-detect.php">Plugin page</a>';
	array_push( $links, $settings_link );
	return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'geoip_detect_add_settings_link' );

function geoip_detect_activate()
{
	if ( !wp_next_scheduled( 'geoipdetectupdate' ) )
		wp_schedule_event(time() + 7*24*60*60, 'weekly', 'geoipdetectupdate');
}
register_activation_hook(__FILE__, 'geoip_detect_activate');


function geoip_detect_deactivate()
{
	wp_clear_scheduled_hook('geoipdetectupdate');
}
register_deactivation_hook(__FILE__, 'geoip_detect_deactivate');

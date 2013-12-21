<?php
/*
Plugin Name: CDN Sync Tool
Plugin URI: http://www.olliearmstrong.co.uk
Description: Allows wordpress owners to sync their static files to CDN
Author: Fubra Limited
Author URI: http://www.catn.com
Version: 2.2.1
*/

global $wpdb;

define('CST_DIR', dirname(__FILE__).'/');
define('CST_VERSION', '2.4.1');
define('CST_URL', admin_url('options-general.php'));
define('CST_FILE', __FILE__);
define('CST_TABLE_FILES', $wpdb->get_blog_prefix().'cst_new_files');
define('CST_CONTACT_EMAIL', 'support@catn.com');

if( !function_exists('wdgFileCheck')  ){
  function wdgFileCheck($strFlPth = false){
    if( !file_exists($strFlPth) ) {
      touch($strFlPth);
    } elseif(!is_writable($strFlPth) ) {
      unlink($strFlPth);
      touch($strFlPth);
    }

    return $strFlPth;
  }
}

if( !function_exists('wdgPathCheck') ){
  function wdgPathCheck($strPath){
    $strCachePath = realpath($strPath);

    if( !$strCachePath ){
      mkdir($strPath);

      $strCachePath = realpath($strPath);
    }

    return $strCachePath;
  }
}


if (is_admin()) {
	require_once CST_DIR.'lib/Cst.php';
	global $core;
	$core = new Cst();
} else {
	require_once CST_DIR.'lib/Site.php';
	new Cst_Site;
}

function cst_install() {
	global $wpdb;

	if (get_option('cst_cdn')) {
		$cdnOptions = get_option('cst_cdn');
		if ($cdnOptions['provider'] == 'aws') {
			update_option('cst-cdn', 'S3');
			if (isset($cdnOptions['access']))
				update_option('cst-s3-accesskey', $cdnOptions['access']);
			if (isset($cdnOptions['secret']))
				update_option('cst-s3-secretkey', $cdnOptions['secret']);
			if (isset($cdnOptions['bucket_name']))
				update_option('cst-s3-bucket', $cdnOptions['bucket_name']);
		} else if ($cdnOptions['provider'] == 'ftp') {
			update_option('cst-cdn', 'FTP');
			if (isset($cdnOptions['username']))
				update_option('cst-ftp-username', $cdnOptions['username']);
			if (isset($cdnOptions['password']))
				update_option('cst-ftp-password', $cdnOptions['password']);
			if (isset($cdnOptions['server']))
				update_option('cst-ftp-server', $cdnOptions['server']);
			if (isset($cdnOptions['port']))
				update_option('cst-ftp-port', $cdnOptions['port']);
			if (isset($cdnOptions['directory']))
				update_option('cst-ftp-dir', $cdnOptions['directory']);
		} else if ($cdnOptions['provider'] == 'cf') {
			update_option('cst-cdn', 'Cloudfiles');
			if (isset($cdnOptions['username']))
				update_option('cst-cf-username', $cdnOptions['username']);
			if (isset($cdnOptions['apikey']))
				update_option('cst-cf-api', $cdnOptions['apikey']);
			if (isset($cdnOptions['container']))
				update_option('cst-cf-container', $cdnOptions['container']);
		}
		delete_option('cst_cdn');
	}

	$wpdb->query("
		CREATE TABLE IF NOT EXISTS ".CST_TABLE_FILES." (
		  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
		  `file_dir` text NOT NULL,
		  `remote_path` text NOT NULL,
		  `changedate` int(11) DEFAULT NULL,
		  `synced` tinyint(1) NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=MyISAM DEFAULT CHARSET=utf8;
	");

	update_option('cst-js-savepath', 'wp-content/uploads');
	wp_schedule_event(time(), 'hourly', 'cron_cst_sync');
}

function cst_deactivate() {
	wp_clear_scheduled_hook('cron_cst_sync');
}

function hourlySync() {
	require_once CST_DIR.'lib/Cst.php';
	global $core;
	$core = new Cst();
	$core->syncFiles();
}

function superCacheError() {
	echo '<div class="error"><p>CDN Sync Tool requires <a href="http://wordpress.org/extend/plugins/wp-super-cache/" target="_blank">WP Super Cache</a>.</p></div>';
}

add_action('wp_ajax_cst_get_queue', 'cst_get_queue');

function cst_get_queue() {
	if ( !current_user_can( 'manage_options' ) ) {
		die();
	}
	check_ajax_referer( 'cst_check_string', 'cst_check' );
	ob_clean(); // clear buffer
	$queue = $GLOBALS['core']->getQueue();
	echo json_encode($queue);
	die();
}

// add_action('wp_ajax_cst_sync_file', 'cst_sync_file');

function cst_sync_file() {
	if ( !current_user_can( 'manage_options' ) ) {
		die();
	}
	check_ajax_referer( 'cst_check_string', 'cst_check' );
	ob_clean(); // clear buffer

	$file = '';
	if(isset( $_POST['file'] )) {
		$file = filter_var_array($_POST['file'], FILTER_SANITIZE_STRING);
	}

	$success = $GLOBALS['core']->syncIndividualFile($file);
	if ($success) {
		$GLOBALS['core']->updateDatabaseAfterSync($file);
	}
	echo '<br /><hr />';
	die();
}

add_action('wp_ajax_cst_sync_file', 'cst_sync_many_files');
function cst_sync_many_files() {
	if ( !current_user_can( 'manage_options' ) ) {
		die();
	}
	check_ajax_referer( 'cst_check_string', 'cst_check' );
	ob_clean(); // clear buffer

	// ABSPATH

	$file = '';

	if(isset( $_POST['file'] )) {
		$file = filter_var_array($_POST['file'], FILTER_SANITIZE_STRING);
	}

	$success = $GLOBALS['core']->syncManyFiles();

	// if ($success) {
	// 	$GLOBALS['core']->updateDatabaseAfterSync($file);
	// }

	echo 'Done...<br /><hr />';
	die();
}
add_action('wp_ajax_cst_sync_progress', 'cst_sync_progress');
function cst_sync_progress() {
	$strCachePath = wdgPathCheck(ABSPATH .'/wp-content/cache');
	$strCachePath = wdgPathCheck(ABSPATH .'/wp-content/cache/cdn');
	$strFilesToSyncPath = wdgFileCheck($strCachePath .'/filetosync.cdn_sync');
	$strProgressPath = wdgFileCheck($strCachePath .'/progress.cdn_sync');

	$strProgress = file_get_contents($strProgressPath);
	file_put_contents($strProgressPath, '');

  $arrResults = unserialize( file_get_contents($strFilesToSyncPath) );

	echo(json_encode(array(
		'remaining' => sizeof($arrResults),
		'progress_message' =>	$strProgress
	)));

	die();
}

register_activation_hook(__FILE__, "cst_install");
register_deactivation_hook(__FILE__, 'cst_deactivate');
add_action('cron_cst_sync', 'hourlySync');

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if (!is_plugin_active('wp-super-cache/wp-cache.php')) {
	add_action('admin_notices', 'superCacheError');
}

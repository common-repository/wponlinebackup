<?php
/*
Plugin Name: Online Backup for WordPress
Plugin URI: http://www.backup-technology.com/free-wordpress-backup/
Description: Online Backup for WordPress can automatically backup your WordPress database and filesystem on a configurable schedule and can incrementally send the backup compressed (and optionally encrypted using DES or AES) to our online vault where you can later retrieve it. Backups can also be emailed to you or produced on-demand and downloaded straight to your computer. You can view the current status and change settings at "Online Backup", or by clicking the "View Dashboard" link next to the plugin name in the Plugins list.
Author: Jason Woods @ Backup Technology
Version: 3.0.4
Author URI: http://www.backup-technology.com/
Licence: GPLv2 - See LICENCE.txt
*/

/*
This file must be called wponlinebackup.php, or the uninstall.php file must also be modified to reflect the plugin's new filename
If this file is renamed and uninstall.php is not modified, the uninstaller will not trigger when the plugin is removed
*/

// Die if we haven't been included by WordPress
if ( !defined( 'ABSPATH' ) ) die();

// Version
define( 'WPONLINEBACKUP_VERSION', '3.0.4' );
define( 'WPONLINEBACKUP_DBVERSION', 12 );

// Prepare the paths
// - Symlink issue in plugin_basename but appears to be a planned fix in WordPress internally using an add_filter on plugin_basename
//   so we can treat symlinking as something the person configuring the blog should be accounting for.
define( 'WPONLINEBACKUP_FILE', plugin_basename( __FILE__ ) );
define( 'WPONLINEBACKUP_FILEPATH', __FILE__ );
define( 'WPONLINEBACKUP_DIR', dirname( WPONLINEBACKUP_FILE ) );
define( 'WPONLINEBACKUP_LANG', WPONLINEBACKUP_DIR . '/lang' );
define( 'WPONLINEBACKUP_PATH', preg_replace( '#/$#', '', plugin_dir_path( __FILE__ ) ) ); // BTL code styling requires we do not have forward slash!
define( 'WPONLINEBACKUP_TMP', WPONLINEBACKUP_PATH . '/tmp' );
// WPONLINEBACKUP_URL requires the use of plugin_dir_url which should only be called during init()
// It is also only used in the administration pages, so it is now defined in WPOnlineBackup_Admin::Init() which is our admin_init action

// Ensure PHP newline is defined (it is since PHP 4.3.10 and PHP 5.0.2)
if ( !defined( 'PHP_EOL' ) )
	define( 'PHP_EOL', "\n" ); // Default to Linux style

// When we generate data, we use Windows line-endings since they are more universal, and most users will be Windows
// This prevents apparent mangled Readme.txt and OBFW_Database.sql files in downloaded ZIP files
define( 'WPONLINEBACKUP_EOL', "\r\n" );

// Our custom error handler - Only used when we are missing error_get_last() and only activated when WPOnlineBackup::Enable_Error_Handling is called
$GLOBALS['OBFW_Error_Handler'] = false;

class OBFW_Error_Handler
{
	/*private*/ var $Last = null;
	/*private*/ var $Previous_Handler;

	/*public*/ function Init()
	{
		// This call must NOT be in a constructor!
		// On PHP 4 the new keyword is a pain, if we call $a = new A() it creates the object, runs the constructor, then returns a COPY of the object so the constructor's $this object is not the same as $a
		// To get this to work in a constructor on PHP 4 we would need to return the result of new by reference by calling $a = & new A()
		// However, returning new by reference is deprecated on PHP 5 and will cause a stream of errors in WP-Admin and the website itself (since it displays the error at compile time!)
		// So to get this to work cleanly in both PHP 4 and PHP 5, we use a method, call $a = new A() and then $a->Init() - this ensures that Init()'s $this object is the same as $a
		// If $this and $a are different objects, when the handler sets $this->Last, $a->Last is completely different... We get $a->Last when we call $a->Get_Last()
		$this->Previous_Handler = set_error_handler( array( & $this, 'Handler' ) );
	}

	/*public*/ function Handler( $type, $message, $file, $line, $context )
	{
		// Filter known WordPress related errors due to us running INSERT and such within query()
		$skip = false;
		if ( $type == E_WARNING ) {
			if ( substr( $file, -10 ) == '/wp-db.php' && substr( $message, 0, 76 ) == 'mysql_fetch_object(): supplied argument is not a valid MySQL result resource' )
				$skip = true;
		}

		if ( !$skip ) {

			$this->Last = array(
				'type'		=> $type,
				'message'	=> $message,
				'file'		=> $file,
				'line'		=> $line,
			);

		}

		// Call the original error handler
		if ( !is_null( $this->Previous_Handler ) )
			return call_user_func( $this->Previous_Handler, $type, $message, $file, $line, $context );

		return false;
	}

	/*public*/ function Get_Last()
	{
		return $this->Last;
	}
}

/*
WPOnlineBackup - Main wrapper, contains the minimum functions required to get things going
Other required code is in the include directory and only brought in if required
*/

class WPOnlineBackup
{
	// Settings and capabilities arrays
	/*private*/ var $settings_stored = null; // These are the STORED settings
	/*private*/ var $settings_default = null; // These are the DEFAULT settings, for where there isn't one overriding it in the STORED settings
	/*private*/ var $env = null;
	/*private*/ var $cache_memory_limit = null;
	/*private*/ var $db_prefix;
	/*private*/ var $multisite = false;
	/*private*/ var $blog_url = null;

	// Objects
	/*public*/ var $bootstrap = null;
	/*public*/ var $scheduler = null;
	/*public*/ var $admin = null;

	// Bootstrap sets this to true if we're in recovery mode (where update_ticks=1 etc)
	// We use it to adjust our automatic settings such as reducing max_block_size to reduce memory usage
	// It's also used by bootstrap itself if it wants to know if we're in recovery mode or not
	/*public*/ var $recovery_mode = false;

	/*private*/ var $_done_activate = false;
	/*private*/ var $_done_check_tables = false;

	/*private*/ var $_last_seed = '';

	/*private*/ var $_lang_loaded = false;

	/*public*/ function WPOnlineBackup()
	{
		$has_v3_multisite = function_exists( 'is_multisite' );

		// Are we WPMU? Not supported!
		if ( !$has_v3_multisite && function_exists( 'switch_to_blog' ) ) {

			// If in admin, print a notice saying we won't work
			if ( is_admin() ) {

				add_filter( 'admin_notices', array( & $this, 'Admin_Notices_Not_Supported_WPMU' ) );

			}

			// Do nothing else
			return;

		}

		// Grab database version
		$dbv = get_option( 'wponlinebackup_db_version', '' );

		// Are we multisite in WP v3? If not, we skip all this and simply run / activate
		if ( $has_v3_multisite && is_multisite() ) {

			$this->multisite = true;

			// We only run on the main site (network admin runs as the main site) and only if we're network activated
			if ( !is_main_site() || !get_option( 'wponlinebackup_network_activated', 0 ) ) {

				// Register an activation hook, this will catch activation on main site, and in network admin
				// Try_Activate will do nothing for main site so we end up adding the below plugin action link
				// For network admin activation it will call Activate and set wponlinebackup_network_activated so that we run as fully activated
				register_activation_hook( WPONLINEBACKUP_FILE, array( & $this, 'Try_Activate' ) );

				// If in admin, show in the plugin action links that only the network admin can manage it
				if ( is_admin() ) {

					add_filter( 'plugin_action_links_' . WPONLINEBACKUP_FILE, array( & $this, 'Plugin_Actions_Not_Network_Admin' ) );

				}

				// Do nothing else
				return;

			}

		}

		// Upgrade check... Since 3.1 the activation hook doesn't trigger during updates (WordPress #14915)
		// This will also activate us (which also works as a "Must Use" plugin)
		// We ditched the activation hook since it is made redundant by this
		if ( $dbv === '1.0' || get_option( 'wponlinebackup_db_version', 0 ) < WPONLINEBACKUP_DBVERSION )
			$this->Activate();

		// Check the DB tables exist - creating them if needed - we exclude this option during backup meaning we get the default here - 1
		// Only do this if we are already activated though (db version will exist)
		if ( $dbv !== '' && get_option( 'wponlinebackup_check_tables', 1 ) )
			$this->Check_Tables();

		// Register deactivation hook, but only in network admin if we're multisite so we don't attempt a deactivate if main site deactivates
		if ( !$this->multisite || is_network_admin() )
			register_deactivation_hook( WPONLINEBACKUP_FILE, array( & $this, 'Deactivate' ) );

		// Register initialisation hook
		add_action( 'wp_loaded', array( & $this, 'Init' ) );

		// Register job hooks
		add_action( 'wponlinebackup_start', array( & $this, 'Action_Start' ) );
		add_action( 'wponlinebackup_perform', array( & $this, 'Action_Perform' ) );
		add_action( 'wponlinebackup_perform_check', array( & $this, 'Action_Perform_Check' ) );
		add_action( 'wponlinebackup_perform_config_verify', array( & $this, 'Action_Perform_Config_Verify' ) );

		// Are we admin page? Remeber we need to load admin on is_admin for main site as well since we need to register the AJAX as there is no network admin AJAX
		// The is_main_site is already checked above - the network admin pages are always on the main site, they redirect if not
		// So is_admin will return true for main site admin, or network admin
		if ( is_admin() ) {

			// Increase memory limit in case WordPress Admin didn't - this ensures advanced configuration shows correct memory values
			$this->Increase_Memory_Limit();

			// Run config check at most, once a day, but only run it after admin activity!
			$config_verify = get_option( 'wponlinebackup_config_verify' );
			if ( !isset( $config_verify['last_update'] ) || $config_verify['last_update'] <= time() - 86400 ) {
				wp_clear_scheduled_hook( 'wponlinebackup_perform_config_verify' );
				wp_schedule_single_event( time() - 5, 'wponlinebackup_perform_config_verify' );
			}

			// Bring in the administration
			require_once WPONLINEBACKUP_PATH . '/include/admin.php';

			$this->admin = new WPOnlineBackup_Admin( $this );

		}
	}

	/*public*/ function Admin_Notices_Not_Supported_WPMU( $actions )
	{
?>
<div class="error">
	<p><b>Online Backup for WordPress is not compatible with WordPressMU installations!</b><br />
	We apologise for any inconvenience. The plugin is only compatible with the multisite features included with WordPress v3.0.0 and above and not with WordPressMU.<br />
	This message will disappear when you deactivate the Online Backup for WordPress plugin.</p>
</div>
<?php
	}

	/*public*/ function Plugin_Actions_Not_Network_Admin( $actions )
	{
		// Add "Managed by Network Administrator" to the plugin actions
		array_unshift( $actions, '<i>' . _x( 'Managed by Network Administrator', 'Plugin actions', 'wponlinebackup' ) . '</i>' );

		return $actions;
	}

	/*public*/ function Init()
	{
		if ( isset( $_GET['wponlinebackup_fetch'] ) || isset( $_POST['wponlinebackup_fetch'] ) ) {

			// Retrieving a backup
			$this->Load_BootStrap();
			if ( $this->bootstrap->Process_Pull() ) exit;

		} else if ( isset( $_GET['wponlinebackup_do'] ) ) {

			// Kick starting a new backup session
			$this->Load_BootStrap();
			$this->bootstrap->Perform();
			exit;

		} else if ( isset( $_GET['wponlinebackup_do_check'] ) ) {

			// Kick starting a perform check session
			$this->Load_BootStrap();
			$this->bootstrap->Perform_Check();
			exit;

		}
	}

	/*public*/ function Enable_Error_Handling()
	{
		// If we've already enabled our error handling functions, return
		if ( function_exists( 'OBFW_Exception' ) )
			return;

		// PHP4 does not have error_get_last, so let's create one
		// We could use php_errormsg but it requires track_errors On and we need to pass it as a parameter to OBFW_Exception, and it won't exist if there is a user error handler that returned true
		// We can use this handler for PHP5 too as it will filter known warnings that we are not interested in

		// We use GLOBALS since it's easier than global keyword
		$GLOBALS['OBFW_Error_Handler'] = new OBFW_Error_Handler();
		$GLOBALS['OBFW_Error_Handler']->Init();

		function OBFW_Exception()
		{
			$err = $GLOBALS['OBFW_Error_Handler']->Get_Last();
			if ( is_null( $err ) )
				return __( 'No message was logged.', 'wponlinebackup' );

			// If the last error was an E_STRICT notice it will most likely be due to our PHP 4 compatibility so pretend no message was logged
			if ( defined( 'E_STRICT' ) && $err['type'] == E_STRICT )
				return __( 'No error message was logged.', 'wponlinebackup' );

			return sprintf( __( "An error happened at: %s(%s)\n%s", 'wponlinebackup' ), basename( $err['file'] ), $err['line'], $err['message'] );
		}

		function OBFW_Raw_Exception()
		{
			$err = $GLOBALS['OBFW_Error_Handler']->Get_Last();
			if ( is_null( $err ) )
				return __( 'No message was logged.', 'wponlinebackup' );

			// Return the error message only
			return $err['message'];
		}

		function OBFW_Tidy_Exception()
		{
			$err = $GLOBALS['OBFW_Error_Handler']->Get_Last();
			if ( is_null( $err ) )
				return __( 'No message was logged.', 'wponlinebackup' );

			// If the last error was an E_STRICT notice it will most likely be due to our PHP 4 compatibility so pretend no message was logged
			if ( defined( 'E_STRICT' ) && $err['type'] == E_STRICT )
				return __( 'No error message was logged.', 'wponlinebackup' );

			// Try to match the error - hopefully this will match all languages as it bases solely on the layout of the error message
			// If we can't match, just return the original message untouched
			if ( !preg_match( '/^[^:(]+(?:\\(.*?\\))?(?: \\[.*?\\])?: (?:[^:]+: )([^:]+)$/', $err['message'], $matches ) )
				return $err['message'];

			// Message matches nicely so let's return just the bit we need
			return $matches[1];
		}

		function OBFW_FOpen_Exception( $path, $original )
		{
			// We call this when we failed to call filesize or stat, because they simply return "stat failed" which is pretty useless
			// Calling fopen and getting the error from that will give us a better error, such as "permission denied"
			if ( false === ( $f = @fopen( $path, 'rb' ) ) )
				return OBFW_Tidy_Exception();

			// We opened successfully, which is odd, so just close and return the original exception
			@fclose( $f );

			return $original;
		}

		function OBFW_Exception_WP( $wp_error )
		{
			$codes = $wp_error->get_error_codes();
			$messages = $wp_error->get_error_messages();

			$errors = array();
			foreach ( $codes as $key => $code )
				$errors[] = sprintf( __( '[Error Code %s] %s', 'wponlinebackup' ), $code, $messages[ $key ] );

			return implode( PHP_EOL, $errors );
		}
	}

	/*public*/ function Try_Activate()
	{
		// If not network admin, it means we're main site activating, so just do nothing
		// We'll end up just showing the plugin action links as with non-main sites
		if ( !is_network_admin() )
			return;

		// Activate now, otherwise it's delayed a page load since we've already passed on the activate / upgrade logic in the constructor
		$this->Activate();

		// We're network admin, mark as network activated
		update_option( 'wponlinebackup_network_activated', 1 );
	}

	/*public*/ function Activate()
	{
		global $wpdb;

		// Only run once
		if ( $this->_done_activate )
			return;
		$this->_done_activate = true;

		// Grab the database prefix to use
		$db_prefix = $wpdb->prefix;

		// Get current database version
		$dbv = get_option( 'wponlinebackup_db_version', '' );

		// If database version is not 1.0 and not an integer, this is either new installation or 1.0.3 or below
		if ( $dbv !== '1.0' && !is_numeric( $dbv ) ) {

			// Upgrade from 1.0.3 or below, remove old stuff we no longer need.
			if ( get_option('wponlinebackup_progress') !== false ) {

				// No longer used - we use database instead for atomic operations
				delete_option( 'wponlinebackup_progress' );
				delete_option( 'wponlinebackup_status' );

				// Convert schedule from legacy to V1 format
				$this->Load_Scheduler();
				$this->scheduler->Update();

				$dbv = 1;

			} else {

				// New installation - no upgrades required, set to latest DB version
				$dbv = WPONLINEBACKUP_DBVERSION;

			}

		}

		// Translate database version 1.0 into 1
		if ( $dbv === '1.0' ) $dbv = 1;

		if ( $dbv < 2 ) {

			// Upgrade from database version 1 to version 2

			// Drop old table if it exists
			if ( $wpdb->get_var( 'SHOW TABLES LIKE \'' . $db_prefix . 'online_backup\'' ) === $db_prefix . 'online_backup' )
				$wpdb->query( 'DROP TABLE `' . $db_prefix . 'online_backup`' );

			// Load stored settings
			$this->settings_stored = get_option( 'wponlinebackup_settings' );

			// If the gzip temporary directory setting is one of the environment variables, or it is "/tmp", unset it so we use the environment variable
			if ( $this->settings_stored['gzip_tmp_dir'] == WPOnlineBackup::Get_Temp() || $this->settings_stored['gzip_tmp_dir'] == '/tmp' )
				unset( $this->settings_stored['gzip_tmp_dir'] );

			// Save the new settings
			$this->Save_Settings();
			$this->settings_stored = null;

			// Convert schedule from V1 format to V2
			$this->Load_Scheduler();
			$this->scheduler->Update_V1();

		}

		if ( $dbv < 4 ) {

			// Upgrade from database version 2 and 3 to version 4
			// This used to be the upgrade to database version 3 - but we did not add this option on fresh installations
			// So we increase db version again and add the setting again

			// Destroy any legacy schedules that were left behind
			wp_clear_scheduled_hook( 'WPOnlineBackup_Perform' );
			wp_clear_scheduled_hook( 'WPOnlineBackup_Perform_Check' );

		}

		if ( $dbv < 9 ) {

			// Upgrade to database version 9

			// We had big issues with the upgrade of the scan_log table in 2.2.7/2.2.8 due to dbDelta fun
			// Just drop it and let it get recreated in Check_Tables()...
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_scan_log`' );

		}

		if ( $dbv < 11 ) {

			// Upgrade to database version 11

			// Knock out of sync and wipe items table since we'll be doing a hefty modification
			delete_option( 'wponlinebackup_in_sync' );
			$wpdb->query( 'TRUNCATE TABLE `' . $db_prefix . 'wponlinebackup_items`' );

		}

		if ( $dbv < 12 ) {

			// Upgrade to database version 12

			// We no longer use the last_full option
			delete_option( 'wponlinebackup_last_full' );

			// Load stored settings
			$settings = get_option( 'wponlinebackup_settings' );

			// Calculate local_tmp_dir
			$local_tmp_dir = isset( $settings[ 'local_tmp_dir' ] ) ? $settings[ 'local_tmp_dir' ] : WPONLINEBACKUP_TMP;

			// Repair the path
			$this->_Repair_Path( $local_tmp_dir );

			// Wipe and remove the full directory we don't use anymore
			if ( false !== ( $d = @opendir( $p = $local_tmp_dir . '/full' ) ) ) {

				while ( ( $f = readdir( $d ) ) !== false ) {

					// Ignore directories
					if ( is_dir( $f ) )
						continue;

					// Does it match our file patterns? Only remove stuff we know we created
					if ( !preg_match( '#^(?:backup\\.zip(?:\\.enc)?(?:\\.[0-9]+|\\.rc)?|cdrbuffer(?:\\.[0-9]+)?|gzipbuffer(?:\\.[0-9]+)?|encbuffer(?:\\.[0-9]+)?)\\.php$#', $f ) )
						continue;

					// Remove everything else
					@unlink( $p . '/' . $f );

				}

				// Attempt to remove the full folder - if there are files inside it still they are not ours
				@rmdir( $p );

			}

		}

		// If newer DB version - we rollback by deleting the settings - we just cannot know what we'll add or change in the future
		if ( $dbv > WPONLINEBACKUP_DBVERSION ) {

			// Cleanup the options
			delete_option( 'wponlinebackup_db_version' );
			delete_option( 'wponlinebackup_settings' );
			delete_option( 'wponlinebackup_schedule' );
			delete_option( 'wponlinebackup_temps' );
			delete_option( 'wponlinebackup_bsn' );
			delete_option( 'wponlinebackup_in_sync' );
			delete_option( 'wponlinebackup_quota' );
			delete_option( 'wponlinebackup_config_verify' );
			delete_option( 'wponlinebackup_last_gzip_tmp_dir' );
			delete_option( 'wponlinebackup_network_activated' );

			// Cleanup the database tables
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_status`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_generations`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_activity_log`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_event_log`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_scan_log`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_local`' );
			$wpdb->query( 'DROP TABLE `' . $db_prefix . 'wponlinebackup_items`' );

			// Make sure we run check tables again
			$this->_done_check_tables = false;

		}

		// Add / update database version setting - auto-load since we'll use it on every request pretty much
		add_option( 'wponlinebackup_db_version', WPONLINEBACKUP_DBVERSION, '', 'yes' );
		update_option( 'wponlinebackup_db_version', WPONLINEBACKUP_DBVERSION );

		// Check the DB tables exist - creating them if needed
		$this->Check_Tables();

		// Main settings are now done in Check_Tables so we can delta any differences during upgrade
		// We add the rest of the other settings areas here - except check_tables and dbversion which are already done at this point
		add_option( 'wponlinebackup_temps', array(), '', 'no' );

		add_option( 'wponlinebackup_bsn', 0, '', 'no' );

		add_option( 'wponlinebackup_in_sync', 0, '', 'no' );

		add_option( 'wponlinebackup_quota', array(), '', 'no' );

		// Auto-load since we'll use on a lot of admin requests
		add_option( 'wponlinebackup_config_verify', array(), '', 'yes' );

		add_option( 'wponlinebackup_last_gzip_tmp_dir', false, '', 'no' );

		// Force us to find temporary directory again
		update_option( 'wponlinebackup_last_gzip_tmp_dir', '' );

		$this->Load_Scheduler();
		$this->scheduler->Restart();
	}

	/*public*/ function Check_Tables()
	{
		global $wpdb;

		// Only run once
		if ( $this->_done_check_tables )
			return;
		$this->_done_check_tables = true;

		// Grab the database prefix to use
		$db_prefix = $wpdb->prefix;

		// Ensure dbDelta is available
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Truncate the scan_log table to ensure the update goes quick
		$wpdb->query( 'TRUNCATE TABLE `' . $db_prefix . 'wponlinebackup_scan_log`' );

		// Validate all tables except items
		dbDelta( <<<SQL
CREATE TABLE `{$db_prefix}wponlinebackup_status` (
	`status` TINYINT(1) UNSIGNED NOT NULL,
	`time` INT(10) UNSIGNED NOT NULL,
	`counter` INT(10) UNSIGNED NOT NULL,
	`activity_id` INT(10) UNSIGNED NOT NULL,
	`code` INT(10) UNSIGNED NOT NULL,
	`compressed` INT(10) UNSIGNED NOT NULL,
	`stop_user` VARCHAR(255) NOT NULL,
	`memory_freed` INT(10) UNSIGNED NOT NULL,
	`memory_used` INT(10) UNSIGNED NOT NULL,
	`progress_max` INT(10) UNSIGNED NOT NULL,
	`progress` MEDIUMBLOB NOT NULL,
	PRIMARY KEY  (`status`, `time`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_generations` (
	`bin` INT(10) UNSIGNED NOT NULL,
	`item_id` INT(10) UNSIGNED NOT NULL,
	`backup_time` INT(10) UNSIGNED NOT NULL,
	`deleted_time` int(10) unsigned DEFAULT NULL,
	`file_size` int(10) unsigned DEFAULT NULL,
	`stored_size` int(10) unsigned DEFAULT NULL,
	`mod_time` int(10) unsigned DEFAULT NULL,
	`new_deleted_time` int(10) unsigned DEFAULT NULL,
	`commit` smallint(1) unsigned NOT NULL,
	PRIMARY KEY  (`bin`,`item_id`,`backup_time`),
	KEY `commit` (`commit`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_activity_log` (
	`activity_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`start` INT(10) UNSIGNED NOT NULL,
	`end` INT(10) UNSIGNED NULL,
	`comp` TINYINT(2) NOT NULL,
	`type` TINYINT(1) NOT NULL,
	`media` TINYINT(1) NOT NULL,
	`encrypted` TINYINT(1) NOT NULL,
	`compressed` TINYINT(1) NOT NULL,
	`errors` INT(10) UNSIGNED NOT NULL,
	`warnings` INT(10) UNSIGNED NOT NULL,
	`bsize` BIGINT(20) UNSIGNED NOT NULL,
	`bcount` INT(10) UNSIGNED NOT NULL,
	`rsize` BIGINT(20) UNSIGNED NOT NULL,
	`rcount` INT(10) UNSIGNED NOT NULL,
	PRIMARY KEY  (`activity_id`),
	KEY `start` (`start`),
	KEY `end` (`end`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_event_log` (
	`event_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`activity_id` INT(10) UNSIGNED NOT NULL,
	`time` INT(10) UNSIGNED NOT NULL,
	`type` TINYINT(1) UNSIGNED NOT NULL,
	`event` TEXT NOT NULL,
	PRIMARY KEY  (`event_id`),
	KEY `activity_id` (`activity_id`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_scan_log` (
	`scan_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`parent_id` INT(10) UNSIGNED NOT NULL,
	`type` SMALLINT(1) UNSIGNED NOT NULL,
	`name` VARBINARY(255) NOT NULL,
	PRIMARY KEY  (`scan_id`),
	UNIQUE `name` (`parent_id`,`type`,`name`)
);
CREATE TABLE `{$db_prefix}wponlinebackup_local` (
	`filename` VARCHAR(255) NOT NULL,
	`filesize` INT(10) UNSIGNED NOT NULL,
	`creation_date` INT(10) UNSIGNED NOT NULL,
	`locked` TINYINT(1) UNSIGNED NOT NULL,
	`encrypted` TINYINT(1) NOT NULL,
	`compressed` TINYINT(1) NOT NULL,
	PRIMARY KEY  (`filename`),
	KEY `creation_date` (`locked`,`creation_date`)
);
SQL
);

		// Ensure the collation and engine for the items table are absolutely correct
		// Items table must be MyISAM as InnoDB does not support multi-column index with an AUTO_INCREMENT
		// dbDelta will attempt to create the table without ENGINE specification which means if it defaults to InnoDB it will fail to create the table
		// So we must do it manually here
		$sql = <<<SQL
CREATE TABLE `{$db_prefix}wponlinebackup_items` (
	`bin` INT(10) UNSIGNED NOT NULL,
	`item_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
	`parent_id` INT(10) UNSIGNED NOT NULL,
	`type` SMALLINT(1) UNSIGNED NOT NULL,
	`name` VARBINARY(255) NOT NULL,
	`name_bin` VARBINARY(255) NOT NULL,
	`exists` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`file_size` INT(10) UNSIGNED DEFAULT NULL,
	`mod_time` INT(10) UNSIGNED DEFAULT NULL,
	`backup` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`new_exists` SMALLINT(1) UNSIGNED DEFAULT NULL,
	`new_file_size` INT(10) UNSIGNED DEFAULT NULL,
	`new_mod_time` INT(10) UNSIGNED DEFAULT NULL,
	`activity_id` INT(10) UNSIGNED NOT NULL,
	`counter` INT(10) UNSIGNED NOT NULL,
	`path` BLOB NOT NULL,
	PRIMARY KEY  (`bin`,`item_id`),
	UNIQUE `item` (`bin`,`parent_id`,`type`,`name`),
	KEY `browse` (`bin`,`parent_id`,`exists`,`type`,`name`),
	KEY `activity_id` (`activity_id`,`backup`,`bin`,`item_id`),
	KEY `exists` (`bin`,`exists`,`activity_id`)
)
SQL;

		// If we already exist, just do a dbDelta, otherwise, manually create it with ENGINE=MyISAM appended since it's the only way we can create the table
		if ( $wpdb->get_var( 'SHOW TABLES LIKE \'' . $db_prefix . 'wponlinebackup_items\'' ) === $db_prefix . 'wponlinebackup_items' ) {

			// Ensure we're MyISAM before we dbDelta in case we've got an InnoDB table already there with a completely incorrect schema
			$wpdb->query( 'ALTER TABLE `' . $db_prefix . 'wponlinebackup_items` ENGINE=MyISAM' );

			// Run dbDelta
			dbDelta( $sql . ';' );

		} else {

			// Create with MyISAM engine
			$wpdb->query( $sql . ' ENGINE=MyISAM' );

		}

		// Clear the status table
		$wpdb->query( 'DELETE FROM `' . $db_prefix . 'wponlinebackup_status`;' );

		// Pre-populate the status table - bootstrap is not included so don't use the constants and use their values instead
		$wpdb->insert(
			$db_prefix . 'wponlinebackup_status',
			array(
				'status'	=> 0, // 0 = WPONLINEBACKUP_STATUS_NONE
				'time'		=> 0,
				'counter'	=> 0,
				'activity_id'	=> 0,
				'code'		=> 0, // 0 = WPONLINEBACKUP_CODE_NONE
				'compressed'	=> 0,
				'stop_user'	=> '',
				'memory_freed'	=> 0,
				'memory_used'	=> 0,
				'progress_max'	=> 0,
				'progress'	=> 'a:0:{}',
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%s' )
		);

		// Update settings by setting up defaults and overwriting them by the stored ones if any
		// This will add new settings and their defaults, and remove settings we've since removed
		$settings = array(

			'username'		=> '',
			'password'		=> '',

			'encryption_type'	=> '',
			'encryption_key'	=> '',

			'selection_method'	=> 'exclude',
			'selection_list'	=> array(),
			'ignore_trash_comments'	=> false,
			'ignore_spam_comments'	=> false,

			'filesystem_upone'	=> false,
			'filesystem_themes'	=> true,
			'filesystem_plugins'	=> true,
			'filesystem_uploads'	=> true,
			'filesystem_excludes'	=> '',

			'max_log_age'		=> 6,

			'local_min_gens'	=> 2,
			'local_max_storage'	=> 200,

		);

		$existing = get_option( 'wponlinebackup_settings', array() );

		// Delta add missing settings
		foreach ( $existing as $key => $value )
			if ( array_key_exists( $key, $settings ) )
				$settings[ $key ] = $value;

		// Add it first so we can turn autoload off for it - this won't update if it already exists, however - so update it immediately after
		add_option( 'wponlinebackup_settings', $settings, '', 'no' );
		update_option( 'wponlinebackup_settings', $settings );

		$schedule = array(
			'schedule'		=> '',
			'day'			=> 0,
			'hour'			=> 0,
			'minute'		=> 0,
			'next_trigger'		=> null,
			'target'		=> 'online',
			'email_to'		=> '',
			'backup_database'	=> true,
			'backup_filesystem'	=> true,
		);

		$existing = get_option( 'wponlinebackup_schedule', array() );

		// Delta add missing settings
		foreach ( $existing as $key => $value )
			if ( array_key_exists( $key, $schedule ) )
				$schedule[ $key ] = $value;

		// For schedule, we leave alone apart from backup_filesystem, which used to default to off when we set up the plugin
		// If the schedule has not been changed from the original, we change the backup_filesystem to true, the new default
		if ( $schedule['schedule'] == '' && $schedule['backup_database'] )
			$schedule['backup_filesystem'] = true;

		// Add it first so we can turn autoload off for it - this won't update if it already exists, however - so update it immediately after, but update with the existing info!
		add_option( 'wponlinebackup_schedule', $schedule, '', 'no' );
		update_option( 'wponlinebackup_schedule', $schedule );

		// Add / update the check tables option - auto-load since we use it every load
		add_option( 'wponlinebackup_check_tables', 0, '', 'yes' );
		update_option( 'wponlinebackup_check_tables', 0 );

		// Start an update of config verify information
		wp_clear_scheduled_hook( 'wponlinebackup_perform_config_verify' );
		wp_schedule_single_event( time() - 5, 'wponlinebackup_perform_config_verify' );
	}

	/*public*/ function Deactivate()
	{
		//TODO:Abort running backups and cleanup files

		// Clear the schedule hooks - we don't want them running when the plugin is deactivated!
		wp_clear_scheduled_hook( 'wponlinebackup_start' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform_config_verify' );
	}

	/*public*/ function Action_Start()
	{
		// Load the bootstrap, scheduler and settings
		$this->Load_Language();
		$this->Load_Scheduler();

		// Sometimes we get triggered multiple times, so check the following:
		// 1. Are we're actually scheduled. This should never fail though.
		// 2. Are we due to start in the next 5 minutes? This means if we already ran and rescheduled, we don't run again,
		//    but also means if the schedule starts a little early, we don't cancel the backup. It should always start late though
		if ( is_null( $this->scheduler->schedule['next_trigger'] ) || $this->scheduler->schedule['next_trigger'] > time() + 300 )
			return;

		$this->Load_BootStrap();

		$this->scheduler->Restart( true );

		// Prepare the scheduled backup configuration
		$config = array(
			'backup_database'	=> $this->scheduler->schedule['backup_database'],
			'backup_filesystem'	=> $this->scheduler->schedule['backup_filesystem'],
			'target'		=> $this->scheduler->schedule['target'],
			'email_to'		=> $this->scheduler->schedule['email_to'],
			'disable_encryption'	=> false,
		);

		// Start the backup with immediate effect
		$this->bootstrap->Start( $config, WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP, true );
		exit;
	}

	/*public*/ function Action_Perform()
	{
		// Perform backup - load the bootstrap and run it
		$this->Load_Language();
		$this->Load_BootStrap();
		$this->bootstrap->Perform();
		exit;
	}

	/*public*/ function Action_Perform_Check()
	{
		// Perform backup check - load the bootstrap and run it
		$this->Load_Language();
		$this->Load_BootStrap();
		$this->bootstrap->Perform_Check();
		exit;
	}

	/*public*/ function Action_Perform_Config_Verify()
	{
		// Perform config verify - load the bootstrap and run it
		$this->Load_Language();
		$this->Load_BootStrap();
		$this->bootstrap->Perform_Config_Verify();
	}

	/*public*/ function Set_Recovery_Mode( $mode )
	{
		$this->recovery_mode = $mode;
	}

	/*public*/ function Load_Language()
	{
		if ( $this->_lang_loaded )
			return;

		// Load translations
		load_plugin_textdomain( 'wponlinebackup', false, WPONLINEBACKUP_LANG );

		$this->_lang_loaded = true;
	}

	/*public*/ function Load_Settings()
	{
		// Check we haven't already loaded
		if ( !is_null( $this->settings_stored ) ) return;

		// Load bits and bobs
		$this->settings_stored = get_option( 'wponlinebackup_settings' );

		// Prepare the defaults - these settings will have "Override default" checkboxes on advanced settings page
		// We store these separately so we can store overrides in settings_stored
		$this->settings_default = array(
			// Maximum execution time of a backup
			'max_execution_time'	=> null, // on-the-fly in Get_Setting()
			// Safe mode
			'safe_mode'		=> null, // on-the-fly in Get_Setting()
			// Minimum execution time so we don't cause a massive stream of local loads - only affects Tick() so if the backup finishes it won't delay it
			'min_execution_time'	=> 15,
			// Time that passes between backup recovery checks - must be at least 120 - 2 times the maximum wordpress cron frequency (60)
			'timeout_recovery_time'	=> 130,
			// Time that must pass before we presume a backup to have died completely, and allow a new one to be started. Set large for sites with low visitor count
			'time_presumed_dead'	=> 7200, // 2 hours
			// Local temporary directory - used for all backup files, except those we cannot protect with a Rejection header
			'local_tmp_dir'		=> WPONLINEBACKUP_TMP,
			// Gzip temporary directory - only used for processing large files which forces us to use gzopen() without a Rejection header
			'gzip_tmp_dir'		=> null, // on-the-fly in Get_Setting()
			// The local backup directory
			'backup_directory'	=> WP_CONTENT_DIR . '/backups',
			// Tables that always backup and are not optional
			'core_tables'		=> array(
				'blog_versions', 'blogs', 'registration_log', 'signups', 'site', 'sitemeta', 'usermeta', 'users',
			),
			// Tables that always backup and are not optional, but are duplicated for each site in a network
			'site_tables'		=> array(
				'commentmeta', 'comments', 'links', 'options', 'postmeta', 'posts', 'term_relationships', 'term_taxonomy', 'terms',
			),
			// Block sizes for backup processing - we may make these dynamic in future based on available memory so we can fully optimize backups
			'dump_segment_size'	=> 200, // Rows for table backup - we count data size as we process to not go past max_block_size
			'sync_segment_size'	=> 500, // Rows for synchronisation
			'max_block_size'	=> null, // on-the-fly in Get_Setting()
			'max_email_size'	=> null, // on-the-fly in Get_Setting()
			// The following sizes will be used for buffers saved in the state to the database - so consider max_allowed_packet MySQL setting (sometimes as low as 1 MiB)...
			'file_buffer_size'	=> 1024 * 8, // 8 KiB
			'encryption_block_size' => 1024 * 8, // 8 KiB
			// Maximum number of retries to make on a backup that keeps timing out, and makes no progress each attempt
			// - if it makes no progress twice, but the next run makes progress, this counter is reset - see max_progress_retries to limit even if progress is made
			'max_frozen_retries'	=> 5,
			// Maximum number of retries to make on a backup that keeps timing out, but actually makes progress each time, 0 = no maximum, keep going (not recommended as ultimately the progress packet will get too big)
			'max_progress_retries'	=> 100,
			// Some servers are poor and have old certificate chains installed, and do not recognise the wordpress.backup-technology.com certificate
			// - if set to true, this will disable the certificate check, and lower the overall security of the plugin, although it might be considered that encryption alleviates this somewhat
			'ignore_ssl_cert'	=> false,
			// Number of ticks before actually saving the current state - speeds up backup phenominally on fast servers
			// If we timeout, we change to 1 so we keep updating state, until we get past the blockage, where we reset to default again
			'update_ticks'		=> 100,
			// Number of times to retry transmission operations to the online vault
			'remote_api_retries'	=> 5,
			// Should we fall back to the wpdb API? We now prefer to use the MySQL API directly so we can manage memory errors better (they are not fatal in the MySQL client)
			'use_wpdb_api'		=> null,
			// Open handle limit for filesystem backup - set to 50 since we shouldn't really need to go any deeper than that
			'handle_limit'		=> 50,
			// Generate backwards compatible ZIP files where all filenames are ASCII and UTF-8 names are stored separately in extra fields
			// After testing this we find that nothing seems compatible - Mac OS X, Windows, 7-Zip, unzip, all extract the ASCII name and not the UTF-8 even if the filesystem supports it
			// So we leave this disabled as it may be only Info-Zip (which defined the UTF-8 extra field) is compatible
			'zip_backwards_compat'	=> false,
		);

		// Check hashing capabilities
		if ( function_exists( 'hash' ) && function_exists( 'hash_hmac' ) && function_exists( 'hash_algos' ) && in_array( 'sha256', hash_algos() ) )
			$key_stretcher_available = 'php5hash';
		else if ( function_exists( 'mhash' ) && defined( 'MHASH_SHA256' ) )
			$key_stretcher_available = 'mhash';
		else
			$key_stretcher_available = false;

		// Examine environment capabilities
		$this->env = array(
			'inc_hash_available'		=> function_exists( 'hash_copy' ),
			'deflate_available'		=> function_exists( 'gzdeflate' ),
			'key_stretcher_available'	=> $key_stretcher_available,
		);

		// Check encryption capabilities
		$available = array();

		// If no key stretcher available, don't allow encryption - the PHP port of SHA256 we used to use just killed encryption so this will just disable it for those instances
		if ( $key_stretcher_available ) {

			if ( function_exists( 'mcrypt_module_open' ) ) {

				if ( defined( 'MCRYPT_DES' ) && $c = mcrypt_module_open( MCRYPT_DES, '', MCRYPT_MODE_CBC, '' ) ) {
					$available['DES'] = 'DES';
					mcrypt_module_close( $c );
				}

				if ( defined( 'MCRYPT_RIJNDAEL_128' ) && $c = mcrypt_module_open( MCRYPT_RIJNDAEL_128, '', MCRYPT_MODE_CBC, '' ) ) {
					$available['AES128'] = 'AES128';
					$available['AES192'] = 'AES192';
					$available['AES256'] = 'AES256';
					mcrypt_module_close( $c );
				}

			}

		}

		$this->env['encryption_available'] = count( $available ) == 0 ? false : true;
		$this->env['encryption_list'] = array(
			'DES'		=> 'DES',
			'AES128'	=> 'AES128',
			'AES192'	=> 'AES192',
			'AES256'	=> 'AES256',
		);
		$this->env['encryption_types'] = $available;
	}

	/*public*/ function Save_Settings()
	{
		// Save straight back to the database, we will fix up entries such as tmp_dir on load
		update_option( 'wponlinebackup_settings', $this->settings_stored );
	}

	/*public*/ function Delete_Setting( $setting )
	{
		// Only allow settings with defaults to be deleted from the stored settings
		if ( array_key_exists( $setting, $this->settings_stored ) && array_key_exists( $setting, $this->settings_default ) ) {
			unset( $this->settings_stored[ $setting ] );
		}
	}

	/*public*/ function Set_Setting( $setting, $value )
	{
		// Only allow existing settings to be set
		if ( ( $default = array_key_exists( $setting, $this->settings_default ) ) || array_key_exists( $setting, $this->settings_stored ) ) {
			if ( is_null( $value ) && $default ) {
				unset( $this->settings_stored[$setting] );
			} else {
				$this->settings_stored[$setting] = $value;
			}
		}
	}

	/*public*/ function Get_Setting( $setting, $raw = false )
	{
		// Check stored settings - only get from default if not wanting raw
		if ( array_key_exists( $setting, $this->settings_stored ) ) {
			$ret = $this->settings_stored[$setting];
		} else if ( !$raw && array_key_exists( $setting, $this->settings_default ) ) {

			// Cheating - on-the-fly collapsing of specific defaults - we'll still do the path repairs below
			if ( is_null( $this->settings_default[$setting] ) ) {

				switch ($setting) {

					case 'max_execution_time':
						// Based on the max_execution_time of scripts, but maximum of 15 so we don't block singlethreaded servers too long (bad server design though!)
						// Force a minimum of 5 too
						$ret = min( 15, max( 5, floor( ( 2 * ini_get( 'max_execution_time' ) ) / 3 ) ) );

						// If in recovery mode, reduce how long we run for - keep minimum of 5
						// This catches dodgy execution time limits that we sometimes cannot detect properly or that other plugins are playing around with!
						if ( $this->recovery_mode !== false )
							$ret = min( 5, intval( $ret / $this->recovery_mode ) ); // On second retry this reduces to 7 seconds, then on third, to 5 seconds
						break;

					case 'safe_mode':
						$ret = ini_get( 'safe_mode' );
						if ( $ret && preg_match( '/^off$/i', $ret ) )
							$ret = false;
						else
							$ret = !!$ret;
						break;

					case 'gzip_tmp_dir':
						// OK, check what we used last time
						if ( ( $ret = get_option( 'wponlinebackup_last_gzip_tmp_dir', false ) ) === false || ( $ret = WPOnlineBackup::Test_Temp( $ret ) ) === false ) {

							// This is the first time we're grabbing this setting or the path is not writable anymore, try to grab fresh and then store the result
							$ret = WPOnlineBackup::Get_Temp();

							update_option( 'wponlinebackup_last_gzip_tmp_dir', $ret );

						}
						break;

					case 'max_block_size':
						// fifth of memory or 8 MiB max
						$ret = min( floor( ( $memory_limit = $this->Memory_Limit() ) / 5 ), 8 * 1024 * 1024 );

						// Sometimes WordPress is using a lot of memory so lets try to account for that, since the above works for 95% of cases
						if ( function_exists( 'memory_get_usage' ) ) {

							$usage = memory_get_usage();

							// If our current free memory is less than what we're about to use, try to adjust, but don't drop too far
							if ( ( $free = $memory_limit - $usage ) < $ret * 1.2 )
								$ret = max( floor( $free / 1.2 ), 65535 );

						}

						// Another thing we do, is if we're in recovery mode reduce memory! The above should fix any problems but this is here for those select few where it doesn't
						// It doesn't make sense to reduce our memory usage and potentially our performance to make it work for 5% of users, and doing it this way will only reduce performance for that 5%
						if ( $this->recovery_mode !== false )
							$ret = min( 1024, intval( $ret / ( $this->recovery_mode * $this->recovery_mode ) ) ); // We use a square to quickly drop the block size right down, since we only retry 5 times (max_frozen_retries)
						break;

					case 'max_email_size':
						// What memory do we have available? Take 8 MB away for WordPress and other plugin - 5 MiB minimum so we don't break
						$ret = max( ( $memory_limit = $this->Memory_Limit() ) - ( 8 * 1024 * 1024 ), 5 * 1024 * 1024 );

						// Sometimes WordPress is using a lot of memory so lets try to account for that, since the above works for 95% of cases - this is for the case WordPress and other plugins use more than 5MB of memory, a standard installation uses less than 1MB
						if ( function_exists( 'memory_get_usage' ) ) {

							$usage = memory_get_usage();

							// If our current free memory is less than what we're about to use, try to adjust
							if ( ( $free = $memory_limit - $usage ) < $ret * 1.2 )
								$ret = max( floor( $free / 1.2 ), 65535 );

						}

						// Divide the limit by 5 - tests show that encoding the backup file into an email usually takes around 5 times the backup size due to PHPMailer's ignorance towards memory usage
						// Specifically:
						// - AttachAll encodes string attachments but keeps the original attachment data - could do with an option to delete it
						// - AttachAll uses an array of mime and then implodes() - we think this uses double memory as it combines the array into the string, before releasing the array
						// - PreSend stores the entire MIME message into SentMIMEMessage using sprintf - the memory allocation here is 3 times the size of the backup file, and this member is unused! - could do with an option to not do this
						// - Maybe we can put in some requests for an updated phpMailer and package it in with our plugin... or hack it and package it in
						$ret = floor( $ret / 5 );
						break;

					case 'use_wpdb_api':
						// Can we cheat the system and avoid nasty memory problems with the inefficient WPDB? Not to mention the hugely annoying lack of error handling!
						// If only we could use BTL's DB wrapper :(
						global $wpdb;
						if ( isset( $wpdb->dbh ) && function_exists( 'mysql_get_server_info' ) && @mysql_get_server_info( $wpdb->dbh ) !== false )
							$ret = false;
						else
							$ret = true;
						break;

					default:
						$ret = $this->settings_default[$setting];
						break;

				}

				// Store if we set a value
				if ( !is_null($ret) ) $this->settings_default[$setting] = $ret;

			} else {

				// Not cheating, just grab
				$ret = $this->settings_default[$setting];

			}

		} else {
			return null;
		}

		// Repair the path if we're not asking raw and it's a path setting
		if ( !$raw && ( $setting == 'gzip_tmp_dir' || $setting == 'local_tmp_dir' ) )
			$this->_Repair_Path( $ret );

		return $ret;
	}

	/*private*/ function _Repair_Path( & $path )
	{
		// Repair the path - ensure a trailing forward slash and change relative into absolute
		$path = preg_replace( '#(?:/|\\\\)$#', '', $path );

		if ( !preg_match( '#^(?:/|\\\\|[A-Za-z]:)#', $path ) )
			$path = ABSPATH . $path;
	}

	/*public*/ function Get_Env( $env )
	{
		return array_key_exists( $env, $this->env ) ? $this->env[ $env ] : null;
	}

	/*public*/ function Load_BootStrap()
	{
		// Check we haven't loaded already
		if ( !is_null( $this->bootstrap ) )
			return;

		// Load bootstrap
		require_once WPONLINEBACKUP_PATH . '/include/bootstrap.php';
		$this->bootstrap = new WPOnlineBackup_BootStrap( $this );
	}

	/*public*/ function Load_Scheduler()
	{
		// Check we haven't loaded already
		if ( !is_null( $this->scheduler ) )
			return;

		// Load scheduler
		require_once WPONLINEBACKUP_PATH . '/include/scheduler.php';
		$this->scheduler = new WPOnlineBackup_Scheduler();
	}

	/*public*/ function Increase_Memory_Limit()
	{
		// Attempt to increase the memory limit
		@ini_set( 'memory_limit', '256M' );

		// Clear the cache
		$this->cache_memory_limit = null;
	}

	/*public*/ function Memory_Limit()
	{
		if ( is_null( $this->cache_memory_limit ) ) {

			if ( ( $memory_limit = ini_get( 'memory_limit' ) ) == '' || $memory_limit == -1 )
				$memory_limit = '64M';

			if ( preg_match( '/^\\s*[0-9]+\\s*(K|M|G)?\\s*$/', $memory_limit, $matches ) )
				switch ( $matches[1] ) {
					case 'K': $m = 1024; break;
					case 'M': $m = 1024*1024; break;
					case 'G': $m = 1024*1024*1024; break;
					default: $m = 1; break;
				}
			else
				$m = 1;

			$this->cache_memory_limit = max( 8*1024*1024, intval( $memory_limit ) * $m ) - 5*1024*1024;

		}

		return $this->cache_memory_limit;
	}

	/*private static*/ function Test_Temp( $tmp )
	{
		// Check it's valid
		if ( !isset( $tmp ) || $tmp === false || $tmp == '' )
			return false;

		// Try to write to it
		if ( ( $tmpfile = @fopen( $tmp . '/obfw.writetest', 'w' ) ) === false )
			return false;

		// Cleanup
		@fclose( $tmpfile );
		@unlink( $tmp . '/obfw.writetest' );
		return $tmp;
	}

	/*private static*/ function Get_Temp_Raw()
	{
		// Try and find the environment variable for the temporary directory
		// If that fails, try and work out if we are on Windows or not, and just give a rough guess
		if ( $ret = WPOnlineBackup::Test_Temp( getenv( 'TMP' ) ) ) return $ret;
		if ( $ret = WPOnlineBackup::Test_Temp( getenv( 'TEMP' ) ) ) return $ret;
		if ( $ret = WPOnlineBackup::Test_Temp( getenv( 'TMPDIR' ) ) ) return $ret;
		if ( function_exists( 'sys_get_temp_dir' ) && ( $ret = WPOnlineBackup::Test_Temp( sys_get_temp_dir() ) ) ) return $ret;
		if ( preg_match( '/^(?:Windows|WINNT)$/i', php_uname( 's' ) ) ) {
			if ( $ret = WPOnlineBackup::Test_Temp( 'C:\\WINDOWS\\TEMP' ) ) return $ret;
		} else {
			if ( $ret = WPOnlineBackup::Test_Temp( '/tmp' ) ) return $ret;
		}

		// Host didn't give us anywhere... use our tmp folder
		return WPONLINEBACKUP_TMP;
	}

	/*private static*/ function Get_Temp()
	{
		return realpath( WPOnlineBackup::Get_Temp_Raw() );
	}

	/*private static*/ function Convert_Unixtime_To_Wordpress_Unixtime( $unixtime )
	{
		return $unixtime + ( get_option( 'gmt_offset' ) * 3600 );
	}

	/*public*/ function Get_Blog_URL()
	{
		// In cache?
		if ( is_null( $this->blog_url ) ) {

			// Remove the site_url filter that might get called - we don't want it messing with the raw URL
			// This fixes problems caused by plugins such as Any-Hostname
			remove_all_filters( 'site_url' );

			// Remove a get_site_url specific filter
			remove_all_filters( 'blog_option_siteurl' );

			// Remove some site_url specific filters
			remove_all_filters( 'pre_option_siteurl' );
			remove_all_filters( 'option_siteurl' );

			// Standard site_url call - this is fine in multisite since we now restrict ourselves to run only on the main site
			$this->blog_url = site_url( '/' );

		}

		return $this->blog_url;
	}

	/*public static*/ function Get_WPDB_Last_Error()
	{
		global $wpdb;
		// $wpdb->last_error is marked private - consolidate calls here so we can update easily
		return 'WPDB error: ' . $wpdb->last_error . '. Last query: ' . $wpdb->last_query;
	}

	/*public static*/ function Random_Seed( $arg1, $arg2 )
	{
		return ( $this->_last_seed = sha1( time() . $arg1 . mt_rand() . $this->_last_seed . $arg2 ) );
	}
}

$WPOnlineBackup = new WPOnlineBackup();

?>

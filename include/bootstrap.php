<?php

/*
WPOnlineBackup_BootStrap - Workhouse for the overall backup
Coordinates the different types of backups: Files, Database tables.
*/

// Define the backup status codes
define( 'WPONLINEBACKUP_STATUS_NONE',		0 ); // Referenced manually in wponlinebackup.php Activate() (bootstrap is not included)
define( 'WPONLINEBACKUP_STATUS_STARTING',	1 );
define( 'WPONLINEBACKUP_STATUS_RUNNING',	2 );
define( 'WPONLINEBACKUP_STATUS_TICKING',	3 );
define( 'WPONLINEBACKUP_STATUS_CHECKING',	4 );
define( 'WPONLINEBACKUP_STATUS_STOPPING',	5 ); // Referenced manually in js/progress.js doAJAXSuccess()

// Problem reason codes
define( 'WPONLINEBACKUP_CODE_NONE',		0 ); // Referenced manually in wponlinebackup.php Activate() (bootstrap is not included)
define( 'WPONLINEBACKUP_CODE_MEMORY',		1 );

// Define the activity types
define( 'WPONLINEBACKUP_ACTIVITY_UNKNOWN',	-1 );
define( 'WPONLINEBACKUP_ACTIVITY_BACKUP',	0 );
define( 'WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP',	1 );
//define( 'WPONLINEBACKUP_ACTIVITY_RESTORE',	2 );
define( 'WPONLINEBACKUP_ACTIVITY_DECRYPT',	3 );

// Define the activity media types
define( 'WPONLINEBACKUP_MEDIA_UNKNOWN',		0 ); // Mainly for backwards compatibility when we didn't store the target for backups
define( 'WPONLINEBACKUP_MEDIA_DOWNLOAD',	1 );
define( 'WPONLINEBACKUP_MEDIA_EMAIL',		2 );
define( 'WPONLINEBACKUP_MEDIA_ONLINE',		3 );

// Define the activity completion status codes
define( 'WPONLINEBACKUP_COMP_RUNNING',		0 ); // Running
define( 'WPONLINEBACKUP_COMP_SUCCESSFUL',	1 ); // Successful
define( 'WPONLINEBACKUP_COMP_PARTIAL',		2 ); // Completed, but with errors (so SOME data was backed up)
define( 'WPONLINEBACKUP_COMP_UNEXPECTED',	3 ); // Failed - timed out and never recovered - WP-Cron broken?
define( 'WPONLINEBACKUP_COMP_FAILED',		4 ); // Failed - mainly where backup file could not be opened, or online transmission fails for incrementals
define( 'WPONLINEBACKUP_COMP_TIMEOUT',		5 ); // Failed - timed out constantly
define( 'WPONLINEBACKUP_COMP_SLOWTIMEOUT',	6 ); // Failed - timed out intermittently and too many times
define( 'WPONLINEBACKUP_COMP_STOPPED',		7 ); // Failed - a user stopped the backup
define( 'WPONLINEBACKUP_COMP_MAINTENANCE',	8 ); // Failed - online backup vault maintenance
define( 'WPONLINEBACKUP_COMP_MEMORY',		9 ); // Failed - low memory constantly
define( 'WPONLINEBACKUP_COMP_SLOWMEMORY',	10 ); // Failed - low memory intermittently and too many times

// Define the event codes
define( 'WPONLINEBACKUP_EVENT_INFORMATION',	0 );
define( 'WPONLINEBACKUP_EVENT_WARNING',		1 );
define( 'WPONLINEBACKUP_EVENT_ERROR',		2 );

// Define the bin codes and names
define( 'WPONLINEBACKUP_BIN_DATABASE',		1 );
define( 'WPONLINEBACKUP_BIN_FILESYSTEM',	2 );

// Update status flags
define( 'WPONLINEBACKUP_UPSTATUS_IGNORESTOP',	1 );
define( 'WPONLINEBACKUP_UPSTATUS_PROGRESSRAW',	2 );
define( 'WPONLINEBACKUP_UPSTATUS_PROGRESSNONE',	4 );

// Maintenance types
define( 'WPONLINEBACKUP_MAINTENANCE_NONE',	0 );
define( 'WPONLINEBACKUP_MAINTENANCE_SCHEDULED',	1 );
define( 'WPONLINEBACKUP_MAINTENANCE_EMERGENCY',	2 );

// WP-Cron runs events in succession - so we could have Perform_Check() running, and then immediately after
// (in the same PHP process) Perform(). So we use this global to ensure we run once per process!
// We also use this to detect how long we've been running and adjust max_execution_time as necessary
$GLOBALS['WPOnlineBackup_Init'] = time();
$GLOBALS['WPOnlineBackup_Perform_Once'] = false;
$GLOBALS['WPOnlineBackup_Perform_Check_Once'] = false;

class WPOnlineBackup_BootStrap
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $status = null;
	/*private*/ var $last_tick_status;
	/*private*/ var $start_time;
	/*private*/ var $update_ticks;
	/*private*/ var $status_memory_freed = false;
	/*private*/ var $status_memory_used = false;
	/*private*/ var $mem_buffer;
	/*private*/ var $perform_ignore_timeout = false;
	/*private*/ var $compression_available = false;

	/*private*/ var $options = array();
	/*private*/ var $processors = array();
	/*private*/ var $stream = null;

	// Cache
	/*private*/ var $min_execution_time;
	/*private*/ var $max_execution_time;

	// Database
	/*private*/ var $db_prefix;
	/*private*/ var $db_force_master = '';

	/*public*/ var $maintenance = WPONLINEBACKUP_MAINTENANCE_NONE;
	/*public*/ var $activity_id = null;

	/*public*/ function WPOnlineBackup_BootStrap( & $WPOnlineBackup )
	{
		global $wpdb;

		$this->WPOnlineBackup = & $WPOnlineBackup;

		// Grab the database prefix to use
		$this->db_prefix = $wpdb->prefix;

		// Need formatting functions - used in many places in and out of the bootstrap
		require_once WPONLINEBACKUP_PATH . '/include/formatting.php';

		// Enable our error handling functions
		$WPOnlineBackup->Enable_Error_Handling();

		// Memory buffer - we free it if we shutdown due to error in case it was a memory error
		// This makes sure the shutdown function doesn't cause another memory error and fail to recover the situation
		$this->mem_buffer = str_repeat( '0123456789012345678901234567890123456789012345678901234567890123', 160 );
	}

	/*private*/ function Load_Status( $disallow_stale = true )
	{
		global $wpdb;

		// Load settings
		$this->WPOnlineBackup->Load_Settings();

		// Cache compression availability, we use it now in Update_Status if state information gets very big
		$this->compression_available = $this->WPOnlineBackup->Get_Env( 'deflate_available' );

		if ( $disallow_stale ) {

			// Ensure DOING_CRON is set - some plugins, such as DB-Cache plugin, stop caching when this is set
			// Technically this should always be set, but kick start can run stuff sometimes without it being set
			if ( !defined( 'DOING_CRON' ) )
				define( 'DOING_CRON', true );

			// Configure the PHP mysqldn extension mysqlnd-ms plugin if its in use, to allow us to force read-only queries on the master so we don't get stale data
			// This is especially important for grabbing the status and updating the status
			// This shouldn't really be in use on production systems... but just in case
			if ( defined( 'MYSQLND_MS_MASTER_SWITCH' ) )
				$this->db_force_master = '/*' . MYSQLND_MS_MASTER_SWITCH . '*/';

			// HyperDB plugin - force master on everything
			if ( is_callable( array( & $wpdb, 'send_reads_to_masters' ) ) )
				$wpdb->send_reads_to_masters();

			// MySQL-Proxy read/write splitting - START TRANSACTION to make sure we go to a master
			// This shouldn't be in use on production systems... but just in case
			$wpdb->query( 'START TRANSACTION' );

		}

		$this->status = array();

		// Grab the data from the database
		$result =
			$wpdb->get_row(
				$this->db_force_master . 'SELECT SQL_NO_CACHE status, time, counter, activity_id, code, compressed, stop_user, progress FROM `' . $this->db_prefix . 'wponlinebackup_status` LIMIT 1',
				ARRAY_N
			);

		if ( is_null( $result ) )
			$result = array( WPONLINEBACKUP_STATUS_NONE, 0, 0, 0, WPONLINEBACKUP_CODE_NONE, 0, '', '' );

		if ( $disallow_stale ) {

			// MySQL-Proxy read/write splitting - COMMIT the transaction
			$wpdb->query( 'COMMIT' );

		}

		list ( $this->status['status'], $this->status['time'], $this->status['counter'], $this->status['activity_id'], $this->status['code'], $compressed, $this->status['stop_user'], $this->status['progress'] ) = $result;

		// When we tick and we don't update, we'll save the status here, so we can recover it and trigger CHECKING during On_Shutdown pretty much immediately
		$this->last_tick_status = false;

		// Compressed?
		if ( $compressed ) {
			if ( $this->compression_available ) {
				$this->status['progress'] = @unserialize( @gzinflate( $this->status['progress'] ) );
			} else {
				// What the... gzdeflate vanishment - 0 is special and gives an error relating to compression capability vanishing
				$this->status['progress'] = 0;
			}
		} else {
			$this->status['progress'] = @unserialize( $this->status['progress'] );
		}

		// Copy activity_id
		$this->activity_id = $this->status['activity_id'];
	}

	/*public*/ function Fetch_Status()
	{
		// We don't mind stale from front-end, and only front-end calls this
		if ( is_null( $this->status ) )
			$this->Load_Status( false );

		return $this->status;
	}

	/*private*/ function Update_Status( $new_status = false, $new_counter = false, $flags = 0 )
	{
		global $wpdb;

		// If we didn't give a status, leave it the same
		if ( $new_status === false )
			$new_status = $this->status['status'];

		// Increase the progress counter so we don't fail to update
		if ( $new_counter === false )
			$new_counter = $this->status['counter'] + 1;

		// If we're setting status to NONE, wipe the cache and jobs information as we don't need it anymore
		if ( $new_status == WPONLINEBACKUP_STATUS_NONE ) {
			unset( $this->status['progress']['jobs'] );
			unset( $this->status['progress']['cache'] );
		}

		// Serialize and escape_by_ref (uses _real_escape - better)
		// On_Shutdown calls this with PROGRESSNONE flag so we can do a minimal memory impact update of the status to checking and chain a check immediately
		// It then calls this function with PROGRESSRAW flag and the progress of the last tick already serialized, so we can try not to lose the last tick (but this uses more memory and might fail if memory low)
		if ( $flags & WPONLINEBACKUP_UPSTATUS_PROGRESSNONE )
			$q_new_progress = false;
		else if ( $flags & WPONLINEBACKUP_UPSTATUS_PROGRESSRAW )
			$q_new_progress = $this->status['progress'];
		else
			$q_new_progress = serialize( $this->status['progress'] );

		if ( $q_new_progress !== false ) {

			// Compress it if more than 60KB and compression is available
			if ( $this->compression_available && strlen( $q_new_progress ) > 61440 ) {
				// This should never fail but check anyway since false is special for q_new_progress
				if ( false !== ( $_tmp = gzdeflate( $q_new_progress ) ) ) {
					$q_new_progress = $_tmp;
					unset( $_tmp );
					$compressed = 1;
				} else {
					$compressed = 0;
				}
			} else {
				$compressed = 0;
			}

			// Escape it and get the length
			$wpdb->escape_by_ref( $q_new_progress );
			$len = strlen( $q_new_progress );

			// If starting, set the progress_max to the length of our initial progress, otherwise, only set it if we're bigger
			// This results in progress_max being the maximum size the progress reached during backup, a figure we use for max_allowed_packet issues
			if ( $new_status == WPONLINEBACKUP_STATUS_STARTING )
				$l_new_progress = $len;
			else
				$l_new_progress = 'CASE WHEN progress_max < ' . $len . ' THEN ' . $len . ' ELSE progress_max END';

		}

		$where =
			'counter = ' . $this->status['counter'] . ' ' .
			'AND time = ' . $this->status['time'];

		// Update the database
		$now = time();
		$result = $wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_status` ' .
			'SET status = ' . $new_status .
				', time = ' . $now .
				', counter = ' . $new_counter .
				', activity_id = ' . $this->activity_id .
				', code = ' . $this->status['code'] .
				( $this->status_memory_freed !== false ? ', memory_freed = ' . $this->status_memory_freed : '' ) .
				( $this->status_memory_used !== false ? ', memory_used = ' . $this->status_memory_used : '' ) .
				( $q_new_progress !== false ? ', compressed = ' . $compressed . ', progress = \'' . $q_new_progress . '\', progress_max = ' . $l_new_progress : '' ) .
				' ' .
			'WHERE status = ' . $this->status['status'] . ' ' .
				'AND ' . $where
		);

		if ( $result ) {

			$this->have_lock = true;

			// We updated the row, store the time
			$this->status['status'] = $new_status;
			$this->status['time'] = $now;
			$this->status['counter'] = $new_counter;

			// Continue
			return true;

		} else {

			// MySQL-Proxy - START TRANSACTION to make sure we go to a master
			$wpdb->query( 'START TRANSACTION' );

			// We may have lost the lock - see if we lost it because Stop() was called
			$result =
				$wpdb->get_row(
					$this->db_force_master . 'SELECT SQL_NO_CACHE status, stop_user FROM `' . $this->db_prefix . 'wponlinebackup_status` LIMIT 1',
					ARRAY_N
				);

			if ( is_null( $result ) )
				$result = array( WPONLINEBACKUP_STATUS_NONE, '' );

			// MySQL-Proxy - COMMIT the transaction
			$wpdb->query( 'COMMIT' );

			list ( $check_status, $stop_user ) = $result;

			// Are we stopping?
			if ( $check_status == WPONLINEBACKUP_STATUS_STOPPING ) {

				// If we're not ignoring the stopping status, make sure we write the stopping back instead of our new status
				if ( !( $flags & WPONLINEBACKUP_UPSTATUS_IGNORESTOP ) ) {

					$new_status = $check_status;

					// Adjust our message to say Stopping backup... otherwise we'll change it away from it!
					// We don't do this if we're ignoring stop, because we only ignore stop if we're about to end (due to failure etc.)
					$this->status['progress']['message'] = __( 'Stopping backup...', 'wponlinebackup' );

					// Remove the jobs list and the cache - we don't need these anymore
					unset( $this->status['progress']['jobs'] );
					unset( $this->status['progress']['cache'] );

					// Prepare the new progress for the query
					// Serialize and escape_by_ref (uses _real_escape - better)
					$q_new_progress = serialize( $this->status['progress'] );
					$wpdb->escape_by_ref( $q_new_progress );

				}

				// Try to keep the lock but with stopping status
				$result = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_status` ' .
					'SET status = ' . $new_status .
						', time = ' . $now .
						', counter = ' . $new_counter .
						', activity_id = ' . $this->activity_id .
						', code = ' . $this->status['code'] .
						( $this->status_memory_freed !== false ? ', memory_freed = ' . $this->status_memory_freed : '' ) .
						( $this->status_memory_used !== false ? ', memory_used = ' . $this->status_memory_used : '' ) .
						( $q_new_progress !== false ? ', compressed = ' . $compressed . ', progress = \'' . $q_new_progress . '\', progress_max = ' . $l_new_progress : '' ) .
						' ' .
					'WHERE status = ' . $check_status . ' ' .
						'AND ' . $where
				);

				if ( $result ) {

					$this->have_lock = true;

					// We updated the row, store the time
					$this->status['status'] = $new_status;
					$this->status['time'] = $now;
					$this->status['counter'] = $new_counter;
					$this->status['stop_user'] = $stop_user;

					// Continue - returning 1 lets the caller see if we're stopping or not
					return 1;

				}

			}

		}

		$this->have_lock = false;

		// No row was updated, the mutex lock is lost - abort
		return 0;
	}

	/*public*/ function Perform_Config_Verify()
	{
		// Never exit in this function - we run it inside admin
		$this->WPOnlineBackup->Load_Settings();

		$config_verify = array();

		// Verify that the backups directory is NOT browsable - we should get back 403, 404 or a blank index.html if it's not
		// First ensure the index.html is there and then check
		if ( !@file_exists( $this->WPOnlineBackup->Get_Setting( 'backup_directory' ) . '/index.html' ) )
			@copy( WPONLINEBACKUP_PATH . '/index.html', $this->WPOnlineBackup->Get_Setting( 'backup_directory' ) . '/index.html' );

		$config_verify['backups_url'] = content_url() . '/backups/';
		$response = wp_remote_get(
			$config_verify['backups_url'],
			array(
				'timeout'	=> 30,
				'sslverify'	=> !$this->WPOnlineBackup->Get_Setting( 'ignore_ssl_cert' ),
			)
		);

		if ( is_wp_error( $response ) ) {

			$config_verify['backups_secure'] = 0; // We'll just have to rely on the user to check

		} else if ( $response['response']['code'] == '200' && trim( $response['body'] ) != '' ) {

			$config_verify['backups_secure'] = -1; // Not secure, we got a page back when looking at the index!

		} else {

			$config_verify['backups_secure'] = 1; // This is good, we're pretty sure we can't browse the directory
			// We may add a further check for username/password HTTP authentication, which is even more secure for the backups directory, but the filenames are so scrambled it is already safe for most people

		}

		// Grab tmp directory
		$local_tmp_dir = $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' );

		// Do the same for the tmp directory
		if ( !@file_exists( $local_tmp_dir . '/index.html' ) )
			@copy( WPONLINEBACKUP_PATH . '/index.html', $local_tmp_dir . '/index.html' );

		// Try to find the URL
		if ( substr( $local_tmp_dir, 0, 1 ) != '/' )
			$config_verify['tmp_url'] = site_url() . '/' . $local_tmp_dir;
		else if ( false !== ( $p = strpos( $local_tmp_dir . '/', ABSPATH . '/' ) ) )
			$config_verify['tmp_url'] = site_url() . '/' . substr( $local_tmp_dir, $p + strlen( ABSPATH ) + 1 );
		else
			$config_verify['tmp_url'] = false;

		// Only check if we found the URL for tmp dir - if we didn't, we're probably hidden and not public, which is brilliant
		if ( $config_verify['tmp_url'] !== false ) {

			$response = wp_remote_get(
				$config_verify['tmp_url'],
				array(
					'timeout'	=> 30,
					'sslverify'	=> !$this->WPOnlineBackup->Get_Setting( 'ignore_ssl_cert' ),
				)
			);

			if ( is_wp_error( $response ) ) {

				$config_verify['tmp_secure'] = 0; // We'll just have to rely on the user to check

			} else if ( $response['response']['code'] == '200' && trim( $response['body'] ) != '' ) {

				$config_verify['tmp_secure'] = -1; // Not secure, we got a page back when looking at the index!

			} else {

				$config_verify['tmp_secure'] = 1; // This is good, we're pretty sure we can't browse the directory
				// We may add a further check for username/password HTTP authentication

			}

		}

		// Set last update time and save
		$config_verify['last_update'] = time();
		update_option( 'wponlinebackup_config_verify', $config_verify );
	}

	/*public*/ function Start( $config, $type, $with_immediate_effect = false )
	{
		// Load status
		$this->Load_Status();

		// Check to see if a backup is already running, and grab the backup lock if possible
		// If a backup is running, but the time_presumed_dead period has passed, we presume the backup to have failed, and allow another to be started
		if (
			(
					$this->status['status'] != WPONLINEBACKUP_STATUS_NONE
				&&	$this->status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
			)
		)
			return false;

		// Reset activity_id
		$this->activity_id = 0;

		// Populate the settings cache
		$cache = array(
			'local_tmp_dir'		=> $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ),
			'backup_directory'	=> $this->WPOnlineBackup->Get_Setting( 'backup_directory' ),
		);

		if ( $type == WPONLINEBACKUP_ACTIVITY_DECRYPT ) {

			// Drop the .enc from the file we're decrypting
			if ( preg_match( '#^\\.enc$#i', substr( $config['file'], -4 ) ) )
				$config['decrypted_file'] = substr( $config['file'], 0, -4 );
			else
				$config['decrypted_file'] = $config['file'];

		} else {

			if ( $config['target'] == 'online' ) {

				// Cache the username and password so we ensure we use the same throughout the backup process
				$cache['username'] = $this->WPOnlineBackup->Get_Setting( 'username' );
				$cache['password'] = $this->WPOnlineBackup->Get_Setting( 'password' );

				// Some vaults are experiencing a change of blogurl mid backup due to different URL being accessed, so cache it here
				$cache['blogurl'] = $this->WPOnlineBackup->Get_Blog_URL();

			}

			if ( $config['disable_encryption'] ) {

				$cache['enc_type'] = '';
				$cache['enc_key'] = '';

			} else {

				$cache['enc_type'] = $this->WPOnlineBackup->Get_Setting( 'encryption_type' );
				$cache['enc_key'] = $this->WPOnlineBackup->Get_Setting( 'encryption_key' );

			}

		}

		// Reset the problem code
		$this->status['code'] = WPONLINEBACKUP_CODE_NONE;

		switch ( $type ) {

			case WPONLINEBACKUP_ACTIVITY_BACKUP:
				$start = __( 'Waiting for the backup to start...' , 'wponlinebackup' );
				break;

			case WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP:
				$start = __( 'Waiting for the scheduled backup to start...' , 'wponlinebackup' );
				break;

			case WPONLINEBACKUP_ACTIVITY_DECRYPT:
				$start = __( 'Waiting for the decrypt to start...' , 'wponlinebackup' );
				break;

			default:
				$start = __( 'Waiting for the activity to start...' , 'wponlinebackup' );
				break;

		}

		// Prepare the progress and its tracker - more entries are created as we need them
		$this->status['progress'] = array(
			'version'		=> WPONLINEBACKUP_VERSION,	// Version of plugin used to start the backup - used to stop the backup if we upgrade plugin during backup, which would be unsafe and prone to failure
			'start_time'		=> time(),			// The start time of the backup
			'initialise'		=> 1,				// Whether or not initialisation is complete
			'comp'			=> '-',				// Completion status
			'message'		=> $start,			// Message to show in monitoring page
			'config'		=> $config,			// Backup configuration
			'type'			=> $type,			// Type of backup
			'frozen_timeouts'	=> 0,				// Timeouts with no progress (compared with max_frozen_timeouts)
			'last_timeout'		=> null,			// Progress at last timeout (used to detect a frozen timeout)
			'progress_timeouts'	=> 0,				// Timeouts with progress (compared with max_progress_timeouts)
			'errors'		=> 0,				// Number of errors
			'warnings'		=> 0,				// Number of warnings
			'jobs'			=> array(),			// Job list - the backup job works its way through these - we populate this below
			'cleanups'		=> array(),			// Cleanup list - jobs that run after the backup completes
			'jobcount'		=> 0,				// Number of jobs - used to calculate progress in percent
			'jobdone'		=> 0,				// Number of jobs done
			'rotation'		=> 0,				// Do we need to rotate due to failure?
			'file'			=> null,			// The backup file - we populate this below
			'file_set'		=> null,			// The resulting backup files
			'rcount'		=> 0,				// Total number of files approached (not necessarily stored)
			'rsize'			=> 0,				// Total size of files approached (not necessarily stored)
			'ticks'			=> 0,				// Tick count
			'update_ticks'		=> $this->WPOnlineBackup->Get_Setting( 'update_ticks' ), // Number of ticks before update. We decrease to 1 on timeout.
			'revert_update_ticks'	=> 0,				// When update_ticks is set to 1 we use this to decide when to change it back
			'tick_progress'		=> array( 0 => false, 1 => 0 ),	// Tick progress when update_ticks is 1 and we're taking care
			'performs'		=> 0,				// Perform count
			'nonce'			=> '',				// Nonce for online collection if we need it
			'bsn'			=> 0,				// Keep track of BSN for incremental backups
			'cache'			=> $cache,			// Cached settings - we clear them after backup
		);

		// Update status to starting - ignore stopping status if we're set to stopping
		if ( !$this->Update_Status( WPONLINEBACKUP_STATUS_STARTING, 0, WPONLINEBACKUP_UPSTATUS_IGNORESTOP ) )
			return false;

		// Schedule the backup check thread for 65 seconds in the future
		wp_schedule_single_event( time() + 65, 'wponlinebackup_perform_check' );

		if ( $with_immediate_effect ) {

			// A scheduled backup so we start with immediate effect
			$this->perform_ignore_timeout = true;
			$this->Perform();

		} else {

			// Manual - Schedule the backup thread for in 5 seconds - hopefully after this page load so we can show the progress from the start if manually starting
			wp_schedule_single_event( time() + 5, 'wponlinebackup_perform' );

		}

		// Backup has started and is ready to run!
		return true;
	}

	/*public*/ function Stop()
	{
		global $wpdb, $current_user;

		// Load status
		$this->Load_Status();

		// Get current user - this function is always called from user-land (admin page)
		get_currentuserinfo();

		// Check we're still running - cancel stop if not
		if ( $this->status['status'] != WPONLINEBACKUP_STATUS_STARTING && $this->status['status'] != WPONLINEBACKUP_STATUS_RUNNING && $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING ) {
			return;
		}

		// Store the user that requested the stop and prepare it for the query
		$stop_user = $current_user->display_name;
		$wpdb->escape_by_ref($stop_user);
	
		// Force the status to be updated to stopping - but only if we're starting/running/ticking
		// We do this because if we lose lock during Update_Status we will check if only status has changed to stopping (like we have here)
		// In which case will update our internal status in the actual running script to stopping and begin to stop
		// This is our way of signalling the running backup process to stop, allowing it to clean up gracefully and tidily
		$result = $wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_status` ' .
			'SET status = ' . WPONLINEBACKUP_STATUS_STOPPING . ', ' .
				'stop_user = \'' . $stop_user . '\' ' .
			'WHERE status = ' . WPONLINEBACKUP_STATUS_STARTING . ' ' .
				'OR status = ' . WPONLINEBACKUP_STATUS_RUNNING . ' ' .
				'OR status = ' . WPONLINEBACKUP_STATUS_TICKING . ' ' .
				'OR status = ' . WPONLINEBACKUP_STATUS_CHECKING
		);
	}

	/*public*/ function Start_Activity()
	{
		global $wpdb;

		// Cleanup any old stale activity entries - any that have NULL completion time - care not for the result
		$wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_activity_log` ' .
			'SET end = start, ' .
				'comp = ' . WPONLINEBACKUP_COMP_UNEXPECTED . ' ' .
			'WHERE end IS NULL'
		);

		// Resolve the media
		if ( $this->status['progress']['type'] == WPONLINEBACKUP_ACTIVITY_DECRYPT ) {

				// Always local/download
				$media = WPONLINEBACKUP_MEDIA_DOWNLOAD;

		} else {

			switch ( $this->status['progress']['config']['target'] ) {
				case 'download':
					$media = WPONLINEBACKUP_MEDIA_DOWNLOAD;
					break;
				case 'email':
					$media = WPONLINEBACKUP_MEDIA_EMAIL;
					break;
				case 'online':
					$media = WPONLINEBACKUP_MEDIA_ONLINE;
					break;
				default:
					$media = WPONLINEBACKUP_MEDIA_UNKNOWN;
					break;
			}

		}

		// Insert a new activity row. Return false if we fail
		if ( $wpdb->query(
			'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_activity_log` ' .
			'(start, end, type, comp, media, compressed, encrypted, errors, warnings, bsize, bcount, rsize, rcount) ' .
			'VALUES ' .
			'(' .
				$this->status['progress']['start_time'] . ', ' .	// Start time
				'NULL, ' .						// End time is null as the activity has yet to finish
				$this->status['progress']['type'] . ', ' .		// Activity type
				WPONLINEBACKUP_COMP_RUNNING . ', ' .			// Current status is running
				$media . ', ' .						// Media
				'0, ' .							// Compressed?
				'0, ' .							// Encrypted?
				'0, ' .							// Number of errors - start at 0
				'0, ' .							// Number of warnings - start at 0
				'0, ' .							// These four fields are described in wponlinebackup.php during creation
				'0, ' .							// -
				'0, ' .							// -
				'0' .							// -
			')'
		) === false )
			return WPOnlineBackup::Get_WPDB_Last_Error();

		// Store the activity_id
		$this->activity_id = $wpdb->insert_id;

		return true;
	}

	/*public*/ function End_Activity( $status, $progress = false )
	{
		global $wpdb;

		// If we didn't complete we won't pass in the progress, so use 0s for the activity
		if ( $progress === false )
			$progress = array(
				'file_set'	=> array(
					'compressed'	=> 0,
					'encrypted'	=> 0,
					'size'		=> 0,
					'files'		=> 0,
				),
				'rsize'		=> 0,
				'rcount'	=> 0,
				'errors'	=> 0,
				'warnings'	=> 0,
			);

		// Update the loaded activity
		// - care not for the return status, best to kick off errors during starting a backup, then starting a backup AND finishing a backup
		//   that and we could be finishing the backup due to database errors anyways - so reporting here would be completely redundant
		$wpdb->update(
			$this->db_prefix . 'wponlinebackup_activity_log',
			array(
				'end'		=> time(),	// Set end time to current time
				'comp'		=> $status,	// Set completion status to the given status
				'errors'	=> $progress['errors'],
				'warnings'	=> $progress['warnings'],
				'compressed'	=> $progress['file_set']['compressed'],
				'encrypted'	=> $progress['file_set']['encrypted'],
				'bsize'		=> $progress['file_set']['size'],
				'bcount'	=> $progress['file_set']['files'],
				'rsize'		=> $progress['rsize'],
				'rcount'	=> $progress['rcount'],
			),
			array(
				'activity_id'	=> $this->activity_id,
			),
			'%d',
			'%d'
		);

		// Update the completion status stored in the progress, we send it to the server if it asks for a backup when we weren't expecting it to
		$this->status['progress']['comp'] = $status;
	}

	/*public*/ function Log_Event( $type, $event )
	{
		global $wpdb;

		// Increase error count if an error is being logged
		if ( $type == WPONLINEBACKUP_EVENT_ERROR )
			$this->status['progress']['errors']++;
		else if ( $type == WPONLINEBACKUP_EVENT_WARNING )
			$this->status['progress']['warnings']++;

		// Insert the event
		$res = $wpdb->insert(
			$this->db_prefix . 'wponlinebackup_event_log',
			array(
				'activity_id'	=> $this->activity_id,	// Current activity
				'time'		=> time(),		// Set event time to current time
				'type'		=> $type,		// Set event type to given type
				'event'		=> $event,		// Set event message to given message
			)
		);

		if ( $res === false )
			return WPOnlineBackup::Get_WPDB_Last_Error();

		return true;
	}

	/*public*/ function DBError( $file, $line, $friendly = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			__( 'A database operation failed.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'Please try reinstalling the plugin - in most cases this will repair the database.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'Please contact support if the issue persists, providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				WPOnlineBackup::Get_WPDB_Last_Error()
		);

		if ( $friendly === false )
			$friendly = __( 'A database operation failed.' , 'wponlinebackup' );

		return $friendly;
	}

	/*public*/ function FSError( $file, $line, $of, $ret, $friendly = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			( $of === false ? __( 'A filesystem operation failed.' , 'wponlinebackup' ) : sprintf( __( 'A filesystem operation failed while processing %s for backup.' , 'wponlinebackup' ), $of ) ) . PHP_EOL .
				__( 'If the following error message is not clear as to the problem and the issue persists, please contact support providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				$ret
		);

		if ( $friendly === false )
			$friendly = __( 'A filesystem operation failed.' , 'wponlinebackup' );

		return $friendly;
	}

	/*public*/ function COMError( $file, $line, $ret, $friendly = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			__( 'A transmission operation failed.' , 'wponlinebackup' ) . PHP_EOL .
				__( 'If the following error message is not clear as to the problem and the issue persists, please contact support providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				$ret
		);

		if ( $friendly === false )
			$friendly = __( 'Communication with the online vault failed.' , 'wponlinebackup' );

		return $friendly;
	}

	/*public*/ function MALError( $file, $line, $xml, $parser_ret = false )
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			( $parser_ret === false ? __( 'An online request succeeded but was malformed.' , 'wponlinebackup' ) : __( 'An online request failed: The server response was malformed.' , 'wponlinebackup' ) ) . PHP_EOL .
				__( 'Please contact support if the issue persists, providing the complete event log for the activity. Diagnostic information follows:' , 'wponlinebackup' ) . PHP_EOL . PHP_EOL .
				'Failed at: ' . $file . '(' . $line . ')' . PHP_EOL .
				( $parser_ret === false ? 'XML parser succeeded' : 'XML parser: ' . $parser_ret . PHP_EOL ) .
				'XML log:' . PHP_EOL . $xml->log
		);

		return __( 'Communication with the online vault failed.' , 'wponlinebackup' );
	}

	/*private*/ function Get_Option( $option, $default = null )
	{
		// This cheats get_option - it loads the option without loading autoload options, which uses memory unnecessarily
		global $wpdb;

		// In the cache?
		if ( !array_key_exists( $option, $this->options ) ) {

			$esc_option = $option;
			$wpdb->escape_by_ref( $esc_option );

			// Grab from the database
			$result = $wpdb->get_row( 'SELECT option_value FROM ' . $wpdb->options . ' WHERE option_name = \'' . $esc_option . '\' LIMIT 1', ARRAY_A );

			if ( is_null( $result ) )
				return $default;

			// Cache
			$this->options[ $option ] = maybe_unserialize( $result['option_value'] );

		}

		return $this->options[ $option ];
	}

	/*private*/ function Update_Option( $option, $newvalue )
	{
		// This cheats update_option - the real one actually loads the old value with get_option causing an autoload, which uses memory unnecessarily
		global $wpdb;

		// Cache
		$this->options[ $option ] = $newvalue;

		// Update the database
		return $wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $newvalue ) ), array( 'option_name' => $option ) );
	}

	/*public*/ function Register_Temp( $temp )
	{
		$temps = $this->Get_Option( 'wponlinebackup_temps', array() );
		
		$temps[] = $temp;
		$this->Update_Option( 'wponlinebackup_temps', $temps );
	}

	/*public*/ function Unregister_Temp( $temp )
	{
		$temps = $this->Get_Option( 'wponlinebackup_temps', array() );
		
		if ( ( $key = array_search( $temp, $temps ) ) !== false ) {

			unset( $temps[$key] );
			$this->Update_Option( 'wponlinebackup_temps', $temps );

		}
	}

	/*public*/ function Clean_Temps()
	{
		$temps = $this->Get_Option( 'wponlinebackup_temps', array() );

		foreach ( $temps as $item )
			@unlink( $item );

		$this->Update_Option( 'wponlinebackup_temps', array() );
	}

	/*public*/ function On_Shutdown()
	{
		// Immediately grab the last error, but only the message part (raw)
		$last_error = OBFW_Raw_Exception();

		// If we lost the lock and exit, don't bother doing anything
		if ( !$this->have_lock )
			return;

		$what = '';

		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_RUNNING ) {

			// In case of memory area, free up our buffer
			unset( $this->mem_buffer );

			// We only reach here if we had a failure, check for a memory failure
			if ( preg_match( '/^Allowed memory size of ([0-9]+) bytes exhausted/', $last_error, $matches ) ) {

				// Flag up memory problems - we can report them if needed
				$this->status['code'] = WPONLINEBACKUP_CODE_MEMORY;

			}

			// Update status, leave if we've lost the lock, but ignore progress in case we had a low memory problem so we can get the update through
			// If we're stopping we do same as if we're checking... run check to finish off
			if ( !$this->Update_Status( WPONLINEBACKUP_STATUS_CHECKING, false, WPONLINEBACKUP_UPSTATUS_PROGRESSNONE ) )
				return;

			// If we're in recovery mode the last tick status was saved, so we don't need to save it now
			// We can move immediately onto chaining. This is good because on the first memory failure we might fail to chain and cause a delay.
			// However, subsequent memory failures while in recovery mode will chain immediately
			if ( !$this->WPOnlineBackup->recovery_mode ) {

				// Keep the current status/time/counter since it will have been updated by the previous Update_Status above
				// If we didn't keep this, it would mean we try to update again with the same values
				// This will cause an update failure as it will think we lost the lock
				$keep_values = array( $this->status['status'], $this->status['time'], $this->status['counter'] );

				// Copy last tick status to current status
				$this->status = $this->last_tick_status;

				// Restore the values
				list ( $this->status['status'], $this->status['time'], $this->status['counter'] ) = $keep_values;

				// Update status, leave if we've lost the lock, but try to set the progress now - in low memory this might fail... but at least we'll be able to enter checking immediately on next kick start or schedule run
				if ( !$this->Update_Status( WPONLINEBACKUP_STATUS_CHECKING, false, WPONLINEBACKUP_UPSTATUS_PROGRESSRAW ) )
					return;

			}

			// Trigger the Perform_Check so we can work out failures etc - we must be in STATUS_CHECKING though otherwise it will just abort thinking we're still running
			$what = '_check';

		} else if ( $this->status['status'] == WPONLINEBACKUP_STATUS_NONE ) {

			// We only add on On_Shutdown() AFTER we mark as running, so if we marked as NONE we just finished
			return;

		} else if ( $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING && $this->status['status'] != WPONLINEBACKUP_STATUS_STOPPING ) {

			// Not idle, not running, not ticking and not stopping, exit
			return;

		}

		// Clean up stream but don't wipe it - this will ensure fclose is called on all file handles
		// This should ensure when the next run starts (which will most likely be during this script run since we trigger it directly) that all data has been written to disk
		if ( is_object( $this->stream ) ) {
			$this->stream->CleanUp( false );
			$this->stream = null;
		}

		// Attempt to kick start the backup again - this bit based on spawn_cron()
		$do_url = $this->WPOnlineBackup->Get_Blog_URL() . '?wponlinebackup_do' . $what . '&' . time();
		wp_remote_post(
			$do_url,
			array(
				'timeout' => 1,
				'blocking' => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
			)
		);
	}

	/*public*/ function Tick( $next = false, $update = false )
	{
		$exit = false;

		$run_time = time() - $this->start_time;

		if ( $next || $run_time > $this->max_execution_time ) {

			$this->status['progress']['rotation']--;

			// Update the stream state
			if ( is_object( $this->stream ) )
				$this->status['progress']['file']['state'] = $this->stream->Save();
			else
				$this->status['progress']['file'] = null;

			// Processors may have information they need to save
			$this->Save_Processors();

			// Reset the tick count
			$this->status['progress']['ticks'] = 0;

			// We don't need last_tick_status since it will be the same as we're saving it now
			$this->last_tick_status = false;

			// Update status - ignore if we're stopping, we'll sort on the next tick
			if ( !$this->Update_Status( WPONLINEBACKUP_STATUS_TICKING ) ) {

				// We've lose lock, so don't bother with a sleep
				$next = false;

			}

			$this->CleanUp_Processors( true );

			$exit = true;

			// If we're forcing next, ensure we've run for at least 10 seconds
			if ( $next && $run_time < $this->min_execution_time ) {

				// Sleep a bit, but not too long as to reach max_execution_time
				// We do this to prevent eating too much resources on the server
				if ( ( $sleep_time = $this->min_execution_time - 2 - $run_time ) > 0 ) {

					// In case we get interrupts
					$now = time();
					$end = $now + $sleep_time;
					do {
						sleep( $end - $now );
						$now = time();
					} while ( $now < $end );

				}

			}

		} else {

			if ( $this->WPOnlineBackup->recovery_mode ) {

				// We made progress, so clear the frozen timeouts counter and reset problem code
				$this->status['progress']['frozen_timeouts'] = 0;
				$this->status['code'] = WPONLINEBACKUP_CODE_NONE;

				// We'll store a 0 tick count... since we want to start from beginning when we reload with full update_ticks required, and not end up updating after a couple ticks
				// - the tick count will be placed back after the update since we still need it to tell us when to restore the old update_ticks value
				$ticks = $this->status['progress']['ticks'];
				$this->status['progress']['ticks'] = 0;

				$update = true;

				// We're taking our time at the moment and always updating; if we hit the revert update_ticks value we can revert it
				// - use $ticks and not the progress array since we've reset the one in the progress to 0 and we'll be putting it back from ticks,
				if ( ++$ticks >= $this->status['progress']['revert_update_ticks'] ) {

					// Revert back update ticks and drop out of recovery mode
					$this->update_ticks = $this->status['progress']['update_ticks'];
					$ticks = 0;
					$this->WPOnlineBackup->Set_Recovery_Mode( false );

					// Reset progress timeouts since we managed to get through 100 ticks fine
					$this->status['progress']['progress_timeouts'] = 0;

				}

			} else {

				// Only update if tick count reached - speeds things up alot - but if update is forced, make sure we still reset ticks to 0
				if ( $update || ++$this->status['progress']['ticks'] >= $this->status['progress']['update_ticks'] ) {

					$this->status['progress']['ticks'] = 0;

					$update = true;

				}

			}

			// Update the stream state
			if ( is_object( $this->stream ) )
				$this->status['progress']['file']['state'] = $this->stream->Save();
			else
				$this->status['progress']['file'] = null;

			// Processors may have information they need to save
			$this->Save_Processors();

			if ( $update ) {

				// We don't need last_tick_status since it will be the same as we're saving it now
				$this->last_tick_status = false;

				// Update status, leave if we've lost the lock
				// Check if we're stopping - we'll need to exit then so we can trigger a check in On_Shutdown() like we do when we're finished
				if ( !( $check_stop = $this->Update_Status() ) || $check_stop === 1 ) {

					$this->CleanUp_Processors( true );

					$exit = true;

				}

			} else {

				// Store the current status as the latest tick status - if an error occurs we'll drop back to this one
				// Do the processing in a temporary variable though so $this->last_tick_status is always usable
				$last_tick_status = $this->status;

				// Split the references away from the real data - this is why we used a temporary variable - there will be a point where we change last_tick_status and then need memory - if memory fails us inbetween it would be bad
				unset( $last_tick_status['progress'] );
				$last_tick_status['progress'] = serialize( $this->status['progress'] );

				// Now switch $this->last_tick_status atomically to the $last_tick_status that we know is safe
				$this->last_tick_status = $last_tick_status;

			}

			if ( $this->WPOnlineBackup->recovery_mode ) {

				// Put the tick count back...
				$this->status['progress']['ticks'] = $ticks;

			}

		}

		if ( $exit )
			exit;

		return true;
	}

	/*private*/ function Perform_Stop( $comp, $ret )
	{
		// Remove any schedule
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );

		// Run cleanup
		$this->CleanUp();

		// Log the message
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_WARNING,
			$ret
		);

		$this->End_Activity( $comp );

		$this->status['progress']['message'] = $ret;

		// Ignore stopping status, too many time outs so we're stopping anyway
		$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );
	}

	/*private*/ function _Check_Progress_In_Tact()
	{
		if ( !is_array( $this->status['progress'] ) ) {

			// Special cases
			if ( $this->status['progress'] === 0 ) {

				// Stop the backup - fail it due to missing compression
				// This same message is duplicated exactly in compressor_deflate, we'll common code it later
				$this->Perform_Stop( WPONLINEBACKUP_COMP_FAILED, __( 'Compression is no longer available on the server. The server configuration must have changed during backup. Please run the backup again to run without compression.' , 'wponlinebackup' ) );

			} else {

				// Stop the backup - fail it
				$this->Perform_Stop( WPONLINEBACKUP_COMP_FAILED, __( 'The backup cannot proceed any further as the state information for the backup has exceeded the maximum size MySQL will allow. Please visit the Help & Support section for information on where to find help.', 'wponlinebackup' ) );

			}

			return false;

		}

		return true;
	}

	/*public*/ function Perform_Check()
	{
		// Check we haven't already run once during this PHP session
		if ( $GLOBALS['WPOnlineBackup_Perform_Check_Once'] === true )
			return;

		$GLOBALS['WPOnlineBackup_Perform_Check_Once'] = true;

		// Remove any schedule
		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );

		// Load status
		$this->Load_Status();

		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_NONE ) return;

		// Allow an instant start from the overview page when AJAX is enabled
		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_STARTING ) {

			// Perform - but don't ignore timeout
			$this->Perform();

			return;

		}

		if (
				$this->status['status'] != WPONLINEBACKUP_STATUS_NONE
			&&	$this->status['time'] <= time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
		) return;

		// Have we been triggered by On_Shutdown? (Status will be checking) Or if we haven't, has Perform not run in the recovery time?
		if ( $this->status['status'] != WPONLINEBACKUP_STATUS_CHECKING && $this->status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time' ) ) {

			// Schedule again in future in 60 seconds
			wp_schedule_single_event( time() + 65, 'wponlinebackup_perform_check' );

			// Timeout didn't occur, just exit
			return;

		}

		// Check the progress information is in tact - if it returns false we just stopped the backup
		if ( !$this->_Check_Progress_In_Tact() )
			return;

		// Check we haven't changed version midway through backup
		if ( $this->status['progress']['version'] != WPONLINEBACKUP_VERSION ) {

			// Stop the backup
			$this->Perform_Stop( WPONLINEBACKUP_COMP_STOPPED, __( 'The activity was stopped to allow a plugin update to complete.', 'wponlinebackup' ) );

			return;

		}

		// If job status not changed, we've frozen
		$last_timeout = md5( serialize( $this->status['progress']['jobs'] ) );

		// ...but if the disk status DID change, we've not frozen! (reconstruction etc.)
		$last_timeout .= md5( serialize( $this->status['progress']['file'] ) );

		// Did we make progress?
		if ( !is_null( $this->status['progress']['last_timeout'] ) ) {

			if ( $last_timeout == $this->status['progress']['last_timeout'] ) {

				if ( ++$this->status['progress']['frozen_timeouts'] > $this->WPOnlineBackup->Get_Setting( 'max_frozen_retries' ) ) {

					// Was the latest failure due to memory? Chances are all failures were due to memory
					if ( $this->status['code'] == WPONLINEBACKUP_CODE_MEMORY ) {

						// Report memory issues
						$this->Perform_Stop( WPONLINEBACKUP_COMP_MEMORY, __( 'The activity cannot proceed any further due to low memory. If possible, increase PHP\'s memory limit. Otherwise, disabling some unused plugins may free up enough memory to allow the activity to complete.', 'wponlinebackup' ) );

					} else {

						// Unknown problem
						$this->Perform_Stop( WPONLINEBACKUP_COMP_TIMEOUT, __( 'The activity cannot proceed any further due to an unknown problem. Please visit the Help & Support section for information on where to find help.', 'wponlinebackup' ) );

					}

					return;

				}

			}

		}

		if ( 0 != ( $max_progress_retries = $this->WPOnlineBackup->Get_Setting( 'max_progress_retries' ) ) && ++$this->status['progress']['progress_timeouts'] > $max_progress_retries ) {

			// Was the latest failure due to memory? Chances are all failures were due to memory
			if ( $this->status['code'] == WPONLINEBACKUP_CODE_MEMORY ) {

				// Report memory issues
				$this->Perform_Stop( WPONLINEBACKUP_COMP_SLOWMEMORY, __( 'The activity cannot proceed any further due to low memory. If possible increase PHP\'s memory limit. Otherwise, disabling some unused plugins may free up enough memory to allow the activity to complete.', 'wponlinebackup' ) );

			} else {

				// Unknown problem
				$this->Perform_Stop( WPONLINEBACKUP_COMP_SLOWTIMEOUT, __( 'The activity cannot proceed any further due to an unknown problem. Please visit the Help & Support section for information on where to find help.', 'wponlinebackup' ) );

			}

			return;

		}

		// Reset tick count to 1 so we constantly update to try get past this blockage that caused the timeout, also store the revert count and the latest progress
		$this->update_ticks = 1;
		$this->status['progress']['revert_update_ticks'] = $this->status['progress']['update_ticks'];
		$this->status['progress']['last_timeout'] = $last_timeout;

		// Flag up recovery mode in WPOnlineBackup so it can reduce some memory related automatic settings
		$this->WPOnlineBackup->Set_Recovery_Mode( $this->status['progress']['progress_timeouts'] );

		// Schedule again in future
		wp_schedule_single_event( time() + 65, 'wponlinebackup_perform_check' );

		// Update the message - bit vague but we don't really know if this is going to be a timeout issue or not
		$this->status['progress']['message'] = __( 'A timeout occurred (large files and slow servers are a common cause of this); trying again...' , 'wponlinebackup' );

		// Run the activity now
		$this->perform_ignore_timeout = true;
		$this->Perform();
	}

	/*public*/ function Perform()
	{
		// Check we haven't already run once during this PHP session
		if ( $GLOBALS['WPOnlineBackup_Perform_Once'] === true )
			return;

		$GLOBALS['WPOnlineBackup_Perform_Once'] = true;

		// Remove any schedule
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );

		if ( !$this->perform_ignore_timeout ) {

			// Load status
			$this->Load_Status();

			// If we're not ticking, starting or stopping, we're either not running at all or checking, so lets stop here
			if ( $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING && $this->status['status'] != WPONLINEBACKUP_STATUS_STARTING && $this->status['status'] != WPONLINEBACKUP_STATUS_STOPPING )
				return;

			// Check we're not presumed dead - Perform_Check() will kill the entire process if we are so lets not do anything
			if (
					$this->status['status'] != WPONLINEBACKUP_STATUS_NONE
				&&	$this->status['time'] <= time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
			)
				return;

			// If we're outside the normal run time, we've probably crashed and awaiting recovery so lets not do anything - Perform_Check() will log the timeout and kick start Perform() again
			if ( $this->status['time'] <= time() - $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time' ) )
				return;

			// Check the progress information is in tact - if it returns false we just stopped the backup
			if ( !$this->_Check_Progress_In_Tact() )
				return;

			// Grab the tick count - perform_check will already have done this thus why we only get it here
			$this->update_ticks = $this->status['progress']['update_ticks'];

		}

		// Check we haven't changed version midway through backup
		if ( $this->status['progress']['version'] != WPONLINEBACKUP_VERSION ) {

			// Stop the backup
			$this->Perform_Stop( WPONLINEBACKUP_COMP_STOPPED, __( 'The activity was stopped to allow a plugin update to complete.', 'wponlinebackup' ) );

			return;

		}

		$this->start_time = time();

		// Ignore user aborts
		@ignore_user_abort( true );

		// Some IIS kill PHP for some reason if we send output and the connection was aborted, ignoring ignore_user_abort()
		// We don't output so it must be errors or something else - try to compensate
		ini_set( 'display_errors', 0 );
		$this->_Clear_Output_Buffers();
		ob_start( array( & $this, '_Prevent_Output' ), 4096 );

		// Turn off HTML errors and remove docref_root to normalise error messages
		ini_set( 'html_errors', 0 );
		ini_set( 'docref_root', '' );

		// Test safe mode
		if ( $this->WPOnlineBackup->Get_Setting( 'safe_mode' ) ) {

			// Cannot change time limit in safe mode, so offset the max_execution_time based on how much time we've lost since initialisation, but give a minimum of 5 seconds
			$offset = time() - $GLOBALS['WPOnlineBackup_Init'];
			$this->max_execution_time = ( $offset > $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) ? false : $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) - $offset );
			if ( $this->max_execution_time === false ) $this->max_execution_time = min( 5, $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) );

		} else {

			$this->max_execution_time = $this->WPOnlineBackup->Get_Setting( 'max_execution_time' );

			// Prevent timeouts - we used to do max_exec_time * 2 but this of course might cause issues
			@set_time_limit( 0 );

		}

		// Attempt to increase memory availability
		$this->WPOnlineBackup->Increase_Memory_Limit();

		// If we have memory functions available, try to detect how much memory we free at start of backup
		// This might be useful to us
		$start = 0;
		if ( function_exists( 'memory_get_usage' ) )
			$start = memory_get_usage();

		// Clear WP cache - it'll ditch all the autoload option information etc. for other plugins
		// We've already loaded our options so we don't need WP cache to keep anything anymore
		wp_cache_flush();

		// Calculate the amount of memory we freed and store it in the status information, also store the total usage at start of backup
		if ( $start != 0 ) {
			$this->status_memory_freed = $start - memory_get_usage();
			$this->status_memory_used = memory_get_usage();
		}

		// Minimum execution time can't be more than execution time - we fix default so need to check
		$this->min_execution_time = min( $this->max_execution_time, $this->WPOnlineBackup->Get_Setting( 'min_execution_time' ) );

		$this->status['progress']['performs']++;

		// Store the previous rotation value - we use this when recreating the stream - and increase it so if we're interrupted we implicitly rotate - we'll decrease it back if we exit gracefully
		$rotation = $this->status['progress']['rotation']++;

		// Check we still have the lock before we do anything, even activity start, since it will log an activity and we don't want multiple activities getting created that show as unexpected stop
		if ( $this->status['status'] == WPONLINEBACKUP_STATUS_STOPPING ) {

			// Update with stopping so we don't change it
			if ( !( $this->Update_Status( WPONLINEBACKUP_STATUS_STOPPING ) ) )
				return;

			// Flag a stop
			$check_stop = 1;

		} else {

			// Check for stop again in case we clicked Stop() inbetween us loading the status and doing this update
			if ( !( $check_stop = $this->Update_Status( WPONLINEBACKUP_STATUS_RUNNING ) ) )
				return;

		}

		// If we're just starting, populate a new activity, if this fails then there is a problem with the internal database tables - needs reactivation - return the message
		// We need to do this BEFORE stopping, since stop will want to log an event - so we do CheckLock->CheckStart->CheckStop essentially
		if ( $this->activity_id == 0 ) {

			if ( ( $ret = $this->Start_Activity() ) === true ) {

				switch ( $this->status['progress']['type'] ) {

					case WPONLINEBACKUP_ACTIVITY_BACKUP:
						$starting = __( 'Backup starting...' , 'wponlinebackup' );
						break;

					case WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP:
						$starting = __( 'Scheduled backup starting...' , 'wponlinebackup' );
						break;

					case WPONLINEBACKUP_ACTIVITY_DECRYPT:
						$starting = __( 'Decrypt starting...' , 'wponlinebackup' );
						break;

					default:
						$starting = __( 'Starting...' , 'wponlinebackup' );
						break;

				}

				// Log the starting event to see if the event log is fine and check we're actually logged in if we're doing an online backup
				if ( ( $ret = $this->Log_Event( WPONLINEBACKUP_EVENT_INFORMATION, $starting ) ) !== true ) {

					$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

				}

			}

			if ( $ret !== true ) {

				switch ( $this->status['progress']['type'] ) {

					case WPONLINEBACKUP_ACTIVITY_BACKUP:
						$failed_start = __( 'The backup failed to start: %s' , 'wponlinebackup' );
						break;

					case WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP:
						$failed_start = __( 'The scheduled backup failed to start: %s' , 'wponlinebackup' );
						break;

					case WPONLINEBACKUP_ACTIVITY_DECRYPT:
						$failed_start = __( 'The decrypt failed to start: %s' , 'wponlinebackup' );
						break;

					default:
						$failed_start = __( 'The activity failed to start: %s' , 'wponlinebackup' );
						break;

				}

				// Update the message to say we're stopped now (it would be Stopping... at the moment)
				$this->status['progress']['message'] = sprintf( $failed_start, $ret );

				// Update status one second time to mark as finished
				$this->Update_Status( WPONLINEBACKUP_STATUS_NONE );

				return;

			}

			// Check we're actually logged into the online vault if we're doing an online backup
			if ( ( $this->status['progress']['type'] == WPONLINEBACKUP_ACTIVITY_BACKUP || $this->status['progress']['type'] == WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP ) && $this->status['progress']['config']['target'] == 'online' && $this->status['progress']['cache']['username'] == '' ) {

				$this->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					__( 'An online backup cannot be performed if the plugin is not currently logged into the online backup servers. Please click \'Online Backup Settings\' and login to enable online backup.' , 'wponlinebackup' )
				);

				$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

				// Update the message to say we're stopped now (it would be Stopping... at the moment)
				$this->status['progress']['message'] = __( 'The backup could not be started: An online backup cannot be performed if the plugin is not currently logged into the online backup servers.' , 'wponlinebackup' );

				// Update status one second time to mark as finished
				$this->Update_Status( WPONLINEBACKUP_STATUS_NONE );

				return;

			}

		}

		// Are we stopping?
		if ( $check_stop === 1 ) {

			// Run cleanup
			$this->CleanUp();

			// Log stopped event
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( __( 'The activity was stopped by %s.', 'wponlinebackup' ), $this->status['stop_user'] )
			);

			// Mark as completed
			$this->End_Activity( WPONLINEBACKUP_COMP_STOPPED );

			// Update the message to say we're stopped now (it would be Stopping... at the moment)
			$this->status['progress']['message'] = __( 'The activity was stopped.', 'wponlinebackup' );

			// Update status one second time to mark as finished
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE );

			return;

		}

		// Clear old temporary files left behind by any previous failed runs as they are now redundant now that we've officially started a new backup run
		$this->Clean_Temps();

		// Register shutdown event - from this point forward we need instant ticking and instant recovery
		// If we hit the shutdown function and we're still in RUNNING status we'll take the last tick status from the last tick and save it to the database with CHECKING and then instant chain
		// Without this we would need to wait for the recovery thread to start before we could continue which will take several minutes
		register_shutdown_function( array( & $this, 'On_Shutdown' ) );

		// Update the seed we use for files
		$this->WPOnlineBackup->Random_Seed( $this->last_tick_status, 'reseed' );

		// Initialise if required
		if ( $this->status['progress']['initialise'] && ( $ret = $this->Initialise() ) !== true ) {

			// Log the failure event
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				$ret = sprintf( __( 'The activity failed to initialise: %s' , 'wponlinebackup' ), $ret )
			);

			// Mark as failed
			$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

			// Set message to the error message
			$this->status['progress']['message'] = $ret;

			// End the backup
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

			return;

		}

		// If the stream is null and file is not full (i.e. we don't have a file_set) then we've got a saved stream state so load it
		if ( is_null( $this->stream ) && !is_null( $this->status['progress']['file'] ) ) {

			// Which stream type do we need?
			require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $this->status['progress']['file']['type'] ) . '.php';

			// Create it
			$name = 'WPOnlineBackup_' . $this->status['progress']['file']['type'];
			$stream = new $name( $this->WPOnlineBackup );
			if ( ( $ret = $stream->Load( $this->status['progress']['file']['state'], $rotation ) ) !== true )
				$stream = null;

			// Store the steam - we cannot store it in this->stream until it is Loade because we call CleanUp in On_Shutdown if an error occurs and CleanUp can not be called until after Open or Load
			$this->stream = & $stream;

		} else {

			$ret = true;

		}

		if ( $ret === true ) {

			// Call the backup processor
			$ret = $this->Backup();

		}

		if ( $ret !== true ) {

			// Clean up the stream if it's still set, otherwise cleanup the file_set if it exists (only one or the other exists)
			if ( !is_null( $this->stream ) ) {

				$this->stream->CleanUp();

			} else if ( !is_null( $this->status['progress']['file_set'] ) ) {

				if ( !is_array( $this->status['progress']['file_set']['file'] ) )
					@unlink( $this->status['progress']['file_set']['file'] );
				else
					foreach ( $this->status['progress']['file_set']['file'] as $file )
						@unlink( $file );

			}

			// Run cleanup
			$this->CleanUp();

			// Log event for failure
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				$ret = sprintf( __( 'The activity failed: %s' , 'wponlinebackup' ), $ret )
			);

			// Mark as failed - if there is maintenance use the maintenance failure type instead
			if ( $this->maintenance != WPONLINEBACKUP_MAINTENANCE_NONE )
				$this->End_Activity( WPONLINEBACKUP_COMP_MAINTENANCE );
			else
				$this->End_Activity( WPONLINEBACKUP_COMP_FAILED );

			// Update the status message to be the error message
			$this->status['progress']['message'] = $ret;

			// End te backup
			$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

			return;

		}

		// Run cleanup
		$this->CleanUp();

		switch ( $this->status['progress']['type'] ) {

			case WPONLINEBACKUP_ACTIVITY_BACKUP:
				$complete = __( 'Backup complete.' , 'wponlinebackup' );
				break;

			case WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP:
				$complete = __( 'Scheduled backup complete.' , 'wponlinebackup' );
				break;

			case WPONLINEBACKUP_ACTIVITY_DECRYPT:
				$complete = __( 'Decrypt complete.' , 'wponlinebackup' );
				break;

			default:
				$complete = __( 'Completed.' , 'wponlinebackup' );
				break;

		}

		// Log the completed event
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			$complete
		);

		// Mark the activity as finished
		$this->End_Activity(
			$this->status['progress']['errors'] ? WPONLINEBACKUP_COMP_PARTIAL : WPONLINEBACKUP_COMP_SUCCESSFUL,
			$this->status['progress']
		);

		// End the backup as we're finished
		$this->Update_Status( WPONLINEBACKUP_STATUS_NONE, false, WPONLINEBACKUP_UPSTATUS_IGNORESTOP );

		// Clear any hooks left behind to make sure we don't try running anything again (it wouldn't run anything due to the status update but it saves resources)
		wp_clear_scheduled_hook( 'wponlinebackup_perform' );
		wp_clear_scheduled_hook( 'wponlinebackup_perform_check' );
	}

	/*private*/ function CleanUp()
	{
		// Cleanup processors first of all
		$this->CleanUp_Processors();

		// Now cleanup any temporaries - should be empty if this is the CleanUp call for finished or failed backup
		$this->Clean_Temps();

		// Now we scour the tmp directory and remove all materials
		if ( ( $d = @opendir( $p = $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ) ) ) !== false ) {

			while ( false !== ( $f = readdir( $d ) ) ) {

				// Ignore directories, index.html and dotfiles
				if ( is_dir( $f ) || substr( $f, 0, 1 ) == '.' || $f == 'index.html' )
					continue;

				// Does it match our file patterns?
				if ( !preg_match( '#^(?:backup\\.data|backup\\.indx|backup|gzipbuffer|encbuffer|cdrbuffer|decrypt)(\\.[A-Za-z0-9\\.]+)?(?:\\.[0-9]+|\\.rc)?\\.php$#', $f ) )
					continue;

				// Remove everything else - after online backup completes the tmp folder should be completely empty
				@unlink( $p . '/' . $f );

			}

		}
	}

	/*private*/ function Initialise()
	{
		global $wpdb;

		$progress = & $this->status['progress'];

		// Track the steps we're on
		$next_step = 1;

		// Set the message
		if ( $progress['initialise'] < ++$next_step ) {

			$progress['message'] = __( 'Initialising...' , 'wponlinebackup' );

			// Force update
			$this->Tick( false, true );

		}

		// Decrypt? We'll soon be creating bootstrap scripts for each type of backup but we'll squeeze it in here for now
		if ( $progress['type'] == WPONLINEBACKUP_ACTIVITY_DECRYPT ) {

			if ( true !== ( $ret = $this->Initialise_Decrypt( $this, $progress, $next_step ) ) )
				return $ret;

		} else {

			if ( true !== ( $ret = $this->Initialise_Backup( $this, $progress, $next_step ) ) )
				return $ret;

		}

		$progress['initialise'] = 0;

		$this->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			__( 'Initialisation completed.' , 'wponlinebackup' )
		);

		// Force a save to prevent duplicated events
		$this->Tick( false, true );

		// Success
		return true;
	}

	/*private*/ function Initialise_Decrypt( & $bootstrap, & $progress, & $next_step )
	{
		// Add on the decryption job
		if ( $progress['initialise'] < ++$next_step ) {

			require_once WPONLINEBACKUP_PATH . '/include/decrypt.php';
			$decrypt = new WPOnlineBackup_Backup_Decrypt( $this->WPOnlineBackup, $this->db_force_master );

			// Initialise - pass ourselves so we can log events, and also pass the progress and its tracker
			if ( true !== ( $ret = $decrypt->Initialise( $this, $progress ) ) )
				return $ret;

			$progress['initialise'] = $next_step;

			$bootstrap->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Reconstruct the data files
			$progress['jobs'][] = array(
				'processor'	=> 'reconstruct',
				'progress'	=> 0,
				'progresslen'	=> 5,
			);

			// Add the cleanups to the end of the job list so they happen only after the main backup jobs have finished
			$progress['jobs'] = array_merge( $progress['jobs'], $progress['cleanups'] );
			$progress['cleanups'] = array();

			// Add on the file cleanup - this places local backups in their correct place and deletes files for online backup etc.
			$progress['jobs'][] = array(
				'processor'		=> 'cleanupfiles',
				'progress'		=> 0,
				'progresslen'		=> 5,
			);

			// Total up the jobcount as total of all progresslens - makes calculating progress % easier
			foreach ( $progress['jobs'] as $job )
				$progress['jobcount'] += $job['progresslen'];

			$progress['initialise'] = $next_step;

			$this->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Create the local backup directory - this SHOULD exist really... since the file we'll be decrypting is going to be in here
			if ( true !== ( $ret = $bootstrap->Create_Dir_Local_Backup() ) )
				return $ret;

			// Try to create the temporary backup directory
			if ( true !== ( $ret = $bootstrap->Create_Dir_Temporary() ) )
				return $ret;

			$progress['initialise'] = $next_step;

			// Force a save here since we did some file operation checks and it'll save us doing it again
			$this->Tick( false, true );

		}

		// Prepare the stream configuration
		$config = array(
			'designated_path'	=> $progress['cache']['local_tmp_dir'],
			'compressed'		=> $progress['config']['compressed'],
		);

		// Set up a direct stream (wrapper around disk) since we're doing simple read and write
		require_once WPONLINEBACKUP_PATH . '/include/stream_direct.php';

		$stream = new WPOnlineBackup_Stream_Direct( $this->WPOnlineBackup );

		// Open the file
		if ( ( $ret = $stream->Open( $config ) ) !== true ) {

			return $bootstrap->Create_Failure();

		}

		// Store the disk - we cannot store it in this->stream until it is Opened because we call CleanUp in On_Shutdown if an error occurs and CleanUp can not be called until after Open or Load
		$bootstrap->Set_Stream( 'Stream_Direct', $stream );

		return true;
	}

	/*private*/ function Initialise_Backup( & $bootstrap, & $progress, & $next_step )
	{
		global $wpdb;

		// First of all, clean up everything from last backup
		if ( $progress['initialise'] < ++$next_step ) {

			$this->CleanUp();

			$progress['initialise'] = $next_step;

			$bootstrap->Tick();

		}

		// First of all, clear back activity logs
		if ( $progress['initialise'] < ++$next_step ) {

			do {

				if ( ( $ret = $wpdb->query(
					'DELETE a, e FROM `' . $this->db_prefix . 'wponlinebackup_activity_log` a ' .
						'LEFT JOIN `' . $this->db_prefix . 'wponlinebackup_event_log` e ON (e.activity_id = a.activity_id) ' .
					'WHERE a.start < ' . strtotime( '-' . $this->WPOnlineBackup->Get_Setting( 'max_log_age' ) . ' months', $progress['start_time'] )
				) ) === false ) return $bootstrap->DBError( __LINE__, __FILE__ );

				// Before the Tick so we skip completely if we're done
				if ( !$ret )
					$progress['initialise'] = $next_step;

				$bootstrap->Tick();

			} while ( $ret );

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// If online, add a synchronisation job
			if ( $progress['config']['target'] == 'online' ) {

				$progress['jobs'][] = array(
					'processor'		=> 'transmission',
					'progress'		=> 0,
					'progresslen'		=> 5,
					'retries'		=> 0,
					'action'		=> 'synchronise',
					'total_items'		=> 0,
					'total_generations'	=> 0,
					'done_items'		=> 0,
					'done_generations'	=> 0,
				);

			}

			$progress['initialise'] = $next_step;

			$bootstrap->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Are we backing up the database?
			if ( $progress['config']['backup_database'] ) {

				require_once WPONLINEBACKUP_PATH . '/include/tables.php';
				$tables = new WPOnlineBackup_Backup_Tables( $this->WPOnlineBackup, $this->db_force_master );

				// Initialise - pass ourself so we can log events, and also pass the progress and its tracker
				if ( true !== ( $ret = $tables->Initialise( $this, $progress ) ) )
					return $ret;

			}

			$progress['initialise'] = $next_step;

			$bootstrap->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Are we backing up the filesystem?
			if ( $progress['config']['backup_filesystem'] ) {

				require_once WPONLINEBACKUP_PATH . '/include/files.php';
				$files = new WPOnlineBackup_Backup_Files( $this->WPOnlineBackup, $this->db_force_master );

				// Initialise - pass ourselves so we can log events, and also pass the progress and its tracker
				if ( true !== ( $ret = $files->Initialise( $this, $progress ) ) )
					return $ret;

			}

			$progress['initialise'] = $next_step;

			$bootstrap->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			// Reconstruct the data files
			$progress['jobs'][] = array(
				'processor'	=> 'reconstruct',
				'progress'	=> 0,
				'progresslen'	=> 5,
			);

			if ( $progress['config']['target'] == 'online' ) {

				// Online needs to transmit
				$progress['jobs'][] = array(
					'processor'		=> 'transmission',
					'progress'		=> 0,
					'progresslen'		=> 10,
					'retries'		=> 0,
					'action'		=> 'transmit',
					'total'			=> 0,
					'done'			=> 0,
					'done_retention'	=> 0,
					'retention_size'	=> 0,
					'new_bsn'		=> 0,
					'wait'			=> false,
				);

			} else if ( $progress['config']['target'] == 'email' ) {

				// Email needs to email
				$progress['jobs'][] = array(
					'processor'		=> 'email',
					'progress'		=> 0,
					'progresslen'		=> 10,
					'retries'		=> 0,
				);

			}

			// Add the cleanups to the end of the job list so they happen only after the main backup jobs have finished
			$progress['jobs'] = array_merge( $progress['jobs'], $progress['cleanups'] );
			$progress['cleanups'] = array();

			// Add on the file cleanup - this places local backups in their correct place and deletes files for online backup etc.
			$progress['jobs'][] = array(
				'processor'		=> 'cleanupfiles',
				'progress'		=> 0,
				'progresslen'		=> 5,
			);

			// Retention for local backups should be done AFTER cleanupfiles, since cleanupfiles adds to the database the current backup and retention should take that into account
			if ( $progress['config']['target'] == 'download' ) {

				// Local needs retention
				$progress['jobs'][] = array(
					'processor'		=> 'localretention',
					'progress'		=> 0,
					'progresslen'		=> 5,
					'deleted_gens'		=> 0,
					'deleted_storage'	=> 0,
					'delete_error'		=> 0,
				);

			}

			// Total up the jobcount as total of all progresslens - makes calculating progress % easier
			foreach ( $progress['jobs'] as $job )
				$progress['jobcount'] += $job['progresslen'];

			$progress['initialise'] = $next_step;

			$bootstrap->Tick();

		}

		if ( $progress['initialise'] < ++$next_step ) {

			if ( $progress['config']['target'] == 'download' ) {

				// Create the local backup directory
				if ( true !== ( $ret = $bootstrap->Create_Dir_Local_Backup() ) )
					return $ret;

			}

			// Try to create the temporary backup directory
			if ( true !== ( $ret = $bootstrap->Create_Dir_Temporary() ) )
				return $ret;

			$progress['initialise'] = $next_step;

			// Force a save here since we did some file operation checks and it'll save us doing it again
			$bootstrap->Tick( false, true );

		}

		// Prepare the stream configuration
		// - the streams use this configuration instead of the central configuration so we can use different settings in different streams
		$config = array(
			'designated_path'	=> $progress['cache']['local_tmp_dir'],
			'compression'		=> $this->WPOnlineBackup->Get_Env( 'deflate_available' ) ? 'DEFLATE' : 'store',
			'encryption'		=> $progress['cache']['enc_type'],
			'encryption_key'	=> $progress['cache']['enc_key'],
		);

		// Set up the required stream
		if ( $progress['config']['target'] == 'online' ) {

			$stream_type = 'Stream_Delta';

		} else {

			$stream_type = 'Stream_Full';

		}

		require_once WPONLINEBACKUP_PATH . '/include/' . strtolower( $stream_type ) . '.php';

		$name = 'WPOnlineBackup_' . $stream_type;
		$stream = new $name( $this->WPOnlineBackup );

		// Open the file - suppress errors in html_entity_decode as PHP4 will flood out warnings about multibyte characters
		if ( ( $ret = $stream->Open( $config, @html_entity_decode( get_bloginfo('name'), ENT_QUOTES, get_bloginfo('charset') ), @html_entity_decode( get_bloginfo('description'), ENT_QUOTES, get_bloginfo('charset') ) ) ) !== true ) {

			return $bootstrap->Create_Failure();

		}

		if ( $progress['config']['target'] == 'email' ) {

			// Check we aren't too big to process. Add 50% to the filesize to allow for MIME encoding and headers etc, and take 5MB from Memory_Limit for processing
			$max = floor( ( ( $memory_limit = $this->WPOnlineBackup->Memory_Limit() ) - 5*1024*1024 ) / 2.5 );

		}

		// Store the steam - we cannot store it in this->stream until it is Opened because we call CleanUp in On_Shutdown if an error occurs and CleanUp can not be called until after Open or Load
		$bootstrap->Set_Stream( $stream_type, $stream );

		return true;
	}

	/*public*/ function Set_Stream( $stream_type, & $stream )
	{
		$this->stream = & $stream;

		// Store the stream state so we can load it when performing
		$this->status['progress']['file'] = array(
			'type'	=> $stream_type,
			'state'	=> $this->stream->Save(),
		);
	}

	/*public*/ function Create_Dir_Local_Backup()
	{
		// Create the local backup directory
		// The web server must be able to serve files from the local backups directory so we can't risk setting 0700; it may cause the web server to lose access if the server config is weird
		if ( !@file_exists( $this->status['progress']['cache']['backup_directory'] ) && false === @mkdir( $this->status['progress']['cache']['backup_directory'] ) ) {

			// Grab the error
			$ret = OBFW_Tidy_Exception();

			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The directory (%s) where local backups will be stored could not be created.', 'wponlinebackup' ), $this->status['progress']['cache']['backup_directory'] ) . PHP_EOL .
				sprintf( __( 'You may need to login via FTP and create the folder yourself and "CHMOD" it to "0770" or, as a last resort, "0777". You should also create the temporary processing directory (%s) with the same permissions.', 'wponlinebackup' ), $this->status['progress']['cache']['local_tmp_dir'] ) . PHP_EOL .
				sprintf( __( 'Error: %s', 'wponlinebackup' ), $ret )
			);

			return __( 'Unable to create the local backup directory.', 'wponlinebackup' );

		}

		$ret = true;

		// Prevent browsing by dropping in a blank index.html if there isn't one
		if ( !@file_exists( $this->status['progress']['cache']['backup_directory'] . '/index.html' ) )
			$ret = @copy( WPONLINEBACKUP_PATH . '/index.html', $this->status['progress']['cache']['backup_directory'] . '/index.html' );

		// If we failed to copy, report a write problem - or if we didn't copy because index.html already existed, try to write to it with touch which only touches modification time
		if ( false === $ret || false === @touch( $this->status['progress']['cache']['backup_directory'] . '/index.html' ) ) {

			$ret = OBFW_Tidy_Exception();

			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The directory (%s) where local backups will be stored cannot be written to.', 'wponlinebackup' ), $this->status['progress']['cache']['backup_directory'] ) . PHP_EOL .
				sprintf( __( 'You may need to login via FTP and "CHMOD" it to "0770" or, as a last resort, "0777". You should also check the temporary processing directory (%s) has the same permissions, also creating the folder if necessary.', 'wponlinebackup' ), $this->status['progress']['cache']['local_tmp_dir'] ) . PHP_EOL .
				sprintf( __( 'Error: %s', 'wponlinebackup' ), $ret )
			);

			return __( 'Unable to write to the local backup directory.', 'wponlinebackup' );

		}

		return true;
	}

	/*public*/ function Create_Dir_Temporary()
	{
		// Try to create the temporary backup directory
		if ( !@file_exists( $this->status['progress']['cache']['local_tmp_dir'] ) && false === @mkdir( $this->status['progress']['cache']['local_tmp_dir'], 0700 ) ) {

			// Log the error
			$ret = OBFW_Tidy_Exception();

			$this->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The temporary processing directory (%s) where data is processed could not be created.', 'wponlinebackup' ), $this->status['progress']['cache']['local_tmp_dir'] ) . PHP_EOL .
				__( 'You may need to login via FTP and create the folder yourself and "CHMOD" it to "0770" or, as a last resort, "0777".', 'wponlinebackup' ) . PHP_EOL .
				sprintf( __( 'Error: %s', 'wponlinebackup' ), $ret )
			);

			return __( 'Unable to create the temporary processing directory.', 'wponlinebackup' );

		} else {

			// Ensure 0700 if we can
			@chmod( $this->status['progress']['cache']['local_tmp_dir'], 0700 );

		}

		// Check we have a .htaccess and attempt to copy one in
		if ( !@file_exists( $this->status['progress']['cache']['local_tmp_dir'] . '/.htaccess' ) )
			@copy( WPONLINEBACKUP_PATH . '/tmp.httpd', $this->status['progress']['cache']['local_tmp_dir'] . '/.htaccess' );

		// Prevent browsing by dropping in a blank index.html if there isn't one
		if ( !@file_exists( $this->status['progress']['cache']['backup_directory'] . '/index.html' ) )
			@copy( WPONLINEBACKUP_PATH . '/index.html', $this->status['progress']['cache']['backup_directory'] . '/index.html' );

		// This now gives us three fold security, number 3 being the 100% protection and the others just to deter and out of principle
		// 1. tmp folderwill have no permissions for the web server and only for PHP on secure servers
		// 2. .htaccess will protect the folder if the server is Apache or a compatible equivalent
		// 3. The data files are PHP and the PHP at the start stops people accessing them to get the data inside them

		return true;
	}

	/*public*/ function Create_Failure()
	{
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_ERROR,
			sprintf( __( 'The temporary data file could not be created in the temporary processing directory (%s).', 'wponlinebackup' ), $this->status['progress']['cache']['local_tmp_dir'] ) . PHP_EOL .
			__( 'If the below error is related to permissions, you may need to login to your website via FTP and change the permissions on the temporary processing directory. The permissions required, in numeric form, are normally 0770, but on some servers you may need to use 0777. Your host will be able to assist if you have any doubts.', 'wponlinebackup' ) . PHP_EOL .
			sprintf( __( 'Error: %s', 'wponlinebackup' ), OBFW_Exception() )
		);

		return __( 'Unable to create the temporary data file.', 'wponlinebackup' );
	}

	/*private*/ function Save_Processors()
	{
		$keys = array_keys( $this->processors );

		// For each processor we have loaded, clean it up
		foreach ( $keys as $key )
			$this->processors[ $key ]->Save();
	}

	/*private*/ function CleanUp_Processors( $ticking = false )
	{
		$keys = array_keys( $this->processors );

		// For each processor we have loaded, clean it up
		foreach ( $keys as $key )
			$this->processors[ $key ]->CleanUp( $ticking );
	}

	/*private*/ function & Fetch_Processor( $processor )
	{
		// If we don't have the processor loaded already, load it
		if ( !array_key_exists( $processor, $this->processors ) ) {

			require_once WPONLINEBACKUP_PATH . '/include/' . $processor . '.php';

			$class = 'WPOnlineBackup_Backup_' . ucfirst( $processor );
			$this->processors[$processor] = new $class( $this->WPOnlineBackup, $this->db_force_master );

		}

		return $this->processors[$processor];
	}

	/*private*/ function Backup()
	{
		// Iterate through keys so we can grab references
		$keys = array_keys( $this->status['progress']['jobs'] );

		$ret = true;

		foreach ( $keys as $key ) {

			$job = & $this->status['progress']['jobs'][$key];

			// Call the correct processor for this job
			switch ( $job['processor'] ) {

				case 'tables':
				case 'files':
				case 'transmission':
				case 'email':
				case 'decrypt':
					$processor = & $this->Fetch_Processor( $job['processor'] );
					if ( ( $ret = $processor->Backup( $this, $this->stream, $this->status['progress'], $job ) ) !== true )
						break 2;
					break;

				case 'reconstruct':
					if ( ( $ret = $this->Reconstruct( $job ) ) !== true )
						break 2;
					break;

				case 'cleanupfiles':
					if ( ( $ret = $this->CleanUp_Files( $job ) ) !== true )
						break 2;
					break;

				case 'localretention':
					if ( ( $ret = $this->Local_Retention( $job ) ) !== true )
						break 2;
					break;

			}

			// Job done - increase progress and drop the job
			$this->status['progress']['jobdone'] += $job['progresslen'];

			unset( $this->status['progress']['jobs'][$key] );

			// Force an update at the end of each job
			$this->Tick( false, true );

		}

		return $ret;
	}

	/*private*/ function Reconstruct( & $job )
	{
		// Update the message
		if ( $job['progress'] == 0 ) {

			$this->status['progress']['message'] = __( 'Finalising...', 'wponlinebackup' );

			$job['progress'] = 1;

			$this->Tick( false, true );

		}

		// Flush all data
		if ( $job['progress'] == 1 ) {

			if ( ( $ret = $this->stream->Flush() ) !== true ) return $ret;

			$job['progress'] = 20;

			$this->Tick( false, true );

		}

		// Close all files
		if ( $job['progress'] == 20 ) {

			if ( ( $ret = $this->stream->Close() ) !== true ) return $ret;

			$job['progress'] = 40;

			$this->Tick();

		}

		// Prepare for reconstruction
		if ( $job['progress'] == 40 ) {

			if ( ( $ret = $this->stream->Start_Reconstruct() ) !== true ) return $ret;

			$job['progress'] = 60;

			$this->Tick();

		}

		// Reconstruct any files that fragmented due to timeouts
		if ( $job['progress'] == 60 ) {

			while ( ( $ret = $this->stream->Do_Reconstruct() ) === true ) {

				$this->Tick();

			}

			if ( !is_array( $ret ) )
				return $ret;

			// Store the resulting file set
			$this->status['progress']['file_set'] = array_merge(
				$ret,
				array(
					'files'		=> $this->stream->Files(),
					'compressed'	=> $this->stream->Is_Compressed() ? 1 : 0,
					'encrypted'	=> $this->stream->Is_Encrypted() ? 1 : 0,
				)
			);

			$job['progress'] = 95;

			$this->Tick( false, true );

		}

		// End reconstruction - remove any left temporary files etc
		if ( ( $ret = $this->stream->End_Reconstruct() ) !== true )
			return $ret;

		// All done, destroy the stream
		$this->stream = null;

		$job['progress'] = 100;

		return true;
	}

	/*private*/ function CleanUp_Files( $job )
	{
		if ( $this->status['progress']['type'] == WPONLINEBACKUP_ACTIVITY_DECRYPT ) {

			return $this->CleanUp_Files_Decrypt();

		}

		return $this->CleanUp_Files_Backup();
	}

	/*private*/ function CleanUp_Files_Decrypt()
	{
		global $wpdb;

		// The name is in the config
		$name = $this->status['progress']['config']['decrypted_file'];

		$esc_name = $name;
		$wpdb->escape_by_ref( $esc_name );

		// Allocate a file - store it as locked so we don't remove it during retention
		$wpdb->query(
			'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_local` (filename, filesize, creation_date, compressed, encrypted, locked) ' .
			'VALUES (\'' . $esc_name . '\', ' . $this->status['progress']['file_set']['size'] . ', ' . $this->status['progress']['start_time'] . ', ' . $this->status['progress']['file_set']['compressed'] . ', 0, 1) ' .
			'ON DUPLICATE KEY UPDATE filesize = VALUES(filesize), creation_date = VALUES(creation_date), compressed = VALUES(compressed), encrypted = VALUES(encrypted), locked = VALUES(locked)'
		);

		// Move it into place
		@rename( $this->status['progress']['file_set']['file'], $this->status['progress']['cache']['backup_directory'] . '/' . $name );

		// Store the file name
		$this->status['progress']['download_file'] = $name;

		// Log an event
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			sprintf( __( 'Stored the decrypted file locally as %s.', 'wponlinebackup' ), $name )
		);

		$job['progress'] = 100;

		// Prevent duplicate log events
		$this->Tick( false, true );

		return true;
	}

	/*private*/ function CleanUp_Files_Backup()
	{
		if ( $this->status['progress']['config']['target'] == 'online' ) {

			// Update the backup serial number
			update_option( 'wponlinebackup_bsn', $this->status['progress']['bsn'] );

			// Cleanup the files, we don't keep them for online backups
			foreach ( $this->status['progress']['file_set']['file'] as $file )
				@unlink( $file );

			$this->status['progress']['file_set']['size'] = array_sum( $this->status['progress']['file_set']['size'] );

		} else if ( $this->status['progress']['config']['target'] == 'email' ) {

			// We emailed the backup - no longer need to keep it
			@unlink( $this->status['progress']['file_set']['file'] );

		} else {

			global $wpdb;

			$name = sprintf(
				__( 'WPOnlineBackup_%1$s_%2$s_%3$s', 'Downloadable backup filename, %1$s is date, %2$s is activity identifier and %3$s is a random nonce to hide the file', 'wponlinebackup' ),
				date( 'd-m-Y_H-i-s', $this->status['progress']['start_time'] ),
				$this->activity_id,
				$this->WPOnlineBackup->Random_Seed( $this->last_tick_status, 'download' )
			);

			// Grab the extension from our file_set file, if we fail - give no extension... that would be a bug
			if ( preg_match( '#((?:\\.[a-z]+)+)\\.[A-Za-z0-9]+\\.php$#i', $this->status['progress']['file_set']['file'], $matches ) )
				$name .= $matches[1];

			$esc_name = $name;
			$wpdb->escape_by_ref( $esc_name );

			// Allocate a file
			$wpdb->query(
				'REPLACE INTO `' . $this->db_prefix . 'wponlinebackup_local` (filename, filesize, creation_date, compressed, encrypted) ' .
				'VALUES (\'' . $esc_name . '\', ' . $this->status['progress']['file_set']['size'] . ', ' . $this->status['progress']['start_time'] . ', ' . $this->status['progress']['file_set']['compressed'] . ', ' . $this->status['progress']['file_set']['encrypted'] . ')'
			);

			// Move it into place
			if ( false === @rename( $this->status['progress']['file_set']['file'], $this->status['progress']['cache']['backup_directory'] . '/' . $name ) ) {

				// Grab the error
				$ret = OBFW_Tidy_Exception();

				// Delete it from the database - we add it before moving as the page view can add non-existant entries with NULL data and we don't want to do that
				$wpdb->query( 'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_local` WHERE filename = \'' . $esc_name . '\'' );

				// Log the error
				$this->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'An error occurred trying to save the backup to the local backup folder at %s.', 'wponlinebackup' ), $this->status['progress']['cache']['backup_directory'] ) . PHP_EOL .
					sprintf( __( 'Error: %s', 'wponlinebackup' ), $ret )
				);

				return __( 'Failed to save the backup to local storage.', 'wponlinebackup' );

			}

			// Store the file name
			$this->status['progress']['download_file'] = $name;

			// Log an event
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( __( 'Stored the backup locally as %s.', 'wponlinebackup' ), $name )
			);

		}

		$job['progress'] = 100;

		// Prevent duplicate log events
		$this->Tick( false, true );

		return true;
	}

	/*private*/ function Local_Retention( $job )
	{
		global $wpdb;

		// Grab info on existing local storage
		$current = $wpdb->get_row(
			'SELECT COUNT(*) AS gens, SUM(filesize) AS storage ' .
			'FROM `' . $this->db_prefix . 'wponlinebackup_local` ' .
			'WHERE locked = 0',
			ARRAY_A
		);

		if ( is_null( $current ) )
			return $this->DBError( __FILE__, __LINE__ );

		// Grab minimum generation count to keep
		$min_gens = $this->WPOnlineBackup->Get_Setting( 'local_min_gens' );

		// Grab maximum size for local backup storage - it's in MiB so multiply to get bytes
		$max_storage = $this->WPOnlineBackup->Get_Setting( 'local_max_storage' ) * 1048576;

		if ( $job['progress'] == 0 ) {

			// Log an event to show current storage amount
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( _n(
					'Local Backups contains %1$s generation with a total size of %2$s.',
					'Local Backups contains %1$s generations with a total size of %2$s.',
					$current['gens']
				, 'wponlinebackup' ), $current['gens'], WPOnlineBackup_Formatting::Fix_B( $current['storage'], true ) )
			);

			$job['progress'] = 25;

			// Set the message
			$this->status['progress']['message'] = __( 'Performing retention...', 'wponlinebackup' );

			// Prevent duplicate log entries
			$this->Tick( false, true );

		}

		if ( $job['progress'] == 25 ) {

			// If we're storing min_gens or less, no retention needed
			// Also, if storage is not full or gone over, no retention needed
			if ( $current['gens'] <= $min_gens || $current['storage'] <= $max_storage ) {

				// Log that no retention was required and mark as complete
				$this->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					__( 'Retention is not required.', 'wponlinebackup' )
				);

				$job['progress'] = 100;

				// Prevent duplicate log entries
				$this->Tick( false, true );

				return true;

			}

			$job['progress'] = 50;

		}

		if ( $job['progress'] == 50 ) {

			// Start removing the oldest backups until we're within the threshold
			while ( true ) {

				// Grab oldest 5
				$result = $wpdb->get_results(
					'SELECT filename, filesize ' .
					'FROM `' . $this->db_prefix . 'wponlinebackup_local` ' .
					'WHERE locked = 0 ' .
					'ORDER BY creation_date ASC ' .
					'LIMIT 5',
					ARRAY_A
				);

				if ( count( $result ) == 0 )
					break;

				foreach ( $result as $row ) {

					// Delete it - if it's not there assume deleted (user may have deleted manually)
					if ( !file_exists( $this->status['progress']['cache']['backup_directory'] . '/' . $row['filename'] ) || false !== @unlink( $this->status['progress']['cache']['backup_directory'] . '/' . $row['filename'] ) ) {

						// Drop from database
						$esc_filename = $row['filename'];
						$wpdb->escape_by_ref( $esc_filename );

						$wpdb->query(
							'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_local` ' .
							'WHERE filename = \'' . $esc_filename . '\''
						);

						$update = false;

					} else {

						$err = OBFW_Tidy_Exception();

						// Error!
						$this->Log_Event(
							WPONLINEBACKUP_EVENT_ERROR,
							sprintf( __( 'Failed to delete local backup %1$s: %2$s', 'wponlinebackup' ), $row['filename'], $err )
						);

						// Mark as error occured so we can log a bit of info after retention completes
						$job['delete_error'] = 1;

						// Force update to prevent dupe messages
						$update = true;

					}

					// Log how much we deleted - even if we failed so we don't remove backups we SHOULD be keeping
					$job['deleted_gens']++;
					$job['deleted_storage'] += $row['filesize'];

					// If we've now dropped the storage enough, leave the loops
					if ( $current['gens'] - $job['deleted_gens'] <= $min_gens || $current['storage'] - $job['deleted_storage'] <= $max_storage )
						break 2;

					$this->Tick( false, $update );

				}

			}

			$job['progress'] = 95;

			// Force an update so we don't need to loop again
			$this->Tick( false, true );

		}

		if ( $job['delete_error'] ) {

			// Explain that retention might grow larger than normal
			$this->Log_Event(
				WPONLINEBACKUP_EVENT_WARNING,
				__( 'Errors were encountered trying to delete one or more Local Backups. Disk space used by Local Backups will be higher than configured until they can be successfully removed.', 'wponlinebackup' )
			);

		}

		// Mark as complete and log retention completed
		$this->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			sprintf( _n(
				'Retention completed; deleted %d file with a total size of %s.',
				'Retention completed; deleted %d files with a total size of %s.',
				$job['deleted_gens']
			, 'wponlinebackup' ), $job['deleted_gens'], WPOnlineBackup_Formatting::Fix_B( $job['deleted_storage'], true ) )
		);

		$job['progress'] = 100;

		// Prevent duplicate log entries
		$this->Tick( false, true );

		return true;
	}

	/*public*/ function Process_Pull()
	{
		// Load status
		$this->Load_Status();

		// Clear any data we have in any WordPress buffers - should not get much due to POST but just in case
		$this->_Clear_Output_Buffers();

		// Avoid timeouts and do not ignore a client abort
		@set_time_limit( 0 );
		@ignore_user_abort( false );

		// Turn off HTML errors to try and ensure the data stream doesn't get tainted by any
		ini_set( 'display_errors', 0 );
		ini_set( 'html_errors', 0 );

		// Check we have a backup running
		if ( $this->status['status'] != WPONLINEBACKUP_STATUS_RUNNING && $this->status['status'] != WPONLINEBACKUP_STATUS_TICKING && $this->status['status'] != WPONLINEBACKUP_STATUS_CHECKING )
			return $this->_Pull_Failure( 'OBFWRF' . $this->status['status'] . ':' . ( isset( $this->status['progress']['comp'] ) ? $this->status['progress']['comp'] : '?' ) );

		// If we're not an online backup, we shouldn't be retrieving
		if ( $this->status['progress']['config']['target'] != 'online' )
			return $this->_Pull_Failure( 'OBFWRI2' );

		// Grab the variables
		$nonce = isset( $_GET['wponlinebackup_fetch'] ) ? strval( $_GET['wponlinebackup_fetch'] ) : strval( $_POST['wponlinebackup_fetch'] );
		$which = isset( $_GET['which'] ) ? strval( $_GET['which'] ) : ( isset( $_POST['which'] ) ? strval( $_POST['which'] ) : '' );
		$start = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : ( isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 0 );

		// Check the nonce
		if ( $this->status['progress']['nonce'] == '' )
			return $this->_Pull_Failure( 'OBFWRI3' );
		else if ( $nonce != $this->status['progress']['nonce'] )
			return $this->_Pull_Failure( 'OBFWRI4' );

		// Make sure which is acceptable
		$which = ( $which == 'data' ? 'data' : 'indx' );

		// Check we're not starting the transfer past the end of the file
		if ( $start > $this->status['progress']['file_set']['size'][$which] )
			return $this->_Pull_Failure( 'OBFWRE1' );

		// Open the requested file - open in binary mode! We don't want any conversions happening
		// If this fails - we treat as a temporary condition since maybe the file isn't quite ready
		if ( ( $f = @fopen( $this->status['progress']['file_set']['file'][$which], 'rb' ) ) === false )
			return $this->_Pull_Delay( 'OBFWRE2 ' . OBFW_Exception() );

		if ( @fseek( $f, $this->status['progress']['file_set']['offset'][$which] + $start, SEEK_SET ) != 0 ) {
			$ret = OBFW_Exception();
			@fclose( $f );
			return $this->_Pull_Failure( 'OBFWRE3 ' . $ret );
		}

		// Send through a content-type header to stop any CDN or rogue plugin modifying our binary stream
		// We had an instance where 0x09 (tab) was getting replaced with 0x20 (space), corrupting the data stream
		header( 'Content-Type: application/octet-stream' );

		// Send the length of the data we're about to pass through - this is OBFWRD (6) + Length of nonce + File Size - Start position
		header( 'Content-Length: ' . ( $this->status['progress']['file_set']['size'][$which] - $start + strlen( $this->status['progress']['nonce'] ) + 6 ) );
		header( 'Content-Disposition: attachment; filename="backup.' . $which . '"' );

		// Print the validation header
		echo 'OBFWRD' . $this->status['progress']['nonce'];

		// Passthrough
		@fpassthru( $f );
		@fclose( $f );

		return $this->_End_Request();
	}

	/*private*/ function _Pull_Failure( $message )
	{
		// This tells the server we failed - octet-stream to prevent conversions of line endings so we remain consistent
		header( 'HTTP/1.1 500 Service Unavailable' );
		header( 'Content-Type: application/octet-stream' );

		// And the reason for failure
		echo $message;

		return $this->_End_Request();
	}

	/*private*/ function _Pull_Delay( $message )
	{
		// This header tells the server to try again - octet-stream to prevent conversions of line endings so we remain consistent
		header( 'HTTP/1.1 503 Service Unavailable' );
		header( 'Content-Type: application/octet-stream' );

		// If the server has tried again multiple times, it'll use this message as the reason
		echo $message;

		return $this->_End_Request();
	}

	/*private*/ function _Clear_Output_Buffers()
	{
		// Grab number of output buffer levels, then free each one
		// DO NOT use ob_get_level() in a loop, some output buffers can't be freed and cause an infinite loop since ob_get_level() can never reach 0
		$cnt = ob_get_level();
		while ( $cnt-- > 0 )
			ob_end_clean();
	}

	/*private*/ function _End_Request()
	{
		// Capture any post-request junk - POST should have resolved most of this but double check
		ob_start( array( & $this, '_Prevent_Output' ), 4096 );

		return true;
	}

	/*public*/ function _Prevent_Output( $in )
	{
		// This is registered with ob_start() in Perform() to prevent output from the script, and Process_Pull() to prevent junk problems
		return '';
	}
}

?>

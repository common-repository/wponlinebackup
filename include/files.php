<?php

/*
WPOnlineBackup_Backup_Files - Workhouse for the file based backup
We pass it the stream we want it to use to store the data
It can be configured to do delta based on the backup blog in the database (for online backup or local backup)
Alternatively, we can configure it to do a full backup to an archive that users can download
*/

define( 'WPONLINEBACKUP_ITEM_FOLDER',			0 );
define( 'WPONLINEBACKUP_ITEM_FILE',			1 );

define( 'WPONLINEBACKUP_FILE_EXCLUDE_LOCALTMPDIR', 	0 );
define( 'WPONLINEBACKUP_FILE_EXCLUDE_GZIPTMPDIR', 	1 );
define( 'WPONLINEBACKUP_FILE_EXCLUDE_THEMES',	 	2 );
define( 'WPONLINEBACKUP_FILE_EXCLUDE_PLUGINS',	 	3 );
define( 'WPONLINEBACKUP_FILE_EXCLUDE_PLUGINS_MU', 	4 );
define( 'WPONLINEBACKUP_FILE_EXCLUDE_UPLOADS',	 	5 );
define( 'WPONLINEBACKUP_FILE_EXCLUDE_LOCALBACKUPDIR',	6 );

define( 'WPONLINEBACKUP_FILE_EXCLUDE_CUSTOM',	 	1000000 );

class WPOnlineBackup_Backup_Files
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $bootstrap;
	/*private*/ var $stream;
	/*private*/ var $progress;
	/*private*/ var $job;

	/*private*/ var $folder_id;

	/*private*/ var $blog_folder;
	/*private*/ var $blog_file;

	/*private*/ var $handles;
	/*private*/ var $handle_limit;
	/*private*/ var $file_failed_msg;
	/*private*/ var $file_taken_msg;

	/*private*/ var $db_prefix;
	/*private*/ var $db_force_master;

	/*public*/ function WPOnlineBackup_Backup_Files( & $WPOnlineBackup, $db_force_master = '' )
	{
		global $wpdb;

		require_once WPONLINEBACKUP_PATH . '/include/functions.php';

		$this->WPOnlineBackup = & $WPOnlineBackup;

		$this->db_prefix = $wpdb->prefix;
		$this->db_force_master = $db_force_master;

		$this->handles = array();
		$this->handle_limit = $this->WPOnlineBackup->Get_Setting( 'handle_limit' );
	}

	/*private*/ function Get_Exclude_Reason( $exclude )
	{
		switch ( $exclude ) {

			case WPONLINEBACKUP_FILE_EXCLUDE_LOCALTMPDIR:
				return __( 'Temporary backup directory', 'wponlinebackup' );

			case WPONLINEBACKUP_FILE_EXCLUDE_GZIPTMPDIR:
				return __( 'Temporary compression directory', 'wponlinebackup' );

			case WPONLINEBACKUP_FILE_EXCLUDE_LOCALBACKUPDIR:
				return __( 'Local backups directory', 'wponlinebackup' );

			case WPONLINEBACKUP_FILE_EXCLUDE_THEMES:
				return __( 'Themes directory', 'wponlinebackup' );

			case WPONLINEBACKUP_FILE_EXCLUDE_PLUGINS:
				return __( 'Plugins directory', 'wponlinebackup' );

			case WPONLINEBACKUP_FILE_EXCLUDE_PLUGINS_MU:
				return __( 'Multisite plugins directory', 'wponlinebackup' );

			case WPONLINEBACKUP_FILE_EXCLUDE_UPLOADS:
				return __( 'Uploads directory', 'wponlinebackup' );

		}

		return __( 'Custom exclude', 'wponlinebackup' );
	}

	/*public*/ function Normalise_Path( $path )
	{
		// Strip duplicate slashes and on Windows turn any backslashes into forward slashes while maintaining UNC
		if ( preg_match( '#^([/' . ( DIRECTORY_SEPARATOR == '\\' ? '\\\\' : '' ) . ']{2})#', $path, $matches ) ) {
			$c = substr( $matches[1], 0, 1 );
		} else {
			$c = '';
		}

		$c .= preg_replace( '#[/' . ( DIRECTORY_SEPARATOR == '\\' ? '\\\\' : '' ) . ']+#', '/', $path );

		if ( substr( $c, -1 ) == DIRECTORY_SEPARATOR )
			$c = substr( $c, 0, -1 );

		return $c;
	}

	/*public*/ function Get_Roots()
	{
		// First strip any trailing slashes as we don't want them
		$root = preg_replace( '#(?:' . ( DIRECTORY_SEPARATOR == '\\' ? '\\\\|' : '' ) . '/)$#', '', ABSPATH );

		// Normalise the root path - along with excludes these are the only inputs that will have directory separators in them - everywhere else we build the path ourselves with a single forward slash so will never need to normalise anywhere else
		$root = $this->Normalise_Path( $root );

		// Always have the root as the folder just above WordPress - we'll just change the stack to only do the WordPress folder if needed
		// We'll check the access to this folder later on, but only if we've enabled filesystem_upone
		// And if filesystem_upone won't work due to permission issues, it will automatically proceed without it
		// (This is done during the Init_Job() call when we start scanning because it is not fatal to the backup)
		$parent_root = dirname( $root );
		$wordpress_root = basename( $root );

		return array( $root, $parent_root, $wordpress_root );
	}

	/*public*/ function Initialise( & $bootstrap, & $progress )
	{
		global $wpdb;

		$counter = 0;

		list ( $root, $parent_root, $wordpress_root ) = $this->Get_Roots();

		// Just check we can access the WordPress path
		if ( ( $d = @opendir( $root ) ) === false ) {

			$ret = OBFW_Tidy_Exception();

			$bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				// For consistency, normalise the root before placing it in the error message
				sprintf( __( 'Skipping filesystem backup due to an error: Could not access %s. %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $this->Normalise_Path( $root ) ), $ret )
			);

			// Return true so we don't cause backup to completely die, as we may still have backed up the database OK
			return true;

		}

		@closedir( $d );

		// Start generating the excludes
		$excludes = array();

		// Default excludes - we always exclude the backup data temporary directory, and the global temporary direct (in case we relocated it in advanced settings screen)
		$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_LOCALTMPDIR ] = $progress['cache']['local_tmp_dir'];
		$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_GZIPTMPDIR ] = $this->WPOnlineBackup->Get_Setting( 'gzip_tmp_dir' );
		$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_LOCALBACKUPDIR ] = $progress['cache']['backup_directory'];

		// Build additional excludes
		if ( !$this->WPOnlineBackup->Get_Setting( 'filesystem_themes' ) ) {

			$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_THEMES ] = get_theme_root();

		}

		if ( !$this->WPOnlineBackup->Get_Setting( 'filesystem_plugins' ) ) {

			// This seems the safest way to get the plugin paths
			$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_PLUGINS ] = WP_PLUGIN_DIR;
			$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_PLUGINS_MU ] = WPMU_PLUGIN_DIR;

		}

		if ( !$this->WPOnlineBackup->Get_Setting( 'filesystem_uploads' ) ) {

			// Load upload directory information
			$uploads = wp_upload_dir();

			$excludes[ WPONLINEBACKUP_FILE_EXCLUDE_UPLOADS ] = $uploads['basedir'];

		}

		// Normalise the built-in excludes
		foreach ( $excludes as $key => $exclude_item )
			$excludes[ $key ] = $this->Normalise_Path( $exclude_item );

		// Grab custom excludes and add them to the list
		$custom_excludes = preg_split( '#\\s*(\\r\\n?|\\n)\\s*#', $this->WPOnlineBackup->Get_Setting( 'filesystem_excludes' ), -1, PREG_SPLIT_NO_EMPTY );

		// Start custom excludes from the index of the last reason
		$key = WPONLINEBACKUP_FILE_EXCLUDE_CUSTOM;

		foreach ( $custom_excludes as $exclude_item ) {

			// If prefixed with ../ then take relative to the parent folder if we have one - ignoring the exclude if we don't
			// Otherwise, take relative to the wordpress folder
			if ( preg_match( '#^\\.\\./#', $exclude_item, $matches ) ) {

				if ( $wordpress_root == '' )
					continue;

				$exclude_item = substr( $exclude_item, strlen( $matches[0] ) );

				if ( $exclude_item != '' )
					$excludes[$key++] = $parent_root . '/' . $exclude_item;

			} else {

				$excludes[$key++] = $root . '/' . $exclude_item;

			}

		}

		// Populate the jobs we require
		// Remember that excludes are generated when the job first runs because we need to have calculated the root directory
		if ( $progress['config']['target'] == 'online' ) {

			$progress['jobs'][] = array(
				'processor'	=> 'files',
				'progress'	=> 0,
				'progresslen'	=> 20,
				'action'	=> 'scan',
				'root'		=> & $parent_root,
				'wordpress'	=> & $wordpress_root,
				'excludes'	=> & $excludes,
				'stack'		=> array(),
				'counter'	=> & $counter,
				'scan_id'	=> 0,
				'guess_total'	=> 0,
				'done'		=> 0,
			);

			$progress['jobs'][] = array(
				'processor'	=> 'files',
				'progress'	=> 0,
				'progresslen'	=> 20,
				'action'	=> 'collect',
				'root'		=> & $parent_root,
				'last_id'	=> 0,
				'total'		=> 0,
				'done'		=> 0,
				'generation'	=> time(),
			);

			$progress['jobs'][] = array(
				'processor'	=> 'files',
				'progress'	=> 0,
				'progresslen'	=> 5,
				'action'	=> 'mark',
				'root'		=> & $parent_root,
				'counter'	=> & $counter,
				'last_id'	=> 0,
				'total'		=> 0,
				'done'		=> 0,
				'deletion_time'	=> time(),
			);

			$progress['cleanups'][] = array(
				'processor'	=> 'files',
				'progress'	=> 0,
				'progresslen'	=> 5,
				'action'	=> 'commit',
				'counter'	=> & $counter,
			);

		} else {

			$progress['jobs'][] = array(
				'processor'	=> 'files',
				'progress'	=> 0,
				'progresslen'	=> 45,
				'action'	=> 'backup',
				'root'		=> & $parent_root,
				'wordpress'	=> & $wordpress_root,
				'excludes'	=> & $excludes,
				'counter'	=> & $counter,
				'stack'		=> array(),
				'scan_id'	=> 0,
				'guess_total'	=> 0,
				'done'		=> 0,
				'generation'	=> time(),
			);

			$progress['cleanups'][] = array(
				'processor'	=> 'files',
				'progress'	=> 0,
				'progresslen'	=> 5,
				'action'	=> 'cleanup',
				'counter'	=> & $counter,
			);

		}

		// Return the progress tracker
		return true;
	}

	/*public*/ function Save()
	{
	}

	/*public*/ function CleanUp( $ticking = false )
	{
		foreach ( $this->handles as $handle )
			@closedir( $handle );

		$this->handles = array();
	}

	/*public*/ function Init_Job()
	{
		global $wpdb;

		// Ensure scan log database is clean
		if ( $this->job['progress'] == 0 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_scan_log` ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 1;

		}

		// Remove items we did not submit to online...
		if ( $this->job['progress'] == 1 ) {

			// ...but only if we are sending to online
			if ( $this->progress['config']['target'] == 'online' ) {

				do {

					if ( ( $ret = $wpdb->query(
						'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
						'WHERE `exists` IS NULL ' .
						'LIMIT 500'
					) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

					$this->bootstrap->Tick();

				} while ( $ret );

			}

			$this->job['progress'] = 2;

		}

		// Ensure items table is ready if we are doing online backup
		if ( $this->job['progress'] == 2 ) {

			if ( $this->progress['config']['target'] == 'online' ) {

				do {

					if ( ( $ret = $wpdb->query(
						'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
						'SET backup = 0 ' .
						'WHERE backup = 1 ' .
						'LIMIT 500'
					) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

					$this->bootstrap->Tick();

				} while ( $ret );

			}

			$this->job['progress'] = 3;

		}

		// Cleanup items that were marked deleted and not commited
		if ( $this->job['progress'] == 3 ) {

			if ( $this->progress['config']['target'] == 'online' ) {

				do {

					if ( ( $ret = $wpdb->query(
						'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
						'SET ' .
							'new_exists = NULL ' .
						'WHERE new_exists IS NOT NULL ' .
						'LIMIT 500'
					) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

					$this->bootstrap->Tick();

				} while ( $ret );

			}

			$this->job['progress'] = 4;

		}

		// Cleanup generations that were marked deleted and not commited
		if ( $this->job['progress'] == 4 ) {

			if ( $this->progress['config']['target'] == 'online' ) {

				do {

					if ( ( $ret = $wpdb->query(
						'UPDATE `' . $this->db_prefix . 'wponlinebackup_generations` ' .
						'SET ' .
							'new_deleted_time = NULL ' .
						'WHERE new_deleted_time IS NOT NULL ' .
						'LIMIT 500'
					) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

					$this->bootstrap->Tick();

				} while ( $ret );

			}

			$this->job['progress'] = 5;

		}

		// Prepare wordpress folder for insert
		$insert_name = $this->job['wordpress'];

		// Jose: Are you in the roooooooooot!?
		// Directly disable filesystem_upone if we're in the root of the filesystem - it shouldn't be allowed to be enabled in that case anyway
		if ( $insert_name == '' ) $upone = false;
		else $upone = $this->WPOnlineBackup->Get_Setting( 'filesystem_upone' );

		$report_fallback = false;

		// This loop lets us temporarily disable filesystem_upone and repeat the parent folder resolving if we can't access the parent folder
		while (42) {

			if ( $upone ) {

				// Prepare wordpress folder for insert
				$insert_name_bin = $insert_name;
				$wpdb->escape_by_ref( $insert_name_bin );

				WPOnlineBackup_Functions::UTF8_Validate( $insert_name );
				$wpdb->escape_by_ref( $insert_name );

				// Prefill the entry - this will be getting updated during the scan so we can ignore the backup field
				if ( $wpdb->query(
					'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_items` (bin, item_id, parent_id, type, name, name_bin, path, activity_id, counter) ' .
					'VALUES (' .
						WPONLINEBACKUP_BIN_FILESYSTEM . ', ' .
						'1, ' .
						'0, ' .
						WPONLINEBACKUP_ITEM_FOLDER . ', ' .
						'\'' . $insert_name . '\', ' .
						'\'' . $insert_name_bin . '\', ' .
						'\'/\', ' .
						$this->bootstrap->activity_id . ', ' .
						'0' .
					') ' .
					'ON DUPLICATE KEY UPDATE ' .
						'type = ' . WPONLINEBACKUP_ITEM_FOLDER . ', ' .
						'name = \'' . $insert_name . '\', ' .
						'name_bin = \'' . $insert_name_bin . '\', ' .
						'path = \'/\', ' .
						'activity_id = ' . $this->bootstrap->activity_id . ', ' .
						'counter = 0'
				) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				// In this case, root folder is ID 0
				$folder_id = 0;
				$parent_id = -1;
				$parent_folder = '';
				$folder = '';

				// Check we can access the parent folder first...
				if ( ( $d = @opendir( $this->job['root'] ) ) === false ) {

					// OK, can't access parent folder, report an error and try with filesystem_upone disabled
					$report_fallback = OBFW_Tidy_Exception();

					$upone = false;

					continue;

				}

				@closedir( $d );

			} else {

				// If in the root - simulate a wordpress folder in the database
				if ( $insert_name == '' )
					$insert_name = 'wordpress';

				$insert_name_bin = $insert_name;
				$wpdb->escape_by_ref( $insert_name_bin );

				WPOnlineBackup_Functions::UTF8_Validate( $insert_name );
				$wpdb->escape_by_ref( $insert_name );

				// Prefill the entry - this won't be getting updated so all bits, especially backup, need inserting
				if ( $wpdb->query(
					'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_items` (bin, item_id, parent_id, type, name, name_bin, path, backup, activity_id, counter) ' .
					'VALUES (' .
						WPONLINEBACKUP_BIN_FILESYSTEM . ', ' .
						'1, ' .
						'0, ' . 
						WPONLINEBACKUP_ITEM_FOLDER . ', ' .
						'\'' . $insert_name . '\', ' .
						'\'' . $insert_name_bin . '\', ' .
						'\'/\', ' .
						'1, ' .
						$this->bootstrap->activity_id . ', ' .
						'0' .
					') ' .
					'ON DUPLICATE KEY UPDATE ' .
						'type = ' . WPONLINEBACKUP_ITEM_FOLDER . ', ' .
						'name = \'' . $insert_name . '\', ' .
						'name_bin = \'' . $insert_name_bin . '\', ' .
						'path = \'/\', ' .
						'backup = IF(`exists` IS NULL, 1, 0), ' .
						'activity_id = ' . $this->bootstrap->activity_id . ', ' .
						'counter = 0'
				) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				// Just WordPress folder, root folder is ID 1 which we created above
				$folder_id = 1;
				$parent_id = 0;

				// Jose again: Are you in the roooooooooot!?
				if ( $this->job['wordpress'] == '' ) {
					$parent_folder = '';
					$folder = '';
				} else {
					$parent_folder = '/';
					$folder = $this->job['wordpress'];
				}

			}

			// Start the stack, and leave the loop
			$this->job['stack'] = array( $folder_id => array( $parent_id, $parent_folder, $folder, false ) );
			break;

		}

		$wpdb->query( 'START TRANSACTION' );

		// Guestimate the number of items we'll be processing based on previous backups
		if ( is_null( $row = $wpdb->get_row(
			$this->db_force_master . 'SELECT COUNT(*) AS guess_total ' .
			'FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
			'WHERE `exists` IS NULL OR `exists` = 1',
			ARRAY_A
		) ) && is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

			$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
			$wpdb->query( 'COMMIT' );
			return $ret;

		}

		$wpdb->query( 'COMMIT' );

		if ( is_null( $row ) ) $this->job['guess_total'] = 1;
		else $this->job['guess_total'] = $row['guess_total'];

		$this->job['progress'] = 10;

		// Report the fall back JUST before we exit the function
		// Why do we do this here and not in the loop? To prevent duplicate log entries
		// As soon as we leave this function we'll force an update
		if ( $report_fallback !== false )
			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The WordPress parent folder, %s, could not be accessed; the backup will try to continue by backing up the WordPress folder only: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $this->job['root'] ), $ret )
			);

		return true;
	}

	/*public*/ function Backup( & $bootstrap, & $stream, & $progress, & $job )
	{
		$this->bootstrap = & $bootstrap;
		$this->stream = & $stream;
		$this->progress = & $progress;
		$this->job = & $job;

		// We will always enter this loop on script run as we never actually Tick on 99%, only on 98% and 100%
		if ( $job['progress'] < 99 ) {

			if ( $job['action'] == 'scan' ) {

				if ( $job['progress'] < 10 ) {

					if ( !is_bool( $ret = $this->Init_Job() ) )
						return $ret;

					// Always force an update after initialisation to prevent duplicate log entries (we may have inserted one at the very end)
					// Progress is set to 10 at the end of Init_Job
					$bootstrap->Tick( false, true );

					// If Init_Job return false, we need to abort file system backup and carry on with the other bits (database might have backed up OK)
					if ( $ret === false ) {
						$job['progress'] = 100;
						return true;
					}

				}

				$event = __( 'File system scan completed.' , 'wponlinebackup' );
				$method = 'Process_Folder';

				$this->file_failed_msg = __( 'Failed to scan file %s: %s.' , 'wponlinebackup' );
				$this->file_taken_msg = __( 'Examined file %s.' , 'wponlinebackup' );

				$param = true;

				// Have we already finished? Up to 99%
				if ( count( $job['stack'] ) == 0 )
					$job['progress'] = 99;

				// Ensure the stack is at the end
				end( $job['stack'] );

			} else if ( $job['action'] == 'collect' ) {

				$event = __( 'File collection completed.' , 'wponlinebackup' );
				$method = 'Collect_Files';

				$this->file_failed_msg = __( 'Failed to backup file %s: %s.' , 'wponlinebackup' );
				$this->file_taken_msg = __( 'Backed up file %s.' , 'wponlinebackup' );

				$param = false;

			} else if ( $job['action'] == 'mark' ) {

				$event = __( 'Deleted files processing completed.' , 'wponlinebackup' );
				$method = 'Write_Deleted';

				$param = false;

			} else if ( $job['action'] == 'commit' ) {

				$event = false;
				$method = 'Commit';

				$param = false;

			} else if ( $job['action'] == 'cleanup' ) {

				$event = false;
				$method = 'Commit';

				$param = true;

			} else { // The action should be 'backup' if we get here

				if ( $job['progress'] < 10 ) {

					if ( !is_bool( $ret = $this->Init_Job() ) )
						return $ret;

					// Always force an update after initialisation to prevent duplicate log entries (we may have inserted one at the very end)
					// Progress is set to 10 at the end of Init_Job
					$bootstrap->Tick( false, true );

					// If Init_Job return false, we need to abort file system backup and carry on with the other bits (database might have backed up OK)
					if ( $ret === false ) {
						$job['progress'] = 100;
						return true;
					}

				}

				$event = __( 'File system backup completed.' , 'wponlinebackup' );
				$method = 'Process_Folder';

				$this->file_failed_msg = __( 'Failed to backup file %s: %s.' , 'wponlinebackup' );
				$this->file_taken_msg = __( 'Backed up file %s.' , 'wponlinebackup' );

				$param = false;

				// Have we already finished? Up to 99%
				if ( count( $job['stack'] ) == 0 )
					$job['progress'] = 99;

				// Ensure the stack is at the end
				end( $job['stack'] );

			}

			// Loop until we gracefully exit and reschedule, or until we reach 99%, which is our signal to exit
			while ( $job['progress'] < 99 )
				if ( ( $ret = $this->$method( $param ) ) !== true )
					return $ret;

		}

		// 99%: Cleanup - Add the completed event and set to 100%
		if ( $job['progress'] == 99 ) {

			if ( $event !== false )
				$bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					$event
				);

			$job['progress'] = 100;

		}

		// As soon as we return, bootstrap will force an update so we don't duplicate the completion event log above
		return true;
	}

	/*private*/ function Prevent_Timeout_Start( $prefix, $item_path )
	{
		$tick_progress = $prefix . ':' . $item_path;

		// On first timeout we start taking our time, on second timeout we log where we were each time, so on third we can skip the file
		if ( $this->progress['tick_progress'][0] === $tick_progress ) {

			if ( $this->progress['tick_progress'][1] === 0 ) {

				$this->progress['tick_progress'][1] = false;

				$this->bootstrap->Tick();

				if ( is_array( $size = $this->Fetch_Stat( $item_path ) ) ) {

					$size = sprintf( __( 'The file size is: %s' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $size['file_size'], true ) );

				} else {

					$size = sprintf( __( 'Trying to retrieve the size of the file returned the following error: %s' , 'wponlinebackup' ), $size );

				}

			} else {

				$size = __( 'Trying to retrieve the size of the file also timed out.' , 'wponlinebackup' );

			}

			return sprintf( __( 'Two separate attempts to process the file caused the backup to time out. The file is most likely very large, and therefore difficult to process. %s' , 'wponlinebackup' ), $size );

		}

		// Log where we are
		$this->progress['tick_progress'] = array(
			0	=> $tick_progress,
			1	=> 0,
		);

		$this->bootstrap->Tick();

		return null;
	}

	/*public*/ function Process_Folder( $scan )
	{
		global $wpdb;

		// Get the next folder to process from the top of the stack
		$this->folder_id = key( $this->job['stack'] );
		list ( $parent_id, $parent_folder, $this_folder, $folder_saved ) = current( $this->job['stack'] );

		$current_folder = $parent_folder . $this_folder . '/';

		// Add the folder information
		if ( !$scan && $folder_saved === false ) {

			$success = true;

			// Backup the folder
			if ( ( $ret = $this->stream->Add_Folder_From_Path(
				WPONLINEBACKUP_BIN_FILESYSTEM,
				WPOnlineBackup_Functions::UTF8_Validate( $parent_folder . $this_folder ),
				$this->job['root'] . $parent_folder . $this_folder,
				$success,
				array(
					'item_id'	=> $this->folder_id,
					'parent_id'	=> $parent_id,
					'backup_time'	=> $this->job['generation'],
				)
			) ) !== true ) {

				// $ret false means we need to end backup - but use the $success for the reason like we would if simply skipping
				if ( $ret === false ) {
					$this->bootstrap->Log_Event( WPONLINEBACKUP_ERROR, $success[0] );
					return $success[1];
				}

				return $this->bootstrap->FSError(
					__LINE__, __FILE__,
					'\'Filesystem' . $current_folder . '\'',
					$ret
				);

			}

			// Report an error
			if ( $success !== true ) {

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Status of folder %s was skipped: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder ), $success )
				);

			}

			$this->job['stack'][ $this->folder_id ][3] = true;

			// Force an update to prevent duplicating the above log entry and to ensure we don't trying adding the folder again to the stream
			$this->bootstrap->Tick( false, true );

		}

		if ( array_key_exists( $this->folder_id, $this->handles ) ) {

			// Grab the folder handle from the cache
			$folder = & $this->handles[ $this->folder_id ];

		} else {

			// Open the folder
			if ( ( $folder = @opendir( $this->job['root'] . $current_folder ) ) === false ) {

				$ret = OBFW_Tidy_Exception();

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Folder %s was skipped: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder ), $ret )
				);

				prev( $this->job['stack'] );
				unset( $this->job['stack'][ $this->folder_id ] );

				// If finished, mark at 99% - last 1% for cleanup
				if ( count( $this->job['stack'] ) == 0 )
					$this->job['progress'] = 99;

				// Force an update to ensure we don't duplicate the above log message
				$this->bootstrap->Tick( false, true );

				return true;

			}

			// Cache it
			$this->handles[ $this->folder_id ] = & $folder;

			// If we've reached the open handles limit, close some earlier ones - we can reopen them later, this will cause a rescan of the folder but we should keep open files down low
			if ( count( $this->handles ) > $this->handle_limit ) {
				reset( $this->handles );
				@closedir( current( $this->handles ) );
				unset( $this->handles[ key( $this->handles ) ] );
			}

		}

		// Process each entry in the folder, moving down the directory tree as necessary
		while ( ( $item = @readdir( $folder ) ) !== false ) {

			// Skip current directory and parent directory items
			if ( $item == '.' || $item == '..' )
				continue;

			$next = false;

			$item_path = $this->job['root'] . $current_folder . $item;

			if ( is_link( $item_path ) ) {

				// Have we already visited?
				if ( true === ( $ret = $this->Already_Visited( $item, WPONLINEBACKUP_ITEM_FILE, $this->folder_id ) ) )
					continue;

				if ( $ret !== false )
					return $ret;

				// Skip symbolic links - we might support them in future, but not now
				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					sprintf( __( 'Link %s was skipped: Links are not currently supported' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder . $item ) )
				);

				// This marking as visited will prevent duplicated log messages
				if ( true !== ( $ret = $this->Mark_Visited( $item, WPONLINEBACKUP_ITEM_FILE, $this->folder_id ) ) )
					return $ret;

			} else if ( is_dir( $item_path ) ) {

				// Have we already visited?
				if ( true === ( $ret = $this->Already_Visited( $item, WPONLINEBACKUP_ITEM_FOLDER, $this->folder_id ) ) )
					continue;

				if ( $ret !== false )
					return $ret;

				if ( false !== ( $key = array_search( $item_path, $this->job['excludes'] ) ) ) {

					// Excluded folder
					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_INFORMATION,
						sprintf( __( 'Folder %s was excluded: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder . $item ), $this->Get_Exclude_Reason( $key ) )
					);

					// This marking as visited will prevent duplicated log messages
					if ( true !== ( $ret = $this->Mark_Visited( $item, WPONLINEBACKUP_ITEM_FOLDER, $this->folder_id ) ) )
						return $ret;

				} else if ( false === ( $id = $this->Track_Item( $item, WPONLINEBACKUP_ITEM_FOLDER, $current_folder ) ) ) {

					// Special case when returning false is that we encountered another file that conflicts with one we already have, possibly due to UTF-8 conversion
					$this->bootstrap->Log_Event(
						WPONLINEBACKUP_EVENT_WARNING,
						sprintf( __( 'Folder %s was skipped: Unknown special characters in the folder name; please remove them to allow backup' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder . $item ) )
					);

					// This marking as visited will prevent duplicated log messages
					if ( true !== ( $ret = $this->Mark_Visited( $item, WPONLINEBACKUP_ITEM_FOLDER, $this->folder_id ) ) )
						return $ret;

				} else {

					// Insert this folder into the item list
					if ( !is_int( $id ) )
						return $id;

					if ( $id > $this->job['counter'] )
						$this->job['counter'] = $id;

					// Grab the actual item_id we need to specify the parent folder
					if ( !is_int( $id = $this->Track_Item( $item, WPONLINEBACKUP_ITEM_FOLDER, $current_folder, true ) ) )
						return $id;

					if ( ( $ret = $this->Update_Item( $item, WPONLINEBACKUP_ITEM_FOLDER, array() ) ) !== true )
						return $ret;

					// Queue a scan and return to begin scanning that folder
					$this->job['stack'][$id] = array( $this->folder_id, $current_folder, $item, false );
					next( $this->job['stack'] );

					$next = true;

				}

			} else if ( false !== ( $key = array_search( $item_path, $this->job['excludes'] ) ) ) {

				// Have we already visited?
				if ( true === ( $ret = $this->Already_Visited( $item, WPONLINEBACKUP_ITEM_FILE, $this->folder_id ) ) )
					continue;

				if ( $ret !== false )
					return $ret;

				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					sprintf( __( 'File %s was excluded: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder . $item ), $this->Get_Exclude_Reason( $key ) )
				);

				// This marking as visited will prevent duplicated log messages
				if ( true !== ( $ret = $this->Mark_Visited( $item, WPONLINEBACKUP_ITEM_FILE, $this->folder_id ) ) )
					return $ret;

			// Add the item
			} else if ( false === ( $id = $this->Track_Item( $item, WPONLINEBACKUP_ITEM_FILE ) ) ) {

				// Have we already visited?
				if ( true === ( $ret = $this->Already_Visited( $item, WPONLINEBACKUP_ITEM_FILE, $this->folder_id ) ) )
					continue;

				if ( $ret !== false )
					return $ret;

				// Special case when returning false is that we encountered another file that conflicts with one we already have, possibly due to UTF-8 conversion
				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_WARNING,
					sprintf( __( 'File %s was skipped: Unknown special characters in the file name; please remove them to allow backup' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $current_folder . $item ) )
				);

				// This marking as visited will prevent duplicated log messages
				if ( true !== ( $ret = $this->Mark_Visited( $item, WPONLINEBACKUP_ITEM_FOLDER, $this->folder_id ) ) )
					return $ret;

			} else {

				if ( !is_int( $id ) )
					return $id;

				// Check we haven't already stored this file
				if ( $id <= $this->job['counter'] )
					continue;

				$this->job['counter'] = $id;

				$item_file = $current_folder . $item;

				// If recovery mode we have previously timed out and we are taking our time in this run
				if ( $this->WPOnlineBackup->recovery_mode ) {

					$size = $this->Prevent_Timeout_Start( 'fs:' . ( $scan ? 'S' : 'C' ), $item_path );

				} else {

					$size = null;

				}

				if ( is_null( $size ) ) {

					if ( $scan ) {

						if ( is_array( $size = $this->Fetch_Stat( $item_path ) ) ) {

							if ( ( $ret = $this->Update_Item( $item, WPONLINEBACKUP_ITEM_FILE, $size ) ) !== true )
								return $ret;

						}

					} else {

						// Add the file to the stream
						if ( ( $ret = $this->stream->Add_File_From_Path(
							WPONLINEBACKUP_BIN_FILESYSTEM,
							WPOnlineBackup_Functions::UTF8_Validate( $item_file ), // This works fine as we store the path and that needs to be UTF-8 too
							$item_path,
							$size,
							array(
								'backup_time'	=> $this->job['generation'],
							)
						) ) !== true ) {

							// $ret false means we need to end backup - but use the $size for the reason like we would if simply skipping
							if ( $ret === false ) {
								$this->bootstrap->Log_Event( WPONLINEBACKUP_ERROR, $size[0] );
								return $size[1];
							}

							return $this->bootstrap->FSError(
								__LINE__, __FILE__,
								'\'Filesystem' . $item_file . '\' (' . ( is_array( $size ) ? $size['file_size'] : __( 'Size unknown', 'wponlinebackup' ) ) . ')',
								$ret
							);

						}

					}

				}

				// If recovery mode we can now clear where we are as we've just finished this entry
				if ( $this->WPOnlineBackup->recovery_mode ) {

					$this->progress['tick_progress'][0] = false;

				}

				// Update the progress message and report an error if needed
				if ( is_array( $size ) ) {

					$this->progress['message'] = sprintf( $this->file_taken_msg, WPOnlineBackup_Functions::UTF8_Validate( $item_file ) );

					$this->progress['rcount']++;
					$this->progress['rsize'] += $size['file_size'];

				} else {

					// Have we already visited? - This is a bit better than forcing a full update of the status, which is quite a large chunk of information
					if ( true !== ( $ret = $this->Already_Visited( $item, WPONLINEBACKUP_ITEM_FILE, $this->folder_id ) ) ) {

						if ( $ret !== false )
							return $ret;

						$this->bootstrap->Log_Event(
							WPONLINEBACKUP_EVENT_ERROR,
							sprintf( __( 'File %s was skipped: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $item_path ), $size )
						);

						$this->progress['message'] = sprintf( $this->file_failed_msg, WPOnlineBackup_Functions::UTF8_Validate( $item_file ), $size );

						// This marking as visited will prevent duplicated log messages
						if ( true !== ( $ret = $this->Mark_Visited( $item, WPONLINEBACKUP_ITEM_FOLDER, $this->folder_id ) ) )
							return $ret;

					}

				}

			}

			++$this->job['done'];

			// Update the progress
			if ( $this->job['done'] >= $this->job['guess_total'] ) {
				$this->job['progress'] = 98;
			} else {
				$this->job['progress'] = 10 + floor( ( $this->job['done'] * 88 ) / $this->job['guess_total'] );
				if ( $this->job['progress'] > 98 )
					$this->job['progress'] = 98;
			}

			$this->bootstrap->Tick();

			if ( $next )
				return true;

		}

		@closedir( $folder );

		// Remove closed folder from handle cache
		unset( $this->handles[ $this->folder_id ] );

		// Move onto next folder
		prev( $this->job['stack'] );
		unset( $this->job['stack'][ $this->folder_id ] );

		// Add this folder to the scan log - folders we enter are only added to scan log when we're completely finished with them
		if ( true !== ( $ret = $this->Mark_Visited( $this_folder, WPONLINEBACKUP_ITEM_FOLDER, $parent_id ) ) )
			return $ret;

		// If finished, mark at 99% - last 1% for cleanup
		if ( count( $this->job['stack'] ) == 0 )
			$this->job['progress'] = 99;

		return true;
	}

	/*private*/ function Already_Visited( $item, $type, $parent_id )
	{
		global $wpdb;

		// Our name field in scan_log is binary so we can keep track of unique names, even if they'd normally conflict
		$esc_item = $item;
		$wpdb->escape_by_ref( $esc_item );

		// Check the scan log to see if we already finished this item - but don't insert
		$wpdb->query( 'START TRANSACTION' );

		if ( is_null( $row = $wpdb->get_row(
			$this->db_force_master . 'SELECT scan_id ' .
			'FROM `' . $this->db_prefix . 'wponlinebackup_scan_log` ' .
			'WHERE parent_id = ' . $parent_id . ' AND type = ' . $type . ' AND name = \'' . $esc_item . '\'',
			ARRAY_A
		) ) && is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

			$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
			$wpdb->query( 'COMMIT' );
			return $ret;

		}

		$wpdb->query( 'COMMIT' );

		// If no entries, we haven't visited yet
		if ( is_null( $row ) )
			return false;

		// If we found a an entry in the scan log, see if the ID is from a previous attempt meaning we have visited it
		if ( $row['scan_id'] <= $this->job['scan_id'] )
			return true;

		// ID returned was ahead of our job status so we haven't visited
		return false;
	}

	/*private*/ function Mark_Visited( $item, $type, $parent_id )
	{
		global $wpdb;

		// Our name field in scan_log is binary so we can keep track of unique names, even if they'd normally conflict
		$esc_item = $item;
		$wpdb->escape_by_ref( $esc_item );

		// Add this folder to the scan log and return the new ID - or fetch the existing ID if entry exists already
		if ( $wpdb->query(
			'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_scan_log` ' .
				'(parent_id, type, name) ' .
			'VALUES ' .
				'(' . $parent_id . ', ' . $type . ', \'' . $esc_item . '\') ' .
			'ON DUPLICATE KEY UPDATE ' .
				'scan_id = LAST_INSERT_ID(scan_id)'
		) === false )
			return $this->bootstrap->DBError( __LINE__, __FILE__ );

		$this->job['scan_id'] = $wpdb->insert_id;

		return true;
	}

	/*private*/ function Track_Item( $item, $type, $path = null, $item_id = false )
	{
		global $wpdb;

		$esc_item = WPOnlineBackup_Functions::UTF8_Validate( $item );
		$wpdb->escape_by_ref( $esc_item );

		if ( $item_id ) {

			$wpdb->query( 'START TRANSACTION' );

			// Return the ID from the database
			if ( is_null( $row = $wpdb->get_row(
				$this->db_force_master . 'SELECT item_id ' .
				'FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
				'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND parent_id = ' . $this->folder_id . ' AND type = ' . $type . ' AND name = \'' . $esc_item . '\'',
				ARRAY_A
			) ) && is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

				$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
				$wpdb->query( 'COMMIT' );
				return $ret;

			}

			$wpdb->query( 'COMMIT' );

			if ( is_null( $row ) ) return 0;

			return intval( $row['item_id'] );

		}

		$esc_item_bin = $item;
		$wpdb->escape_by_ref( $esc_item_bin );

		if ( is_null( $path ) ) {
			$esc_path = null;
		} else {
			$esc_path = $path;
			$wpdb->escape_by_ref( $esc_path );
		}

		$new_counter = $this->job['counter'] + 1;

		$wpdb->query( 'START TRANSACTION' );

		// Insert into the database
		if ( $wpdb->query(
			'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_items` ' .
				'(bin, parent_id, type, name, name_bin, path, activity_id, counter) ' .
			'VALUES ' .
				'(' . WPONLINEBACKUP_BIN_FILESYSTEM . ', ' . $this->folder_id . ', ' . $type . ', \'' . $esc_item . '\', \'' . $esc_item_bin . '\', \'' . $esc_path . '\', ' . $this->bootstrap->activity_id . ', ' . $new_counter . ') ' .
			'ON DUPLICATE KEY UPDATE ' .
				'name_bin = IF(activity_id = ' . $this->bootstrap->activity_id . ', name_bin, \'' . $esc_item_bin . '\'), ' .
				'path = IF(activity_id = ' . $this->bootstrap->activity_id . ', path, \'' . $esc_path . '\'), ' .
				'counter = IF(activity_id = ' . $this->bootstrap->activity_id . ', counter, ' . $new_counter . '), ' .
				'activity_id = ' . $this->bootstrap->activity_id
		) === false ) {
			$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
			$wpdb->query( 'COMMIT' );
			return $ret;
		}

		// Return the ID from the database - some MySQL versions have bugs with rows_affected so we can't rely on using that and LAST_INSERT_ID()
		if ( is_null( $row = $wpdb->get_row(
			$this->db_force_master . 'SELECT counter, name_bin ' .
			'FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
			'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND parent_id = ' . $this->folder_id . ' AND type = ' . $type . ' AND name = \'' . $esc_item . '\'',
			ARRAY_A
		) ) && is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

			$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
			$wpdb->query( 'COMMIT' );
			return $ret;

		}

		if ( is_null( $row ) ) {

			if ( $wpdb->last_error == '' ) {
				$wpdb->last_error =
					'An entry for a file was inserted into the database but couldn\'t be found afterwards!' . PHP_EOL .
					'Hexdump of the file name is: ' . $this->_To_Binary( $item ) . PHP_EOL .
					'When escaped for insertion: ' . $this->_To_Binary( $esc_item ) . ' (' . $esc_item . ')' . PHP_EOL;
			}

			$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
			$wpdb->query( 'COMMIT' );
			return $ret;

		} else if ( $row['name_bin'] != $item ) {

			// False is a magic return to say skip the file
			// This will only ever happen if we had a conversion conflict, that is, we convert a non-utf8 filename into utf8 and cause a duplication
			return false;

		}

		$wpdb->query( 'COMMIT' );

		return intval( $row['counter'] );
	}

	/*private*/ function _To_Binary( $item )
	{
		$ret = '';
		for ( $i = 0; $i < strlen( $item ); $i++ )
			$ret .= str_pad( dechex( ord( substr( $item, $i, 1 ) ) ), 2, '0', STR_PAD_LEFT );
		return $ret;
	}

	/*private*/ function Update_Item( $item, $type, $status )
	{
		global $wpdb;

		$esc_item = WPOnlineBackup_Functions::UTF8_Validate( $item );
		$wpdb->escape_by_ref( $esc_item );

		// Update the database with whether to backup or not
		if ( $wpdb->query(
			'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
			'SET ' .
				( $type == WPONLINEBACKUP_ITEM_FILE ? 
					'new_file_size = ' . $status['file_size'] . ', ' .
					'new_mod_time = ' . $status['mod_time'] . ', ' .
					'backup = IF(`exists` IS NULL OR `exists` = 0 OR file_size != new_file_size OR mod_time != new_mod_time, 1, 0) '
				:
					'backup = IF(`exists` IS NULL OR `exists` = 0, 1, 0) '
				) .
			'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND parent_id = ' . $this->folder_id . ' AND type = ' . $type . ' AND name = \'' . $esc_item . '\''
		) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

		return true;
	}

	/*private*/ function Collect_Files( $param )
	{
		global $wpdb;

		if ( $this->job['progress'] == 0 ) {

			$wpdb->query( 'START TRANSACTION' );

			// Initialise by grabbing the total number of items to backup, so we can track the progress
			if ( is_null( $row = $wpdb->get_row(
				$this->db_force_master . 'SELECT COUNT(*) AS total ' .
				'FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
				'WHERE activity_id = ' . $this->bootstrap->activity_id . ' AND backup = 1 AND bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND item_id > ' . $this->job['last_id'],
				ARRAY_A
			) ) && is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

				$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
				$wpdb->query( 'COMMIT' );
				return $ret;

			}

			if ( !is_null( $row ) ) $this->job['total'] = $row['total'];

			$this->job['progress'] = 5;

			$this->bootstrap->Tick();

		}

		if ( $this->job['progress'] < 99 ) while ( true ) {

			$wpdb->query( 'START TRANSACTION' );

			// Fetch a batch of files we have marked for backup
			$result = $wpdb->get_results(
				$this->db_force_master . 'SELECT f.item_id, f.type, f.name_bin, p.item_id AS parent_id, p.name AS parent_name, p.path AS parent_path, f.new_file_size, f.new_mod_time ' .
				'FROM `' . $this->db_prefix . 'wponlinebackup_items` AS f ' .
					'LEFT JOIN `' . $this->db_prefix . 'wponlinebackup_items` AS p ON (p.bin = f.bin AND p.item_id = f.parent_id) ' .
				'WHERE f.activity_id = ' . $this->bootstrap->activity_id . ' AND f.backup = 1 AND f.bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND f.item_id > ' . $this->job['last_id'] . ' ' .
				'ORDER BY f.item_id ASC ' .
				'LIMIT 50',
				ARRAY_A
			);

			if ( is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

				$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
				$wpdb->query( 'COMMIT' );
				return $ret;

			}

			$wpdb->query( 'COMMIT' );

			// If no files left, return
			if ( count( $result ) == 0 ) {
				$this->job['progress'] = 99;
				break;
			}

			// Process file list
			foreach ( $result as $item ) {

				if ( is_null( $item['parent_id'] ) ) {
					$item['parent_id'] = 0;
					$item['parent_path'] = '';
					$item['parent_name'] = '';
				}

				$item_name = $item['parent_path'] . $item['parent_name'] . '/' . $item['name_bin'];

				$item_path = $this->job['root'] . $item_name;

				if ( $item['type'] == WPONLINEBACKUP_ITEM_FILE ) {

					// If recovery mode we have previously timed out and we are taking our time in this run
					if ( $this->WPOnlineBackup->recovery_mode ) {

						$size = $this->Prevent_Timeout_Start( 'fs:c', $item_path );

					} else {

						$size = null;

					}

					if ( is_null( $size ) ) {

						// Backup the file
						if ( ( $ret = $this->stream->Add_File_From_Path(
							WPONLINEBACKUP_BIN_FILESYSTEM,
							WPOnlineBackup_Functions::UTF8_Validate( $item_name ),
							$item_path,
							$size,
							array(
								'item_id'	=> $item['item_id'],
								'parent_id'	=> $item['parent_id'],
								'mod_time'	=> $item['new_mod_time'],
								'backup_time'	=> $this->job['generation'],
							)
						) ) !== true ) {

							// $ret false means we need to end backup - but use the $size for the reason like we would if simply skipping
							if ( $ret === false ) {
								$this->bootstrap->Log_Event( WPONLINEBACKUP_ERROR, $size[0] );
								return $size[1];
							}

							return $this->bootstrap->FSError( __LINE__, __FILE__, '\'Filesystem' . $item_path . '\' (' . ( is_array( $size ) ? $size['file_size'] : __( 'Size unknown', 'wponlinebackup' ) ) . ')', $ret );

						}

					}

					// Update the progress message and report an error if needed
					if ( is_array( $size ) ) {

						if ( $size['file_size'] != $item['new_file_size'] ) {

							if ( $wpdb->query(
								'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
								'SET new_file_size = ' . $size['file_size'] . ' ' .
								'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND item_id = ' . $item['item_id']
							) === false )
								return $this->bootstrap->DBError( __LINE__, __FILE__ );

						}

						$this->progress['message'] = sprintf( $this->file_taken_msg, WPOnlineBackup_Functions::UTF8_Validate( $item_name ) );

						// Insert the generation
						if ( $wpdb->query(
							'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_generations` ' .
								'(bin, item_id, backup_time, file_size, stored_size, mod_time, commit) ' .
							'VALUES (' .
								WPONLINEBACKUP_BIN_FILESYSTEM . ', ' .
								$item['item_id'] . ', ' .
								$this->job['generation'] . ', ' .
								$item['new_file_size'] . ', ' .
								$size['stored_size'] . ', ' .
								$item['new_mod_time'] . ', ' .
								'0' .
							') ' .
							'ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)'
						) === false )
							return $this->bootstrap->DBError( __LINE__, __FILE__ );

					} else {

						$this->bootstrap->Log_Event(
							WPONLINEBACKUP_EVENT_ERROR,
							sprintf( __( 'File %s was skipped: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $item_name ), $size )
						);

						$this->progress['message'] = sprintf( $this->file_failed_msg, WPOnlineBackup_Functions::UTF8_Validate( $item_name ), $size );

						// To prevent duplication of the above event log, we'll have to do something special - mark the item as DON'T backup
						// We don't want to force an update if we can help it, and we don't want to have to use Mark_Visited/Already_Visited here too
						if ( $wpdb->query(
							'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
							'SET backup = 0 ' .
							'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND item_id = ' . $item['item_id']
						) === false )
							return $this->bootstrap->DBError( __LINE__, __FILE__ );

					}

					// If recovery mode we can now clear where we are as we've just finished this entry
					if ( $this->WPOnlineBackup->recovery_mode ) {

						$this->progress['tick_progress'][0] = false;

					}

				} else {

					$success = true;

					// Backup the folder
					if ( ( $ret = $this->stream->Add_Folder_From_Path(
						WPONLINEBACKUP_BIN_FILESYSTEM,
						WPOnlineBackup_Functions::UTF8_Validate( $item_name ),
						$item_path,
						$success,
						array(
							'item_id'	=> $item['item_id'],
							'parent_id'	=> $item['parent_id'],
							'backup_time'	=> $this->job['generation'],
						)
					) ) !== true ) {

						// $ret false means we need to end backup - but use the $success for the reason like we would if simply skipping
						if ( $ret === false ) {
							$this->bootstrap->Log_Event( WPONLINEBACKUP_ERROR, $success[0] );
							return $success[1];
						}

						return $this->bootstrap->FSError( __LINE__, __FILE__, '\'Filesystem' . $item_path . '/\'', $ret );

					}

					// Report an error
					if ( $success !== true ) {
	
						$this->bootstrap->Log_Event(
							WPONLINEBACKUP_EVENT_ERROR,
							sprintf( __( 'Status of folder %s was skipped: %s' , 'wponlinebackup' ), WPOnlineBackup_Functions::UTF8_Validate( $item_name ), $success )
						);

						// To prevent duplication of the above event log, we'll have to do something special - mark the item as DON'T backup
						// We don't want to force an update if we can help it, and we don't want to have to use Mark_Visited/Already_Visited here too
						if ( $wpdb->query(
							'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
							'SET backup = 0 ' .
							'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND item_id = ' . $item['item_id']
						) === false )
							return $this->bootstrap->DBError( __LINE__, __FILE__ );
	
					} else {

						// Insert the generation
						if ( $wpdb->query(
							'INSERT INTO `' . $this->db_prefix . 'wponlinebackup_generations` ' .
								'(bin, item_id, backup_time, commit) ' .
							'VALUES (' .
								WPONLINEBACKUP_BIN_FILESYSTEM . ', ' .
								$item['item_id'] . ', ' .
								$this->job['generation'] . ', ' .
								'0' .
							') ' .
							'ON DUPLICATE KEY UPDATE item_id = VALUES(item_id)'
						) === false )
							return $this->bootstrap->DBError( __LINE__, __FILE__ );

					}

				}

				++$this->job['done'];

				// Update the progress
				if ( $this->job['done'] >= $this->job['total'] ) $this->job['progress'] = 98;
				else {
					$this->job['progress'] = 5 + floor( ( $this->job['done'] * 93 ) / $this->job['total'] );
					if ( $this->job['progress'] >= 98 ) $this->job['progress'] = 98;
				}

				$this->job['last_id'] = $item['item_id'];

				$this->bootstrap->Tick();

			}

		}

		return true;
	}

	/*private*/ function Write_Deleted( $param )
	{
		global $wpdb;

		if ( $this->job['progress'] == 0 ) {

			$wpdb->query( 'START TRANSACTION' );

			// Initialise by grabbing the total number of items to mark as deleted
			if ( is_null( $row = $wpdb->get_row(
				$this->db_force_master . 'SELECT COUNT(*) AS total ' .
				'FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
				'WHERE activity_id <> ' . $this->bootstrap->activity_id . ' OR counter > ' . $this->job['counter'],
				ARRAY_A
			) ) && is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

				$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
				$wpdb->query( 'COMMIT' );
				return $ret;

			}

			$wpdb->query( 'COMMIT' );

			if ( !is_null( $row ) ) $this->job['total'] = $row['total'];

			$this->progress['message'] = __( 'Processing deleted files...' , 'wponlinebackup' );

			$this->job['progress'] = 5;

			// Force an update so we update to the deleted files message immediately
			$this->bootstrap->Tick( false, true );

		}

		if ( $this->job['progress'] < 99 ) while ( true ) {

			$wpdb->query( 'START TRANSACTION' );

			// Fetch a batch of files we need to mark as deleted
			$result = $wpdb->get_results(
				$this->db_force_master . 'SELECT i.item_id, (SELECT g.backup_time FROM `' . $this->db_prefix . 'wponlinebackup_generations` g WHERE g.bin = i.bin AND g.item_id = i.item_id ORDER BY g.backup_time DESC LIMIT 1) AS backup_time ' .
				'FROM `' . $this->db_prefix . 'wponlinebackup_items` i ' .
				'WHERE i.bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND i.`exists` = 1 AND (i.activity_id <> ' . $this->bootstrap->activity_id . ' OR i.counter > ' . $this->job['counter'] . ') ' .
				'ORDER BY i.item_id ASC ' .
				'LIMIT ' . $this->job['done'] . ',50',
				ARRAY_A
			);

			if ( is_null( $wpdb->get_col_info( 'name', 0 ) ) ) {

				$ret = $this->bootstrap->DBError( __LINE__, __FILE__ );
				$wpdb->query( 'COMMIT' );
				return $ret;

			}

			$wpdb->query( 'COMMIT' );

			// If no files left, return
			if ( count( $result ) == 0 ) {

				$this->job['progress'] = 99;
				break;
			}

			// Process file list
			foreach ( $result as $item ) {

				if ( ( $ret = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
					'SET new_exists = 0 ' .
					'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND item_id = ' . $item['item_id']
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				if ( ( $ret = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_generations` ' .
					'SET new_deleted_time = ' . $this->job['deletion_time'] . ' ' .
					'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND item_id = ' . $item['item_id'] . ' AND backup_time = ' . $item['backup_time']
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$success = true;

				if ( true !== ( $ret = $this->stream->Add_Deletion_Entry( WPONLINEBACKUP_BIN_FILESYSTEM, $item['item_id'], $item['backup_time'], $this->job['deletion_time'], $success ) ) ) {

					// $ret false means we need to end backup - but use the $success for the reason like we would if simply skipping
					if ( $ret === false ) {
						$this->bootstrap->Log_Event( WPONLINEBACKUP_ERROR, $success[0] );
						return $success[1];
					}

					return $this->bootstrap->FSError( __LINE__, __FILE__, false, $ret );

				}

				++$this->job['done'];

				// Update the progress
				if ( $this->job['done'] >= $this->job['total'] ) $this->job['progress'] = 98;
				else {
					$this->job['progress'] = 5 + floor( ( $this->job['done'] * 93 ) / $this->job['total'] );
					if ( $this->job['progress'] >= 98 ) $this->job['progress'] = 98;
				}

				$this->job['last_id'] = $item['item_id'];

			}

			$this->bootstrap->Tick();

		}

		return true;
	}

	/*public*/ function Commit( $full )
	{
		global $wpdb;

		if ( $this->job['progress'] < 5 ) {

			$this->progress['message'] = __( 'Updating backup journal...' , 'wponlinebackup' );

			$this->job['progress'] = 5;

			// Force update to set the above message immediately
			$this->bootstrap->Tick( false, true );

		}

		// Clean up the scan log table
		if ( $this->job['progress'] == 5 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_scan_log` ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 20;

		}

		if ( $full ) {

			// If full backup, clear back unencountered items, but only where exists is NULL as we haven't encountered those in incremental yet
			if ( $this->job['progress'] == 20 ) {

				do {

					if ( ( $ret = $wpdb->query(
						'DELETE FROM `' . $this->db_prefix . 'wponlinebackup_items` ' .
						'WHERE bin = ' . WPONLINEBACKUP_BIN_FILESYSTEM . ' AND `exists` IS NULL AND (activity_id <> ' . $this->bootstrap->activity_id . ' OR counter > ' . $this->job['counter'] . ')'
					) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

					$this->bootstrap->Tick();

				} while ( $ret );

				$this->job['progress'] = 99;

			}

			return true;

		}

		// Commit changes to items
		if ( $this->job['progress'] == 20 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
					'SET ' .
						'`exists` = 1, ' .
						'backup = 0, ' .
						'file_size = new_file_size, ' .
						'mod_time = new_mod_time ' .
					'WHERE activity_id = ' . $this->bootstrap->activity_id . ' AND counter <= ' . $this->job['counter'] . ' AND backup = 1'
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 40;

		}

		// Commit new generations
		if ( $this->job['progress'] == 40 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_generations` ' .
					'SET ' .
						'commit = 1 ' .
					'WHERE commit = 0 ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 60;

		}

		// Commit items that have been marked deleted
		if ( $this->job['progress'] == 60 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_items` ' .
					'SET ' .
						'`exists` = new_exists ' .
					'WHERE new_exists IS NOT NULL ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 80;

		}

		// Commit deleted generations
		if ( $this->job['progress'] == 80 ) {

			do {

				if ( ( $ret = $wpdb->query(
					'UPDATE `' . $this->db_prefix . 'wponlinebackup_generations` ' .
					'SET ' .
						'deleted_time = new_deleted_time, ' .
						'new_deleted_time = NULL ' .
					'WHERE new_deleted_time IS NOT NULL ' .
					'LIMIT 500'
				) ) === false ) return $this->bootstrap->DBError( __LINE__, __FILE__ );

				$this->bootstrap->Tick();

			} while ( $ret );

			$this->job['progress'] = 99;

		}

		return true;
	}

	/*private*/ function Fetch_Stat( $file )
	{
		if ( ( $file_size = @filesize( $file ) ) === false ) {
			$ret = OBFW_Tidy_Exception();
			return OBFW_FOpen_Exception( $file, $ret );
		}
		if ( ( $mod_time = @filemtime( $file ) ) === false ) {
			$ret = OBFW_Tidy_Exception();
			return OBFW_FOpen_Exception( $file, $ret );
		}

		return compact( 'file_size', 'mod_time' );
	}
}

?>

<?php

/*
WPOnlineBackup_Admin - Displays the administration pages
All HTML will be in this file
*/

class WPOnlineBackup_Admin
{
	/*private*/ var $WPOnlineBackup;

	// Page hook so we can hook the page load actions etc
	/*private*/ var $page_hook;

	// Current section we are viewing - used when displaying the form wrappers etc
	/*private*/ var $section;

	// Page callback to use to display the page - if false we display metaboxes instead
	/*private*/ var $page_callback = false;

	// Set to the form identifier when form wrapper is required or false if no form is required
	/*private*/ var $enable_form = false;

	// The form type - we can switch to get if we need to
	/*private*/ var $form_type = 'post';

	function WPOnlineBackup_Admin( & $WPOnlineBackup )
	{
		// Store the main object
		$this->WPOnlineBackup = & $WPOnlineBackup;

		// Initializing admin page...
		add_action( 'wp_loaded', array( & $this, 'Init' ) );
	}

	/*public*/ function Init()
	{
		// We reach here for main site blog admin AND network admin, even if multisite enabled
		// This is because in multisite there is no network admin AJAX, so we need to utilise the admin AJAX
		// So if we're is_admin and not network admin (or not multisite), do the AJAX, and then quit if we're multisite (since we only want to show stuff in network_admin)

		if ( is_admin() && ( !$this->WPOnlineBackup->multisite || !is_network_admin() ) ) {

			// Register AJAX action for dynamic manual backup.
			add_action( 'wp_ajax_wponlinebackup_progress', array( & $this, 'AJAX_Progress' ) );
			add_action( 'wp_ajax_wponlinebackup_kick_start', array( & $this, 'AJAX_Kick_Start' ) );

			// If we're multisite, we're done!
			if ( $this->WPOnlineBackup->multisite )
				return;

		}

		// Only load if we have permission
		if ( ( !$this->WPOnlineBackup->multisite && current_user_can( 'install_plugins' ) ) || ( $this->WPOnlineBackup->multisite && current_user_can( 'manage_sites' ) ) ) {

			// Define WPONLINEBACKUP_URL using plugin_dir_url - we have to do this here because plugin_dir_url uses filters and therefore we need to wait for wordpress to load before using it
			define( 'WPONLINEBACKUP_URL', preg_replace( '#/$#', '', plugin_dir_url( WPONLINEBACKUP_FILEPATH ) ) ); // BTL code styling requires we do not have forward slash!
			define( 'WPONLINEBACKUP_LOCALBACKUPURL', content_url() . '/backups' );

			// Plugin links...
			add_filter( ( $this->WPOnlineBackup->multisite ? 'network_admin_' : '' ) . 'plugin_action_links_' . WPONLINEBACKUP_FILE, array( & $this, 'Plugin_Actions' ) );

			// Queue CSS we use for our menu item
			add_action( 'admin_head', array( & $this, 'Admin_Menu_Head' ) );

			// Adding the navigation entries...
			add_action( ( $this->WPOnlineBackup->multisite ? 'network_admin_' : 'admin_' ) . 'menu', array( & $this, 'Admin_Menu' ) );

			// Grab formatting functions
			require_once WPONLINEBACKUP_PATH . '/include/formatting.php';

			$this->WPOnlineBackup->Load_Language();

		}
	}

	/*public*/ function Plugin_Actions( $actions )
	{
		// Add View Dashboard to the plugin actions
		array_unshift( $actions, '<a href="admin.php?page=wponlinebackup">' . _x( 'View Dashboard', 'wponlinebackup' ) . '</a>' );

		return $actions;
	}

	/*public*/ function Admin_Menu_Head()
	{
?>
<style type="text/css">
#adminmenu .toplevel_page_wponlinebackup div.wp-menu-image{background:url('<?php echo WPONLINEBACKUP_URL . '/images/icon32.png'; ?>') no-repeat center center; background-size:16px 16px;}
</style>
<?php
	}

	/*public*/ function Admin_Menu()
	{
		global $wpdb;

		if ( $this->WPOnlineBackup->multisite )
			$cap = 'manage_sites';
		else
			$cap = 'install_plugins';

		// Add a menu item
		$page_hook = add_menu_page( __( 'Online Backup', 'wponlinebackup' ), __( 'Online Backup', 'wponlinebackup' ), $cap, 'wponlinebackup', array( & $this, 'Print_Page' ), 'div' );
		add_action( 'load-' . $page_hook, array( & $this, 'Prepare_Page' ) );

		$this->page_hook = $page_hook;
	}

	/*public*/ function Prepare_Page()
	{
		// Add the help processor
		add_action( 'contextual_help', array( & $this, 'Contextual_Help' ), 10, 3 );

		// Grab user information in case we need it
		get_currentuserinfo();

		// Ensure the settings and scheduler are loaded
		$this->WPOnlineBackup->Load_Settings();
		$this->WPOnlineBackup->Load_Scheduler();
		$this->WPOnlineBackup->Load_BootStrap();

		// Grab the requested section
		$this->section = array_key_exists( 'section', $_GET ) ? strval( $_GET['section'] ) : '';

		// Prepare the page
		switch ( $this->section )
		{

			// Front page
			// Force section to blank
			default:
				$this->Prepare_Overview();
				$this->section = '';
				break;

			// Manual backup page
			case 'backup':
				$this->Prepare_Backup();
				break;

			case 'local':
				$this->Prepare_Local();
				break;

			// Activity Logs page
			case 'activities':
				$this->Prepare_Activities();
				break;

			// Event Logs page - required activity id to be specified
			case 'events':
				$this->Prepare_Events();
				break;

			// Schedule page
			case 'schedule':
				$this->Prepare_Schedule();
				break;

			// General settings page
			case 'settings':
				$this->Prepare_Settings();
				break;

			// Online backup settings page
			case 'online':
				$this->Prepare_Online();
				break;

			// Help & Support page
			case 'help':
				$this->Prepare_Help();
				break;

			// Hidden advanced settings page
			case 'advanced':
				$this->Prepare_Advanced();
				break;

		}
	}

	/*private*/ function Get_Help()
	{
		global $wpdb;

		$env = '';
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES )
			$env .= 'SAVEQUERIES Enabled' . PHP_EOL;
		if ( defined( 'MYSQLND_MS_VERSION' ) )
			$env .= 'mysqlnd detected: version ' . MYSQLND_MS_VERSION . PHP_EOL;
		if ( defined( 'MYSQLND_MS_MASTER_SWITCH' ) )
			$env .= 'mysqlnd detected: mysqlnd-ms plugin installed' . PHP_EOL;
		if ( is_callable( array( & $wpdb, 'send_reads_to_masters' ) ) )
			$env .= 'Hyper-DB plugin installed' . PHP_EOL;

		$caps = '';
		if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) )
			$caps .= ' hashcopy';
		if ( $this->WPOnlineBackup->Get_Env( 'deflate_available' ) )
			$caps .= ' gzdeflate';
		if ( $key_stretcher_available = $this->WPOnlineBackup->Get_Env( 'key_stretcher_available' ) )
			$caps .= ' ' . $key_stretcher_available . ' ' . implode( ' ', $this->WPOnlineBackup->Get_Env( 'encryption_types' ) );
		if ( $caps == '' )
			$caps = ' None';

		return
			'<p><strong>Having problems? Want to ask something?</strong></p>' . PHP_EOL .
			'<p>For answers to common queries and solutions to common problems, check out our FAQ at <a href="https://wordpress.backup-technology.com/FAQ" target="_blank">https://wordpress.backup-technology.com/FAQ</a>.' . PHP_EOL .
				'If you encounter any issues not covered in the FAQ, please start a new topic in the plugin\'s support forum at <a href="http://wordpress.org/support/plugin/wponlinebackup" target="_blank" title="Support Forum">http://wordpress.org/support/plugin/wponlinebackup</a>.' . PHP_EOL .
				'Backup Technology monitor the forum every few days and will be able to provide you with assistance there. Feedback and feature requests are also very welcome in the forum.</p>' . PHP_EOL .
			'<p><strong>Please note, we are only able to provide technical support on the WordPress forum mentioned above, and not by email, telephone or the contact forms on our website.</strong></p>' . PHP_EOL .
			'<p>The following anonymous information will be extremely useful to the support personnel and should be provided along with any support request.</p>' . PHP_EOL .
			'<p><textarea cols="75" rows="15">' . PHP_EOL .
			'Online Backup for WordPress Plugin Version ' . WPONLINEBACKUP_VERSION . PHP_EOL .
			'WordPress Version: ' . get_bloginfo( 'version' ) . ( function_exists( 'is_multisite' ) && is_multisite() ? ' (multisite)' : ' (standalone)' ) . PHP_EOL .
			'PHP Version: ' . phpversion() . ( $this->WPOnlineBackup->Get_Setting( 'safe_mode' ) ? ' (safe mode)' : '' ) . PHP_EOL .
			'Server Software: ' . ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown' ) . PHP_EOL .
			'MySQL Server Version: ' . ( isset( $wpdb->dbh ) && function_exists( 'mysql_get_server_info' ) && ( $__mysql_version = @mysql_get_server_info( $wpdb->dbh ) ) !== false ? $__mysql_version : '(unknown)' ) . PHP_EOL .
			'MySQL Client Version: ' . phpversion( 'mysql' ) . '; MySQL Packet Size: ' . $this->Get_Packet_Size() . PHP_EOL .
			'Memory: ' . ini_get( 'memory_limit' ) . '; Post: ' . ini_get( 'post_max_size' ) . '; Upload: ' . ini_get( 'upload_max_filesize' ) . '; Timeout: ' . ini_get( 'max_execution_time' ) . PHP_EOL .
			'Memory Changable: ' . $this->Can_Change_Memory() . '; Admin Cache Size: ' . $this->Get_Cache_Memory() . PHP_EOL .
			'Status: ' . $this->Get_Status_Stats() . PHP_EOL .
			'Capabilities:' . $caps . PHP_EOL .
			( $env ? 'Abnormalities Detected:' . PHP_EOL . $env : '' ) .
			'End of Information' . PHP_EOL .
			'</textarea></p>' . PHP_EOL .
			'<p><em>We hope you find our plugin useful! Thank you.</em></p>';
	}

	/*private*/ function Get_Cache_Memory()
	{
		if ( !function_exists( 'memory_get_usage' ) )
			return 'U';

		// Grab memory usage now
		$start = memory_get_usage();

		// Flush cache
		wp_cache_flush();

		// Return total memory
		return WPOnlineBackup_Formatting::Fix_B( $start - memory_get_usage() );
	}

	/*private*/ function Get_Status_Stats()
	{
		global $wpdb;

		$result =
			$wpdb->get_row(
				'SELECT COUNT(*) AS row_count, MAX(progress_max) AS progress_max, MAX(memory_freed) AS memory_freed, MIN(memory_used) AS memory_used FROM `' . $wpdb->prefix . 'wponlinebackup_status` GROUP BY NULL',
				ARRAY_A
			);

		if ( is_null( $result ) )
			return '0 rows (!)';

		// Return a bit of info on our status entry
		return $result['row_count'] . ' row' . ( $result['row_count'] == 1 ? '' : 's' ) . ', ' . WPOnlineBackup_Formatting::Fix_B( $result['progress_max'] ) . ' packet size, ' . WPOnlineBackup_Formatting::Fix_B( $result['memory_freed'] ) . ' cache freed, ' . WPOnlineBackup_Formatting::Fix_B( $result['memory_used'] ) . ' memory used';
	}

	/*private*/ function Get_Packet_Size()
	{
		global $wpdb;

		$result = $wpdb->get_row( 'SELECT @@max_allowed_packet AS max_allowed_packet', ARRAY_A );

		if ( is_null( $result ) )
			return 'U';

		return WPOnlineBackup_Formatting::Fix_B( $result['max_allowed_packet'] );
	}

	/*private*/ function Can_Change_Memory()
	{
		$old = ini_get( 'memory_limit' );
		if ( preg_match( '/^([0-9]+)(.*+)$/', $old, $matches ) )
			$try = ( intval( $matches[1] ) + 1 ) . $matches[2];
		else
			$try = '256M';
		@ini_set( 'memory_limit', $try );
		$new = ini_get( 'memory_limit' );
		@ini_set( 'memory_limit', $old );
		return ( $new != $old ? 'Yes' : 'No' );
	}

	/*public*/ function Contextual_Help( $contextual_help, $screen_id, $screen )
	{
		if ( $screen_id == $this->page_hook && $this->section != 'help' ) {
			$contextual_help = $this->Get_Help();
		}
		return $contextual_help;
	}

	/*private*/ function Have_Messages()
	{
		global $user_ID;

		// Use Transient API if available
		if ( $transient = function_exists( 'get_transient' ) ) {

			// Fetch existing messages
			$data = get_transient( 'wponlinebackupmessages' . $this->section . $user_ID );

			return ( $data !== false );

		} else {

			// Old method, get from user meta
			$data = get_option( 'wponlinebackupmessages' . $this->section . $user_ID );

			return ( $data !== false );

		}
	}

	/*private*/ function Register_Messages( $messages )
	{
		global $user_ID;

		// Use Transient API if available
		if ( function_exists( 'get_transient' ) ) {

			// Fetch existing messages
			$data = get_transient( 'wponlinebackupmessages' . $this->section . $user_ID );

			// If none existing, store messages, else merge
			if ( $data === false ) $data = $messages;
			else $data = array_merge( $data, $messages );

			// Set transient
			set_transient( 'wponlinebackupmessages' . $this->section . $user_ID, $data, 120 );

		} else {

			// Old method, get from user meta
			$data = get_option( 'wponlinebackupmessages' . $this->section . $user_ID );

			// If none existing, store messages, else merge
			if ( $data === false ) $data = array( 'expire' => time() + 120, 'messages' => $messages );
			else {
				$data['expire'] = time() + 120;
				$data['messages'] = array_merge( $data['messages'], $messages );
			}

			// Set user meta
			update_option( 'wponlinebackupmessages' . $this->section . $user_ID, $data );

		}
	}

	/*private*/ function Print_Messages()
	{
		global $user_ID;

		// Use Transient API if available
		if ( $transient = function_exists( 'get_transient' ) ) {

			// Fetch messages
			$data = get_transient( 'wponlinebackupmessages' . $this->section . $user_ID );

			// If no messages, skip
			if ( $data === false ) return;

			// Remove the data
			delete_transient( 'wponlinebackupmessages' . $this->section . $user_ID );

		} else {

			// Old method, get from options
			$data = get_option( 'wponlinebackupmessages' . $this->section . $user_ID );

			// If no messages, skip
			if ( $data === false ) return;

			// Remove the data
			delete_option( 'wponlinebackupmessages' . $this->section . $user_ID );

			// Check not expired
			if ( $data['expire'] < time() ) return;

			$data = $data['messages'];

		}

?><div id="setting-error-settings_updated" class="updated settings-error">
<?php
		// Iterate and display
		$errors = false;
		foreach ( $data as $message ) {
			// Detect if we are showing errors
			if ( $message['icon'] != 'accept' ) $errors = true;
?><p><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo htmlentities( $message['icon'], ENT_QUOTES, 'UTF-8'); ?>.png" alt="" style="width: 16px; height: 16px; vertical-align: middle">&nbsp;<strong><?php echo htmlentities( $message['text'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
<?php
		}
?></div>
<?php
		// If no errors, output JS to hide the success message
		if ( !$errors ) {
?><script type="text/javascript">
//<![CDATA[
jQuery(function($)
{
	window.setTimeout( function()
	{
		$('#setting-error-settings_updated').slideUp(1000);
	}, 2500 );
});
//]]>
</script>
<?php
		}
	}

	/*public*/ function Print_Page()
	{
		// If a backup is running, change the Backup link
		$status = $this->WPOnlineBackup->bootstrap->Fetch_Status();
		if (
			$status['status'] != WPONLINEBACKUP_STATUS_NONE
			&& $status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
		) {
			$query = '&amp;monitor=true';
			$link = __( 'Monitor Running Activity', 'wponlinebackup' );
		} else {
			$query = '';
			$link = __( 'Backup', 'wponlinebackup' );
		}

		// Prepare the links
		$links = array(
			''		=> __( 'Overview', 'wponlinebackup' ),
			'backup'	=> $link,
			'local'		=> __( 'Local Backups & Decryption', 'wponlinebackup' ),
			'activities'	=> __( 'Activity Log', 'wponlinebackup' ),
			'schedule'	=> _x( 'Schedule', 'The schedule configuration screen', 'wponlinebackup' ),
			'settings'	=> __( 'General Settings', 'wponlinebackup' ),
			'online'	=> __( 'Online Backup Settings', 'wponlinebackup' ),
			'help'		=> __( 'Help & Support', 'wponlinebackup' ),
		);

		// Get the last item
		end( $links );
		$last = key( $links );

		// Print the page wrapper
?>
<div class="wrap">
<div id="icon-wponlinebackup" class="icon32" style="background: transparent url('<?php echo WPONLINEBACKUP_URL; ?>/images/icon32.png') no-repeat center center"></div>
<h2><?php _e( 'Online Backup for WordPress', 'wponlinebackup' ); ?></h2>
<style type="text/css">
.wponlinebackup_red {
	color: #A00;
	font-weight: bold;
}
</style>
<ul class="subsubsub">
<?php
		// Print the section links
		foreach ( $links as $section => $label ) {
			$id = 'wponlinebackup_section' . ( $section ? '_' . $section : '' );
?><li id="<?php echo $id; ?>"><a href="admin.php?page=wponlinebackup&amp;section=<?php echo $section . ( $section == 'backup' ? $query : '' ); ?>"<?php
			if ( $section == $this->section ) {
				// If the current section, print class="current" too
?> class="current"<?php
			} else if ( $section == 'help' ) {
				// Red for Help
?> class="wponlinebackup_red"<?php
			}
?>><?php echo esc_html( $label ); ?></a><?php
			// If the current section is the last, don't print the pipe
			if ( $section != $last ) {
?> |<?php
			}
?></li>
<?php
		}
?></ul>
<div style="clear: left"></div>
<?php
		// Show messages, if any, but only if messages=true
		if ( array_key_exists( 'messages', $_GET ) && strval( $_GET['messages'] ) == 'true' ) $this->Print_Messages();

		// If no callback, start printing the metabox wrappers
		if ( $this->page_callback === false ) {
?><div class="postbox-container" style="width: 100%"><div id="poststuff" class="metabox-holder">
<?php
			// Have we enabled the form wrapper for this section?
			if ( $this->enable_form ) {
?><form<?php
				if ( $this->form_type == 'post' ) {
?> enctype="multipart/form-data" method="post" action="admin.php?page=wponlinebackup&amp;section=<?php echo $this->section; ?>&amp;messages=true"><?php
				} else {
?> method="get">
<input type="hidden" name="page" value="wponlinebackup">
<input type="hidden" name="section" value="<?php echo $this->section; ?>">
<input type="hidden" name="messages" value="true"><?php
				}
?>

<?php wp_nonce_field($this->enable_form, $this->enable_form . 'nonce', false); ?>

<?php
			}

			// Create the metaboxes
			do_meta_boxes( 'wponlinebackup' . $this->section, 'normal', null );

			// Close the form wrapper if neccessary
			if ( $this->enable_form ) {
?>

</form><?php
			}
?>

</div></div>
<?php
		} else {
			call_user_func( $this->page_callback );
		}
?></div>
<?php
	}

	/*private*/ function Prepare_Overview()
	{
		// Forcing a config check?
		if ( isset( $_GET['config_verify'] ) ) {

			$this->WPOnlineBackup->bootstrap->Perform_Config_Verify();

			// Fall through and we'll use the updated information

		}

		// Prepare the front page.
		add_meta_box( 'wponlinebackup', __( 'Overview', 'wponlinebackup' ), array( & $this, 'Print_Overview' ), 'wponlinebackup', 'normal' );
	}

	/*public*/ function Print_Overview()
	{
		global $wpdb;

		// Get schedule information.
		$schedule = $this->WPOnlineBackup->scheduler->schedule;
		$status = $this->WPOnlineBackup->bootstrap->Fetch_Status();

		$dead_time = time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' );

		$checklist = array();
		$notices = array();

		// Check the schedule information
		if ( $schedule['schedule'] == '' ) {

			$notices[] = array(
				'icon'		=> 'error.png',
				'colour'	=> 'A80',
				'label'		=> __( 'Backups are not scheduled', 'wponlinebackup' ),
				'text'		=> __( 'Click \'Schedule\' to configure.', 'wponlinebackup' ),
			);

		} else if ( $schedule['target'] == 'online' && $this->WPOnlineBackup->Get_Setting( 'username' ) == '' ) {

			$notices[] = array(
				'icon'		=> 'exclamation.png',
				'colour'	=> 'A00',
				'label'		=> __( 'Scheduled backups will fail', 'wponlinebackup' ),
				'text'		=> sprintf( __( 'Backups are scheduled to run to the online vault but credentials have yet to be provided.<br />You can provide credentials on the <a href="%s">Online Backup Settings</a> page.', 'wponlinebackup' ), 'admin.php?page=wponlinebackup&amp;section=online' ),
			);

		} else {

			// Say whether we are running, about to start, or waiting on schedule
			if (
				$status['status'] != WPONLINEBACKUP_STATUS_NONE
				&& $status['time'] > $dead_time
			) {

				if ( $status['status'] == WPONLINEBACKUP_STATUS_STARTING ) {

					$message = array(
						'accept.png',
						'<img src="' . WPONLINEBACKUP_URL . '/images/ajax-loader.gif" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b>' .
							__( 'A backup is currently starting...', 'wponlinebackup' ) .
						'</b>',
					);

				} else {

					$message = array(
						'accept.png',
						'<img src="' . WPONLINEBACKUP_URL . '/images/ajax-loader.gif" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b>' .
							__( 'A backup is currently running...', 'wponlinebackup' ) .
						'</b>',
					);

				}

			} else if ( $schedule['next_trigger'] <= time() ) {

				$message = array(
					'accept.png',
					__( 'A backup is due to start at any moment...', 'wponlinebackup' ),
				);

			} else {

				$message = array(
					'accept.png',
					sprintf(
						__( '%s - The next backup will begin at %s.', 'wponlinebackup' ),
						$this->WPOnlineBackup->scheduler->schedule_list[ $schedule['schedule'] ],
						date_i18n( _x( 'jS M Y g.i.s A', 'Next backup start time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $schedule['next_trigger'] ) )
					),
				);

			}

			$checklist[] = array(
				'icon'		=> $message[0],
				'colour'	=> '0A0',
				'label'		=> __( 'Backups are scheduled', 'wponlinebackup' ),
				'text'		=> $message[1],
			);

		}

		// Check compression settings...
		if ( $this->WPOnlineBackup->Get_Env( 'deflate_available' ) ) {

			$checklist[] = array(
				'icon'		=> 'accept.png',
				'colour'	=> '0A0',
				'label'		=> __( 'Compression is available', 'wponlinebackup' ),
				'text'		=> __( 'Backup sizes will be greatly reduced.', 'wponlinebackup' ),
			);

		} else {

			$notices[] = array(
				'icon'		=> 'error.png',
				'colour'	=> 'A80',
				'label'		=> __( 'Compression is unavailable', 'wponlinebackup' ),
				'text'		=> __( 'ZLIB support is not enabled in PHP.<br />We recommend you contact your host to enable this; it will greatly reduce the size of backups.', 'wponlinebackup' ),
			);

		}

		// Check encryption settings...
		if ( $this->WPOnlineBackup->Get_Env( 'encryption_available' ) ) {

			if ( !in_array( $this->WPOnlineBackup->Get_Setting( 'encryption_type' ), $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) ) {

				$notices[] = array(
					'icon'		=> 'error.png',
					'colour'	=> 'A80',
					'label'		=> __( 'Encryption is not configured', 'wponlinebackup' ),
					'text'		=> sprintf( __( 'Encryption is highly recommended. You can configure this on the <a href="%s">General Settings</a> page.', 'wponlinebackup' ), 'admin.php?page=wponlinebackup&amp;section=settings' ),
				);

			} else {

				$checklist[] = array(
					'icon'		=> 'accept.png',
					'colour'	=> '0A0',
					'label'		=> __( 'Encryption is enabled', 'wponlinebackup' ),
					'text'		=> sprintf( __( 'Backups will be encrypted with %s level encryption.', 'wponlinebackup' ), $this->WPOnlineBackup->Get_Setting( 'encryption_type' ) ),
				);

			}

		} else {

			if ( version_compare( phpversion(), '5.1.2' ) < 0 )
				$message = __( 'Encryption requires the php-mcrypt extension and either php-hash or php-mhash.<br />We recommend you contact your host about this; encryption is highly recommended.', 'wponlinebackup' );
			else
				$message = __( 'Encryption requires the php-mcrypt extension.<br />We recommend you contact your host about this; encryption is highly recommended.', 'wponlinebackup' );

			$notices[] = array(
				'icon'		=> 'error.png',
				'colour'	=> 'A80',
				'label'		=> __( 'Encryption is unavailable', 'wponlinebackup' ),
				'text'		=> $message,
			);

		}

		// Check if full WordPress backup is configured...
		if ( !$schedule['backup_filesystem'] ) {

			$notices[] = array(
				'icon'		=> 'error.png',
				'colour'	=> 'A80',
				'label'		=> __( 'Filesystem backup is disabled in schedule', 'wponlinebackup' ),
				'text'		=> sprintf( __( 'Only the database will be backed up during scheduled backups. You can configure otherwise on the <a href="%s">Schedule</a> page.', 'wponlinebackup' ), 'admin.php?page=wponlinebackup&amp;section=schedule' ),
			);

		}

		// Check config verify information
		$config_verify = get_option( 'wponlinebackup_config_verify' );

		if ( isset( $config_verify['backups_secure'] ) ) {

			if ( $config_verify['backups_secure'] == -1 ) {

				$notices[] = array(
					'icon'		=> 'error.png',
					'colour'	=> 'A80',
					'label'		=> __( 'Backups may be visible to third-parties!', 'wponlinebackup' ),
					'text'		=> sprintf( __( 'Local backups are stored at <a href="%s">%s</a> and this location appears to be visible to third-parties.', 'wponlinebackup' ), esc_attr( $config_verify['backups_url'] ), esc_html( $config_verify['backups_url'] ) ) .
								'<br />' . __( 'You should turn off directory listing for this directory, or password protected it. Contact your host if you need any help.', 'wponlinebackup' ),
				);

			}

		}

		if ( isset( $config_verify['tmp_secure'] ) ) {

			if ( $config_verify['tmp_secure'] == -1 ) {

				$notices[] = array(
					'icon'		=> 'error.png',
					'colour'	=> 'A80',
					'label'		=> __( 'Temporary data may be visible to third-parties!', 'wponlinebackup' ),
					'text'		=> sprintf( __( 'Temporary data created during backup is stored at <a href="%s">%s</a> and this location appears to be visible to third-parties.', 'wponlinebackup' ), esc_attr( $config_verify['tmp_url'] ), esc_html( $config_verify['tmp_url'] ) ) .
								'<br />' . __( 'You should turn off directory listing for this directory, or password protected it. Contact your host if you need any help.', 'wponlinebackup' ),
				);

			}

		}

		// If we have a running backup, we need to choose the one before it, so choose the one where a.end IS NOT NULL
		// If we don't have a running backup, we need to choose the latest, even if it is running - but do an extra sanity check with the start time
		if ( $status['status'] != WPONLINEBACKUP_STATUS_NONE ) {
			if ( $status['time'] > $dead_time )
				$where = 'a.end IS NOT NULL';
			else
				$where = 'a.start <= ' . $dead_time;
		} else {
			$where = 'a.end IS NOT NULL';
		}

		$activity = $wpdb->get_row(
			'SELECT a.activity_id, a.start, a.end, a.type, a.comp, a.errors, a.warnings, a.compressed, a.encrypted, ' .
				'a.bsize, a.bcount, a.rsize, a.rcount, ' .
				'(SELECT COUNT(*) FROM `' . $wpdb->prefix . 'wponlinebackup_event_log` e WHERE e.activity_id = a.activity_id) AS events ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_activity_log` a ' .
			'WHERE ' . $where . ' ' .
			'ORDER BY a.start DESC, a.activity_id DESC LIMIT 1',
			ARRAY_A
		);

		// No activities?
		if ( is_null( $activity ) ) {

			$last = null;

		} else {

			// Fix activity end in case it is a dead running activity
			if ( is_null( $activity['end'] ) )
				$activity['end'] = $activity['start'];

			switch ( $activity['comp'] ) {

				// This case will be used when we picked a running activity that hasn't yet been updated to unexpected stop
				//case WPONLINEBACKUP_COMP_UNEXPECTED:
				default:
					$message = array( 'exclamation.png', 'A00', __( 'Unexpected stop', 'wponlinebackup' ) );
					break;

				case WPONLINEBACKUP_COMP_SUCCESSFUL:
					$message = array( 'accept.png', '0A0', $activity['warnings'] ? sprintf( _n( 'Successful (%d warning)', 'Successful (%d warnings)', $activity['warnings'] , 'wponlinebackup' ), $activity['warnings'] ) : __( 'Successful', 'wponlinebackup' ) );
					break;

				case WPONLINEBACKUP_COMP_PARTIAL:
					$message = array( 'error.png', 'A80', $activity['errors'] ? sprintf( _n( 'Partial (%d error)', 'Partial (%d errors)', $activity['errors'] , 'wponlinebackup' ), $activity['errors'] ) : __( 'Partial', 'wponlinebackup' ) );
					break;

				case WPONLINEBACKUP_COMP_STOPPED:
					$message = array( 'exclamation.png', 'A00', __( 'Stopped', 'wponlinebackup' ) );
					break;

				case WPONLINEBACKUP_COMP_FAILED:
				case WPONLINEBACKUP_COMP_TIMEOUT:
				case WPONLINEBACKUP_COMP_SLOWTIMEOUT:
					$message = array( 'exclamation.png', 'A00', __( 'Failed', 'wponlinebackup' ) );
					break;

				case WPONLINEBACKUP_COMP_MEMORY:
				case WPONLINEBACKUP_COMP_SLOWMEMORY:
					$message = array( 'exclamation.png', 'A00', __( 'Low Memory', 'wponlinebackup' ) );
					break;

				case WPONLINEBACKUP_COMP_MAINTENANCE:
					$message = array( 'exclamation.png', 'A00', __( 'Maintenance', 'wponlinebackup' ) );
					break;

			}

			if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_BACKUP ) $type = __( '(Manual)', 'wponlinebackup' );
			else $type = __( '(Scheduled)', 'wponlinebackup' );

			$last = array(
				'last'		=> htmlentities( date_i18n( _x( 'jS M Y g.i.s A', 'Last backup time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $activity['start'] ) ), ENT_QUOTES, 'UTF-8' ) . ' ' . $type,
				'icon'		=> $message[0],
				'colour'	=> $message[1],
				'comp'		=> $message[2],
				'summary'	=> sprintf(
					_n( 'The backup ran for %s and backed up %s in %d file.', 'The backup ran for %s and backed up %s in %d files.', $activity['bcount'] , 'wponlinebackup' ),
					WPOnlineBackup_Formatting::Fix_Time( $activity['end'] - $activity['start'] ),
					WPOnlineBackup_Formatting::Fix_B( $activity['bsize'] ),
					$activity['bcount']
				) . ' ' . sprintf(
					_n( 'A total of %s in %d file was scanned.', 'A total of %s in %d files were scanned.', $activity['rcount'] , 'wponlinebackup' ),
					WPOnlineBackup_Formatting::Fix_B( $activity['rsize'] ),
					$activity['rcount']
				)
			);

		}
?>
<p style="float: right; margin: 0; width: 200px">
	<a href="http://www.backup-technology.com/online-backup-for-wordpress/"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/logo.png" alt="Backup Technology" style="border: 0; width: 200px; height: 36px"></a><br><br>
	<i>Plugin version <?php echo WPONLINEBACKUP_VERSION; ?></i>
</p>
<h4><?php echo __( 'Latest backup:', 'wponlinebackup' ); ?></h4>
<div class="inside" style="margin-right: 200px"><p>
<?php
		if ( is_null( $last ) ) {
?>
	<i>A backup has never run.</i>
<?php
		} else {
?>
	<?php echo $last['last']; ?><br>
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $last['icon']; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b style="color: #<?php echo $last['colour']; ?>"><?php echo $last['comp']; ?></b><br>
	<?php echo $last['summary'];
		}
?>
</p></div>
<?php
		if ( count( $checklist ) ) {
?>
<h4><?php echo __( 'Configuration checklist:', 'wponlinebackup' ); ?></h4>
<div class="inside" style="margin-right: 200px"><table cellspacing="0" cellpadding="0" border="0" style="width: 100%; border: 0; margin: 0; padding: 0"><tbody>
<?php
			foreach ( $checklist as $item ) {
?>
<tr>
	<td style="width: 25%; border: 0; padding: 2px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $item['icon']; ?>" alt="" style="width: 16px; height: 16px; vertical-align: middle">&nbsp;<b style="color: #<?php echo $item['colour']; ?>"><?php echo $item['label']; ?></b></td>
	<td style="border: 0; padding: 2px"><?php echo $item['text']; ?></td>
</tr>
<?php
			}
?>
</tbody></table></div>
<?php
		}

		if ( count( $notices ) ) {
?>
<h4><?php echo __( 'Notices:', 'wponlinebackup' ); ?></h4>
<div class="inside" style="margin-right: 200px"><table cellspacing="0" cellpadding="0" border="0" style="width: 100%; border: 0; margin: 0; padding: 0"><tbody>
<?php
			foreach ( $notices as $item ) {
?>
<tr>
	<td style="width: 25%; border: 0; padding: 2px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $item['icon']; ?>" alt="" style="width: 16px; height: 16px; vertical-align: middle">&nbsp;<b style="color: #<?php echo $item['colour']; ?>"><?php echo $item['label']; ?></b></td>
	<td style="border: 0; padding: 2px"><?php echo $item['text']; ?></td>
</tr>
<?php
			}
?>
</tbody></table></div>
<?php
		}
?>
<p><?php
		$check_config_url = 'admin.php?page=wponlinebackup&amp;section=&amp;config_verify';
		if ( isset( $config_verify['last_update'] ) )
			echo sprintf( __( 'The configuration check was last completed at %s. <a href="%s">Check my configuration now</a>.', 'wponlinebackup' ), date_i18n( _x( 'jS M Y g.i.s A', 'Last configuration check time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $config_verify['last_update'] ) ), $check_config_url );
		else
			echo sprintf( __( 'No configuration check has been performed yet. <a href="%s">Check my configuration now</a>.', 'wponlinebackup' ), $check_config_url );
?></p>
<div style="clear: right"></div>
<?php
	}

	/*private*/ function Prepare_Backup()
	{
		$form_submitted = ( array_key_exists( 'backupnonce', $_POST ) && wp_verify_nonce( strval( $_POST['backupnonce'] ), 'backup' ) );

		// Are we stopping a backup?
		if ( $form_submitted && isset( $_POST['stop'] ) ) {

			$this->WPOnlineBackup->bootstrap->Stop();

			// Backup stop requested, redirect to the monitor page so refresh does not resubmit
			wp_redirect( 'admin.php?page=wponlinebackup&section=backup&monitor=true' );

			exit;

		}

		// If a backup is running or we are refreshing the monitor page, show the monitor page
		// NOTE - We refer to this $status variable during download request below and use it as entropy in the nonce generation
		$status = $this->WPOnlineBackup->bootstrap->Fetch_Status();
		if (
			(
				array_key_exists( 'monitor', $_GET )
				&& strval( $_GET['monitor'] ) == 'true'
			)
			|| (
				$status['status'] != WPONLINEBACKUP_STATUS_NONE
				&& $status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
			)
		) {

			// Queue the AJAX scripts.
			wp_enqueue_script(
				'wponlinebackup_progress',
				WPONLINEBACKUP_URL . '/js/progress.js',
				array(
					'jquery',
				),
				'2013091001'
			);
			wp_localize_script(
				'wponlinebackup_progress',
				'WPOnlineBackup_Vars',
				array(
					'AJAX_URL'		=> admin_url( 'admin-ajax.php' ),
					'Plugin_URL'		=> WPONLINEBACKUP_URL,
					'Plugin_File'		=> WPONLINEBACKUP_FILE,
					'Page_URL'		=> 'admin.php?page=wponlinebackup',
					'String_Backup'		=> __( 'Backup', 'wponlinebackup' ),
					'Refresh_Interval'	=> 2,
					'Error_Threshold'	=> 5,
					'Kick_Start_Interval'	=> $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) + 2,
				)
			);

			// Get the result status
			$result = $this->Fetch_Progress();

			// Add the headers for the backup monitor page but only if not completed as it adds the refresh header
			if ( $result['progress'] != 100 )
				add_action( 'admin_head', array( & $this, 'Head_Backup_Monitor' ) );

			// Prepare the backup monitor page
			add_meta_box( 'wponlinebackupbackupmonitor', __( 'Monitor Activity', 'wponlinebackup' ), array( & $this, 'Print_Backup_Monitor' ), 'wponlinebackupbackup', 'normal', 'default', array( 'result' => $result ) );

			// Enable the form wrapper
			$this->enable_form = 'backup';

			// Prevent the manual backup page from showing
			return;

		}

		$config = false;

		// Are we starting a backup? Downloading the latest? Deleting it?
		if ( $form_submitted ) {

			if ( isset( $_POST['download'] ) ) {

				// Try to start the download - if it isn't found, just display the backup page
				if ( isset( $_POST['file'] ) ) {

					$this->_Download_Local( strval( $_POST['file'] ) );

				}

			} else if ( isset( $_POST['delete'] ) ) {

				// Delete the file - if it isn't found, just display the backup page
				if ( isset( $_POST['file'] ) ) {

					if ( $this->_Delete_Local( strval( $_POST['file'] ) ) ) {

						// Redirect so refresh does not resubmit
						wp_redirect( 'admin.php?page=wponlinebackup&section=backup&messages=true' );

						exit;

					}

				}

			} else {

				$errors = array();

				// Initialize the configuration from the form variables
				$config = array(
					'backup_database'	=> array_key_exists( 'backup_database', $_POST ) && strval( $_POST['backup_database'] ) == '1',
					'backup_filesystem'	=> array_key_exists( 'backup_filesystem', $_POST ) && strval( $_POST['backup_filesystem'] ) == '1',
				);

				// Grab the backup target
				if ( array_key_exists( 'target', $_POST ) && array_search( $_POST['target'] = strval( $_POST['target'] ), array( 'online', 'download', 'email' ) ) !== false )
					$config['target'] = $_POST['target'];
				else
					$config['target'] = 'online';

				// Check we are logged in before we allow online backup
				if ( $config['target'] == 'online' && $this->WPOnlineBackup->Get_Setting( 'username' ) == '' ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> __( 'You cannot start an Online Backup as the plugin is not yet logged into the online backup vault. Click \'Online Backup Settings\' for more information.', 'wponlinebackup' ),
					);
				}

				// Validate the email address if we are emailing
				if ( $config['target'] == 'email' ) {
	
					$config['email_to'] = array_key_exists( 'email_to', $_POST ) ? stripslashes( strval( $_POST['email_to'] ) ) : '';
	
					if ( !preg_match( '/^.+@.+$/', $config['email_to'] ) ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> __( 'The email address you specified is not valid.', 'wponlinebackup' ),
						);
					}
	
				} else {
	
					$config['email_to'] = '';
	
				}

				// Disabling encryption for local/email?
				$config['disable_encryption'] = array_key_exists( 'disable_encryption', $_POST ) && strval( $_POST['disable_encryption'] ) == '1';

				// Check we're not disabling on online - that's not valid
				if ( $config['disable_encryption'] && $config['target'] == 'online' ) {

					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> __( 'Encryption can not be bypassed for backups to the online vault', 'wponlinebackup' ),
					);

				}

				// Check we are actually backing something up
				if ( !$config['backup_database'] && !$config['backup_filesystem'] ) {

					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> __( 'You did not select anything to backup. Please choose whether to backup the database, filesystem or both.', 'wponlinebackup' ),
					);

				}

				if ( count( $errors ) ) {

					$this->Register_Messages( $errors );

				} else {

					if ( ( $ret = $this->WPOnlineBackup->bootstrap->Start( $config, WPONLINEBACKUP_ACTIVITY_BACKUP ) ) === true ) {

						// Backup started fine, redirect to the monitor page so refresh does not resubmit
						wp_redirect( 'admin.php?page=wponlinebackup&section=backup&monitor=true' );

						exit;

					} else {

						if ( $ret === false ) {

							// Could not start the backup - one may have already just started - register the error message
							$this->Register_Messages( array( array(
								'icon'	=> 'error',
								'text'	=> __( 'The backup could not be started; another backup is already in progress.', 'wponlinebackup' ),
							) ) );

						} else {

							// Some other error
							$this->Register_Messages( array( array(
								'icon'	=> 'error',
								'text'	=> sprintf( __( 'The backup could not be started; the following error was encountered during initialisation: %s.', 'wponlinebackup' ), $ret ),
							) ) );

						}

					}

				}

			}

		}

		// Default configuration
		if ( $config === false ) {

			$config = array(
				'backup_database'	=> 1,
				'backup_filesystem'	=> 1,
				'target'		=> 'online',
				'email_to'		=> get_bloginfo( 'admin_email' ),
				'disable_encryption'	=> false,
			);

		}

		// Add the extra scripts for Start Backup section
		wp_enqueue_script( 'jquery' );
		add_action( 'admin_head', array( & $this, 'Head_Backup_Start' ) );

		// Prepare the manual backup page.
		add_meta_box( 'wponlinebackupbackupstart', __( 'Start Backup', 'wponlinebackup' ), array( & $this, 'Print_Backup_Start' ), 'wponlinebackupbackup', 'normal', 'default', array( 'config' => $config ) );

		// Enable the form wrapper
		$this->enable_form = 'backup';
	}

	/*public*/ function Head_Backup_Start()
	{
?>
<script type="text/javascript">
//<![CDATA[
jQuery(function($)
{
	$('#target_online, #target_download').click(function()
	{
		$('#target_email_toggle').hide();
	});
	$('#target_email').click(function()
	{
		$('#target_email_toggle').show();
	});
	if ( !$('#target_email').is(':checked') )
		$('#target_email_toggle').hide();
	$('#target_online').click(function()
	{
		$('#disable_encryption_toggle').hide();
	});
	$('#target_download, #target_email').click(function()
	{
		$('#disable_encryption_toggle').show();
	});
	if ( !$('#target_download').is(':checked') && !$('#target_email').is(':checked') )
		$('#disable_encryption_toggle').hide();
});
//]]>
</script>
<?php
	}

	/*public*/ function Print_Backup_Start( $post, $metadata )
	{
		$config = $metadata['args']['config'];
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/dvd.png" alt=""></p>
<p><?php _e( 'Each time you run a backup on-demand you can select whether to backup the database, the filesystem, or both.', 'wponlinebackup' ); ?></p>
<p><?php _e( 'Additionally, you can temporarily disable encryption if creating a local backup.', 'wponlinebackup' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tbody>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 14px"><label style="font-weight: bold"><?php _e( 'Backup selection:', 'wponlinebackup' ); ?></label></th>
<td><p><input name="backup_database" type="checkbox" id="backup_database"<?php
		if ( $config['backup_database'] ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="backup_database"><?php echo __( 'Database', 'wponlinebackup' ); ?></label><br>
<input name="backup_filesystem" type="checkbox" id="backup_filesystem"<?php
		if ( $config['backup_filesystem'] ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="backup_filesystem"><?php echo __( 'Filesystem', 'wponlinebackup' ); ?></label></p>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 14px"><label style="font-weight: bold"><?php _e( 'Backup type:', 'wponlinebackup' ); ?></label></th>
<td><p><input name="target" type="radio" id="target_online"<?php
		if ( $config['target'] == 'online' ) {
?> checked="checked"<?php
		}
?> value="online">&nbsp;<label for="target_online"><?php _e( 'Online - Send an incremental backup to the Online Backup for WordPress Vault', 'wponlinebackup' ); ?></label><br>
<input name="target" type="radio" id="target_download"<?php
		if ( $config['target'] == 'download' ) {
?> checked="checked"<?php
		}
?> value="download">&nbsp;<label for="target_download"><?php _e( 'Local - Generate a downoadable full backup and store it locally', 'wponlinebackup' ); ?></label><br>
<input name="target" type="radio" id="target_email"<?php
		if ( $config['target'] == 'email' ) {
?> checked="checked"<?php
		}
?> value="email">&nbsp;<label for="target_email"><?php _e( 'Email - Generate a full backup and email it to the specified address', 'wponlinebackup' ); ?></label></p>
</tr>
</tbody>
<tbody id="target_email_toggle">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="email_to" style="font-weight: bold"><?php _e( 'Address to use when emailing:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="text" style="width: 250px" id="email_to" name="email_to" value="<?php echo htmlentities( $config['email_to'], ENT_QUOTES, 'UTF-8' ); ?>"></p></td>
</tr>
</tbody>
<tbody id="disable_encryption_toggle">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 14px"><label style="font-weight: bold"><?php _e( 'Encryption bypass:', 'wponlinebackup' ); ?></label></th>
<td><p><input name="disable_encryption" type="checkbox" id="disable_encryption"<?php
		if ( $config['disable_encryption'] ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="disable_encryption"><?php _e( 'Bypass encryption for this backup (Only valid for Local and Email backups)', 'wponlinebackup' ); ?></label></p>
</tr>
</tbody>
<tbody>
<tbody>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Start Manual Backup', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
</tbody>
</table>
<?php
	}

	/*public*/ function Head_Backup_Monitor()
	{
?>
<noscript><meta http-equiv="refresh" content="5"></noscript>
<?php
	}

	/*public*/ function Print_Backup_Monitor( $post, $metadata )
	{
		$result = $metadata['args']['result'];

		// Fix jQuery bug - don't let width be 0% - brought about by WordPress 3.1's update of jQuery (not sure of specific jQuery version)
		// We do this same fix in progress.js when we update the progress bar
		if ( $result['progress'] == 0 )
			$result['progress'] = 1;
?>
<p>
	<img id="wponlinebackup_message_image" src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $result['message'][0]; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<span id="wponlinebackup_message_text"><?php echo htmlentities( $result['message'][1], ENT_QUOTES ); ?></span>
</p>
<div style="margin: 1em 0; text-align: center; height: 20px">
<div style="text-align: left; width: 90%; height: 20px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progressctile.gif'); background-repeat: repeat-x; float: left; margin-right: 10px">
<div style="height: 20px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresscl.gif'); background-repeat: no-repeat">
<div style="height: 20px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresscr.gif'); background-repeat: no-repeat; background-position: top right; padding: 1px"><div>
<div id="wponlinebackup_progress_bar" style="margin-right: auto; width: <?php echo $result['progress']; ?>%; height: 18px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresshead.gif'); background-repeat: no-repeat; background-position: top right; overflow-x: hidden">
<div style="height: 18px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresstile.gif'); background-repeat: repeat-x; background-position: top right; margin-right: 3px">
<div style="width: 7px; height: 18px; overflow: hidden"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/progressbase.gif" style="width: 3px; height: 18px" alt=""></div>
</div>
</div>
</div></div>
</div>
</div>
<p id="wponlinebackup_progress_text" style="margin: 0; font-weight: bold; font-size: 15px; font-family: 'Georgia', 'Times New Roman', 'Bitstream Charter', 'Times', serif"><?php echo $result['progress']; ?>%</p>
</div>
<?php
		// If activity_id is not set, we're showing the "A backup has never run" screen, so we can just omit these bits
		if ( isset( $result['activity_id'] ) ) {
?>
<p id="wponlinebackup_events" style="float: left; margin: 0<?php
			// If the backup hasn't yet got an activity ID, hide this bit
			if ( $result['activity_id'] == 0 ) {
?>; display: none<?php
			}
?>">
	<b><a id="wponlinebackup_events_link" href="admin.php?page=wponlinebackup&amp;section=events&amp;activity=<?php echo $result['activity_id']; ?>"><?php echo _x( 'Events', 'Backup progress', 'wponlinebackup' ); ?></a>:</b>
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/exclamation.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<?php echo _x( 'Errors', 'Backup progress', 'wponlinebackup' ); ?>: <span id="wponlinebackup_errors"><?php echo $result['errors']; ?></span>
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/error.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<?php echo _x( 'Warnings', 'Backup progress', 'wponlinebackup' ); ?>: <span id="wponlinebackup_warnings"><?php echo $result['warnings']; ?></span>
</p>
<?php
			// If the backup is still running, show the stop button
			if ( $result['progress'] != 100 ) {
?>
<p id="wponlinebackup_stop_message" style="float: right; margin: 0 10% 0 0"><input id="wponlinebackup_stop_button" type="submit" name="stop" class="button-primary"<?php
				// If the backup status is WPONLINEBACKUP_STATUS_STOPPING then disable the stop button to give feedback that we're stopping
				if ( $result['status'] == WPONLINEBACKUP_STATUS_STOPPING ) {
?> value="<?php echo __( 'Stopping...', 'wponlinebackup' ); ?>" disabled="disabled"<?php
				} else {
?> value="<?php echo __( 'Stop Backup', 'wponlinebackup' ); ?>"<?php
				}
?>></p>
<?php
			}
?>
<div style="clear: both"></div>
<?php
		}

		// If the backup is still running, show the notice that we can navigate away from the page
		if ( $result['progress'] != 100 ) {
?><div id="wponlinebackup_background_message">
<p><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/information.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'You can navigate away from this page if you do not wish to wait - the activity will be processed silently in the background.', 'wponlinebackup' ); ?></i></p>
</div>
<?php
		}

		// If we're a backup and size is present, it means we're a running or successful local backup, so add on the download message, hiding if the backup isn't completed - the JS will reveal it
		if ( ( $result['type'] == WPONLINEBACKUP_ACTIVITY_BACKUP || $result['type'] == WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP ) && isset( $result['size'] ) ) {
?><div id="wponlinebackup_completed_message"<?php
			if ( $result['progress'] != 100 ) {
?> style="display: none"<?php
			}
?>>
<p><?php echo sprintf( __( 'The full backup was completed and has been stored on your server as <span id="wponlinebackup_completed_file">%s</span>.', 'wponlinebackup' ), esc_html( $result['file'] ) ); ?><br>
<?php _e( 'You can download it now, and if you do not wish to keep it, delete it.', 'wponlinebackup' ); ?><br>
<?php
			if ( $result['progress'] == 100 ) {
				$size = WPOnlineBackup_Formatting::Fix_B( $result['size'] );
			} else {
				$size = '<span id="wponlinebackup_completed_size"></span>';
			}
			printf( __( 'The size of the backup file is: %s.', 'wponlinebackup' ), $size );
?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><input id="wponlinebackup_completed_form" type="hidden" name="file" value="<?php echo esc_html( $result['file'] ); ?>"></th>
<td><p><input type="submit" name="download" value="<?php echo __( 'Download', 'wponlinebackup' ); ?>" class="button-primary">&nbsp;
<input type="submit" name="delete" value="<?php echo __( 'Delete', 'wponlinebackup' ); ?>" class="button-secondary"></p></td>
</tr>
</table>
</div>
<?php
		}

		// If we're a decryption and size is present, it means we're a running or successful decrypt, so add on the decrypted message, hiding if the decrypt isn't completed - the JS will reveal it
		if ( $result['type'] == WPONLINEBACKUP_ACTIVITY_DECRYPT && isset( $result['size'] ) ) {
?><div id="wponlinebackup_completed_message"<?php
			if ( $result['progress'] != 100 ) {
?> style="display: none"<?php
			}
?>>
<p><?php echo sprintf( __( 'The backup has been decrypted and has been stored on your server as <span id="wponlinebackup_completed_file">%s</span>.', 'wponlinebackup' ), esc_html( $result['file'] ) ); ?><br>
<?php _e( 'You can download it now, and if you do not wish to keep it, delete it.', 'wponlinebackup' ); ?><br>
<?php
			if ( $result['progress'] == 100 ) {
				$size = WPOnlineBackup_Formatting::Fix_B( $result['size'] );
			} else {
				$size = '<span id="wponlinebackup_completed_size"></span>';
			}
			printf( __( 'The size of the decrypted backup file is: %s.', 'wponlinebackup' ), $size );
?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><input id="wponlinebackup_completed_form" type="hidden" name="file" value="<?php echo esc_html( $result['file'] ); ?>"></th>
<td><p><input type="submit" name="download" value="<?php echo __( 'Download', 'wponlinebackup' ); ?>" class="button-primary">&nbsp;
<input type="submit" name="delete" value="<?php echo __( 'Delete', 'wponlinebackup' ); ?>" class="button-secondary"></p></td>
</tr>
</table>
</div>
<?php
		}
	}

	/*private*/ function _Download_Local( $filename )
	{
		global $wpdb;

		$esc_filename = $filename;
		$wpdb->escape_by_ref( $esc_filename );

		$result = $wpdb->get_row(
			'SELECT filename ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_local` ' .
			'WHERE filename = \'' . $esc_filename . '\'',
			ARRAY_A
		);

		// Does it even exist?
		if ( !is_null( $result ) ) {

			// Redirect to download the file
			wp_redirect( WPONLINEBACKUP_LOCALBACKUPURL . '/' . $result['filename'] );

			exit;

		}

		return false;
	}

	/*private*/ function _Delete_Local( $filename )
	{
		global $wpdb;

		// Delete the file - if it isn't found, just display the backup page
		$esc_filename = $filename;
		$wpdb->escape_by_ref( $esc_filename );

		$result = $wpdb->get_row(
			'SELECT filename ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_local` ' .
			'WHERE filename = \'' . $esc_filename . '\'',
			ARRAY_A
		);

		if ( !is_null( $result ) ) {

			if ( file_exists( $this->WPOnlineBackup->Get_Setting( 'backup_directory' ) . '/' . $result['filename'] ) ) {

				$ret = @unlink( $this->WPOnlineBackup->Get_Setting( 'backup_directory' ) . '/' . $result['filename'] );

			} else {

				$ret = true;

			}

			if ( $ret === true ) {

				$wpdb->query(
					'DELETE FROM `' . $wpdb->prefix . 'wponlinebackup_local` ' .
					'WHERE filename = \'' . $result['filename'] . '\''
				);

				$this->Register_Messages( array( array(
					'icon'	=> 'accept',
					'text'	=> __( 'Deleted backup.', 'wponlinebackup' ),
				) ) );

			} else {

				$ret = OBFW_Tidy_Exception();

				$this->Register_Messages( array( array(
					'icon'	=> 'error',
					'text'	=> sprintf( __( 'Failed to delete the backup: %s', 'wponlinebackup' ), $ret ),
				) ) );

			}

			return true;

		}

		return false;
	}

	/*private*/ function _Toggle_Lock_Local( $filename, $locked )
	{
		global $wpdb;

		// Unlock the file - if it isn't found, just display the backup page
		$result = $wpdb->update(
			$wpdb->prefix . 'wponlinebackup_local',
			array(
				'locked'	=> $locked ? 1 : 0,
			),
			array(
				'filename'	=> $filename,
			),
			array( '%d' ),
			array( '%s' )
		);

		if ( $result ) {

			$this->Register_Messages( array( array(
				'icon'	=> 'accept',
				'text'	=> $locked ? __( 'Locked backup.', 'wponlinebackup' ) : __( 'Unlocked backup.', 'wponlinebackup' ),
			) ) );

			return true;

		}

		return false;
	}

	/*private*/ function _Decrypt_Local( $filename, $form_submitted )
	{
		global $wpdb;

		// If the file doesn't exist in our database, just return true so we show local backups as normal
		$esc_filename = $filename;
		$wpdb->escape_by_ref( $esc_filename );

		$result = $wpdb->get_row(
			'SELECT filename, compressed ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_local` ' .
			'WHERE filename = \'' . $esc_filename . '\'',
			ARRAY_A
		);

		if ( is_null( $result ) )
			return true;

		// Is form submitted?
		if ( $form_submitted ) {

			$errors = array();

			$config = array(
				'compressed'	=> $result['compressed'],
				'file'		=> $result['filename'],
			);

			// Extract encryption settings
			if ( isset( $_POST['encryption_type'] ) )
				$config['enc_type'] = strval( $_POST['encryption_type'] );
			else
				$config['enc_type'] = $this->WPOnlineBackup->Get_Setting( 'encryption_type' );

			$length = 0;
			switch ( $config['enc_type'] ) {
				default:
					$config['enc_type'] = '';
				case 'AES256':
					$length = 32;
				case 'AES196':
					if ( $length == 0 )
						$length = 24;
				case 'AES128':
					if ( $length == 0 )
						$length = 16;
				case 'DES':
					if ( $length == 0 )
						$length = 8;
			}

			// Grab the encryption key
			if ( isset( $_POST['encryption_key'] ) )
				$config['enc_key'] = ini_get( 'magic_quotes_gpc' ) ? stripslashes( strval( $_POST['encryption_key'] ) ) : strval( $_POST['encryption_key'] );
			else
				$config['enc_key'] = '';

			// Validate the length of the encryption key
			if ( $config['enc_type'] != '' ) {

				if ( strlen( $config['enc_key'] ) > $length ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> sprintf( __( 'The encryption key specified must be no longer than %1$d characters in length for %2$s encryption.', 'wponlinebackup' ), $length, $config['enc_type'] ),
					);
				} else if ( strlen( $config['enc_key'] ) < 1 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> __( 'Please specify an encryption key.', 'wponlinebackup' ),
					);
				}

			} else {

				if ( strlen( $config['enc_key'] ) > $length ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> sprintf( __( 'The encryption key specified must be no longer than %d characters in length.', 'wponlinebackup' ), $length ),
					);
				}

			}

			if ( count( $errors ) ) {

				$this->Register_Messages( $errors );

			} else {

				if ( ( $ret = $this->WPOnlineBackup->bootstrap->Start( $config, WPONLINEBACKUP_ACTIVITY_DECRYPT ) ) === true ) {

					// Decryption started fine, redirect to the monitor page so refresh does not resubmit
					wp_redirect( 'admin.php?page=wponlinebackup&section=backup&monitor=true' );

					exit;

				} else {

					if ( $ret === false ) {

						// Could not start the decryption - one may have already just started - register the error message
						$this->Register_Messages( array( array(
							'icon'	=> 'error',
							'text'	=> __( 'The decrypt could not be started; another activity is already in progress.', 'wponlinebackup' ),
						) ) );

					} else {

						// Some other error
						$this->Register_Messages( array( array(
							'icon'	=> 'error',
							'text'	=> sprintf( __( 'The decrypt could not be started; the following error was encountered during initialisation: %s.', 'wponlinebackup' ), $ret ),
						) ) );

					}

				}

			}

		}

		// Prepare the decryption form
		add_meta_box( 'wponlinebackupdecryptform', _x( 'Decrypt Backup', 'Decrypt subsection title', 'wponlinebackup' ), array( & $this, 'Print_Decrypt_Form' ), 'wponlinebackuplocal', 'normal', 'default', array( 'filename' => $filename ) );

		// Enable the form
		if ( $this->WPOnlineBackup->Get_Env( 'encryption_available' ) )
			$this->enable_form = 'decrypt';

		// Don't show the local backups screen
		return false;
	}

	/*private*/ function Prepare_Local()
	{
		$redirect = false;
		$show_local = true;
		$encryption_available = $this->WPOnlineBackup->Get_Env( 'encryption_available' );

		// Did we ask for a download or a deletion?
		if ( isset( $_GET['download'] ) ) {

			$redirect = $this->_Download_Local( strval( $_GET['download'] ) );

		} else if ( isset( $_GET['delete'] ) ) {

			$redirect = $this->_Delete_Local( strval( $_GET['delete'] ) );

		} else if ( isset( $_GET['unlock'] ) ) {

			$redirect = $this->_Toggle_Lock_Local( strval( $_GET['unlock'] ), false );

		} else if ( isset( $_GET['lock'] ) ) {

			$redirect = $this->_Toggle_Lock_Local( strval( $_GET['lock'] ), true );

		} else if ( $encryption_available && isset( $_GET['decrypt'] ) ) {

			$show_local = $this->_Decrypt_Local( strval( $_GET['decrypt'] ), false );

		} else if ( $encryption_available && isset( $_POST['decryptnonce'] ) && wp_verify_nonce( strval( $_POST['decryptnonce'] ), 'decrypt' ) && isset( $_POST['decrypt'] ) ) {

			$show_local = $this->_Decrypt_Local( strval( $_POST['decrypt'] ), true );

		}

		// Redirect so refresh does not resubmit
		if ( $redirect )
			wp_redirect( 'admin.php?page=wponlinebackup&section=local&messages=true' );

		// Prepare the local backups table
		if ( $show_local )
			add_meta_box( 'wponlinebackuplocaltable', __( 'Local Backups', 'wponlinebackup' ), array( & $this, 'Print_Local_Table' ), 'wponlinebackuplocal', 'normal' );
	}

	/*public*/ function Print_Local_Table()
	{
		global $wpdb;
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/folders.png" alt=""></p>
<p><?php _e( 'Below is a list of backup files currently stored on your server. <strong>It is highly recommended that these backups are downloaded and stored away from the server to prevent a total loss if the server fails.</strong>', 'wponlinebackup' ); ?></p>
<p><?php echo sprintf( __( 'Old files are deleted automatically to keep you below %s storage space whilst still keeping a minimum of %s files (configurable in General Settings.) LOCKED files will never be deleted and will not be counted towards the storage space.', 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $this->WPOnlineBackup->Get_Setting( 'local_max_storage' ) * 1048576 ), $this->WPOnlineBackup->Get_Setting( 'local_min_gens' ) ); ?></p>
<p><?php echo sprintf( __( 'Encrypted files, which have a .ENC file extension, can be decrypted from this page; the result of the decryption will be stored next to the original file in this folder. You can also upload files to this folder via FTP for decryption or storage; the location is: %s.', 'wponlinebackup' ), $this->WPOnlineBackup->Get_Setting( 'backup_directory' ) ); ?></p>
<table class="widefat" style="clear: left" cellspacing="0">
<thead>
<tr>
<th scope="col" id="filename" class="manage-column column-filename" style=""><?php echo __( 'File Name', 'wponlinebackup' ); ?></th>
<th scope="col" id="filesize" class="manage-column column-filesize" style=""><?php echo __( 'File Size', 'wponlinebackup' ); ?></th>
<th scope="col" id="created" class="manage-column column-created" style=""><?php echo __( 'Created', 'wponlinebackup' ); ?></th>
<th scope="col" id="settings" class="manage-column column-settings" style=""><?php echo __( 'Settings', 'wponlinebackup' ); ?></th>
<th scope="col" id="actions" class="manage-column column-actions" style=""><?php echo __( 'Download / Delete', 'wponlinebackup' ); ?></th>
</tr>
</thead>
<tfoot>
<tr>
<th scope="col" class="manage-column column-filename" style=""><?php echo __( 'File Name', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-filesize" style=""><?php echo __( 'File Size', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-created" style=""><?php echo __( 'Created', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-settings" style=""><?php echo __( 'Settings', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-actions" style=""><?php echo __( 'Download / Delete', 'wponlinebackup' ); ?></th>
</tr>
</tfoot>
<tbody>
<?php
		// For UTF8_Validate
		require_once WPONLINEBACKUP_PATH . '/include/functions.php';

		// Just don't show backups we've marked for deletion
		$result = $wpdb->get_results(
			'SELECT filename, locked, filesize, creation_date, compressed, encrypted ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_local`',
			ARRAY_A
		);

		// Create a map of filenames and index creation dates and remove any missing files
		$backup_dir = $this->WPOnlineBackup->Get_Setting( 'backup_directory' );

		$filenames = array();
		$creation_dates = array();

		foreach ( $result as $key => $download ) {

			// Does it actually exist?
			if ( !file_exists( $backup_dir . '/' . $download['filename'] ) ) {

				$esc_filename = $download['filename'];
				$wpdb->escape_by_ref( $esc_filename );

				// Remove from database
				$wpdb->query(
					'DELETE FROM `' . $wpdb->prefix . 'wponlinebackup_local` ' .
					'WHERE filename = \'' . $esc_filename . '\''
				);

				unset( $result[ $key ] );

				continue;

			}

			$filenames[ $download['filename'] ] = & $download;
			$creation_dates[] = $download['creation_date'];

		}

		// Browse the backups directory and populate any missing entries
		if ( false !== ( $d = @opendir( $backup_dir ) ) ) {

			while ( false !== ( $f = @readdir( $d ) ) ) {

				// Ignore dot files - also ignore index.html
				if ( substr( $f, 0, 1 ) == '.' || $f == 'index.html' )
					continue;

				// Do we have it already?
				if ( isset( $filenames[ $f ] ) )
					continue;

				// Missing entry, get some information we need
				$created = @filemtime( $backup_dir . '/' . $f );
				$size = @filesize( $backup_dir . '/' . $f );

				if ( $created === false || $size === false )
					continue;

				$encrypted = preg_match( '#\\.enc$#i', substr( $f, -4 ) ) ? 1 : 0;

				$add = array(
					'locked'	=> 1, // We're not sure if we own this file... mark as locked
					'filesize'	=> $size,
					'creation_date'	=> $created,
					'compressed'	=> 0, // Maybe we can try detect this from the ENC/ZIP headers...
					'encrypted'	=> $encrypted,
					'filename'	=> $f,
				);

				// If not UTF-8 valid, add it to the list but not the database since it won't be addable
				if ( $f != ( $futf = WPOnlineBackup_Functions::UTF8_Validate( $f ) ) ) {

					$add['invalid_utf8'] = sprintf( __( '%s <b>(Contains invalid characters, please rename via FTP)</b>', 'wponlinebackup' ), $futf );

				} else {

					// Add to database
					$wpdb->insert(
						$wpdb->prefix . 'wponlinebackup_local',
						$add,
						array( '%d', '%d', '%d', '%d', '%d', '%s' )
					);

				}

				// Add to results
				$result[] = & $add;
				$filenames[ $add['filename'] ] = & $add;
				$creation_dates[] = $created;

				unset( $add );

			}

			@closedir( $d );

		}

		// Sort
		array_multisort( $creation_dates, SORT_DESC, $result );

		// Display the backups, or an empty message
		if ( count( $result ) == 0 ) {
?>
<tr>
<td colspan="5" style="text-align: center; padding: 12px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/information.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php _e( 'No local backups exist.', 'wponlinebackup' ); ?></i></td>
</tr>
<?php
		} else {

			$c = 0;

			foreach ( $result as $download ) {
?>
<tr<?php
				if ( $c++ % 2 == 0 ) {
?> class="alternate"<?php
				}
?> valign="top">
<td class="column-filename"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php
				if ( preg_match( '#\\.([A-Za-z]+)$#', $download['filename'], $matches ) )
					$ext = $matches[1];
				else
					$ext = '';

				switch ( $ext ) {

					case 'enc':
						echo 'lock_small.png';
						break;

					case 'zip':
					case 'gz': // Legacy V1 database-only backups are GZ extension
						echo 'compress.png';
						break;

					default:
						echo 'page_white.png';
						break;

				}
?>" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<?php
				if ( $download['locked'] )
					echo sprintf( __( '%s <b>(LOCKED)</b>', 'wponlinebackup' ), esc_html( $download['filename'] ) );
				else if ( isset( $download['invalid_utf8'] ) )
					echo $download['invalid_utf8'];
				else
					echo esc_html( $download['filename'] );
?></td>
<td class="column-filesize"><?php
				echo WPOnlineBackup_Formatting::Fix_B( $download['filesize'] );
?></td>
<td class="column-created"><?php
				echo date_i18n( _x( 'jS M Y g.i.s A', 'Local backup creation time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $download['creation_date'] ) );
?></td>
<td class="column-settings"><?php
				$settings = array();

				if ( $download['compressed'] )
					$settings[] = array( 'compress.png', __( 'Compressed', 'wponlinebackup' ) );

				if ( $download['encrypted'] )
					$settings[] = array( 'lock_small.png', __( 'Encrypted', 'wponlinebackup' ) );

				if ( count( $settings ) ) {
					end( $settings );
					$last = key( $settings );
					foreach ( $settings as $key => $icon ) {
?><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $icon[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="<?php echo $icon[1]; ?>" title="<?php echo $icon[1]; ?>"><?php
						if ( $key != $last ) {
?> <?php
						}
					}
				}
?></td>
<td class="column-events"><a href="admin.php?page=wponlinebackup&amp;section=local&amp;download=<?php echo urlencode( $download['filename'] ); ?>"><?php echo __( 'Download', 'wponlinebackup' ); ?></a>
 | <a href="admin.php?page=wponlinebackup&amp;section=local&amp;delete=<?php echo urlencode( $download['filename'] ); ?>"><?php echo __( 'Delete', 'wponlinebackup' ); ?></a>
<?php
			if ( $download['locked'] ) {
?>
 | <a href="admin.php?page=wponlinebackup&amp;section=local&amp;unlock=<?php echo urlencode( $download['filename'] ); ?>"><?php echo __( 'Unlock', 'wponlinebackup' ); ?></a>
<?php
			} else {
?>
 | <a href="admin.php?page=wponlinebackup&amp;section=local&amp;lock=<?php echo urlencode( $download['filename'] ); ?>"><?php echo __( 'Lock', 'wponlinebackup' ); ?></a>
<?php
			}
			if ( $download['encrypted'] ) {
?>
 | <a href="admin.php?page=wponlinebackup&amp;section=local&amp;decrypt=<?php echo urlencode( $download['filename'] ); ?>"><?php echo __( 'Decrypt', 'wponlinebackup' ); ?></a>
<?php
			}
?></td>
</tr>
<?php
			}

		}
?>
</tbody>
</table>
<p style="text-align: center">
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/compress.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Compressed', 'wponlinebackup' ); ?></i>
	&nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/lock_small.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Encrypted', 'wponlinebackup' ); ?></i>
</p>
<?php
	}

	/*public*/ function Print_Decrypt_Form( $post, $metadata )
	{
?>
<input type="hidden" name="decrypt" value="<?php echo esc_attr( $metadata['args']['filename'] ); ?>" />
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/lock.png" alt=""></p>
<p><?php _e( 'You may use this section to decrypt an encrypted local backup that you have downloaded from the plugin. An encrypted backup will have the .ENC file extension.', 'wponlinebackup' ); ?></p>
<p><?php _e( 'If you try to upload a file to decrypt and nothing happens, or you receive an error or blank page, your backup file may be too large to upload. The maximum size of a file your web server can receive is shown next to the file selector on the form.', 'wponlinebackup' ); ?><br>
<?php _e( 'To upload files larger than the limit imposed by your web server, you can upload them via FTP. Just place them in the folder specified in the form below.', 'wponlinebackup' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<?php
// Is encryption available?
		if ( $this->WPOnlineBackup->Get_Env( 'encryption_available' ) ) {
			if ( count( $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) != count( $this->WPOnlineBackup->Get_Env( 'encryption_list' ) ) ) {
?>
<tr valign="top">
<td colspan="2"><p style="text-align: center"><span style="padding: 4px; display: inline-block; text-align: left; border: 1px dashed #000; background: #E9E999">
<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/error.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'Your blog does not have the following encryption types available.', 'wponlinebackup' ); ?></b><br>
<?php _e( 'If your backup file was encrypted using any of these types, you will not be able to decrypt it using this plugin installation.', 'wponlinebackup' ); ?><br>
<?php _e( 'You may need to contact your host about this.', 'wponlinebackup' ); ?><br><br>
<?php
				$missing = array_diff( $this->WPOnlineBackup->Get_Env( 'encryption_list' ), $this->WPOnlineBackup->Get_Env( 'encryption_types' ) );
				end( $missing );
				$last = key( $missing );
				foreach ( $missing as $type ) {
?>
<b><?php echo $type; ?></b><?php
					if ( $type != $last ) {
?><br>
<?php
					}
				}
?>
</span></p></td>
</tr>
<?php
			}
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="encryption_type" style="font-weight: bold"><?php _e( 'Encryption type:', 'wponlinebackup' ); ?></label></th>
<td><p><select id="encryption_type" name="encryption_type">
<?php
// Iterate and display available encryption types
			foreach ( $this->WPOnlineBackup->Get_Env( 'encryption_types' ) as $type ) {
?><option value="<?php echo $type; ?>"<?php
// Mark the recommended value as the default
				if ( $type == 'AES128' ) {
?> selected="selected"<?php
				}
?>><?php
// Pump out the type, and add recommendation labels
				if ( $type == 'AES128' ) printf( __( '%s [Recommended]', 'wponlinebackup' ), $type );
				else echo $type;
?></option>
<?php
			}
?></select></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="encryption_key" style="font-weight: bold"><?php _e( 'Encryption key:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="password" style="width: 250px" id="encryption_key" name="encryption_key" value=""></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Decrypt Backup', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
<?php
		} else {
// No encryption available
?>
<tr valign="top">
<td colspan="2"><p style="text-align: center"><span style="padding: 4px; display: inline-block; text-align: left; border: 1px dashed #000; background: #E9E999">
<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/exclamation.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'Encryption requires the php-mcrypt extension with AES or DES cipher support and a compatible message digest framework (php 5.1.2 where it is builtin, php-hash or php-mhash).', 'wponlinebackup' ); ?></b><br>
<?php _e( 'Your server does meet these requirements, therefore you cannot this plugin installation to decrypt any encrypted backups.', 'wponlinebackup' ); ?>
</span></p></td>
</tr>
<?php
		}
?>
</table>
<?php
	}

	/*private*/ function Prepare_Schedule()
	{
		// Ensure the scheduler is loaded
		$this->WPOnlineBackup->Load_Scheduler();

		// Are we saving settings?
		if ( isset( $_POST['schedulenonce'] ) && wp_verify_nonce( strval( $_POST['schedulenonce'] ), 'schedule' ) ) {

			$errors = array();

			// Extract the schedule options
			if ( isset( $_POST['schedule'] ) && isset( $this->WPOnlineBackup->scheduler->schedule_list[ $_POST['schedule'] = strval( $_POST['schedule'] ) ] ) )
				$this->WPOnlineBackup->scheduler->schedule['schedule'] = $_POST['schedule'];
			else
				$this->WPOnlineBackup->scheduler->schedule['schedule'] = '';

			if ( isset( $_POST['day'] ) && ( $_POST['day'] = intval( $_POST['day'] ) ) >= 0 && $_POST['day'] <= 6 )
				$this->WPOnlineBackup->scheduler->schedule['day'] = intval( $_POST['day'] );
			else
				$this->WPOnlineBackup->scheduler->schedule['day'] = 0;

			$this->WPOnlineBackup->scheduler->schedule['hour'] = array_key_exists( 'hour', $_POST ) ? strval( $_POST['hour'] ) : '00';

			if ( !preg_match( '/^(?:[0-1]?[0-9]|2[0-3])$/', $this->WPOnlineBackup->scheduler->schedule['hour'] ) ) {
				$errors[] = array(
					'icon'	=> 'error',
					'text'	=> __( 'The hour specified is not valid. The valid range is 0-23.', 'wponlinebackup' ),
				);
			}

			$this->WPOnlineBackup->scheduler->schedule['minute'] = array_key_exists( 'minute', $_POST ) ? strval( $_POST['minute'] ) : '00';

			if ( !preg_match( '/^[0-5]?[0-9]$/', $this->WPOnlineBackup->scheduler->schedule['minute'] ) ) {
				$errors[] = array(
					'icon'	=> 'error',
					'text'	=> __( 'The minute specified is not valid. The valid range is 0-59.', 'wponlinebackup' ),
				);
			}

			// Extract the backup options and the selected target
			if ( isset( $_POST['backup_database'] ) && strval( $_POST['backup_database'] ) == '1' )
				$this->WPOnlineBackup->scheduler->schedule['backup_database'] = true;
			else
				$this->WPOnlineBackup->scheduler->schedule['backup_database'] = false;

			if ( isset( $_POST['backup_filesystem'] ) && strval( $_POST['backup_filesystem'] ) == '1' )
				$this->WPOnlineBackup->scheduler->schedule['backup_filesystem'] = true;
			else
				$this->WPOnlineBackup->scheduler->schedule['backup_filesystem'] = false;

			if ( isset( $_POST['target'] ) && isset( $this->WPOnlineBackup->scheduler->target_list[ $_POST['target'] = strval( $_POST['target'] ) ] ) )
				$this->WPOnlineBackup->scheduler->schedule['target'] = $_POST['target'];
			else
				$this->WPOnlineBackup->scheduler->schedule['target'] = 'online';

			if ( $this->WPOnlineBackup->scheduler->schedule['target'] != '' ) {

				if ( !$this->WPOnlineBackup->scheduler->schedule['backup_database'] && !$this->WPOnlineBackup->scheduler->schedule['backup_filesystem'] ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> __( 'You did not select anything to backup. Please choose whether to backup the database, filesystem or both.', 'wponlinebackup' ),
					);
				}

			}

			// Validate the email address if we chose to email the backup
			$this->WPOnlineBackup->scheduler->schedule['email_to'] = isset( $_POST['email_to'] ) ? stripslashes( strval( $_POST['email_to'] ) ) : '';

			if ( $this->WPOnlineBackup->scheduler->schedule['email_to'] == '' ) {

				// Do not allow blank if sending via email
				if ( $this->WPOnlineBackup->scheduler->schedule['target'] == 'email' ) {

					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> __( 'Please specify an address to email the backup to.', 'wponlinebackup' ),
					);

				}

			} else if ( !preg_match( '/^[a-zA-Z0-9_\\-.]+@[a-zA-Z0-9_\\-.]+$/', $this->WPOnlineBackup->scheduler->schedule['email_to'] ) ) {

				$errors[] = array(
					'icon'	=> 'error',
					'text'	=> __( 'The email address you specified is not valid.', 'wponlinebackup' ),
				);

			}

			// If we have errors, show the form again with an error message
			if ( count( $errors ) ) {

				$this->Register_Messages( $errors );

			} else {

				// Restart the schedule, and save it
				$this->WPOnlineBackup->scheduler->Restart( true );

				// Register success message
				$this->Register_Messages( array( array(
					'icon'	=> 'accept',
					'text'	=> __( 'Saved schedule.', 'wponlinebackup' ),
				) ) );

				// Redirect to the settings page so refresh does not resubmit
				wp_redirect( 'admin.php?page=wponlinebackup&section=schedule&messages=true' );

				exit;

			}

		}

		// Add the extra scripts
		wp_enqueue_script( 'jquery' );
		add_action( 'admin_head', array( & $this, 'Head_Schedule_Form' ) );

		// Prepare the schedule page.
		add_meta_box( 'wponlinebackupscheduleform', __( 'Schedule', 'wponlinebackup' ), array( & $this, 'Print_Schedule_Form' ), 'wponlinebackupschedule', 'normal' );

		// Enable the form wrapper
		$this->enable_form = 'schedule';
	}

	/*public*/ function Head_Schedule_Form()
	{
?>
<script type="text/javascript">
//<![CDATA[
jQuery(function($)
{
	$('#target_online, #target_download').click(function()
	{
		$('#target_email_toggle').hide();
	});
	$('#target_email').click(function()
	{
		$('#target_email_toggle').show();
	});
	if ( !$('#target_email').is(':checked') )
		$('#target_email_toggle').hide();
	$('#schedule').change(function()
	{
		var val = $(this).val();
		var day = false;
		var hour = true;
		if ( val == 'weekly' ) {
			day = true;
		} else if ( val == 'hourly' ) {
			hour = false;
		}
		if ( day )
			$('#day_toggle').show();
		else
			$('#day_toggle').hide();
		if ( hour )
			$('#hour_toggle').show();
		else
			$('#hour_toggle').hide();
	}).trigger('change');
});
//]]>
</script>
<?php
	}

	/*public*/ function Print_Schedule_Form()
	{
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/calendar.png" alt=""></p>
<p><?php _e( 'Configure the backup schedule in this section.', 'wponlinebackup' ); ?></p>
<p><?php _e( 'Your backup will start, with the specified options, at the specified time.', 'wponlinebackup' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tbody>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="schedule" style="font-weight: bold"><?php _e( 'Backup schedule:', 'wponlinebackup' ); ?></label></th>
<td><p><select name="schedule" id="schedule">
<?php
// Display the schedule list
		foreach ( $this->WPOnlineBackup->scheduler->schedule_list as $key => $value ) {
?>
<option value="<?php echo $key; ?>"<?php
			if ( $this->WPOnlineBackup->scheduler->schedule['schedule'] == $key ) {
?> selected="selected"<?php
			}
?>><?php echo $value; ?></option>
<?php
		}
?>
</select></p></td>
</tr>
</tbody>
<tbody id="day_toggle">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="day" style="font-weight: bold"><?php _e( 'Day to perform backup:', 'wponlinebackup' ); ?></label></th>
<td><p><select name="day" id="day">
<?php
// Display the schedule days
		foreach ( $this->WPOnlineBackup->scheduler->schedule_days as $key => $value ) {
?>
<option value="<?php echo $key; ?>"<?php
			if ( $this->WPOnlineBackup->scheduler->schedule['day'] == $key ) {
?> selected="selected"<?php
			}
?>><?php echo $value; ?></option>
<?php
		}
?>
</select><br>
<i><?php _e( 'This value is only used if the schedule is set to "Weekly".', 'wponlinebackup' ); ?></i></p></td>
</tr>
</tbody>
<tbody id="hour_toggle">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="hour" style="font-weight: bold"><?php _e( 'Hour to perform backup:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="text" style="width: 50px" id="hour" name="hour" value="<?php echo htmlentities( $this->WPOnlineBackup->scheduler->schedule['hour'], ENT_QUOTES, 'UTF-8' ); ?>"><br>
<i><?php _e( 'This value is ignored if the schedule is set to "Hourly". If the schedule is set to "Twice Daily" or "Four Times Daily", one of the backups will start at this time, and the rest will happen at 12 or 6 hour intervals around this time.', 'wponlinebackup' ); ?></i></p></td>
</tr>
</tbody>
<tbody>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="minute" style="font-weight: bold"><?php _e( 'Minute to perform backup:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="text" style="width: 50px" id="minute" name="minute" value="<?php echo htmlentities( $this->WPOnlineBackup->scheduler->schedule['minute'], ENT_QUOTES, 'UTF-8' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 14px"><label style="font-weight: bold"><?php _e( 'Backup selection:', 'wponlinebackup' ); ?></label></th>
<td><p><input name="backup_database" type="checkbox" id="backup_database"<?php
		if ( $this->WPOnlineBackup->scheduler->schedule['backup_database'] ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="backup_database"><?php echo __( 'Database', 'wponlinebackup' ); ?></label><br>
<input name="backup_filesystem" type="checkbox" id="backup_filesystem"<?php
		if ( $this->WPOnlineBackup->scheduler->schedule['backup_filesystem'] ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="backup_filesystem"><?php echo __( 'Filesystem', 'wponlinebackup' ); ?></label></p>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="email_yes" style="font-weight: bold"><?php _e( 'Type of backup to perform', 'wponlinebackup' ); ?></label></th>
<td><p><?php
		$target_list = $this->WPOnlineBackup->scheduler->target_list;
		end( $target_list );
		$last = key( $target_list );

		foreach ( $target_list as $target => $value ) {
?><input type="radio" name="target" id="target_<?php echo $target; ?>" value="<?php echo $target; ?>"<?php
			if ( $this->WPOnlineBackup->scheduler->schedule['target'] == $target ) {
?> checked="checked"<?php
			}
?>>&nbsp;<label for="target_<?php echo $target; ?>"><?php
			echo $value;
?></label><?php
			if ( $target != $last ) {
?><br>
<?php
			}
		}
?></p></td>
</tr>
</tbody>
<tbody id="target_email_toggle">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="email_to" style="font-weight: bold"><?php _e( 'Address to use when emailing:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="text" style="width: 250px" id="email_to" name="email_to" value="<?php echo htmlentities( $this->WPOnlineBackup->scheduler->schedule['email_to'], ENT_QUOTES, 'UTF-8' ); ?>"></p></td>
</tr>
</tbody>
<tbody>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Apply Schedule', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
</tbody>
</table>
</form>
<?php
	}

	/*private*/ function Prepare_Activities()
	{
// Prepare the activity log page.
		add_meta_box( 'wponlinebackupactivitiestable', __( 'Activity Log', 'wponlinebackup' ), array( & $this, 'Print_Activities_Table' ), 'wponlinebackupactivities', 'normal' );
	}

	/*public*/ function Print_Activities_Table()
	{
		global $wpdb;

		// See if we have anything running
		$status = $this->WPOnlineBackup->bootstrap->Fetch_Status();
		if (
			$status['status'] != WPONLINEBACKUP_STATUS_NONE
			&& $status['time'] > time() - $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' )
		)
			$running = true;
		else
			$running = false;
?>
<table class="widefat" cellspacing="0">
<thead>
<tr>
<th scope="col" id="start" class="manage-column column-start" style=""><?php echo __( 'Start', 'wponlinebackup' ); ?></th>
<th scope="col" id="end" class="manage-column column-end" style=""><?php echo __( 'End', 'wponlinebackup' ); ?></th>
<th scope="col" id="duration" class="manage-column column-duration" style=""><?php echo __( 'Duration', 'wponlinebackup' ); ?></th>
<th scope="col" id="comp" class="manage-column column-comp" style=""><?php echo __( 'Completion', 'wponlinebackup' ); ?></th>
<th scope="col" id="settings" class="manage-column column-settings" style=""><?php echo __( 'Settings', 'wponlinebackup' ); ?></th>
<th scope="col" id="size" class="manage-column column-size" style=""><?php echo __( 'Backup Size', 'wponlinebackup' ); ?></th>
<th scope="col" id="totalsize" class="manage-column column-totalsize" style=""><?php echo __( 'Total Size', 'wponlinebackup' ); ?></th>
<th scope="col" id="events" class="manage-column column-events" style=""><?php echo __( 'Events', 'wponlinebackup' ); ?></th>
</tr>
</thead>
<tfoot>
<tr>
<th scope="col" class="manage-column column-start" style=""><?php echo __( 'Start', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-end" style=""><?php echo __( 'End', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-duration" style=""><?php echo __( 'Duration', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-comp" style=""><?php echo __( 'Completion', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-settings" style=""><?php echo __( 'Settings', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-size" style=""><?php echo __( 'Backup Size', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-totalsize" style=""><?php echo __( 'Total Size', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-events" style=""><?php echo __( 'Events', 'wponlinebackup' ); ?></th>
</tr>
</tfoot>
<tbody>
<?php
		$result = $wpdb->get_results(
			'SELECT a.activity_id, a.start, a.end, a.type, a.media, a.comp, a.errors, a.warnings, a.compressed, a.encrypted, ' .
				'a.bsize, a.bcount, a.rsize, a.rcount, ' .
				'(SELECT COUNT(*) FROM `' . $wpdb->prefix . 'wponlinebackup_event_log` e WHERE e.activity_id = a.activity_id) AS events ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_activity_log` a ' .
			'ORDER BY a.start DESC, a.activity_id DESC',
			ARRAY_A
		);

		// Display the activity logs, or an empty message
		if ( count( $result ) == 0 ) {
?>
<tr>
<td colspan="8" style="text-align: center; padding: 12px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/information.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php _e( 'The Activity Log is currently empty.', 'wponlinebackup' ); ?></i></td>
</tr>
<?php
		} else {

			$c = 0;

			foreach ( $result as $activity ) {
?>
<tr<?php
				if ( $c++ % 2 == 0 ) {
?> class="alternate"<?php
				}
?> valign="top">
<td class="column-start"><?php
				echo date_i18n( _x( 'jS M Y g.i.s A', 'Activity log start time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $activity['start'] ) );
?></td>
<td class="column-end"><?php
				if ( !$running && is_null( $activity['end'] ) )
					$activity['end'] = $activity['start'];
				if ( is_null( $activity['end'] ) ) {
?><i><?php
					echo __( 'N/A', 'wponlinebackup' );
?></i><?php
				} else {
					echo date_i18n( _x( 'jS M Y g.i.s A', 'Activity log end time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $activity['end'] ) );
				}
?></td>
<td class="column-duration"><?php
				echo WPOnlineBackup_Formatting::Fix_Time( is_null( $activity['end'] ) ? time() - $activity['start'] : $activity['end'] - $activity['start'] );
?></td>
<td class="column-comp"><?php
				switch ( $activity['comp'] ) {

					// case WPONLINEBACKUP_COMP_UNEXPECTED:
					default:
						$message = array( 'exclamation.png', 'A00', __( 'Unexpected stop', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_RUNNING:
						$message = array( 'ajax-loader.gif', '000', __( 'Running...', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_SUCCESSFUL:
						$message = array( 'accept.png', '0A0', $activity['warnings'] ? sprintf( _n( 'Successful (%d warning)', 'Successful (%d warnings)', $activity['warnings'] , 'wponlinebackup' ), $activity['warnings'] ) : __( 'Successful', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_PARTIAL:
						$message = array( 'error.png', 'A80', $activity['errors'] ? sprintf( _n( 'Partial (%d error)', 'Partial (%d errors)', $activity['errors'] , 'wponlinebackup' ), $activity['errors'] ) : __( 'Partial', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_STOPPED:
						$message = array( 'exclamation.png', 'A00', __( 'Stopped', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_FAILED:
					case WPONLINEBACKUP_COMP_TIMEOUT:
					case WPONLINEBACKUP_COMP_SLOWTIMEOUT:
						$message = array( 'exclamation.png', 'A00', __( 'Failed', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_MEMORY:
					case WPONLINEBACKUP_COMP_SLOWMEMORY:
						$message = array( 'exclamation.png', 'A00', __( 'Low Memory', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_COMP_MAINTENANCE:
						$message = array( 'exclamation.png', 'A00', __( 'Maintenance', 'wponlinebackup' ) );
						break;

				}
?><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $message[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b style="color: #<?php echo $message[1]; ?>"><?php echo $message[2]; ?></b></td>
<td class="column-settings"><?php
				$settings = array();

				if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_BACKUP )
					$settings[] = array( 'user.png', __( 'Manual Backup', 'wponlinebackup' ) );
				else if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP )
					$settings[] = array( 'date.png', __( 'Scheduled Backup', 'wponlinebackup' ) );
				else if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_DECRYPT )
					$settings[] = array( 'key.png', __( 'Decrypt', 'wponlinebackup' ) );
				else
					$settings[] = array( 'help.png', __( 'Unknown', 'wponlinebackup' ) );

				switch ( $activity['media'] ) {
					case WPONLINEBACKUP_MEDIA_DOWNLOAD:
						$settings[] = array( 'cd.png', __( 'Local', 'wponlinebackup' ) );
						break;
					case WPONLINEBACKUP_MEDIA_EMAIL:
						$settings[] = array( 'email.png', __( 'Emailed', 'wponlinebackup' ) );
						break;
					case WPONLINEBACKUP_MEDIA_ONLINE:
						$settings[] = array( 'transmit.png', __( 'Sent to Online Vault', 'wponlinebackup' ) );
						break;
				}

				if ( $activity['compressed'] )
					$settings[] = array( 'compress.png', __( 'Compressed', 'wponlinebackup' ) );

				if ( $activity['encrypted'] )
					$settings[] = array( 'lock_small.png', __( 'Encrypted', 'wponlinebackup' ) );

				if ( count( $settings ) ) {
					end( $settings );
					$last = key( $settings );
					foreach ( $settings as $key => $icon ) {
?><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $icon[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="<?php echo $icon[1]; ?>" title="<?php echo $icon[1]; ?>"><?php
						if ( $key != $last ) {
?> <?php
						}
					}
				}
?></td>
<td class="column-size"><?php
				printf( _n( '%s (%d file)', '%s (%d files)', $activity['bcount'] , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $activity['bsize'] ), $activity['bcount'] );
?></td>
<td class="column-totalsize"><?php
				printf( _n( '%s (%d file)', '%s (%d files)', $activity['rcount'] , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $activity['rsize'] ), $activity['rcount'] );
?></td>
<td class="column-events"><a href="admin.php?page=wponlinebackup&amp;section=events&amp;activity=<?php echo $activity['activity_id']; ?>"><?php
				printf( _n( 'View Event (%d)', 'View Events (%d)', $activity['events'] , 'wponlinebackup' ), $activity['events'] );
?></a></td>
</tr>
<?php
			}

		}
?>
</tbody>
</table>
<p style="text-align: center">
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/user.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Manual Backup', 'wponlinebackup' ); ?></i>
	&nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/date.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Scheduled Backup', 'wponlinebackup' ); ?></i>
	&nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/key.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Decrypt', 'wponlinebackup' ); ?></i>
	&nbsp; - &nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/cd.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Local', 'wponlinebackup' ); ?></i>
	&nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/email.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Emailed', 'wponlinebackup' ); ?></i>
	&nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/transmit.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Sent to Online Vault', 'wponlinebackup' ); ?></i>
	&nbsp; - &nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/compress.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Compressed', 'wponlinebackup' ); ?></i>
	&nbsp;
	<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/lock_small.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php echo __( 'Encrypted', 'wponlinebackup' ); ?></i>
</p>
<?php
	}

	/*private*/ function Prepare_Events()
	{
		// Prepare the activity log page.
		add_meta_box( 'wponlinebackupeventstable', __( 'Event Log', 'wponlinebackup' ), array( & $this, 'Print_Events_Table' ), 'wponlinebackupevents', 'normal' );
	}

	/*public*/ function Print_Events_Table()
	{
		global $wpdb;

		$activity_id = array_key_exists( 'activity', $_GET ) ? strval( $_GET['activity'] ) : 0;
		$wpdb->escape_by_ref( $activity_id );

		$activity = $wpdb->get_row(
			'SELECT activity_id, start, end, type, media, comp, errors, warnings, compressed, encrypted, ' .
				'bsize, bcount, rsize, rcount ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_activity_log` ' .
			'WHERE activity_id = \'' . $activity_id . '\'',
			ARRAY_A
		);

		if ( is_null( $activity ) ) {
?>
<p style="text-align: center; padding: 12px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/error.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php _e( 'The specified activity no longer exists. There are no events to show.', 'wponlinebackup' ); ?></i></p>
<?php
			return;
		}
?>
<p>
	<b>Activity ID:</b> <?php echo $activity['activity_id']; ?><?php
		if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_BACKUP )
			$icon = array( 'user.png', __( 'Manual Backup', 'wponlinebackup' ) );
		else if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP )
			$icon = array( 'date.png', __( 'Scheduled Backup', 'wponlinebackup' ) );
		else if ( $activity['type'] == WPONLINEBACKUP_ACTIVITY_DECRYPT )
			$icon = array( 'key.png', __( 'Decrypt', 'wponlinebackup' ) );
		else
			$icon = array( 'help.png', __( 'Unknown', 'wponlinebackup' ) );

		if ( !is_null( $icon ) ) {
?><br>
	<b>Activity Type:</b> <img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $icon[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle">&nbsp;<?php echo $icon[1];
		}

		$settings = array();

		switch ( $activity['media'] ) {
			case WPONLINEBACKUP_MEDIA_DOWNLOAD:
				$settings[] = array( 'cd.png', __( 'Local', 'wponlinebackup' ) );
				break;
			case WPONLINEBACKUP_MEDIA_EMAIL:
				$settings[] = array( 'email.png', __( 'Emailed', 'wponlinebackup' ) );
				break;
			case WPONLINEBACKUP_MEDIA_ONLINE:
				$settings[] = array( 'transmit.png', __( 'Sent to Online Vault', 'wponlinebackup' ) );
				break;
		}

		if ( $activity['compressed'] )
			$settings[] = array( 'compress.png', __( 'Compressed', 'wponlinebackup' ) );

		if ( $activity['encrypted'] )
			$settings[] = array( 'lock_small.png', __( 'Encrypted', 'wponlinebackup' ) );

		if ( count( $settings ) ) {
?><br>
	<b>Settings:</b> <?php
			end( $settings );
			$last = key( $settings );
			foreach ( $settings as $key => $icon ) {
?><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $icon[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle">&nbsp;<?php echo $icon[1]; ?><?php
				if ( $key != $last ) {
?>, <?php
				}
			}
		}
?>
</p>
<p>
	<b>Completion:</b> <?php
		switch ( $activity['comp'] ) {

//			case WPONLINEBACKUP_COMP_UNEXPECTED:
			default:
				$message = array( 'exclamation.png', 'A00', __( 'Unexpected stop', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_RUNNING:
				$message = array( 'ajax-loader.gif', '000', __( 'Running...', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_SUCCESSFUL:
				$message = array( 'accept.png', '0A0', $activity['warnings'] ? sprintf( _n( 'Successful (%d warning)', 'Successful (%d warnings)', $activity['warnings'] , 'wponlinebackup' ), $activity['warnings'] ) : __( 'Successful', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_PARTIAL:
				$message = array( 'error.png', 'A80', $activity['errors'] ? sprintf( _n( 'Partial (%d error)', 'Partial (%d errors)', $activity['errors'] , 'wponlinebackup' ), $activity['errors'] ) : __( 'Partial', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_STOPPED:
				$message = array( 'exclamation.png', 'A00', __( 'Stopped', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_FAILED:
			case WPONLINEBACKUP_COMP_TIMEOUT:
			case WPONLINEBACKUP_COMP_SLOWTIMEOUT:
				$message = array( 'exclamation.png', 'A00', __( 'Failed', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_MEMORY:
			case WPONLINEBACKUP_COMP_SLOWMEMORY:
				$message = array( 'exclamation.png', 'A00', __( 'Low Memory', 'wponlinebackup' ) );
				break;

			case WPONLINEBACKUP_COMP_MAINTENANCE:
				$message = array( 'exclamation.png', 'A00', __( 'Maintenance', 'wponlinebackup' ) );
				break;

		}
?><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $message[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b style="color: #<?php echo $message[1]; ?>"><?php echo $message[2]; ?></b>
</p>
<p>
	<b>Backup Size:</b> <?php
				printf( _n( '%s (%d file)', '%s (%d files)', $activity['bcount'] , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $activity['bsize'] ), $activity['bcount'] );
?><br>
	<b>Total Size:</b> <?php
				printf( _n( '%s (%d file)', '%s (%d files)', $activity['rcount'] , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $activity['rsize'] ), $activity['rcount'] );
?>
</p>
<p>
	<b>Start Time:</b> <?php
		echo htmlentities( date_i18n( _x( 'jS M Y g.i.s A', 'Event log activity start time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $activity['start'] ) ), ENT_QUOTES, 'UTF-8' );
?><br>
	<b>End Time:</b> <?php
		if ( is_null( $activity['end'] ) ) {
?><i><?php
			echo __( 'N/A', 'wponlinebackup' );
?></i><?php
		} else {
			echo htmlentities( date_i18n( _x( 'jS M Y g.i.s A', 'Event log activity end time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $activity['end'] ) ), ENT_QUOTES, 'UTF-8' );
		}
?>

</p>
<table class="widefat" cellspacing="0">
<thead>
<tr>
<th width="15%" scope="col" id="time" class="manage-column column-time" style=""><?php echo __( 'Time', 'wponlinebackup' ); ?></th>
<th width="12%" scope="col" id="type" class="manage-column column-type" style=""><?php echo __( 'Type', 'wponlinebackup' ); ?></th>
<th scope="col" id="event" class="manage-column column-event" style=""><?php echo __( 'Event', 'wponlinebackup' ); ?></th>
</tr>
</thead>
<tfoot>
<tr>
<th width="15%" scope="col" class="manage-column column-time" style=""><?php echo __( 'Time', 'wponlinebackup' ); ?></th>
<th width="12%" scope="col" class="manage-column column-type" style=""><?php echo __( 'Type', 'wponlinebackup' ); ?></th>
<th scope="col" class="manage-column column-event" style=""><?php echo __( 'Event', 'wponlinebackup' ); ?></th>
</tr>
</tfoot>
<tbody>
<?php
		$result = $wpdb->get_results(
			'SELECT time, type, event ' .
			'FROM `' . $wpdb->prefix . 'wponlinebackup_event_log` ' .
			'WHERE activity_id = \'' . $activity_id . '\' ' .
			'ORDER BY time DESC, event_id DESC',
			ARRAY_A
		);

// Display the event logs, or an empty message
		if ( count( $result ) == 0 ) {
?>
<tr>
<td colspan="8" style="text-align: center; padding: 12px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/information.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<i><?php _e( 'The Event Log for this activity is currently empty.', 'wponlinebackup' ); ?></i></td>
</tr>
<?php
		} else {

			$c = 0;

			foreach ( $result as $event ) {
?>
<tr<?php
				if ( $c++ % 2 == 0 ) {
?> class="alternate"<?php
				}
?> valign="top">
<td class="column-time"><?php
				echo htmlentities( date_i18n( _x( 'jS M Y g.i.s A', 'Event log entry time', 'wponlinebackup' ), WPOnlineBackup::Convert_Unixtime_To_Wordpress_Unixtime( $event['time'] ) ), ENT_QUOTES, 'UTF-8' );
?></td>
<td class="column-type"><?php
				switch ( $event['type'] ) {

//					case WPONLINEBACKUP_EVENT_INFORMATION:
					default:
						$type = array( 'information.png', __( 'Information', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_EVENT_WARNING:
						$type = array( 'error.png', __( 'Warning', 'wponlinebackup' ) );
						break;

					case WPONLINEBACKUP_EVENT_ERROR:
						$type = array( 'exclamation.png', __( 'Error', 'wponlinebackup' ) );
						break;

				}
?><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/<?php echo $type[0]; ?>" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<?php echo htmlentities( $type[1], ENT_QUOTES ); ?></td>
<td class="column-event"><?php echo preg_replace( '/(?:\\n|\\r\\n?)/', '<br>' . PHP_EOL, htmlentities( $event['event'], ENT_QUOTES ) ); ?></td>
</tr>
<?php
			}

		}
?>
</tbody>
</table>
<?php
	}

	/*private*/ function Prepare_Settings()
	{
		// Are we saving settings?
		if ( array_key_exists( 'settingsnonce', $_POST ) && wp_verify_nonce( strval( $_POST['settingsnonce'] ), 'settings' ) ) {

			$errors = array();

			// Only adjust encryption keys if we are not logged into Online Backup
			if ( $this->WPOnlineBackup->Get_Setting( 'username' ) == '' ) {

				// Extract encryption settings
				if ( !array_key_exists( 'encryption_type', $_POST ) ) $_POST['encryption_type'] = $this->WPOnlineBackup->Get_Setting( 'encryption_type' );

				$length = 0;
				switch ( strval( $_POST['encryption_type'] ) ) {
					default:
						$_POST['encryption_type'] = '';
					case 'AES256':
						$length = 32;
					case 'AES196':
						if ( $length == 0 ) $length = 24;
					case 'AES128':
						if ( $length == 0 ) $length = 16;
					case 'DES':
						if ( $length == 0 ) $length = 8;
						$type = strval( $_POST['encryption_type'] );
						break;
				}

				// Grab the encryption key
				if ( array_key_exists( 'encryption_key', $_POST ) ) $key = stripslashes( strval( $_POST['encryption_key'] ) );
				else $key = '';

				// Validate the length of the encryption key
				if ( $type != '' ) {

					if ( strlen( $key ) > $length ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> sprintf( __( 'The encryption key specified must be no longer than %1$d characters in length for %2$s encryption.', 'wponlinebackup' ), $length, $_POST['encryption_type'] ),
						);
					} else if ( strlen( $key ) < 1 ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> __( 'Please specify an encryption key.', 'wponlinebackup' ),
						);
					}

				} else {

					if ( strlen( $key ) > $length ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> sprintf( __( 'The encryption key specified must be no longer than %d characters in length.', 'wponlinebackup' ), $length ),
						);
					}

				}

				$this->WPOnlineBackup->Set_Setting( 'encryption_type', $type );
				$this->WPOnlineBackup->Set_Setting( 'encryption_key', $key );

			}

			// Extract backup behaviour settings
			if ( array_key_exists( 'selection_method', $_POST ) ) {
				if ( $_POST['selection_method'] === 'include' )
					$this->WPOnlineBackup->Set_Setting( 'selection_method', 'include' );
				else
					$this->WPOnlineBackup->Set_Setting( 'selection_method', 'exclude' );
			} else {
				$this->WPOnlineBackup->Set_Setting( 'selection_method', 'include' );
			}

			// Extract selected table list
			$selection_list = array();

			if ( array_key_exists( 'selection_list', $_POST ) && is_array( $_POST['selection_list'] ) ) {

				// Include the tables backup processor
				require_once WPONLINEBACKUP_PATH . '/include/tables.php';

				// Create an instance
				$tables = new WPOnlineBackup_Backup_Tables( $this->WPOnlineBackup );

				// Grab the available table list
				list ( $core, $custom ) = $tables->Fetch_Available();

				// Iterate list given by browser
				foreach ( $_POST['selection_list'] as $key => $value ) {

					// Strip any foreign slashes
					$value = stripslashes( strval( $value ) );

					// Is this a core table? Don't include in the list
					if ( in_array( $value, $core ) ) continue;

					// Check this table actually exists and add to the list if it does
					if ( in_array( $value, $custom ) ) {
						$selection_list[] = $value;
					}

				}
			}

			$this->WPOnlineBackup->Set_Setting( 'selection_list', $selection_list );

			// Extract comments options
			if ( array_key_exists( 'ignore_trash_comments', $_POST ) && strval( $_POST['ignore_trash_comments'] ) == '1' )
				$this->WPOnlineBackup->Set_Setting( 'ignore_trash_comments', $_POST['ignore_trash_comments'] ? true : false );
			else
				$this->WPOnlineBackup->Set_Setting( 'ignore_trash_comments', false );

			if ( array_key_exists( 'ignore_spam_comments', $_POST ) && strval( $_POST['ignore_spam_comments'] ) == '1' )
				$this->WPOnlineBackup->Set_Setting( 'ignore_spam_comments', $_POST['ignore_spam_comments'] ? true : false );
			else
				$this->WPOnlineBackup->Set_Setting( 'ignore_spam_comments', false );

			// Extract filesystem options
			// Exclusions are inverted because in the settings they are actually inclusions - we just display them in admin as exclusions for consistency
			// Saves us editing alot of code and more importantly, saves us having to patch the database to invert the settings to their new meaning
			if ( array_key_exists( 'filesystem_plugins', $_POST ) && strval( $_POST['filesystem_plugins'] ) == '1' )
				$this->WPOnlineBackup->Set_Setting( 'filesystem_plugins', $_POST['filesystem_plugins'] ? false : true );
			else
				$this->WPOnlineBackup->Set_Setting( 'filesystem_plugins', true );

			if ( array_key_exists( 'filesystem_themes', $_POST )  && strval( $_POST['filesystem_themes'] ) == '1' )
				$this->WPOnlineBackup->Set_Setting( 'filesystem_themes', $_POST['filesystem_themes'] ? false : true );
			else
				$this->WPOnlineBackup->Set_Setting( 'filesystem_themes', true );

			if ( array_key_exists( 'filesystem_uploads', $_POST ) && strval( $_POST['filesystem_uploads'] ) == '1' )
				$this->WPOnlineBackup->Set_Setting( 'filesystem_uploads', $_POST['filesystem_uploads'] ? false : true );
			else
				$this->WPOnlineBackup->Set_Setting( 'filesystem_uploads', true );

			// Extract the custom excludes
			if ( array_key_exists( 'filesystem_excludes', $_POST ) )
				$this->WPOnlineBackup->Set_Setting( 'filesystem_excludes', strval( $_POST['filesystem_excludes'] ) );

			// Whether or not we're backing up the WordPress parent folder as well
			if ( array_key_exists( 'filesystem_upone', $_POST ) && strval( $_POST['filesystem_upone'] ) == '1' )
				$this->WPOnlineBackup->Set_Setting( 'filesystem_upone', $_POST['filesystem_upone'] ? true : false );
			else
				$this->WPOnlineBackup->Set_Setting( 'filesystem_upone', false );

			// Grab the maximum log age
			if ( array_key_exists( 'max_log_age', $_POST ) )
				$max_log_age = stripslashes( strval( $_POST['max_log_age'] ) );
			else
				$max_log_age = '';

			if ( !is_numeric( $max_log_age ) || $max_log_age < 1 || $max_log_age > 12 ) {
				$errors[] = array(
					'icon'	=> 'error',
					'text'	=> sprintf( __( 'Please specify a valid number between %1$d and %2$d for the number of months activity and event logs should be kept for.', 'wponlinebackup' ), 1, 120 ),
				);
			} else {
				$max_log_age = intval( $max_log_age );
			}

			$this->WPOnlineBackup->Set_Setting( 'max_log_age', $max_log_age );

			// Grab the minimum generations to keep
			if ( array_key_exists( 'local_min_gens', $_POST ) )
				$local_min_gens = stripslashes( strval( $_POST['local_min_gens'] ) );
			else
				$local_min_gens = '';

			if ( !is_numeric( $local_min_gens ) || $local_min_gens < 1 || $local_min_gens > 1000 ) {
				$errors[] = array(
					'icon'	=> 'error',
					'text'	=> sprintf( __( 'Please specify a valid number between %1$d and %2$d for the minimum number of local backups to keep.', 'wponlinebackup' ), 1, 1000 ),
				);
			} else {
				$local_min_gens = intval( $local_min_gens );
			}

			$this->WPOnlineBackup->Set_Setting( 'local_min_gens', $local_min_gens );

			// Grab the maximum log age
			if ( array_key_exists( 'local_max_storage', $_POST ) )
				$local_max_storage = stripslashes( strval( $_POST['local_max_storage'] ) );
			else
				$local_max_storage = '';

			if ( !is_numeric( $local_max_storage ) || $local_max_storage < 1 || $local_max_storage > 1000000 ) {
				$errors[] = array(
					'icon'	=> 'error',
					'text'	=> sprintf( __( 'Please specify a valid number between %1$d and %2$d for the maximum storage, in MiB, local backups should utilise.', 'wponlinebackup' ), 1, 1000000 ),
				);
			} else {
				$local_max_storage = intval( $local_max_storage );
			}

			$this->WPOnlineBackup->Set_Setting( 'local_max_storage', $local_max_storage );

			// If we have errors, show the form again with an error message
			if ( count( $errors ) ) {

				$this->Register_Messages( $errors );

			} else {

				// No errors, save the settings
				$this->WPOnlineBackup->Save_Settings();

				// Register success message
				$this->Register_Messages( array( array(
					'icon'	=> 'accept',
					'text'	=> 'Saved settings.',
				) ) );

				// Redirect to the settings page so refresh does not resubmit
				wp_redirect( 'admin.php?page=wponlinebackup&section=settings&messages=true' );

				exit;

			}

		}

		// Add header
		wp_enqueue_script( 'jquery' );
		add_action( 'admin_head', array( & $this, 'Head_Settings' ) );

		// Prepare the settings page.
		add_meta_box( 'wponlinebackupsettingsencryption', __( 'Encryption', 'wponlinebackup' ), array( & $this, 'Print_Settings_Encryption' ), 'wponlinebackupsettings', 'normal' );
		add_meta_box( 'wponlinebackupsettingsbackup', __( 'Backup Behaviour', 'wponlinebackup' ), array( & $this, 'Print_Settings_Backup' ), 'wponlinebackupsettings', 'normal' );
		add_meta_box( 'wponlinebackupsettingssave', __( 'Save', 'wponlinebackup' ), array( & $this, 'Print_Settings_Save' ), 'wponlinebackupsettings', 'normal' );

		// Enable the form wrapper
		$this->enable_form = 'settings';
	}

	/*public*/ function Head_Settings()
	{
		$key = json_encode( array(
			'key' 		=> $this->WPOnlineBackup->Get_Setting( 'encryption_key' ),
		) );
?>
<script type="text/javascript">
//<![CDATA[
jQuery(function($)
{
	var params = <?php echo $key; ?>;
	$('#encryption_key').after(
		$('<span></span>')
			.html('&nbsp;<input type="checkbox" id="encryption_key_show" name="encryption_key_show">&nbsp;<label for="encryption_key_show">Show encryption key</label>')
			.find('#encryption_key_show')
			.click(function()
			{
				var oele = $('#encryption_key');
				var nele = $('<input type="' + ( $('#encryption_key_show').is(':checked') ? 'text' : 'password' ) + '">')
					.val( oele.val() );
				oele
					.after(nele)
					.detach();
				nele
					.attr( 'id', 'encryption_key' )
					.attr( 'name', 'encryption_key' )
					.css( 'width', '250px' );
			}).parent()
	);
	$('#encryption_key_text').after(
		$('<span></span>')
			.html('&nbsp;<input type="checkbox" id="encryption_key_show" name="encryption_key_show">&nbsp;<label for="encryption_key_show">Show encryption key</label>')
			.find('#encryption_key_show')
			.click(function()
			{
				var txt = $('#encryption_key_text');
				if ( txt.length ) {
					if ( $('#encryption_key_show').is(':checked') )
						txt.text( params.key );
					else
						txt.html( Array( params.key.length + 1 ).join( '&middot;' ) );
				}
			}).parent()
	);
});
//]]>
</script>
<?php
	}

	/*public*/ function Print_Settings_Encryption()
	{
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/lock.png" alt=""></p>
<p><?php _e( 'We highly recommended that you enable encryption. Using encryption will mean that nobody can access your backup files without first providing the encryption key.', 'wponlinebackup' ); ?></p>
<p><?php _e( 'DES is the lightest form of encryption, and actually uses more server resources than AES encryption. Only use DES if you need encryption but need it to be weak. AES encryption is the better encryption. The larger the number after it, the more server resources required to encrypt the data, but the better the protection provided.', 'wponlinebackup' ); ?><br>
<?php _e( 'We recommend AES128 - it has the best balance in not being too resource intensive and still offering enterprise grade protection.', 'wponlinebackup' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<?php
		if ( $this->WPOnlineBackup->Get_Setting( 'username' ) != '' ) {
?>
<tr valign="top">
<td colspan="2"><p style="text-align: center"><span style="padding: 4px; display: inline-block; text-align: left; border: 1px dashed #000; background: #E9E999">
<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/error.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'Encryption details CANNOT be changed because Online Backup is currently active.', 'wponlinebackup' ); ?></b><br>
<?php _e( 'Online Backup is incremental - that is, only changes made between backups are actually sent to the online vault - and so the encryption details used for each backup must remain the same.', 'wponlinebackup' ); ?>
</span></p></td>
</tr>
<?php
			$disable = true;
		} else {
			$disable = false;
		}
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: <?php echo ( $disable ? '15' : '18' ); ?>px"><label for="encryption_type" style="font-weight: bold"><?php _e( 'Encryption type:', 'wponlinebackup' ); ?></label></th>
<td><p><?php
// Disabled?
		if ( $disable ) {
			foreach ( array_merge( array( '' => 'None [Not recommended]' ), $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) as $key => $type ) {
				if ( $key === $this->WPOnlineBackup->Get_Setting( 'encryption_type' ) ) {
					$found = true;
?><b><?php
					if ( $type == 'AES128' ) printf( __( '%s [Recommended]', 'wponlinebackup' ), $type );
					else echo $type;
?></b><?php
				}
			}
		} else {
// Is encryption available?
			if ( $this->WPOnlineBackup->Get_Env( 'encryption_available' ) ) {
?><select id="encryption_type" name="encryption_type">
<?php
// Iterate and display available encryption types
				foreach ( array_merge( array( '' => 'None [Not recommended]' ), $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) as $key => $type ) {
?><option value="<?php echo $type; ?>"<?php
// Is this the selected encryption?
					if ( $key == $this->WPOnlineBackup->Get_Setting( 'encryption_type' ) ) {
?> selected="selected"<?php
					}
?>><?php
// Pump out the type, and add recommendation labels
					if ( $type == 'AES128' ) printf( __( '%s [Recommended]', 'wponlinebackup' ), $type );
					else echo $type;
?></option>
<?php
				}
?></select>
<?php
			} else {
// No encryption available
?><i><?php _e( 'Encryption requires the php-mcrypt extension with AES or DES cipher support and a compatible message digest framework (php 5.1.2 where it is builtin, php-hash or php-mhash).', 'wponlinebackup' ) . ' ' . __( 'Encryption is highly recommended and you should consider contacting your host regarding these requirements.', 'wponlinebackup' ); ?></i><?php
			}
		}
?></p></td>
</tr>
<?php
		if ( $this->WPOnlineBackup->Get_Env( 'encryption_available' ) ) {
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 15px"><label for="encryption_key" style="font-weight: bold"><?php _e( 'Encryption key:', 'wponlinebackup' ); ?></label></th>
<td><p><?php
// Disabled?
			if ( $disable ) {
?><b id="encryption_key_text" style="display: inline-block; width: 250px"><?php echo str_repeat( '&middot;', strlen( $this->WPOnlineBackup->Get_Setting( 'encryption_key' ) ) ); ?></b><?php
			} else {
				_e( 'This is the password that will be used to encrypt your backups - you should set it to a bunch of random characters or symbols.', 'wponlinebackup' );
?><br>
<b><?php _e( 'Remember to write it down!', 'wponlinebackup' ); ?></b><br>
<input type="password" style="width: 250px" id="encryption_key" name="encryption_key" value="<?php echo htmlentities( $this->WPOnlineBackup->Get_Setting( 'encryption_key' ), ENT_QUOTES, 'UTF-8' ); ?>"><br>
<i><?php _e( 'Your encryption key is just like a password, it can be anything you want it to be.', 'wponlinebackup' ); ?></i><?php
			}
?></p></td>
</tr>
<?php
		}
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 15px">&nbsp;</th>
<td><p><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/exclamation.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'ATTENTION! DO NOT LOSE YOUR ENCRYPTION DETAILS!', 'wponlinebackup' ); ?></b><br>
<?php _e( 'Just remember, your backups can NEVER be recovered if you forget these details.', 'wponlinebackup' ); ?><br>
<?php _e( 'Therefore, it is IMPERATIVE that you write them down somewhere. Please do not contact us regarding lost encryption details... there is absolutely nothing we can do.', 'wponlinebackup' ); ?></p></td>
</tr>
</table>
<?php
	}

	/*public*/ function Print_Settings_Backup()
	{
		// Include the tables backup processor
		require_once WPONLINEBACKUP_PATH . '/include/tables.php';
		require_once WPONLINEBACKUP_PATH . '/include/files.php';

		// Create an instance of tables and files
		$tables = new WPOnlineBackup_Backup_Tables( $this->WPOnlineBackup );
		$files = new WPOnlineBackup_Backup_Files( $this->WPOnlineBackup );

		// Grab the available table list
		list ( $core, $custom ) = $tables->Fetch_Available();

		// Convert to HTMLEntities
		foreach ( $core as $entry => $display )
			$core[ $entry ] = htmlentities( $display, ENT_QUOTES );

		foreach ( $custom as $entry => $display )
			$custom[ $entry ] = htmlentities( $display, ENT_QUOTES );

		// Find the last custom item so we know when to stop placing line breaks
		end( $custom );
		$last = key( $custom );

		// Get the uploads directory information
		$uploads = wp_upload_dir();

		// Resolve the parent folder path so we can display it
		list ( $root, $parent_root, $wordpress_root ) = $files->Get_Roots();

		// Regex to strip root from a path
		$strip_root = '#^' . preg_quote( $root, '#' ) . '/#';
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/settings.png" alt=""></p>
<p><?php _e( 'These settings affect the behaviour of the backups. If you have custom/plugin database tables you can choose whether to back them up or not, or only choose to backup certain ones. You can also exclude comments and trash.', 'wponlinebackup' ); ?></p>
<p><?php _e( 'For filesystem backups you can also specify whether to include themes and plugins or not.', 'wponlinebackup' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 15px"><label style="font-weight: bold"><?php _e( 'Database backup behaviour:', 'wponlinebackup' ); ?></label></th>
<td><?php
// If we have detected core tables, display them so we know they are going to be backed up
		if ( count( $core ) ) {
?><p><?php _e( 'Core WordPress tables will always be backed up when the database is included.', 'wponlinebackup' ); ?></p>
<?php
		}
?><p><input type="radio" name="selection_method" id="selection_method_include" value="include"<?php
// Mark as checked if we selected this option
		if ( $this->WPOnlineBackup->Get_Setting( 'selection_method' ) == 'include' ) {
?> checked="checked"<?php
		}
?>>&nbsp;<label for="selection_method_include"><?php _e( 'ONLY backup the custom/plugin tables selected below (new tables will not be backed up until explicitly selected.)', 'wponlinebackup' ); ?></label><br>
<input type="radio" name="selection_method" id="selection_method_exclude" value="exclude"<?php
// Mark as checked if we selected this option
		if ( $this->WPOnlineBackup->Get_Setting( 'selection_method' ) != 'include' ) {
?> checked="checked"<?php
		}
?>>&nbsp;<label for="selection_method_exclude"><?php _e( 'Backup all custom/plugin tables EXCEPT those selected below (new tables will automatically be backed up until explicitly selected.) [Recommended]', 'wponlinebackup' ); ?></label></p>
<p><?php
// If we have custom tables, list them here, or display a message saying none found
		if ( count( $custom ) ) {
			foreach ( $custom as $entry => $display ) {
?><input type="checkbox" name="selection_list[]" id="selection_list_<?php echo $display; ?>" value="<?php echo $display; ?>"<?php
// Check the box if we have this table selected
				if ( in_array( $entry, $this->WPOnlineBackup->Get_Setting( 'selection_list' ) ) ) {
?> checked="checked"<?php
				}
?>>&nbsp;<label for="selection_list_<?php echo $display; ?>"><?php echo $display; ?></label><?php
// If not the last, add a new line
				if ( $entry != $last ) {
?><br>
<?php
				}
			}
		} else {
?><i><?php _e( 'No non-default tables currently exist.', 'wponlinebackup' ); ?></i><?php
		}
?></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 15px"><label style="font-weight: bold"><?php _e( 'Database excludes:', 'wponlinebackup' ); ?></label></th>
<td><p><input name="ignore_trash_comments" type="checkbox" id="ignore_trash_comments"<?php
// Is this enabled?
		if ( $this->WPOnlineBackup->Get_Setting( 'ignore_trash_comments' ) ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="ignore_trash_comments"><?php _e( 'Exclude comments in the trash.', 'wponlinebackup' ); ?></label><br>
<input name="ignore_spam_comments" type="checkbox" id="ignore_spam_comments"<?php
// Is this enabled?
		if ( $this->WPOnlineBackup->Get_Setting( 'ignore_spam_comments' ) ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="ignore_spam_comments"><?php _e( 'Exclude comments that are marked as spam.', 'wponlinebackup' ); ?></label></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 15px"><label style="font-weight: bold"><?php _e( 'Filesystem excludes:', 'wponlinebackup' ); ?></label></th>
<td><p><?php _e( 'Files that are part of the default WordPress installation will always be backed up when the filesystem is included.', 'wponlinebackup' ); ?></p>
<p><input name="filesystem_plugins" type="checkbox" id="filesystem_plugins"<?php
// Is this enabled?
		if ( !$this->WPOnlineBackup->Get_Setting( 'filesystem_plugins' ) ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="filesystem_plugins"><?php printf( __( 'Exclude plugins (%s)', 'wponlinebackup' ), implode( '; ', array( preg_replace( $strip_root, '', $files->Normalise_Path( WP_PLUGIN_DIR ) ), preg_replace( $strip_root, '', $files->Normalise_Path( WPMU_PLUGIN_DIR ) ) ) ) ); ?></label><br>
<input name="filesystem_themes" type="checkbox" id="filesystem_themes"<?php
// Is this enabled?
		if ( !$this->WPOnlineBackup->Get_Setting( 'filesystem_themes' ) ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="filesystem_themes"><?php printf( __( 'Exclude themes (%s)', 'wponlinebackup' ), preg_replace( $strip_root, '', $files->Normalise_Path( get_theme_root() ) ) ); ?></label><br>
<input name="filesystem_uploads" type="checkbox" id="filesystem_uploads"<?php
// Is this enabled?
		if ( !$this->WPOnlineBackup->Get_Setting( 'filesystem_uploads' ) ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="filesystem_uploads"><?php printf( __( 'Exclude uploads (%s)', 'wponlinebackup' ), preg_replace( $strip_root, '', $files->Normalise_Path( $uploads['basedir'] ) ) ); ?></label></p>
<p><?php printf( __( 'Custom filesystem excludes can be specified here, one per line, relative to the following folder: %s', 'wponlinebackup' ), $root ); ?><br>
<?php printf( __( 'For example, to exclude %s, enter %s into the box below on its own line. Always use forward slashes even if your server runs Windows.' ), $root . '/folder/cache', 'folder/cache' ); ?><br>
<textarea rows="10" cols="60" name="filesystem_excludes" id="filesystem_excludes"><?php echo esc_html( $this->WPOnlineBackup->Get_Setting( 'filesystem_excludes' ) ); ?></textarea></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 15px"><label style="font-weight: bold"><?php _e( 'WordPress in a subdirectory:', 'wponlinebackup' ); ?></label></th>
<td><p><?php _e( 'By default, only the WordPress folder is backed up. However, if your WordPress folder is installed as a subdirectory within a larger website, and you wish to backup that website also, this option will allow you to do just that.', 'wponlinebackup' ); ?><br>
<b><?php _e( 'If you are not sure or have not installed WordPress as a subdirectory, you should leave this option disabled as you may end up backing up significantly more than expected.', 'wponlinebackup' ); ?></b><br>
<?php _e( 'You can exclude items from this level by prefixing the filesystem exclude entry with &quot;../&quot; (dot dot forward-slash) like this: ../exclude/from/parent' ); ?></p>
<?php
		if ( false === ( $d = @opendir( $parent_root ) ) ) {
			// Couldn't resolve, let the user know this option will not work, but don't hide the option so it can be disabled if it was enabled
?>
<p><span style="padding: 4px; display: inline-block; text-align: left; border: 1px dashed #000; background: #E9E9E9">
<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/information.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'WordPress looks like it is NOT installed as a subdirectory so this option SHOULD be ignored. If you believe this to be wrong, you can enable the option anyway and any problems will be reported in the event log during backup.', 'wponlinebackup' ); ?></b>
</span></p>
<?php
			$upone_parent = false;
		} else {
			$upone_parent = true;
			@closedir( $d );
		}
?>
<p><input name="filesystem_upone" type="checkbox" id="filesystem_upone"<?php
// Is this enabled?
		if ( $this->WPOnlineBackup->Get_Setting( 'filesystem_upone' ) ) {
?> checked="checked"<?php
		}
?> value="1">&nbsp;<label for="filesystem_upone"><?php
		if ( $upone_parent ) {
			printf( __( 'Backup the parent directory as well as the WordPress directory: %s', 'wponlinebackup' ), $parent_root );
		} else {
			_e( 'Backup the parent directory as well as the WordPress directory.', 'wponlinebackup' );
		}
?></label></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="local_min_gens" style="font-weight: bold"><?php _e( 'Minimum Local Backups:', 'wponlinebackup' ); ?></label></th>
<td><p><?php _e( 'The minimum number of Local Backups that should be kept, regardless of their size. We recommend 2 so you always have at least the last 2 backups available.', 'wponlinebackup' ); ?></p>
<p><input id="local_min_gens" name="local_min_gens" type="text" pattern="\d*" value="<?php echo esc_attr( $this->WPOnlineBackup->Get_Setting( 'local_min_gens' ) ); ?>" /></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="local_max_storage" style="font-weight: bold"><?php _e( 'Maximum Local Backups Storage:', 'wponlinebackup' ); ?></label></th>
<td><p><?php _e( 'The maximum storage space Local Backups should utilise in MiB. Older Local Backups will automatically be deleted when storage exceeds this. This settings is ignored if you have less backups available than the Minimum Local Backups.', 'wponlinebackup' ); ?></p>
<p><input id="local_max_storage" name="local_max_storage" type="text" pattern="\d*" value="<?php echo esc_attr( $this->WPOnlineBackup->Get_Setting( 'local_max_storage' ) ); ?>" /></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="max_log_age" style="font-weight: bold"><?php _e( 'Activity and event logs:', 'wponlinebackup' ); ?></label></th>
<td><p><?php _e( 'This is for how long activity and event logs should be kept. We recommend 6 months.', 'wponlinebackup' ); ?></p>
<p><select id="max_log_age" name="max_log_age">
<?php
		for ( $i = 1; $i <= 12; $i++ ) {
?>
	<option value="<?php echo $i; ?>"<?php
			if ( $this->WPOnlineBackup->Get_Setting( 'max_log_age' ) == $i ) {
?> selected="selected"<?php
			}
?>><?php
			if ( $i == 6 ) printf( __( '%d months [Recommended]', 'wponlinebackup' ), $i );
			else printf( _n( '%d month', '%d months', $i , 'wponlinebackup' ), $i );
?></option>
<?php
		}
?>
</select></p></td>
</tr>
</table>
<?php
	}

	/*public*/ function Print_Settings_Save()
	{
?>
<table class="form-table">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Save Settings', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
</table>
<?php
	}

	/*private*/ function Prepare_Online()
	{
		// Grab whether or not we are logged in
		$username = $this->WPOnlineBackup->Get_Setting( 'username' );

		$do_encryption = false;

		// Are we saving settings?
		if ( array_key_exists( 'onlinenonce', $_POST ) && wp_verify_nonce( strval( $_POST['onlinenonce'] ), 'online' ) ) {

			if ( $username == '' ) {

				$errors = array();

				// Extract the online backup settings
				if ( array_key_exists( 'username', $_POST ) )
					$this->WPOnlineBackup->Set_Setting( 'username', stripslashes( strval( $_POST['username'] ) ) );
				else
					$this->WPOnlineBackup->Set_Setting( 'username', '' );
				if ( array_key_exists( 'password', $_POST ) )
					$this->WPOnlineBackup->Set_Setting( 'password', stripslashes( strval( $_POST['password'] ) ) );
				else
					$this->WPOnlineBackup->Set_Setting( 'password', '' );

				if ( $newkeys = array_key_exists( 'newkeys', $_POST ) ) {

					// Extract encryption settings
					if ( !array_key_exists( 'encryption_type', $_POST ) )
						$_POST['encryption_type'] = $this->WPOnlineBackup->Get_Setting( 'encryption_type' );

					$length = 0;
					switch ( strval( $_POST['encryption_type'] ) ) {
						default:
							$_POST['encryption_type'] = '';
						case 'AES256':
							$length = 32;
						case 'AES196':
							if ( $length == 0 ) $length = 24;
						case 'AES128':
							if ( $length == 0 ) $length = 16;
						case 'DES':
							if ( $length == 0 ) $length = 8;
							$type = strval( $_POST['encryption_type'] );
							break;
					}

					// Grab the encryption key
					if ( array_key_exists( 'encryption_key', $_POST ) )
						$key = stripslashes( strval( $_POST['encryption_key'] ) );
					else
						$key = '';

					// Validate the length of the encryption key
					if ( $type != '' ) {

						if ( strlen( $key ) > $length ) {
							$errors[] = array(
								'icon'	=> 'error',
								'text'	=> sprintf( __( 'The encryption key specified must be no longer than %1$d characters in length for %2$s encryption.', 'wponlinebackup' ), $length, $_POST['encryption_type'] ),
							);
						} else if ( strlen( $key ) < 1 ) {
							$errors[] = array(
								'icon'	=> 'error',
								'text'	=> __( 'Please specify an encryption key.', 'wponlinebackup' ),
							);
						}

					} else {

						if ( strlen( $key ) > $length ) {
							$errors[] = array(
								'icon'	=> 'error',
								'text'	=> sprintf( __( 'The encryption key specified must be no longer than %d characters in length.', 'wponlinebackup' ), $length ),
							);
						}

					}

					$this->WPOnlineBackup->Set_Setting( 'encryption_type', $type );
					$this->WPOnlineBackup->Set_Setting( 'encryption_key', $key );

				}

				if ( count($errors) == 0 ) {

					require_once WPONLINEBACKUP_PATH . '/include/transmission.php';

					$transmission = new WPOnlineBackup_Backup_Transmission( $this->WPOnlineBackup );

					// Try to login with the given account details
					if ( ( $ret = $transmission->Validate_Account() ) !== true ) {

						if ( $ret === false ) {

							// No keys are set on the account - recommend encryption if they have not configured it and it is available
							// However, don't show the keys form more than once for this, so if they leave encryption unconfigured and click Continue it doesn't shout at the user again
							if ( !$newkeys && $this->WPOnlineBackup->Get_Env( 'encryption_available' ) && $this->WPOnlineBackup->Get_Setting( 'encryption_type' ) == '' ) {

								$do_encryption = true;

								$errors[] = array(
									'icon'	=> 'error',
									'text'	=> __( 'IMPORTANT! Last chance to configure encryption! Once you have connected the plugin to the online vault you will NOT be able to change your encryption settings without disconnecting the plugin and deleting all backed up data.', 'wponlinebackup' ),
								);

							}

						} else if ( $ret === 0 ) {

							// The account currently has encryption keys already set, but the ones we have configured don't match them
							if ( $this->WPOnlineBackup->Get_Env( 'encryption_available' ) ) {

								$do_encryption = true;

								$errors[] = array(
									'icon'	=> 'error',
									'text'	=> __( 'The encryption settings configured do not match those previously used with this blog. For the plugin to be able to utilise the existing backup data on the online vault, the encryption settings must match. Please re-enter them below. If you cannot remember these settings you will need to delete all data related to this blog from the online vault, after which you will be allowed to connect the plugin with new encryption settings.', 'wponlinebackup' ),
								);

							} else {

								// Encryption details do not match - and we have no encryption support! Cannot connect this blog to the vault
								$errors[] = array(
									'icon'	=> 'error',
									'text'	=> __( 'Encryption has been previously used with this blog. For the plugin to be able to utilise the existing backup data on the online vault, the encryption settings must match. However, your server does not have any encryption available. You may need to contact your host about installing the libmcrypt PHP encryption, or delete all data related to this blog from the online vault, after which you will be allowed to connect the plugin with new encryption settings.', 'wponlinebackup' ),
								);

							}

						} else {

							// The request failed - maybe failed login? Report the error
							$errors[] = array(
								'icon'	=> 'error',
								'text'	=> $ret,
							);

						}

					}

				}

				// If we have errors, show the form again with an error message
				if ( count( $errors ) ) {

					$this->Register_Messages( $errors );

				} else {

					// No errors, save the settings
					$this->WPOnlineBackup->Save_Settings();

					// Register success message
					$this->Register_Messages( array( array(
						'icon'	=> 'accept',
						'text'	=> 'Successully connected to the online backup vault.',
					) ) );

					// Redirect to the online backup page so refresh does not resubmit
					wp_redirect( 'admin.php?page=wponlinebackup&section=online&messages=true' );

					exit;

				}

			} else {

				// Wipe the quota
				update_option( 'wponlinebackup_quota', array() );

				$this->WPOnlineBackup->Set_Setting( 'username', '' );
				$this->WPOnlineBackup->Set_Setting( 'password', '' );

				$this->WPOnlineBackup->Save_Settings();

				// Register success message
				$this->Register_Messages( array( array(
					'icon'	=> 'accept',
					'text'	=> 'Successfully disconnected from the online backup vault.',
				) ) );

				// Redirect to the online backup page so refresh does not resubmit
				wp_redirect( 'admin.php?page=wponlinebackup&section=online&messages=true' );

				exit;

			}

		}

		if ( $username != '' ) {

			add_meta_box( 'wponlinebackuponlineactive', __( 'Online Backup', 'wponlinebackup' ), array( & $this, 'Print_Online_Active' ), 'wponlinebackuponline', 'normal' );

		} else {

			// If doing encryption add the header bits
			if ( $do_encryption ) {

				wp_enqueue_script( 'jquery' );
				add_action( 'admin_head', array( & $this, 'Head_Online_Credentials_Encryption' ) );

			}

			add_meta_box( 'wponlinebackuponlinecredentials', __( 'Online Backup', 'wponlinebackup' ), array( & $this, 'Print_Online_Credentials' ), 'wponlinebackuponline', 'normal', 'default', array( 'do_encryption' => $do_encryption ) );

		}

		// Enable the form wrapper
		$this->enable_form = 'online';
	}

	/*public*/ function Print_Online_Active()
	{
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/online.png" alt=""></p>
<p><?php _e( 'The plugin is currently connected to the online backup servers and Online Backup is available - your backup data can be transmitted to our secure online vault.', 'wponlinebackup' ); ?><br>
<?php printf( __( 'You may view your available backups at %s, by logging in with your username and password.', 'wponlinebackup' ), '<a href="https://wordpress.backup-technology.com/">https://wordpress.backup-technology.com/</a>' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 14px"><label for="username" style="font-weight: bold"><?php _e( 'Online Backup Username:', 'wponlinebackup' ); ?></label></th>
<td><p><?php echo htmlentities( $this->WPOnlineBackup->Get_Setting( 'username' ), ENT_QUOTES ); ?></p></td>
</tr>
<?php
		// If we have quota information available, display it
		$quota = get_option( 'wponlinebackup_quota', array() );
		if ( isset( $quota['used'] ) && isset( $quota['max'] ) ) {
			if ( $quota['used'] == $quota['max'] ) {
				$percent = 100;
			} else {
				$percent = floor( ( $quota['used'] * 100 ) / $quota['max'] );
				if ( $percent == 100 ) $percent = 99;
			}
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 14px"><label for="username" style="font-weight: bold"><?php _e( 'Online Backup Usage:', 'wponlinebackup' ); ?></label></th>
<td><div style="margin: 4px 0 0 0; text-align: left; height: 20px">
<div style="text-align: left; width: 250px; height: 20px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progressctile.gif'); background-repeat: repeat-x; float: left; margin-right: 10px">
<div style="height: 20px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresscl.gif'); background-repeat: no-repeat">
<div style="height: 20px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresscr.gif'); background-repeat: no-repeat; background-position: top right; padding: 1px"><div>
<div style="margin-right: auto; width: <?php echo $percent; ?>%; height: 18px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresshead.gif'); background-repeat: no-repeat; background-position: top right; overflow-x: hidden">
<div style="height: 18px; background-image: url('<?php echo WPONLINEBACKUP_URL; ?>/images/progresstile.gif'); background-repeat: repeat-x; background-position: top right; margin-right: 3px">
<div style="width: 7px; height: 18px; overflow: hidden"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/progressbase.gif" style="width: 3px; height: 18px" alt=""></div>
</div>
</div>
</div></div>
</div>
</div>
<p style="margin: 0; font-weight: bold; font-size: 15px; font-family: 'Georgia', 'Times New Roman', 'Bitstream Charter', 'Times', serif"><?php echo WPOnlineBackup_Formatting::Fix_B( $quota['used'] ); ?> / <?php echo WPOnlineBackup_Formatting::Fix_B( $quota['max'] ); ?> (<?php echo $percent; ?>%)</p>
</div><div style="clear: both"></div></td>
</tr>
<?php
		}
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Disconnect', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
</table>
<?php
	}

	/*public*/ function Head_Online_Credentials_Encryption()
	{
		$key = json_encode( array(
			'key' 		=> $this->WPOnlineBackup->Get_Setting( 'encryption_key' ),
		) );
?>
<script type="text/javascript">
//<![CDATA[
jQuery(function($)
{
	var params = <?php echo $key; ?>;
	$('#encryption_key').after(
		$('<span></span>')
			.html('&nbsp;<input type="checkbox" id="encryption_key_show" name="encryption_key_show">&nbsp;Show encryption key')
			.find('#encryption_key_show')
			.click(function()
			{
				var oele = $('#encryption_key');
				var nele = $('<input type="' + ( $('#encryption_key_show').is(':checked') ? 'text' : 'password' ) + '">')
					.val( oele.val() );
				oele
					.after(nele)
					.detach();
				nele
					.attr( 'id', 'encryption_key' )
					.attr( 'name', 'encryption_key' )
					.css( 'width', '250px' );
			}).parent()
	);
});
//]]>
</script>
<?php
	}

	/*public*/ function Print_Online_Credentials( $post, $metabox )
	{
		if ( $metabox['args']['do_encryption'] ) {
?>
<input type="hidden" name="newkeys" value="1">
<?php
		}
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/online.png" alt=""></p>
<p><?php _e( 'The plugin is not currently connected to our secure online vault, therefore Online Backup is not currently available.', 'wponlinebackup' ); ?><br>
<?php printf( __( 'To be able to use our online service you will need to register for a FREE account at %s, and provide your username and password to the plugin below. Should you then lose your entire WordPress website you can then download your data from our website.', 'wponlinebackup' ), '<a href="https://wordpress.backup-technology.com/Create_Account">https://wordpress.backup-technology.com/Create_Account</a>' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="username" style="font-weight: bold"><?php _e( 'Online Backup Username:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="text" style="width: 250px" id="username" name="username" value="<?php echo htmlentities( $this->WPOnlineBackup->Get_Setting( 'username' ), ENT_QUOTES ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="password" style="font-weight: bold"><?php _e( 'Online Backup Password:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="password" style="width: 250px" id="password" name="password" value="<?php echo htmlentities( $this->WPOnlineBackup->Get_Setting( 'password' ), ENT_QUOTES ); ?>"></p></td>
</tr>
<?php
		if ( $metabox['args']['do_encryption'] ) {

			if ( count( $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) != count( $this->WPOnlineBackup->Get_Env( 'encryption_list' ) ) ) {
?>
<tr valign="top">
<td colspan="2"><p style="text-align: center"><span style="padding: 4px; display: inline-block; text-align: left; border: 1px dashed #000; background: #E9E999">
<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/error.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'Your blog does not have the following encryption types available.', 'wponlinebackup' ); ?></b><br>
<?php _e( 'If your blog was previously backed up online using any of these types, you will not be able to configure online backup using this plugin installation.', 'wponlinebackup' ); ?><br>
<?php _e( 'You may need to contact your host about this.', 'wponlinebackup' ); ?><br><br>
<?php
				$missing = array_diff( $this->WPOnlineBackup->Get_Env( 'encryption_list' ), $this->WPOnlineBackup->Get_Env( 'encryption_types' ) );
				end( $missing );
				$last = key( $missing );
				foreach ( $missing as $type ) {
?>
<b><?php echo $type; ?></b><?php
					if ( $type != $last ) {
?><br>
<?php
					}
				}
?>
</span></p></td>
</tr>
<?php
			}
?><tr valign="top">
<th scope="row" style="text-align: right; padding: 15px">&nbsp;</th>
<td><p><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/error.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'ATTENTION! DO NOT LOSE YOUR ENCRYPTION DETAILS!', 'wponlinebackup' ); ?></b><br>
<?php _e( 'Just remember, your backups can NEVER be recovered if you forget these details.', 'wponlinebackup' ); ?><br>
<?php _e( 'Therefore, it is IMPERATIVE that you write them down somewhere. Please do not contact us regarding lost encryption details... there is absolutely nothing we can do.', 'wponlinebackup' ); ?>
</p></td>
</tr><tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="encryption_type" style="font-weight: bold"><?php _e( 'Encryption type:', 'wponlinebackup' ); ?></label></th>
<td><p><select id="encryption_type" name="encryption_type">
<?php
				// Iterate and display available encryption types
				foreach ( array_merge( array( '' => 'None [Not recommended]' ), $this->WPOnlineBackup->Get_Env( 'encryption_types' ) ) as $key => $type ) {
?><option value="<?php echo $type; ?>"<?php
					// Mark the recommended value as the default
					if ( $type == $this->WPOnlineBackup->Get_Setting( 'encryption_type' ) ) {
?> selected="selected"<?php
					}
?>><?php
					// Pump out the type, and add recommendation labels
					if ( $type == 'AES128' ) printf( __( '%s [Recommended]', 'wponlinebackup' ), $type );
					else echo $type;
?></option>
<?php
				}
?></select></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="encryption_key" style="font-weight: bold"><?php _e( 'Encryption key:', 'wponlinebackup' ); ?></label></th>
<td><p><input type="password" style="width: 250px" id="encryption_key" name="encryption_key" value="<?php echo htmlentities( $this->WPOnlineBackup->Get_Setting( 'encryption_key' ), ENT_QUOTES, 'UTF-8' ); ?>"><br>
<i><?php _e( 'Your encryption key is just like a password, it can be anything you want it to be.', 'wponlinebackup' ); ?></i></p></td>
</tr>
<?php
		}
?>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Connect', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
</table>
<?php
	}

	/*private*/ function Prepare_Help()
	{
		add_meta_box( 'wponlinebackuphelpsupport', __( 'Help & Support', 'wponlinebackup' ), array( & $this, 'Print_Help' ), 'wponlinebackuphelp', 'normal' );
	}

	/*public*/ function Print_Help()
	{
		// Dump out the same Help we put in the Help button
		echo $this->Get_Help();
	}

	/*private*/ function Prepare_Advanced()
	{
		if ( array_key_exists( 'advancednonce', $_POST ) && wp_verify_nonce( strval( $_POST['advancednonce'] ), 'advanced' ) ) {

			$errors = array();

			if ( !array_key_exists( 'override_max_execution_time', $_POST ) || !array_key_exists( 'max_execution_time', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'max_execution_time', null );
			} else {
				$value = intval( $_POST['max_execution_time'] );
				if ( strval( $value ) != strval( $_POST['max_execution_time'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_execution_time must be a number',
					);
				} else if ( $value < 5 || $value > 3600 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_execution_time must be between 5 and 3600',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'max_execution_time', $value );
			}

			if ( !array_key_exists( 'override_min_execution_time', $_POST ) || !array_key_exists( 'min_execution_time', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'min_execution_time', null );
			} else {
				$value = intval( $_POST['min_execution_time'] );
				if ( strval( $value ) != strval( $_POST['min_execution_time'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'min_execution_time must be a number',
					);
				} else if ( $value < 1 || $value > 3600 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'min_execution_time must be between 1 and 3600',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'min_execution_time', $value );
			}

			if ( !array_key_exists( 'override_timeout_recovery_time', $_POST ) || !array_key_exists( 'timeout_recovery_time', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'timeout_recovery_time', null );
			} else {
				$value = intval( $_POST['timeout_recovery_time'] );
				if ( strval( $value ) != strval( $_POST['timeout_recovery_time'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'timeout_recovery_time must be a number',
					);
				} else if ( $value < 120 || $value > 86400 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'timeout_recovery_time must be between 120 and 86400',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'timeout_recovery_time', $value );
			}

			if ( !array_key_exists( 'override_time_presumed_dead', $_POST ) || !array_key_exists( 'time_presumed_dead', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'time_presumed_dead', null );
			} else {
				$value = intval( $_POST['time_presumed_dead'] );
				if ( strval( $value ) != strval( $_POST['time_presumed_dead'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'time_presumed_dead must be a number',
					);
				} else if ( $value < 450 || $value > 86400 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'time_presumed_dead must be between 450 and 86400',
					);
				} else if ( $value < $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ) * 2 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'time_presumed_dead must be at least twice max_execution_time',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'time_presumed_dead', $value );
			}

			if ( !array_key_exists( 'override_local_tmp_dir', $_POST ) || !array_key_exists( 'local_tmp_dir', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'local_tmp_dir', null );
			} else {
				$value = strval( $_POST['local_tmp_dir'] );
				if ( !file_exists( $value ) || !is_dir( $value ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'local_tmp_dir must be an existing directory',
					);
				} else {
					if ( ( $f = @fopen( $value . '/obfwtest', 'w' ) ) === false ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> 'local_tmp_dir does not appear to be accessible',
						);
					} else {
						@fclose( $f );
						@unlink( $value . '/obfwtest' );
					}
				}
				$this->WPOnlineBackup->Set_Setting( 'local_tmp_dir', $value );
			}

			if ( !array_key_exists( 'override_gzip_tmp_dir', $_POST ) || !array_key_exists( 'gzip_tmp_dir', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'gzip_tmp_dir', null );
			} else {
				$value = strval( $_POST['gzip_tmp_dir'] );
				if ( !file_exists( $value ) || !is_dir( $value ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'gzip_tmp_dir must be an existing directory',
					);
				} else {
					if ( ( $f = @fopen( $value . '/obfwtest', 'w' ) ) === false ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> 'gzip_tmp_dir does not appear to be accessible',
						);
					} else {
						@fclose( $f );
						@unlink( $value . '/obfwtest' );
					}
				}
				$this->WPOnlineBackup->Set_Setting( 'gzip_tmp_dir', $value );
			}

			if ( !array_key_exists( 'override_backup_directory', $_POST ) || !array_key_exists( 'backup_directory', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'backup_directory', null );
			} else {
				$value = strval( $_POST['backup_directory'] );
				if ( !file_exists( $value ) || !is_dir( $value ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'backup_directory must be an existing directory',
					);
				} else {
					if ( ( $f = @fopen( $value . '/obfwtest', 'w' ) ) === false ) {
						$errors[] = array(
							'icon'	=> 'error',
							'text'	=> 'backup_directory does not appear to be accessible',
						);
					} else {
						@fclose( $f );
						@unlink( $value . '/obfwtest' );
					}
				}
				$this->WPOnlineBackup->Set_Setting( 'backup_directory', $value );
			}

			if ( !array_key_exists( 'override_core_tables', $_POST ) || !array_key_exists( 'core_tables', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'core_tables', null );
			} else {
				$value = explode( ',', strval( $_POST['core_tables'] ) );
				$this->WPOnlineBackup->Set_Setting( 'core_tables', $value );
			}

			if ( !array_key_exists( 'override_dump_segment_size', $_POST ) || !array_key_exists( 'dump_segment_size', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'dump_segment_size', null );
			} else {
				$value = intval( $_POST['dump_segment_size'] );
				if ( strval( $value ) != strval( $_POST['dump_segment_size'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'dump_segment_size must be a number',
					);
				} else if ( $value < 50 || $value > 10000 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'dump_segment_size must be between 50 and 10000',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'dump_segment_size', $value );
			}

			if ( !array_key_exists( 'override_sync_segment_size', $_POST ) || !array_key_exists( 'sync_segment_size', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'sync_segment_size', null );
			} else {
				$value = intval( $_POST['sync_segment_size'] );
				if ( strval( $value ) != strval( $_POST['sync_segment_size'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'sync_segment_size must be a number',
					);
				} else if ( $value < 50 || $value > 10000 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'sync_segment_size must be between 50 and 10000',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'sync_segment_size', $value );
			}

			if ( !array_key_exists( 'override_max_block_size', $_POST ) || !array_key_exists( 'max_block_size', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'max_block_size', null );
			} else {
				$value = intval( $_POST['max_block_size'] );
				if ( strval( $value ) != strval( $_POST['max_block_size'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_block_size must be a number',
					);
				} else if ( $value < 1024 * 1024 || $value > 1024 * 1024 * 1024 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_block_size must be between ' . ( 1024 * 1024 ) . ' and ' . ( 1024 * 1024 * 1024 ),
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'max_block_size', $value );
			}

			if ( !array_key_exists( 'override_max_email_size', $_POST ) || !array_key_exists( 'max_email_size', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'max_email_size', null );
			} else {
				$value = intval( $_POST['max_email_size'] );
				if ( strval( $value ) != strval( $_POST['max_email_size'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_email_size must be a number',
					);
				} else if ( $value < 1024 * 1024 || $value > 1024 * 1024 * 1024 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_email_size must be between ' . ( 1024 * 1024 ) . ' and ' . ( 1024 * 1024 * 1024 ),
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'max_email_size', $value );
			}

			if ( !array_key_exists( 'override_file_buffer_size', $_POST ) || !array_key_exists( 'file_buffer_size', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'file_buffer_size', null );
			} else {
				$value = intval( $_POST['file_buffer_size'] );
				if ( strval( $value ) != strval( $_POST['file_buffer_size'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'file_buffer_size must be a number',
					);
				} else if ( $value < 1024 || $value > 5 * 1024 * 1024 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'file_buffer_size must be between ' . 1024 . ' and ' . ( 5 * 1024 * 1024 ),
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'file_buffer_size', $value );
			}

			if ( !array_key_exists( 'override_encryption_block_size', $_POST ) || !array_key_exists( 'encryption_block_size', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'encryption_block_size', null );
			} else {
				$value = intval( $_POST['encryption_block_size'] );
				if ( strval( $value ) != strval( $_POST['encryption_block_size'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'encryption_block_size must be a number',
					);
				} else if ( $value < 1024 || $value > 5 * 1024 * 1024 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'encryption_block_size must be between ' . 1024 . ' and ' . ( 5 * 1024 * 1024 ),
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'encryption_block_size', $value );
			}

			if ( !array_key_exists( 'override_max_frozen_retries', $_POST ) || !array_key_exists( 'max_frozen_retries', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'max_frozen_retries', null );
			} else {
				$value = intval( $_POST['max_frozen_retries'] );
				if ( strval( $value ) != strval( $_POST['max_frozen_retries'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_frozen_retries must be a number',
					);
				} else if ( $value < 0 || $value > 1000 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_frozen_retries must be between 0 and 1000',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'max_frozen_retries', $value );
			}

			if ( !array_key_exists( 'override_max_progress_retries', $_POST ) || !array_key_exists( 'max_progress_retries', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'max_progress_retries', null );
			} else {
				$value = intval( $_POST['max_progress_retries'] );
				if ( strval( $value ) != strval( $_POST['max_progress_retries'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_progress_retries must be a number',
					);
				} else if ( $value < 0 || $value > 1000 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'max_progress_retries must be between 0 and 1000',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'max_progress_retries', $value );
			}

			if ( !array_key_exists( 'override_ignore_ssl_cert', $_POST ) || !array_key_exists( 'ignore_ssl_cert', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'ignore_ssl_cert', null );
			} else {
				$value = strval( $_POST['ignore_ssl_cert'] );
				if ( $value != 'yes' && $value != 'no' ) $value = 'no';
				$this->WPOnlineBackup->Set_Setting( 'ignore_ssl_cert', $value );
			}

			if ( !array_key_exists( 'override_update_ticks', $_POST ) || !array_key_exists( 'update_ticks', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'update_ticks', null );
			} else {
				$value = intval( $_POST['update_ticks'] );
				if ( strval( $value ) != strval( $_POST['update_ticks'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'update_ticks must be a number',
					);
				} else if ( $value < 0 || $value > 500 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'update_ticks must be between 0 and 500',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'update_ticks', $value );
			}

			if ( !array_key_exists( 'override_remote_api_retries', $_POST ) || !array_key_exists( 'remote_api_retries', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'remote_api_retries', null );
			} else {
				$value = intval( $_POST['remote_api_retries'] );
				if ( strval( $value ) != strval( $_POST['remote_api_retries'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'remote_api_retries must be a number',
					);
				} else if ( $value < 1 || $value > 50 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'remote_api_retries must be between 1 and 50',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'remote_api_retries', $value );
			}

			if ( !array_key_exists( 'override_use_wpdb_api', $_POST ) || !array_key_exists( 'use_wpdb_api', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'use_wpdb_api', null );
			} else {
				$value = strval( $_POST['use_wpdb_api'] );
				if ( $value != 'yes' && $value != 'no' ) $value = 'no';
				$this->WPOnlineBackup->Set_Setting( 'use_wpdb_api', $value );
			}

			if ( !array_key_exists( 'override_handle_limit', $_POST ) || !array_key_exists( 'handle_limit', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'handle_limit', null );
			} else {
				$value = intval( $_POST['handle_limit'] );
				if ( strval( $value ) != strval( $_POST['handle_limit'] ) ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'handle_limit must be a number',
					);
				} else if ( $value < 1 || $value > 1024 ) {
					$errors[] = array(
						'icon'	=> 'error',
						'text'	=> 'handle_limit must be between 1 and 1024',
					);
				}
				$this->WPOnlineBackup->Set_Setting( 'handle_limit', $value );
			}

			if ( !array_key_exists( 'override_zip_backwards_compat', $_POST ) || !array_key_exists( 'zip_backwards_compat', $_POST ) ) {
				$this->WPOnlineBackup->Set_Setting( 'zip_backwards_compat', null );
			} else {
				$value = strval( $_POST['zip_backwards_compat'] );
				if ( $value != 'yes' && $value != 'no' ) $value = 'no';
				$this->WPOnlineBackup->Set_Setting( 'zip_backwards_compat', $value );
			}

// If we have errors, show the form again with an error message
			if ( count( $errors ) ) {

				$this->Register_Messages( $errors );

			} else {

// No errors, save the settings
				$this->WPOnlineBackup->Save_Settings();

// Register success message
				$this->Register_Messages( array( array(
					'icon'	=> 'accept',
					'text'	=> 'Saved settings.',
				) ) );

// Redirect to the settings page so refresh does not resubmit
				wp_redirect( 'admin.php?page=wponlinebackup&section=advanced&messages=true' );

				exit;

			}

		}

		add_meta_box( 'wponlinebackupadvancedsettings', __( 'Advanced Settings', 'wponlinebackup' ), array( & $this, 'Print_Advanced_Settings' ), 'wponlinebackupadvanced', 'normal' );

// Enable the form wrapper
		$this->enable_form = 'advanced';
	}

	/*public*/ function Print_Advanced_Settings()
	{
?>
<p style="float: left; margin: 5px"><img src="<?php echo WPONLINEBACKUP_URL; ?>/images/settings.png" alt=""></p>
<p><?php _e( 'These are advanced (and sometimes dangerous) settings that control the overall behaviour of how backups are performed and how failures are detected. This page is intended only for debugging by Backup Technology staff.', 'wponlinebackup' ); ?></p>
<p><?php _e( 'For 99% of users, the default settings will work perfectly fine. Changes to settings here can prevent backups from working, reduce the performance of your blog during backups, and even reduce the security of online backups.', 'wponlinebackup' ); ?></p>
<table class="form-table" style="clear: left; border-top: 1px solid #DFDFDF">
<tr valign="top">
<td colspan="2"><p style="text-align: center"><span style="padding: 4px; display: inline-block; text-align: left; border: 1px dashed #000; background: #E9E999">
<img src="<?php echo WPONLINEBACKUP_URL; ?>/images/exclamation.png" style="width: 16px; height: 16px; vertical-align: middle" alt="">&nbsp;<b><?php _e( 'ATTENTION! DO NOT MAKE CHANGES TO THESE SETTINGS UNLESS ABSOLUTELY NECESSARY OR A MEMBER OF BACKUP TECHNOLOGY STAFF ASKS YOU TO DO SO.', 'wponlinebackup' ); ?></b>
</span></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="max_execution_time" style="font-weight: bold"><?php echo 'max_execution_time'; ?></label></th>
<td><p><input type="checkbox" name="override_max_execution_time" id="override_max_execution_time" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'max_execution_time', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="max_execution_time" id="max_execution_time" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'max_execution_time' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="min_execution_time" style="font-weight: bold"><?php echo 'min_execution_time'; ?></label></th>
<td><p><input type="checkbox" name="override_min_execution_time" id="override_min_execution_time" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'min_execution_time', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="min_execution_time" id="min_execution_time" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'min_execution_time' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="timeout_recovery_time" style="font-weight: bold"><?php echo 'timeout_recovery_time'; ?></label></th>
<td><p><input type="checkbox" name="override_timeout_recovery_time" id="override_timeout_recovery_time" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="timeout_recovery_time" id="timeout_recovery_time" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'timeout_recovery_time' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="time_presumed_dead" style="font-weight: bold"><?php echo 'time_presumed_dead'; ?></label></th>
<td><p><input type="checkbox" name="override_time_presumed_dead" id="override_time_presumed_dead" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="time_presumed_dead" id="time_presumed_dead" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'time_presumed_dead' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="local_tmp_dir" style="font-weight: bold"><?php echo 'local_tmp_dir'; ?></label></th>
<td><p><strong>WARNING:</strong> This path is automatically excluded from the filesystem backup. It is therefore recommended that this folder contain absolutely nothing and is dedicated to the plugin.</p>
<p><input type="checkbox" name="override_local_tmp_dir" id="override_local_tmp_dir" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="local_tmp_dir" id="local_tmp_dir" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'local_tmp_dir' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="gzip_tmp_dir" style="font-weight: bold"><?php echo 'gzip_tmp_dir'; ?></label></th>
<td><p><strong>WARNING:</strong> This path is automatically excluded from the filesystem backup. It is therefore recommended that this folder contain absolutely nothing and is dedicated to the plugin.</p>
<p><input type="checkbox" name="override_gzip_tmp_dir" id="override_gzip_tmp_dir" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'gzip_tmp_dir', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="gzip_tmp_dir" id="gzip_tmp_dir" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'gzip_tmp_dir' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="backup_directory" style="font-weight: bold"><?php echo 'backup_directory'; ?></label></th>
<td><p><strong>WARNING:</strong> This path is automatically excluded from the filesystem backup. It is therefore recommended that this folder contain absolutely nothing and is dedicated to the plugin.</p>
<p><input type="checkbox" name="override_backup_directory" id="override_backup_directory" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'backup_directory', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="backup_directory" id="backup_directory" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'backup_directory' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="core_tables" style="font-weight: bold"><?php echo 'core_tables'; ?></label></th>
<td><p><input type="checkbox" name="override_core_tables" id="override_core_tables" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'core_tables', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="core_tables" id="core_tables" value="<?php echo implode( ',', $this->WPOnlineBackup->Get_Setting( 'core_tables' ) ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="dump_segment_size" style="font-weight: bold"><?php echo 'dump_segment_size'; ?></label></th>
<td><p><input type="checkbox" name="override_dump_segment_size" id="override_dump_segment_size" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'dump_segment_size', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="dump_segment_size" id="dump_segment_size" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'dump_segment_size' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="sync_segment_size" style="font-weight: bold"><?php echo 'sync_segment_size'; ?></label></th>
<td><p><input type="checkbox" name="override_sync_segment_size" id="override_sync_segment_size" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'sync_segment_size', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="sync_segment_size" id="sync_segment_size" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'sync_segment_size' ); ?>"></p></td>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="max_block_size" style="font-weight: bold"><?php echo 'max_block_size'; ?></label></th>
<td><p><input type="checkbox" name="override_max_block_size" id="override_max_block_size" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'max_block_size', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="max_block_size" id="max_block_size" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'max_block_size' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="max_email_size" style="font-weight: bold"><?php echo 'max_email_size'; ?></label></th>
<td><p><input type="checkbox" name="override_max_email_size" id="override_max_email_size" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'max_email_size', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="max_email_size" id="max_email_size" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'max_email_size' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="file_buffer_size" style="font-weight: bold"><?php echo 'file_buffer_size'; ?></label></th>
<td><p><input type="checkbox" name="override_file_buffer_size" id="override_file_buffer_size" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'file_buffer_size', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="file_buffer_size" id="file_buffer_size" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'file_buffer_size' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="encryption_block_size" style="font-weight: bold"><?php echo 'encryption_block_size'; ?></label></th>
<td><p><input type="checkbox" name="override_encryption_block_size" id="override_encryption_block_size" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'encryption_block_size', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="encryption_block_size" id="encryption_block_size" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'encryption_block_size' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="max_frozen_retries" style="font-weight: bold"><?php echo 'max_frozen_retries'; ?></label></th>
<td><p><input type="checkbox" name="override_max_frozen_retries" id="override_max_frozen_retries" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'max_frozen_retries', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="max_frozen_retries" id="max_frozen_retries" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'max_frozen_retries' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="max_progress_retries" style="font-weight: bold"><?php echo 'max_progress_retries'; ?></label></th>
<td><p><input type="checkbox" name="override_max_progress_retries" id="override_max_progress_retries" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'max_progress_retries', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="max_progress_retries" id="max_progress_retries" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'max_progress_retries' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="ignore_ssl_cert" style="font-weight: bold"><?php echo 'ignore_ssl_cert'; ?></label></th>
<td><p><input type="checkbox" name="override_ignore_ssl_cert" id="override_ignore_ssl_cert" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'ignore_ssl_cert', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="radio" name="ignore_ssl_cert" id="ignore_ssl_cert" value="yes"<?php
		if ( $this->WPOnlineBackup->Get_Setting( 'ignore_ssl_cert' ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<?php _e( 'Yes', 'Yes/No radio button', 'wponlinebackup' ); ?>&nbsp;<input type="radio" name="ignore_ssl_cert" id="ignore_ssl_cert" value="no"<?php
		if ( !$this->WPOnlineBackup->Get_Setting( 'ignore_ssl_cert' ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<?php _e( 'No', 'Yes/No radio button', 'wponlinebackup' ); ?></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="update_ticks" style="font-weight: bold"><?php echo 'update_ticks'; ?></label></th>
<td><p><input type="checkbox" name="override_update_ticks" id="override_update_ticks" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'update_ticks', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="update_ticks" id="update_ticks" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'update_ticks' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="remote_api_retries" style="font-weight: bold"><?php echo 'remote_api_retries'; ?></label></th>
<td><p><input type="checkbox" name="override_remote_api_retries" id="override_remote_api_retries" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'remote_api_retries', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="remote_api_retries" id="remote_api_retries" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'remote_api_retries' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="use_wpdb_api" style="font-weight: bold"><?php echo 'use_wpdb_api'; ?></label></th>
<td><p><input type="checkbox" name="override_use_wpdb_api" id="override_use_wpdb_api" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'use_wpdb_api', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="radio" name="use_wpdb_api" id="use_wpdb_api" value="yes"<?php
		if ( $this->WPOnlineBackup->Get_Setting( 'use_wpdb_api' ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<?php _e( 'Yes', 'Yes/No radio button', 'wponlinebackup' ); ?>&nbsp;<input type="radio" name="use_wpdb_api" id="use_wpdb_api" value="no"<?php
		if ( !$this->WPOnlineBackup->Get_Setting( 'use_wpdb_api' ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<?php _e( 'No', 'Yes/No radio button', 'wponlinebackup' ); ?></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="handle_limit" style="font-weight: bold"><?php echo 'handle_limit'; ?></label></th>
<td><p><input type="checkbox" name="override_handle_limit" id="override_handle_limit" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'handle_limit', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="text" style="width: 250px" name="handle_limit" id="handle_limit" value="<?php echo $this->WPOnlineBackup->Get_Setting( 'handle_limit' ); ?>"></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px"><label for="zip_backwards_compat" style="font-weight: bold"><?php echo 'zip_backwards_compat'; ?></label></th>
<td><p><input type="checkbox" name="override_zip_backwards_compat" id="override_zip_backwards_compat" value="1"<?php
// Mark as checked if we selected this option
		if ( !is_null( $this->WPOnlineBackup->Get_Setting( 'zip_backwards_compat', true ) ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<input type="radio" name="zip_backwards_compat" id="zip_backwards_compat" value="yes"<?php
		if ( $this->WPOnlineBackup->Get_Setting( 'zip_backwards_compat' ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<?php _e( 'Yes', 'Yes/No radio button', 'wponlinebackup' ); ?>&nbsp;<input type="radio" name="zip_backwards_compat" id="zip_backwards_compat" value="no"<?php
		if ( !$this->WPOnlineBackup->Get_Setting( 'zip_backwards_compat' ) ) {
?> checked="checked"<?php
		}
?>>&nbsp;<?php _e( 'No', 'Yes/No radio button', 'wponlinebackup' ); ?></p></td>
</tr>
<tr valign="top">
<th scope="row" style="text-align: right; padding: 18px">&nbsp;</th>
<td><p><input type="submit" value="<?php echo __( 'Save Advanced Settings', 'wponlinebackup' ); ?>" class="button-primary"></p></td>
</tr>
</table>
<?php
	}

	/*public*/ function AJAX_Progress()
	{
		// Load the boostrap, and dump the progress
		$this->WPOnlineBackup->Load_Bootstrap();

		$progress = $this->Fetch_Progress();

		if ( isset( $progress['size'] ) )
			$progress['size'] = WPOnlineBackup_Formatting::Fix_B( $progress['size'], true );

		echo json_encode( $progress );

		exit;
	}

	/*public*/ function AJAX_Kick_Start()
	{
		// Prevent abort
		@ignore_user_abort( true );

		// Load the boostrap, and kick start the backup
		$this->WPOnlineBackup->Load_Bootstrap();
		$this->WPOnlineBackup->bootstrap->Perform_Check();
		exit;
	}

	/*private*/ function Fetch_Progress()
	{
		global $wpdb;

		$size = false;

		// Grab status from bootstrap
		$status = $this->WPOnlineBackup->bootstrap->Fetch_Status();

		if ( isset( $status['progress']['type'] ) ) {

			$type = $status['progress']['type'];

			// Do we need the download bits in the form? If so, store a size, blank until we finish
			// If we're running or successful we'll then populate size and file fields
			switch ( $type ) {

				case WPONLINEBACKUP_ACTIVITY_BACKUP:
				case WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP:
					if ( isset( $status['progress']['config']['target'] ) && $status['progress']['config']['target'] == 'download' )
						$do_size = true;
					else
						$do_size = false;
					break;

				case WPONLINEBACKUP_ACTIVITY_DECRYPT:
					$do_size = true;
					break;

				default:
					$type = WPONLINEBACKUP_ACTIVITY_UNKNOWN;
					$do_size = false;
					break;

			}

		} else {

			// Progress is a bit miffy, mark unknown - it'll probably come with a failure anyway
			$type = WPONLINEBACKUP_ACTIVITY_UNKNOWN;
			$do_size = false;

		}

		// If size is still false at end, it means we failed so shouldn't give any size information
		if ( $do_size )
			$size = false;

		if ( $status['status'] == WPONLINEBACKUP_STATUS_NONE ) {

			switch ( $type ) {

				default:
				case WPONLINEBACKUP_ACTIVITY_UNKNOWN:
					$type_string = _x( 'Unknown', 'Type of backup', 'wponlinebackup' );
					break;

				case WPONLINEBACKUP_ACTIVITY_BACKUP:
				case WPONLINEBACKUP_ACTIVITY_AUTO_BACKUP:
					$type_string = __( 'Backup', 'wponlinebackup' );
					break;

				case WPONLINEBACKUP_ACTIVITY_DECRYPT:
					$type_string = __( 'Decrypt', 'wponlinebackup' );
					break;

			}

			if ( isset( $status['activity_id'] ) ) {

				// Activity has since completed - grab activity information if we can
				$activity = $wpdb->get_row(
					'SELECT activity_id, start, end, comp, media, errors, warnings, compressed, encrypted, ' .
						'bsize, bcount, rsize, rcount ' .
					'FROM `' . $wpdb->prefix . 'wponlinebackup_activity_log` ' .
					'WHERE activity_id = ' . $status['activity_id'],
					ARRAY_A
				);

			} else {

				$activity = null;

			}

			if ( is_null( $activity ) ) {

				if ( isset( $status['progress']['message'] ) ) {

					// We've got a message so we probably failed to start before we could make the activity
					$message = array( 'exclamation.png', $status['progress']['message'] );

				} else {

					// We don't have an activity
					$message = array( 'information.png', __( 'Nothing is currently running.', 'wponlinebackup' ) );

				}

				$progress = 0;

			} else {

				// Return the completion status
				switch ( $activity['comp'] ) {

					//case WPONLINEBACKUP_COMP_RUNNING:
					//case WPONLINEBACKUP_COMP_UNEXPECTED:
					default:
						$message = array( 'exclamation.png', sprintf( __( '%s stopped unexpectedly. The WordPress cron may be misconfigured.', 'wponlinebackup' ), $type_string ) );
						break;

					case WPONLINEBACKUP_COMP_SUCCESSFUL:
						$message = array( 'accept.png', $activity['warnings'] ? sprintf( _n( '%1$s completed successfully with %2$d warning.', '%1$s completed successfully with %2$d warnings.', $activity['warnings'] , 'wponlinebackup' ), $type_string, $activity['warnings'] ) : sprintf( __( '%s completed successfully.', 'wponlinebackup' ), $type_string ) );

						if ( $do_size ) {
							$size = $activity['bsize'];
							$file = $status['progress']['download_file'];
						}
						break;

					case WPONLINEBACKUP_COMP_PARTIAL:
						$message = array( 'error.png', $activity['errors'] ? sprintf( _n( '%1$s completed partially with %2$d error. Please consult the event log for more information.', '%1$s completed partially with %2$d errors. Please consult the event log for more information.', $activity['errors'] , 'wponlinebackup' ), $type_string, $activity['errors'] ) : sprintf( __( '%s completed partially.', 'wponlinebackup' ), $type_string ) );

						if ( $do_size ) {
							$size = $activity['bsize'];
							$file = $status['progress']['download_file'];
						}
						break;

					case WPONLINEBACKUP_COMP_STOPPED:
					case WPONLINEBACKUP_COMP_FAILED:
					case WPONLINEBACKUP_COMP_TIMEOUT:
					case WPONLINEBACKUP_COMP_SLOWTIMEOUT:
					case WPONLINEBACKUP_COMP_MEMORY:
					case WPONLINEBACKUP_COMP_SLOWMEMORY:
					case WPONLINEBACKUP_COMP_MAINTENANCE:
						$message = array( 'exclamation.png', $status['progress']['message'] );
						break;

				}

			}

			$progress = 100;

		} else {

			$activity = isset( $status['activity_id'] );

			// Display the message from the status, and work out the progress
			$message = array( 'ajax-loader.gif', isset( $status['progress']['message'] ) ? $status['progress']['message'] : __( 'Processing...', 'wponlinebackup' ) );

			if ( isset( $status['progress']['jobcount'] ) && $status['progress']['jobcount'] ) {

				$progress = floor( ( $status['progress']['jobdone'] * 100 ) / $status['progress']['jobcount'] );

				if ( count( $status['progress']['jobs'] ) ) {

					reset( $status['progress']['jobs'] );
					$job = current( $status['progress']['jobs'] );

					$each_job = floor( ( 100 * $job['progresslen'] ) / $status['progress']['jobcount'] );

					if ( $each_job > 0 ) $progress += floor( ( $job['progress'] * $each_job ) / 100 );

				}

				if ( $progress >= 100 ) $progress = 99;

			} else {

				$progress = 0;

			}

			// Do give size information since we're still running
			if ( $do_size ) {
				$size = '';
				$file = '';
			}

		}

		// Return the information
		$ret = array(
			'status'	=> $status['status'],
			'type'		=> $type,
			'activity_id'	=> $activity ? $status['activity_id'] : 0,
			'errors'	=> $activity ? $status['progress']['errors'] : 0,
			'warnings'	=> $activity ? $status['progress']['warnings'] : 0,
			'message'	=> $message,
			'progress'	=> $progress,
		);

		// Add on the size and file name if we have one
		if ( $do_size && $size !== false ) {
			$ret['size'] = $size;
			$ret['file'] = $file;
		}

		return $ret;
	}
}

?>

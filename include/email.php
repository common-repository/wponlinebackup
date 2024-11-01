<?php

/*
WPOnlineBackup_Backup_Email - Sends the backup via PHPMailer
Simply attaches the backup and sends it to the specified address
*/

class WPOnlineBackup_Backup_Email
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $bootstrap;
	/*private*/ var $stream;
	/*private*/ var $progress;
	/*private*/ var $job;

	/*private*/ var $PHPMailer;
	/*private*/ var $attachment_data;
	/*private*/ var $attachment_filename;

	/*public*/ function WPOnlineBackup_Backup_Email( & $WPOnlineBackup, $db_force_master = '' )
	{
		$this->WPOnlineBackup = & $WPOnlineBackup;
	}

	/*public*/ function Save()
	{
	}

	/*public*/ function CleanUp( $ticking = false )
	{
	}

	/*public*/ function Backup( & $bootstrap, & $stream, & $progress, & $job )
	{
		// Save variables and send email
		$this->bootstrap = & $bootstrap;
		$this->stream = & $stream;
		$this->progress = & $progress;
		$this->job = & $job;

		return $this->Send_Email();
	}

	/*public*/ function Action_PHPMailer_Init( & $PHPMailer )
	{
		// Save the PHPMailer instance, and add the attachment with the filename
		$this->PHPMailer = & $PHPMailer;
		$PHPMailer->AddStringAttachment( $this->attachment_data, $this->attachment_filename );

		// Free up the memory
		unset( $this->attachment_data );
	}

	/*private*/ function Send_Email()
	{
		global $wpdb;

		// Pre-calculate the backup size and store the text representation
		$text_size = WPOnlineBackup_Formatting::Fix_B( $this->progress['file_set']['size'], true );

		// Change the progress message
		if ( $this->job['progress'] == 0 ) {

			$this->progress['message'] = __( 'Sending email...' , 'wponlinebackup' );

			// Log the size of the backup to help with diagnosis using the event log
			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				sprintf( __( 'The backup is %s in size. Emailing it to %s.', 'wponlinebackup' ), $text_size, $this->progress['config']['email_to'] )
			);

			$this->job['progress'] = 1;

			// Force an update so we can properly catch retries
			$this->bootstrap->Tick( false, true );

		} else if ( $this->job['progress'] == 1 ) {

			// We're retrying, increase the count
			if ( $this->job['retries']++ >= 2 ) {

				$email_limit = $this->WPOnlineBackup->Get_Setting( 'max_email_size' );

				// We've tried twice and failed both times - memory usage is nearly always exactly the same on the 3rd and subsequent runs, so let's just die now and not postpone the inevitable
				$this->bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_ERROR,
					sprintf( __( 'Two attempts to send the email timed out. It looked like PHP would have enough to memory to process backup files of up to %s, and your backup file is %s. There may be an issue with your WordPress installation\'s ability to send emails with attachments, or something is causing the emailing process to use more memory than it normally would. Try reducing the size of your backup by adding some exclusions, and check that any email-related plugins are functioning normally.' , 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $email_limit, true ), $text_size ) . PHP_EOL .
						'Failed at: ' . __FILE__ . ':' . __LINE__
				);

				return sprintf( __( 'There is a problem with your WordPress installation\'s ability to send emails, or the backup file is too large to send as an attachment (%s).', 'wponlinebackup' ), $text_size );

			}

			// Force an update to save the new retry count
			$this->bootstrap->Tick( false, true );

		}

		// Check we aren't too big to process
		// TODO: Once Impose_DataSize_Limit is implemented we'll be able to remove this check since we'll never actually get here
		if ( $this->progress['file_set']['size'] > ( $email_limit = $this->WPOnlineBackup->Get_Setting( 'max_email_size' ) ) ) {

			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				sprintf( __( 'The amount of memory required to encode the backup into email format will use up more memory than PHP currently has available. Your backup is %s and PHP only has enough memory for a backup of approximately %s. Try reducing the size of your backup by adding some exclusions.' , 'wponlinebackup' ), $text_size, WPOnlineBackup_Formatting::Fix_B( $email_limit, true ) ) . PHP_EOL .
					'Failed at: ' . __FILE__ . ':' . __LINE__
			);

			return sprintf( __( 'The backup file is too large to send in an email (%s).' , 'wponlinebackup' ), $text_size );

		}

		// Open the backup file for reading into memory
		if ( false === ( $f = @fopen( $this->progress['file_set']['file'], 'r' ) ) )
			return 'Failed to open the backup file for attaching to the email. PHP: ' . OBFW_Exception();

		// Seek past the start
		if ( 0 !== @fseek( $f, $this->progress['file_set']['offset'], SEEK_SET ) )
			return 'Failed to perpare the backup file for attaching to the email. PHP: ' . OBFW_Exception();

		// Read all the data into an output buffer
		ob_start();
		if ( false === @fpassthru( $f ) )
			return 'Failed to read the backup file for attaching to the email. PHP: ' . OBFW_Exception();

		// Grab the output buffer contents and immediately clear the output buffer to free memory
		$this->attachment_data = ob_get_contents();
		ob_end_clean();

		// Calculate the attachment filename
		$this->attachment_filename = 'WPOnlineBackup_Full';

		// Grab the extension from our file_set file, if we fail - give no extension... that would be a bug
		if ( preg_match( '#((?:\\.[a-z]+)+)\\.[A-Za-z0-9]+\\.php$#i', $this->progress['file_set']['file'], $matches ) )
			$this->attachment_filename .= $matches[1];

		// Hook into the PHPMailer initialisation so we can borrow a reference to PHPMailer and add the attachment to the email with our own filename
		add_action( 'phpmailer_init', array( & $this, 'Action_PHPMailer_Init' ) );

		// Prepare the email body
		$body = sprintf( __( 'Online Backup for WordPress backup of %s successfully completed. The size of the backup is %s.', 'wponlinebackup' ), site_url(), $text_size );

		// Require pluggable.php to define wp_mail
		require_once ABSPATH . 'wp-includes/pluggable.php';

		// Send the email
		if ( @wp_mail( $this->progress['config']['email_to'], sprintf( __( 'Backup of %s completed' , 'wponlinebackup' ), site_url() ), $body, '' ) === false ) {

			$error = OBFW_Exception();

			// Free memory in case it wasn't already
			unset( $this->attachment_data );
			unset( $this->attachment_filename );

			// Report the error - more information is available in ErrorInfo - use the reference to phpMailer we stole in the hook function
			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_ERROR,
				__( 'Failed to send an email containing the backup file. It may be too large to send (%s). Try reducing the size of your backup by adding some exclusions.' , 'wponlinebackup' ) . PHP_EOL .
					'PHPMailer: ' . ( isset( $this->PHPMailer->ErrorInfo ) ? $this->PHPMailer->ErrorInfo : 'ErrorInfo unavailable' ) . PHP_EOL .
					$error
			);

			return sprintf( __( 'Failed to send an email containing the backup file; it may be too large to send (%s).' , 'wponlinebackup' ), $text_size );

		} else {

			$this->bootstrap->Log_Event(
				WPONLINEBACKUP_EVENT_INFORMATION,
				__( 'Successfully emailed the backup.' , 'wponlinebackup' )
			);

		}

		// Remove the hook
		remove_action( 'phpmailer_init', array( & $this, 'phpmailer_init' ) );

		// Free memory in case it wasn't already
		unset( $this->attachment_data );
		unset( $this->attachment_filename );

		$this->job['progress'] = 100;

		return true;
	}
}

?>

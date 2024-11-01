jQuery(function($) {

	WPOnlineBackup_Progress = {

		doInit: function ()
		{
			this.errorCount = 0;
			this.stopKS = false;
			this.doRefreshNow();
			this.doRefreshNowKS();
		},

		doRefreshWait: function ()
		{
			var obj = this;
			window.setTimeout(
				function ()
				{
					obj.doRefreshNow();
				},
				WPOnlineBackup_Vars.Refresh_Interval * 1000
			);
		},

		doRefreshNow: function ()
		{
			$.ajax({
				cache:		false,
				url:		WPOnlineBackup_Vars.AJAX_URL,
				data:		'action=wponlinebackup_progress',
				dataType:	'json',
				error:		this.doAJAXError,
				success:	this.doAJAXSuccess,
				context:	this
			});
		},

		doAJAXError: function (XMLHttpRequest, textStatus, errorThrown)
		{
			if ( ++this.errorCount == WPOnlineBackup_Vars.Error_Threshold ) location.reload( true );
			else this.doRefreshWait();
		},

		doAJAXSuccess: function (result, textStatus, XMLHttpRequest)
		{
			// Validate
			if ( !result || result.message === undefined || result.progress === undefined ) {
				this.doAJAXError();
				return;
			}

			this.errorCount = 0;

			// If we're not finished - queue another refresh now just in case some of the updates fail (shouldn't)
			if ( result.progress != 100 ) this.doRefreshWait();

			// Update the message icon and text
			var new_src = WPOnlineBackup_Vars.Plugin_URL + '/images/' + result.message[0];
			if ( $('#wponlinebackup_message_image').attr( 'src' ) != new_src )
				$('#wponlinebackup_message_image').attr( 'src', new_src );
			$('#wponlinebackup_message_text').text( result.message[1] );

			// Fix jQuery bug - don't let width be 0% - brought about by WordPress 3.1's update of jQuery (not sure of specific jQuery version)
			// We do this same fix inside admin.php when we display the monitor page
			if ( result.progress == 0 ) result.progress = 1;

			// Update the progress bar
			$('#wponlinebackup_progress_bar').animate( { width: result.progress.toString() + '%' }, WPOnlineBackup_Vars.Refresh_Interval * 250 );
			$('#wponlinebackup_progress_text').text( result.progress.toString() + '%' );

			// Update the error and warning counts if we have an activity, and make sure the events area is visible
			if ( result.activity_id == 0 ) {

				if ( $('#wponlinebackup_events').is( ':visible' ) )
					$('#wponlinebackup_events').animate( { opacity: 0 } ).slideUp();

			} else {

				if ( !$('#wponlinebackup_events').is( ':visible' ) )
					$('#wponlinebackup_events').css( 'opacity', 0 ).slideDown().animate( { opacity: 1 } );

				var href = WPOnlineBackup_Vars.Page_URL + '&section=events&activity=' + result.activity_id.toString();

				if ( $('#wponlinebackup_events_link').attr( 'href' ) != href )
					$('#wponlinebackup_events_link').attr( 'href', href );

				$('#wponlinebackup_errors').text( result.errors.toString() );
				$('#wponlinebackup_warnings').text( result.warnings.toString() );

			}

			// Has the backup finished?
			if ( result.progress == 100 ) {

				// Stop kickstarting
				this.stopKS = true;

				// In the list of sections we have, it will currently say "Monitor Running Activity", change this back to "Backup" and update the link
				$('#wponlinebackup_section_backup a')
					.attr( 'href', WPOnlineBackup_Vars.Page_URL + '&section=backup' )
					.text( WPOnlineBackup_Vars.String_Backup )
					.addClass( 'current' );

				// Hide the background message and the stop button
				if ( $('#wponlinebackup_background_message').is( ':visible' ) )
					$('#wponlinebackup_background_message').animate( { opacity: 0 } ).slideUp();
				if ( $('#wponlinebackup_stop_message').is( ':visible' ) )
					$('#wponlinebackup_stop_message').animate( { opacity: 0 } ).slideUp();

				// If size is given then update the size of the file and show the download links, and set the filename
				if ( result.size !== undefined ) {
					$('#wponlinebackup_completed_size').text( result.size );
					$('#wponlinebackup_completed_file').text( result.file );
					$('#wponlinebackup_completed_form').val( result.file );
					if ( !$('#wponlinebackup_completed_message').is( ':visible' ) )
						$('#wponlinebackup_completed_message').css( 'opacity', 0 ).slideDown().animate( { opacity: 1 } );
				}

			} else { // result.progress != 100

				// Show the background message and the stop button
				if ( !$('#wponlinebackup_background_message').is( ':visible' ) )
					$('#wponlinebackup_background_message').css( 'opacity', 0 ).slideDown().animate( { opacity: 1 } );
				if ( !$('#wponlinebackup_stop_message').is( ':visible' ) )
					$('#wponlinebackup_stop_message').css( 'opacity', 0 ).slideDown().animate( { opacity: 1 } );

				// Hide the completed message
				if ( $('#wponlinebackup_completed_message').is( ':visible' ) )
					$('#wponlinebackup_completed_message').animate( { opacity: 0 } ).slideUp();

				// If we're stopping, disable the stop button
				if ( result.status == 5 /*WPONLINEBACKUP_STATUS_STOPPING*/ ) {
					$('#wponlinebackup_stop_button').attr('disabled') = 'disabled';
				}

			} // result.progress != 100
		},

		doRefreshWaitKS: function ()
		{
			var obj = this;
			window.setTimeout(
				function ()
				{
					obj.doRefreshNowKS();
				},
				WPOnlineBackup_Vars.Kick_Start_Interval * 1000
			);
		},

		doRefreshNowKS: function ()
		{
			if ( this.stopKS ) return;
			$.ajax({
				cache:		false,
				url:		WPOnlineBackup_Vars.AJAX_URL,
				data:		'action=wponlinebackup_kick_start',
				dataType:	'json',
				error:		this.doAJAXErrorKS,
				success:	this.doAJAXSuccessKS,
				context:	this
			});
		},

		doAJAXErrorKS: function (XMLHttpRequest, textStatus, errorThrown)
		{
			this.doRefreshWaitKS();
		},

		doAJAXSuccessKS: function (result, textStatus, XMLHttpRequest)
		{
			this.doRefreshWaitKS();
		}
	};

	WPOnlineBackup_Progress.doInit();
});

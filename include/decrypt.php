<?php

/*
WPOnlineBackup_Backup_Decrypt class
Decrypts an encrypted backup file
*/

class WPOnlineBackup_Backup_Decrypt
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $bootstrap;
	/*private*/ var $stream;
	/*private*/ var $progress;
	/*private*/ var $job = false;

	/*private*/ var $file = false;
	/*private*/ var $cipher = false;
	/*private*/ var $cipher_init = false;
	/*private*/ var $hash_ctx = false;

	/*public*/ function WPOnlineBackup_Backup_Decrypt( & $WPOnlineBackup, $db_force_master = '' )
	{
		$this->WPOnlineBackup = & $WPOnlineBackup;

		// Check we have CRC32 stuff available
		require_once WPONLINEBACKUP_PATH . '/include/functions.php';
	}

	/*public*/ function Initialise( & $bootstrap, & $progress )
	{
		// Simple
		$progress['jobs'][] = array(
			'processor'		=> 'decrypt',
			'progress'		=> 0,
			'progresslen'		=> 50,
			'legacy'		=> false,
			'key'			=> '',
			'current_iv'		=> '',
			'done_bytes'		=> 0,
			'header_bytes'		=> 0,
			'crc'			=> false,
			'hash_len'		=> 0,
		);

		return true;
	}

	/*public*/ function Backup( & $bootstrap, & $stream, & $progress, & $job )
	{
		$this->bootstrap = & $bootstrap;
		$this->stream = & $stream;
		$this->progress = & $progress;
		$this->job = & $job;

		// Is the file open?
		if ( $this->file === false ) {

			// Open it
			if ( false === ( $this->file = @fopen( $progress['cache']['backup_directory'] . '/' . $progress['config']['file'], 'rb' ) ) ) {

				$ret = OBFW_Tidy_Exception();

				return sprintf( __( 'Failed to open the encrypted backup file at %s: %s', 'wponlinebackup' ), $progress['config']['file'], $ret );

			}

			// Seek - don't forget to seek past header if we've finished it also, since done_bytes only counts the size AFTER the header
			if ( 0 != @fseek( $this->file, $job['done_bytes'] + $job['header_bytes'], SEEK_SET ) ) {

				$ret = OBFW_Exception();

				return sprintf( __( 'Failed to access the encrypted backup file at %s: %s', 'wponlinebackup' ), $progress['config']['file'], $ret );

			}

		}

		if ( $job['progress'] < 10 ) {

			$this->progress['message'] = __( 'Validating the provided encryption details...', 'wponlinebackup' );

			// Force update to update the message
			$bootstrap->Tick( false, true );

			// Read the header
			if ( true !== ( $ret = $this->_Read_Header() ) ) {

				if ( $ret === false )
					return __( 'The encryption details provided were incorrect.', 'wponlinebackup' );

				return sprintf( __( 'Failed to validate the encryption details provided. The error was: %s', 'wponlinebackup' ), $ret );

			}

			// If legacy we might not have a len, in which case it will be false
			if ( $job['header']['len'] === false ) {

				$bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					__( 'Encryption details validated successfully.', 'wponlinebackup' )
				);

			} else {

				$bootstrap->Log_Event(
					WPONLINEBACKUP_EVENT_INFORMATION,
					sprintf( __( 'Encryption details validated successfully. The total decrypted backup size will be %s.', 'wponlinebackup' ), WPOnlineBackup_Formatting::Fix_B( $job['header']['len'], true ) )
				);

			}

			$job['progress'] = 10;

			// Next message
			$this->progress['message'] = __( 'Decrypting...', 'wponlinebackup' );

			// Force update to update the message again and to prevent duplicated events
			$bootstrap->Tick( false, true );

		}

		// Is decryption initialised?
		if ( $this->cipher === false ) {

			// Pass false to prevent key validation, which is already done during _Read_Header
			if ( true !== ( $ret = $this->_Load_Decryption_Cipher( false ) ) )
				return sprintf( __( 'Failed to initialise the decryption process. The error was: %s', 'wponlinebackup' ), $ret );

		}

		// Initialise hash
		if ( $this->WPOnlineBackup->Get_Env( 'inc_hash_available' ) )
			$this->hash_ctx = hash_init( 'crc32b' );
		else
			$this->hash_ctx = false;

		// Do we have a saved hash_ctx we can load?
		if ( isset( $job['saved_hash_ctx'] ) ) {

			if ( $job['saved_hash_ctx'] !== false ) {

				if ( $job['crc'] !== false )
					$job['crc'] = WPOnlineBackup_Functions::Combine_CRC32( $job['crc'], $job['saved_hash_ctx'], $job['hash_len'] );
				else
					$job['crc'] = $job['saved_hash_ctx'];

			}

			$job['saved_hash_ctx'] = false;

		}

		$job['hash_len'] = 0;

		if ( true !== ( $ret = $this->_Decryption_Loop() ) ) {

			// False means CRC failure
			if ( $ret === false )
				return __( 'The file integrity check failed. The file may be corrupt or the encryption details validated but were actually incorrect (there is a small chance of this happening.)', 'wponlinebackup' );

			return sprintf( __( 'Decryption failed. The error was: %s', 'wponlinebackup' ), $ret );

		}

		// Clean up cipher
		$this->_CleanUp_Cipher();

		// Completed!
		$job['progress'] = 100;
		$progress['rcount']++;
		$progress['rsize'] += $job['done_bytes'];

		$bootstrap->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			__( 'Decryption completed successfully.', 'wponlinebackup' )
		);

		// Force update so no duplicate events
		$bootstrap->Tick( false, true );

		return true;
	}

	/*public*/ function Save()
	{
		if ( $this->hash_ctx !== false && $this->job !== false && $this->job['hash_len'] != 0 ) {

			$copy_ctx = hash_copy( $this->hash_ctx );
			list ( $crc ) = array_values( unpack( 'N', hash_final( $copy_ctx, true ) ) );

			// Save it in the job
			$this->job['saved_hash_ctx'] = $crc;

		}
	}

	/*public*/ function CleanUp( $ticking = false )
	{
		if ( $this->hash_ctx !== false ) {
			hash_final( $this->hash_ctx );
			$this->hash_ctx = false;
		}

		$this->_CleanUp_Cipher();
	}

	/*private*/ function _CleanUp_Cipher()
	{
		if ( $this->file !== false ) {
			@fclose( $this->file );
			$this->file = false;
		}

		if ( $this->cipher !== false ) {

			if ( $this->cipher_init )
				@mcrypt_generic_deinit( $cipher );

			@mcrypt_module_close( $cipher );

			$this->cipher = false;
			$this->cipher_init = false;

		}
	}

	/*private*/ function _Read_Header()
	{
		// Read the encryption header
		//V1, 28 bytes
		//	CHAR[6]		'OBFWEN'	// Signature, always "OBFWEN"
		//	WORD		$version	// Encryption version
		//	WORD		0		// Reserved
		//	CHAR[2]		$pass_auth	// Password authentication value
		//	DWORD		$iv_size	// Length of IV
		//	DWORD		$len		// Length of data
		//	DWORD		$crc		// CRC32 of unencrypted data
		//	DWORD		0		// Reserved (for HMAC-SHA256 or HMAC-SHA1 incremental algorithm result)

		// Try to read 1024 bytes - we'll search for the header in this range
		// This allows us to skip the rejection header if someone downloaded the file via FTP
		// (which is supposed to be unsupported! but people do as people do -.- and we can only try and help them since to be fair, until we finish wponlinebackup-ftp branch it'll stay a problem)
		if ( false === ( $header = @fread( $this->file, 1024 ) ) )
			return OBFW_Exception();

		if ( strlen( $header ) < 6 )
			return 'Partially read ' . strlen( $header ) . ' of 6 bytes from encrypted data file to find the encryption header.';

		// Find the header
		if ( false === ( $p = strpos( $header, 'OBFWEN' ) ) ) {

			// Legacy decryption - we didn't have a header previously
			$this->job['legacy'] = true;

			// Seek back to the beginning of the file so legacy can start over
			if ( 0 != @fseek( $this->file, 0, SEEK_SET ) )
				return OBFW_Exception();

			// The head in legacy encrypted backups is encrypted, so we need to setup the decryption cipher before we can read it - this is why the read header bits for legacy are within the load decryption cipher call
			// This call, due to the legacy flag, will pass through to _Legacy_Load_Decryption_Cipher() which will setup the cipher and then validate the header
			// We still call this main one for tidyness so it matches the below call
			if ( false === ( $ret = $this->_Load_Decryption_Cipher() ) )
				return false;

			return $ret;

		}

		// Return to beginning of header
		if ( @fseek( $this->file, $p, SEEK_SET ) != 0 )
			return OBFW_Exception();

		// Validate what we read - NOTE: the 28 is the header size and header_bytes is set to $p (the header offset) + 28 + iv_size
		if ( false === ( $header = @fread( $this->file, 28 ) ) )
			return OBFW_Exception();

		if ( strlen( $header ) != 28 )
			return 'Partially read ' . strlen( $header ) . ' of 28 bytes from encrypted data file for the encryption header.';

		$header = unpack(
			'a6signature/' .
				'vversion/' .
				'vreserved1/' .
				'C2pass_auth/' .
				'Viv_size/' .
				'Vlen/' .
				'Vcrc/' .
				'Vreserved2',
			$header
		);

		// No need to check signature, we read from the point in the file where we find the OBFWEN signature, so it's implied to be correct
		// If we didn't find OBFWEN signature we'd have sent off into legacy decryption

		if ( $header['version'] < 1 || $header['version'] > 4 ) {
			return 'Unknown version ' . $header['version'] . ' of encrypted data file.';
		}

		// Grab the IV
		if ( false === ( $header['iv'] = @fread( $this->file, $header['iv_size'] ) ) )
			return OBFW_Exception();

		if ( strlen( $header['iv'] ) != $header['iv_size'] )
			return 'Partially read ' . strlen( $header['iv'] ) . ' of ' . $header['iv_size'] . ' bytes from encrypted data file for IV.';

		// Set the initial IV - we'll change it each block we process
		$this->job['current_iv'] = $header['iv'];

		// Store the header
		$this->job['header'] = $header;

		// Mark the header as read, so if we resume we skip past it correctly
		$this->job['header_bytes'] = $p + 28 + $header['iv_size']; // This is the header size read from the file above, plus the header offset and the IV length

		// Load decryption cipher and validate the key - this will store the key in the job information so we can use it during chain - we won't validate key again during chain
		if ( false === ( $ret = $this->_Load_Decryption_Cipher() ) )
			return false;

		return $ret;
	}

	/*private*/ function _Get_Cipher( $cipher_spec )
	{
		// Generate the cipher configuration
		switch ( $cipher_spec ) {

			case 'DES':
				$module = MCRYPT_DES;
				$module_str = 'MCRYPT_DES';
				$key_size = 8;
				break;

			default:
			case 'AES128':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 16;
				break;

			case 'AES192':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 24;
				break;

			case 'AES256':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 32;
				break;

		}

		return array( $module, $module_str, $key_size );
	}

	/*private*/ function _Get_Cipher_NonStandard( $cipher_spec )
	{
		// Generate the cipher configuration
		switch ( $cipher_spec ) {

			case 'DES':
				$module = MCRYPT_DES;
				$module_str = 'MCRYPT_DES';
				$key_size = 8;
				break;

			default:
			case 'AES128':
				$module = MCRYPT_RIJNDAEL_128;
				$module_str = 'MCRYPT_RIJNDAEL_128';
				$key_size = 32;
				break;

			case 'AES192':
				$module = MCRYPT_RIJNDAEL_192;
				$module_str = 'MCRYPT_RIJNDAEL_192';
				$key_size = 32;
				break;

			case 'AES256':
				$module = MCRYPT_RIJNDAEL_256;
				$module_str = 'MCRYPT_RIJNDAEL_256';
				$key_size = 32;
				break;

		}

		return array( $module, $module_str, $key_size );
	}

	/*private*/ function _Load_Decryption_Cipher( $validate_key = true )
	{
		// If legacy, switch to legacy mode
		if ( $this->job['legacy'] )
			return $this->_Legacy_Load_Decryption_Cipher( $validate_key );

		// Attempt to open the cipher module
		if ( $this->job['header']['version'] >= 3 )
			list ( $module, $module_str, $key_size ) = $this->_Get_Cipher( $this->progress['config']['enc_type'] );
		else
			list ( $module, $module_str, $key_size ) = $this->_Get_Cipher_NonStandard( $this->progress['config']['enc_type'] );

		if ( false === ( $this->cipher = @mcrypt_module_open( $module, '', MCRYPT_MODE_CBC, '' ) ) )
			return 'Failed to open encryption module: ' . OBFW_Exception();

		if ( $validate_key ) {

			// Get the IV size
			$iv_size = mcrypt_enc_get_iv_size( $this->cipher );

			// Check header IV size - if incorrect it normally means wrong encryption type selected
			if ( $iv_size != $this->job['header']['iv_size'] )
				return false;

			$extra = 0;

			// Generate the encryption key and password authentication value - allow $extra parameter to use a different section of the key
			$dk = WPOnlineBackup_Functions::PBKDF2( $this->progress['config']['enc_key'], $this->job['header']['iv'], 1148, $key_size * ( 2 + $extra ) + 2 );
			$this->job['key'] = substr( $dk, ( $extra ? $key_size * ( 1 + $extra ) + 2 : 0 ), $key_size );
			$pass_auth = substr( $dk, $key_size * 2, 2 );
			$check_pass_auth = chr( $this->job['header']['pass_auth1'] ) . chr( $this->job['header']['pass_auth2'] );

			// While - so we can jump out
			while ( $pass_auth != $check_pass_auth ) {

				// Try the broken PBKDF2 call if this is a version 1 file
				if ( $this->job['header']['version'] == 1 ) {

					$dk = WPOnlineBackup_Functions::PBKDF2_Broken( $this->progress['config']['enc_key'], $this->job['header']['iv'], 1148, $key_size * ( 2 + $extra ) + 2 );
					$this->job['key'] = substr( $dk, ( $extra ? $key_size * ( 1 + $extra ) + 2 : 0 ), $key_size );
					$pass_auth = substr( $dk, $key_size * 2, 2 );

					if ( $pass_auth == $check_pass_auth )
						break;

				}

				// Password authentication didn't match
				return false;

			}

		}

		// Now initialise the cipher so we can start decrypting. Returns -2/-3 on errors, false on incorrect parameters
		if ( false === ( $ret = @mcrypt_generic_init( $this->cipher, $this->job['key'], $this->job['current_iv'] ) ) || $ret < 0 )
			return 'Failed to initialise encryption. PHP: ' . OBFW_Exception();

		// Flag the cipher as initialised so we deinit it
		$this->cipher_init = true;

		return true;
	}

	/*private*/ function _Decryption_Loop()
	{
		// If legacy drop to legacy function
		if ( $this->job['legacy'] )
			return $this->_Legacy_Decryption_Loop();

		// Grab the real block size and adjust the configured block size to ensure it is an exact divisor
		$real_blocksize = mcrypt_enc_get_block_size( $this->cipher );
		$blocksize = $this->WPOnlineBackup->Get_Setting( 'max_block_size' );

		if ( ( $rem = $blocksize % $real_blocksize ) != 0 )
			$blocksize += ( $real_blocksize - $rem );

		// Grab total length of data - increase it to block size and calculate the amount we'll need to trim after decryption
		$len = $this->job['header']['len'];
		if ( ( $rem = $len % $real_blocksize ) != 0 )
			$len += ( $trim = $real_blocksize - $rem );
		else
			$trim = 0;

		// Take off what we've already done
		$len -= $this->job['done_bytes'];

		// Decrypt loop - if we've already done the last block break out
		while ( $len - $trim > 0 ) {

			$block = min( $blocksize, $len );

			if ( ( $data = @fread( $this->file, $block ) ) === false )
				return OBFW_Exception();

			if ( strlen( $data ) != $block ) {
				return 'Partially read ' . strlen( $data ) . ' of ' . $block . ' bytes from encrypted data file for decryption.';
			}

			// Change the IV for the next block to the encrypted data of the last block we're about to decrypt
			$this->job['current_iv'] = substr( $data, $block - $real_blocksize, $real_blocksize );

			$data = mdecrypt_generic( $this->cipher, $data );

			if ( ( $len -= $block ) <= 0 ) {

				if ( $trim != 0 )
					$data = substr( $data, 0, $trim * -1 );

			}

			$block = strlen( $data );

			if ( true !== ( $ret = $this->stream->Write( $data ) ) )
				return 'Write to stream failed. ' . $ret;

			if ( $this->hash_ctx !== false ) {
				hash_update( $this->hash_ctx, $data );
				$this->job['hash_len'] += $block;
			} else if ( $this->job['crc'] !== false ) {
				$this->job['crc'] = WPOnlineBackup_Functions::Combine_CRC32( $this->job['crc'], crc32( $data ), $block );
			} else {
				$this->job['crc'] = crc32( $data );
			}

			$this->job['done_bytes'] += $block;

			// Update the progress
			if ( $this->job['done_bytes'] >= $this->job['header']['len'] ) {
				$this->job['progress'] = 99;
			} else {
				$this->job['progress'] = 10 + floor( ( $this->job['done_bytes'] * 89 ) / $this->job['header']['len'] );
				if ( $this->job['progress'] > 99 )
					$this->job['progress'] = 99;
			}

			$this->bootstrap->Tick();

		}

		if ( $this->hash_ctx !== false && $this->job['hash_len'] > 0 ) {

			list ( $crc ) = array_values( unpack( 'N', hash_final( $this->hash_ctx, true ) ) );

			if ( $this->job['crc'] !== false )
				$this->job['crc'] = WPOnlineBackup_Functions::Combine_CRC32( $this->job['crc'], $crc, $this->job['hash_len'] );
			else
				$this->job['crc'] = $crc;

			$this->hash_ctx = false;

		}

		if ( $this->job['crc'] != $this->job['header']['crc'] )
			return false;

		$this->bootstrap->Log_Event(
			WPONLINEBACKUP_EVENT_INFORMATION,
			'File integrity check was successful.'
		);

		// Prevent duplicated messages
		$this->bootstrap->Tick( false, true );

		return true;
	}

	/* LEGACY DECRYPTION FUNCTIONS START HERE */
	/*
		These allow decryption of the encrypted backup files created by v1 of the plugin which simply created a compressed .gz file containing the database, or just a .sql file.
		They are due to be removed at any point in the future.
		The _Read_Header function will detect legacy backups and flag up legacy mode.
		While legacy mode is enabled the latest functions above simply drop to these functions immediately when called, since the overall steps involved are very similiar.
		This will simplify code removal in future when we drop support for this capability: delete these functions, remove _Read_Header legacy detection, remove the drop calls.
	*/

	/*private*/ function _Legacy_Load_Decryption_Cipher( $validate_key = true )
	{
		// Decrypt a backup file generated by version 1 of the plugin - NonStandard was caused by v2 and v1 was fine, so we use the standard one
		list ( $module, $module_str, $key_size ) = $this->_Get_Cipher( $this->progress['config']['enc_type'] );

		// Open the encryption module
		if ( false === ( $this->cipher = @mcrypt_module_open( $module, '', MCRYPT_MODE_CBC, '' ) ) )
			return 'Failed to open encryption module: ' . OBFW_Exception();

		// Only do key expansion and header decryption etc if we're validating the key
		if ( $validate_key ) {

			// Expand the key to the required length
			if ( ( $key_len = strlen( $this->progress['config']['enc_key'] ) ) < $key_size )
				$this->job['key'] = substr( str_repeat( $this->progress['config']['enc_key'], ( ( $key_size - ( $key_size % $key_len ) ) / $key_len ) + ( $key_size % $key_len == 0 ? 0 : 1 ) ), 0, $key_size );
			else
				$this->job['key'] = substr( $this->progress['config']['enc_key'], 0, $key_size );

			// Generate IV based on key
			$iv_size = mcrypt_enc_get_iv_size( $this->cipher );

			$this->job['current_iv'] = sha1( $this->job['key'] );
			if ( ( $iv_len = strlen( $this->job['current_iv'] ) ) < $iv_size )
				$this->job['current_iv'] = substr( str_repeat( $this->job['current_iv'], ( ( $iv_size - ( $iv_size % $iv_len ) ) / $iv_len ) + ( $iv_size % $iv_len == 0 ? 0 : 1 ) ), 0, $iv_size );
			else
				$this->job['current_iv'] = substr( $this->job['current_iv'], 0, $iv_size );

			// We need to call this now since to validate key we need to attempt to decrypt the header. Returns -2/-3 on errors, false on incorrect parameters
			if ( false === ( $ret = @mcrypt_generic_init( $this->cipher, $this->job['key'], $this->job['current_iv'] ) ) || $ret < 0 )
				return 'Failed to initialise encryption. PHP: ' . OBFW_Exception();

			// Flag the cipher as initialised so we deinit it
			$this->cipher_init = true;

			// Read validation header size
			if ( false === ( $data_len = @fread( $this->file, 4 ) ) )
				return OBFW_Exception();

			if ( strlen( $data_len ) < 4 )
				return 'Partially read ' . strlen( $data_len ) . ' of 4 bytes from encrypted data file to read the validation header segment size.';

			list ( $len ) = array_values( unpack( 'Nlen', $data_len ) );

			// This size should really be no more than ENCRYPTION_SEGMENT_SIZE in V1 plugin which was 1048576. We shall ensure it is not above 8MB in case people adjusted it
			if ( $len > 1048576*8 )
				return 'The validation header segment size appears to be corrupt.';

			// Read the validation header
			if ( false === ( $data = @fread( $this->file, $len ) ) )
				return OBFW_Exception();

			if ( strlen( $data ) < $len )
				return 'Partially read ' . strlen( $data ) . ' of ' . $len . ' bytes from encrypted data file to read the validation header.';

			// Decrypt the validation header
			$validate = mdecrypt_generic( $this->cipher, $data );

			if ( strlen( $validate ) >= 9 && substr( $validate, 0, 9 ) === "\x01\x01ISVALID" ) {

				// OK, start full decryption
				$this->job['header'] = array(
					'version'	=> 1,
					'len'		=> false,
				);

			} else if ( strlen( $validate ) >= 10 && substr( $validate, 0, 4 ) === "OBFW" ) {

				$unpack = unpack( 'nversion', substr( $validate, 4, 2 ) );

				// This was the slightly improved encryption - check the version
				if ( $unpack['version'] == 2 ) {

					// Version 2 stored the full size of the encrypted data in the header so we could trim the encryption padding correctly
					$unpack = array_merge( $unpack, unpack( 'Nlen', substr( $validate, 6, 4 ) ) );

				} else {

					// Unknown version
					return 'Unknown version ' . $unpack['version'] . ' of legacy encrypted data file.';

				}

				// Store the header info
				$this->job['header'] = $unpack;

			} else {

				// This is a smart check to see if the file is actually a ZIP file and doesn't need decryption, so we can tell the user this
				// Users do strange things - they have a usable .ZIP but for some reason believe they need to use the decryption function (understandably they assume the decryption function does a restore, alas)
				// They then find the file won't decrypt because it's ending .ZIP, so they realise it must be .ENC to be decrypted and rename the file, then upload, and try...
				// This message will catch this scenario and give a friendly message back that will hopefully avoid a support call
				if ( false !== strpos( $data_len . $data, "\x50\x4b\x03\x04" ) )
					return 'The file specified is not encrypted. It appears to be a valid unencrypted data file already. Simply rename the file to .zip and use it as you would an unencrypted backup.';

				// Return invalid encryption key
				return false;

			}

			// Add on the size of the header - encrypted data follows this immediately
			$this->job['header_bytes'] = 4 + $len;

		} else {

			// When validating key we already initialise the cipher
			// So if not validating key, initialise the cipher so we can start decrypting. Returns -2/-3 on errors, false on incorrect parameters
			if ( false === ( $ret = @mcrypt_generic_init( $this->cipher, $this->job['key'], $this->job['current_iv'] ) ) || $ret < 0 )
				return 'Failed to initialise encryption. PHP: ' . OBFW_Exception();

			// Flag the cipher as initialised so we deinit it
			$this->cipher_init = true;

		}

		return true;
	}

	/*private*/ function _Legacy_Decryption_Loop()
	{
		$finished = false;

		// Loop and decrypt
		while ( !$finished ) {

			// Grab a block size
			if ( false === ( $data_len = @fread( $this->file, 4 ) ) )
				return OBFW_Exception();

			// Because we read exact amounts, until we TRY to read past the end of the file, feof will not return true yet
			// So although we may have read everything, feof returns false until we try the above read, and we'll get 0 bytes back and feof will then return true
			if ( strlen( $data_len ) == 0 && @feof( $this->file ) )
				break;

			if ( strlen( $data_len ) < 4 )
				return 'Partially read ' . strlen( $data_len ) . ' of 4 bytes from encrypted data file for a segment header.';

			// Unpack data length
			list ( $len ) = array_values( unpack( 'Nlen', $data_len ) );

			// This size should really be no more than ENCRYPTION_SEGMENT_SIZE in V1 plugin which was 1048576. We shall ensure it is not above 8MB in case people adjusted it
			if ( $len > 1048576*8 )
				return 'A segment header appears to be corrupt.';

			// Read the data
			if ( false === ( $data = @fread( $this->file, $len ) ) )
				return OBFW_Exception();

			if ( strlen( $data ) < $len )
				return 'Partially read ' . strlen( $data ) . ' of ' . $len . ' bytes from encrypted data file.';

			// Decrypt the data
			$data = mdecrypt_generic( $this->cipher, $data );

			// Check if we are done or not
			if ( $this->job['header']['len'] !== false ) {

				$this->job['done_bytes'] += $len;

				if ( $this->job['done_bytes'] > $this->job['header']['len'] ) {
					$data = substr( $data, 0, strlen( $data ) - ( $this->job['done_bytes'] - $this->job['header']['len'] ) );
					$finished = true;
				}

			}

			if ( true !== ( $ret = $this->stream->Write( $data ) ) )
				return 'Write to stream failed. ' . $ret;

			$this->job['done_bytes'] += $block;

			// Update the progress, but only if we have a len (it might be false)
			if ( $this->job['header']['len'] !== false ) {

				if ( $this->job['done_bytes'] >= $this->job['header']['len'] ) {
					$this->job['progress'] = 99;
				} else {
					$this->job['progress'] = 10 + floor( ( $this->job['done_bytes'] * 89 ) / $this->job['header']['len'] );
					if ( $this->job['progress'] > 99 )
						$this->job['progress'] = 99;
				}

			}

			$this->bootstrap->Tick();

		}

		return true;
	}
}

?>

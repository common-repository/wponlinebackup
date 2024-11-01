<?php

/*
WPOnlineBackup_Stream_Direct - Direct data writing to a stream file, used by decryption
Essentially is a wrapper around Disk with standard stream functionality such as Files() / Flush()
*/

class WPOnlineBackup_Stream_Direct
{
	/*private*/ var $WPOnlineBackup;

	/*private*/ var $config;

	/*private*/ var $status;

	/*private*/ var $disk;

	/*public*/ function WPOnlineBackup_Stream_Direct( & $WPOnlineBackup )
	{
		// Store the main object
		$this->WPOnlineBackup = & $WPOnlineBackup;

		// Disk object
		require_once WPONLINEBACKUP_PATH . '/include/disk.php';
	}

	/*public*/ function Save()
	{
		// Return the state
		$state = array(
			'config'		=> $this->config,
			'status'		=> $this->status,
			'disk'			=> $this->disk->Save(),
		);

		return $state;
	}

	/*public*/ function Load( $state, $rotation )
	{
		// Store the config
		$this->config = $state['config'];

		$this->status = $state['status'];

		// Reopen the file
		$this->disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

		if ( true !== ( $ret = $this->disk->Load( $state['disk'], $rotation ) ) )
			return $ret;

		return true;
	}

	/*public*/ function Open( $config )
	{
		// ASSERTION - The file is closed
		$this->config = $config;

		$this->disk = new WPOnlineBackup_Disk( $this->WPOnlineBackup );

		$this->disk->Initialise( $this->config['designated_path'] );

		// Open a disk
		if ( ( $ret = $this->disk->Open( 'decrypted', false ) ) !== true )
			return $ret;

		$this->status = 0;

		return true;
	}

	/*public*/ function Flush()
	{
		return true;
	}

	/*public*/ function Close()
	{
		// Close the disk
		if ( true !== ( $ret = $this->disk->Close() ) )
			return $ret;

		$this->status = 1;

		return true;
	}

	/*public*/ function CleanUp( $wipe = true )
	{
		$this->disk->CleanUp( $wipe );
	}

	/*public*/ function Start_Reconstruct()
	{
		// ASSERTION - Status is 1 - Close() has been called
		return $this->disk->Start_Reconstruct();
	}

	/*public*/ function Do_Reconstruct()
	{
		// ASSERTION - Status is 1 - Close() has been called
		// ASSERTION - Start_Reconstruct has been called
		return $this->disk->Do_Reconstruct();
	}

	/*public*/ function End_Reconstruct()
	{
		// ASSERTION - Status is 1 - Close() has been called
		// ASSERTION - Start_Reconstruct has been called and Do_Reconstruct has returned success
		return $this->disk->End_Reconstruct();
	}

	/*public*/ function Is_Encrypted()
	{
		// Never encrypted
		return 0;
	}

	/*public*/ function Is_Compressed()
	{
		// Depends on whatever is using us
		return $this->config['compressed'];
	}

	/*public*/ function Files()
	{
		// Always 1 since we directly process a single file with this
		return 1;
	}

	/*public*/ function Impose_DataSize_Limit( $size, $message, $ret )
	{
	}

	/*public*/ function Impose_FileSize_Limit( $size, $message, $ret )
	{
	}

	/*public*/ function Write( $data, $length = null )
	{
		return $this->disk->Write( $data, $length );
	}
}

?>

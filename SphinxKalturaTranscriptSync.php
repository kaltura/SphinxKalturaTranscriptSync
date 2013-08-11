<?php 
// Import kaltura library: 
require_once( dirname( __FILE__ ) . '/base.php' );

$mySpixSync = new SphinxKalturaTranscriptSync();
$mySpixSync->run();

// command to generate transcript: 
// Returns JSON with timed transcript

class SphinxKalturaTranscriptSync {
	
	function run(){
		// else parse the request error out if missing anything
		$this->parseRequest();
		// directly return status if jobKey request:
		if( !isset( $this->jobKey ) ){
			$this->addJob();
		}
		// output job status
		$this->outputStatus();
	}
	
	function outputStatus(){
		// read status file: 
		if( is_file( $this->getJobPath() ) ){
			return $this->jsonOut( 
				json_decode( 
					file_get_contents( 
						$this->getJobPath() 
					)
				) 
			);
		}
		$this->errorOut( "Errro no job file present");
	}
	function errorOut( $msg ){
		header('Content-type: application/json');
		echo json_encode( array( 'error' => $msg ) );
		exit();
	}
	function jsonOut( $arry ){
		header('Content-type: application/json');
		echo json_encode( $arry );
		return true;
	}
	
	function addJob(){
		global $gcEnableAddJobs;
		// check if adding jobs is disabled; 
		if( $gcEnableAddJobs === false ){
			$this->errorOut("Adding jobs is disabled");
		}
		
		// check if the job already exists: 
		if( is_file( $this->getJobPath() ) ){
			return ;
		}
		
		// check that we have a free job slot ( only one active job allowed at a time for now )
		if( $this->areJobsActive() ){
			return $this->errorOut( "Other job active ( " . $this->getActivePid(). " ), please wait" );
		}
		// add the file based job
		@file_put_contents($this->getJobPath(), 
			json_encode( 
				array(
					'status' =>  'init',
					'entry_id'=> $this->entry_id,
					'partner_id' => $this->partner_id,
					'jobKey' => $this->getJobKey(),
					'transcript' => $this->transcript
				)
			)
		);
		if( ! is_file( $this->getJobPath() ) ){
			return $this->errorOut( "Could not output job file" );
		}
		// add the job:
		$pid = $this->runInBackground( '/usr/bin/php SphinxKalturaTranscriptJob.php ' . $this->getJobKey() );
		file_put_contents( $this->getPidPath(), $pid );
	}
	function areJobsActive(){
		if( is_file( $this->getPidPath() ) ){
			// check for proccess that is no longer active but still has pid ( time out error? ) 
			if( !$this->isProcessRunning( $this->getActivePid() ) ){
				// remove file but report error:
				$this->errorOut( "job no longer active, possible error?" );
				unlink( $this->getPidPath() );
			}
			return true;
		}
		return false;
	}
	function getActivePid(){
		return file_get_contents( $this->getPidPath() ) ;
	}
	function getPidPath(){
		return dirname( __FILE__ ) . '/jobs/pid';
	}
	function runInBackground( $command, $priority = 0){
		if( $priority ){
			$pid = shell_exec("nohup nice -n $priority $command  > /dev/null &");
		} else {
			$pid= shell_exec("nohup $command > /dev/null &");
		}
		return( $pid );
	}
	function isProcessRunning( $pid ){
		exec( "ps $pid", $processState );
		return ( count( $processState ) >= 2 );
	}
	function parseRequest(){
		// check for jobkey 
		if( isset( $_REQUEST['jobKey' ] ) ){
			if( ! ctype_alnum( str_replace( '_', '', $_REQUEST['jobKey' ] ) ) ){
				return $this->errorOut( "bad jobKey" );
			}
			$this->jobKey = $_REQUEST['jobKey' ];
			return ;
		}
		
		$reqArgs = array( 'partner_id', 'entry_id', 'transcript' );
		foreach( $reqArgs as $arg ){
			if( !isset( $_REQUEST[ $arg ] ) ){
				return $this->errorOut( "missing required param: " . $arg );
			}
			$this->$arg = $_REQUEST[ $arg ];
		}
		// make sure entry_id and wid are ctype_alnum
		if( ! ctype_alnum( str_replace( '_', '', $this->entry_id ) ) ){
			return $this->errorOut( "bad entry_id" + $arg );
		}
		if( ! ctype_alnum( str_replace( '_', '', $this->partner_id ) ) ){
			return $this->errorOut( "bad wid" + $arg );
		}
	}
	function getJobPath(){
		return dirname( __FILE__ ) . '/jobs/' . $this->getJobKey() . '.job';
	}
	function getJobKey(){
		if( isset( $this->jobKey ) ){
			return $this->jobKey;
		}
		return $this->partner_id . '_' . $this->entry_id . '__' . substr( md5( $this->transcript ), 0, 8 );
	}
}
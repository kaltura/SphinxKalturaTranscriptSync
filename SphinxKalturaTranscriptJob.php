<?php 
if( php_sapi_name() != 'cli' ){
	echo "\n jobs should be run on the command line \n";
	exit();
}
require_once( dirname( __FILE__ ) . '/base.php' );

$mySpixJob = new SphinxKalturaTranscriptJob();
$mySpixJob->run();

class SphinxKalturaTranscriptJob{
	function run(){
		if( ! $this->getJobKey() ){
			echo "error job key not found: " . $this->getJobKey() . "\n";
			exit();
		}
		// get job
		$job = $this->getJob();
		if( ! $job ){
			echo "Job not found or missing: " . $this->getJobKey() . " \n";
			exit();
		}
		// Get sources: 
		$this->updateJobStatus( 'getSources');
		$assetUrl = $this->getAssetUrl();
			
		// Download the source
		$this->updateJobStatus( 'downloadAsset' );
		$this->downloadAsset( $assetUrl );
		
		// Get a .wav version of the file
		$this->updateJobStatus( 'getAudio' );
		$this->getAudio();
		
		// Run the transcription:
		$this->updateJobStatus( 'alignment' );
		$job->alignment = $this->getAlignment();
		$job->status = 'done';
		$job->accuracy =  $this->getScore( $job->alignment );
		// Done with job: 
		$this->updateJob( $job );
		
		// remove pid ( job done )
		$pidPath =  dirname( __FILE__ ) . '/jobs/pid';
		if( is_file( $pidPath ) ){
			unlink ( $pidPath );
		}
	}
	function getAlignment(){
		global $gcJavaPath, $gcSphixPath, $gcSphixAlignPath;
		$job = $this->getJob();
		// change into the $gcSphixAlignPath folder:
		chdir( $gcSphixAlignPath );
		// output the transcript to a text file: 
		file_put_contents( $this->getTranscriptPath(), $job->transcript );
		// run the conversion: 
		$textResult = $this->runCmd( $gcJavaPath . 
		' -Dconfig=config/aligner.xml' .
		' -Daudio=' .  $this->getAudioPath() . 
		' -Dtext=' . $this->getTranscriptPath() .
		' -jar ForcedAlignment.jar' );
		
		// Build the known set of word times: 
		$knownWordTime = array();
		$unkownWords = array();
		// parse alinged text, throw out warnings
		foreach( $textResult as $line ){
			if( strpos( $line, 'WARNING dictionary        Missing word:' ) === false 
				&&
				trim( $line ) != ''
			){
				// split wordset
				$wordSet = explode(' ', $line );
				foreach( $wordSet as $wordTime ){
					list( $word, $time ) = explode( '(', $wordTime );
					$time = str_replace( ')', '', $time);
					list( $start, $end ) = explode( ',', $time );
					
					// check for ~long~ words:
					if( floatval( $end )  
							- 
						floatval( $start )
						> 2
					){
						$end = floatval( $start ) + .75;
					}
					$knownWordTime[] = array(
						'w'=> $word,
						's'=> floatval( $start ),
						'e'=> floatval( $end )
					);
					echo $word . " ";
				}
			}
		}
		
		$lookAhead = 40;
		$allWords = explode(' ', $job->transcript );
		$allWordsCombined = $this->syncWords($knownWordTime, $allWords, $lookAhead );
		
		echo "Accuracy score: " . $this->getScore( $allWordsCombined ) . "\n";
		return $allWordsCombined;
	}
	function getScore( $allWordsCombined ){
		$good=0;
		for($i=0;$i < count( $allWordsCombined ); $i++){
			if( isset( $allWordsCombined[$i]['s'] ) && !isset( $allWordsCombined[$i]['est'] ) ){
				$good++;
			}
		}
		return $good / count( $allWordsCombined );
		
	}
	function syncWords( $knownWordTime, $allWords, $lookAhead ){
		echo "\n\n";
		$kwInx =0;
		$allWordsCombined  = array();
		$j=0;
		$missCount = 0;
		$prevKnownWord = array();
		for( $i=0; $i < count( $allWords ); $i++){
			echo preg_replace("/[^A-Za-z]/", '', strtolower( $allWords[$i] ) ) . " ";
		}
		echo "\n\n";
		
		// search for matching words in all set:
		for( $i=0; $i < count( $allWords ); $i++){
			if( isset( $knownWordTime[$j] ) 
					&&
				preg_replace("/[^A-Za-z]/", '', strtolower( $allWords[$i] ) )
					==  
				$knownWordTime[ $j ]['w']
			){
				$allWordsCombined[] = array(
					'w' => $allWords[$i], // retain puntuation and format
					's' => $knownWordTime[ $j ]['s'],
					'e' => $knownWordTime[ $j ]['e'],
				);
				if( $missCount != 0 ){
					echo '] ';
					// set times for all previus unkown time:
					if( isset( $prevKnownWord['e'] ) ){
						/*echo "\n Current word:" . $knownWordTime[ $j ]['w'] . 
						' s:' . $knownWordTime[ $j ]['s'] . ' e:' . $knownWordTime[ $j ]['e'] . "\n";
						*/
						//echo " Previus Known word : " . print_r( $prevKnownWord, true ). "\n";
						$timeSpan = $knownWordTime[ $j ]['s'] - $prevKnownWord['e'];
						$lastEnd =  $prevKnownWord['e'];
						// see if we jumped
						if( $timeSpan == 0 && isset( $knownWordTime[ $j-2 ] ) ){
							//echo 'likey jump, use "' .  print_r( $knownWordTime[ $j-2 ], true ). "\n";
							$timeSpan = $knownWordTime[ $j ]['s'] - $knownWordTime[ $j-2 ]['e'];
							$lastEnd = $knownWordTime[ $j-2 ]['e'];
						}
						
						$blockSize = $timeSpan / $missCount;
						//echo "\nTime Span for " . $missCount . " words is " . $timeSpan . "\n";
						for( $k=0; $k < $missCount; $k++ ){
							$inx = $i - $missCount+$k;
							$allWordsCombined[ $inx ][ 's' ] = $lastEnd + ( $k * $blockSize );
							$allWordsCombined[ $inx ][ 'e' ] = $lastEnd + ( ( $k+1 ) * $blockSize );
							$allWordsCombined[ $inx ][ 'est'] = 1; // note that the words is an est
							/*echo 'update time for: ' . trim( $allWordsCombined[ $inx ]['w'] ) . 
								' s: ' . $allWordsCombined[ $inx ][ 's' ] . 
								' e: ' . $allWordsCombined[ $inx ][ 'e' ] . "\n";
							*/
						}
					}
				}
				$missCount = 0;
				echo $knownWordTime[ $j ]['w'] . " ";
				// set times for all previus unkown time
				$prevKnownWord = $knownWordTime[ $j ];
				// increment known word
				$j++;
			} else {
				// if a miss and the word is 3 words or less skip
				if( isset( $knownWordTime[ $j ] ) 
						&& 
					strlen( $knownWordTime[ $j ]['w'] ) <= 3 
						&&  
					isset( $knownWordTime[ $j+1 ] )
				){
						$j++;
						$i--;
					continue;
				}
				// register end miss count
				if( $missCount == 0 && isset( $knownWordTime[ $j ] ) ){
					echo " [" . $knownWordTime[ $j ]['w'] . ' != ' . 
						preg_replace("/[^A-Za-z]/", '', strtolower( $allWords[$i] ) );
				}
				// hit miss max rewind and look for next $j
				if( $missCount == $lookAhead && isset( $knownWordTime[ $j ] ) ){
					echo '..' . preg_replace("/[^A-Za-z]/", '', strtolower( $allWords[$i] ) ) . "] ";
					echo ' -' . $knownWordTime[$j]['w'] . '- ';
					// skip
					$j++;
					$i = $i-$lookAhead; // rewind to last known word 
					$missCount = 0;
					$allWordsCombined = array_slice ( $allWordsCombined, 0, $i);
					continue;
				}
				$missCount++;
				
				$allWordsCombined[] = array(
					'w'=> $allWords[$i]
				);
			}
		}
		echo "\n\n";
		// make sure the allWords is in order: 
		uasort( $allWordsCombined, array('self', 'wordSort') );
		
		// go through all words and biuld $allWordsCombined array 
		return $allWordsCombined;
	}
	function wordSort( $a, $b ){
		if( !isset( $a['s'] ) || !isset($b['s']) ){
			return 0;
		}
		return ( $a['s'] < $b['s'] ) ? -1 : 1;
	}
	function getTranscriptPath(){
		return dirname( __FILE__ ) . '/jobs/' . $this->getJobKey() . '.txt';
	}
	function getAudio(){
		// check audio has already been converted
		if( is_file( $this->getAudioPath() ) ){
			echo "   Wav file already present: " . $this->getAudioPath();
			return ;
		}
		$result = $this->runCmd('/usr/bin/ffmpeg -i ' . $this->getAssetPath() . 
		' -acodec pcm_s16le -ac 1 '. $this->getAudioPath() );
	}
	function runCmd( $cmd ){
		echo "run command: \n" . $cmd . "\n\n";
		exec( $cmd . ' 2>&1', $output, $return);
		return $output;
	}
	function getAudioPath(){
		return dirname( __FILE__ ) . '/jobs/' . $this->getAssetKey() . '.wav';
	}
	function downloadAsset( $assetUrl ){
		echo "Download: $assetUrl . \n";
		// check if the asset has already been downloaded: 
		if( is_file( $this->getAssetPath() ) ){
			echo "  Asset already present: " . $this->getAssetPath() . "\n";
			return ;
		}
		$this->runCmd( 'wget -O ' . $this->getAssetPath() . ' ' . $assetUrl );
		// check if file is less then 4k:
		if( filesize( $this->getAssetPath() ) < 1024 * 4  ){
			$this->jobError( "Download failed file is less then 4k" );
		}
		echo "File done downloading size: ". $this->hrFilesize( filesize( $this->getAssetPath() ) ) . "\n";
	}
	function hrFilesize($bytes, $decimals = 2) {
		$sz = 'BKMGTP';
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor];
	}
	function getAssetPath(){
		$ext = isset( $this->fileExt ) ? $this->fileExt : 'mp4';
		return dirname( __FILE__ ) . '/jobs/' . $this->getAssetKey() . '.' . $ext;
	}
	function getAssetUrl(){
		$job = $this->getJob();
		
		$config = new KalturaConfiguration( $job->partner_id );
		$config->serviceUrl = 'http://www.kaltura.com/';
		$client = new KalturaClient($config);
		$filter = new KalturaAssetFilter();
		$filter->entryIdEqual = $job->entry_id;
		$pager = null;
		$result = $client->flavorAsset->listAction($filter, $pager);
		// get the source, if we had an audio flavor that would be ideal.
		$targetFlavor = null;
		if( !isset(  $result->objects ) ){
			$this->jobError( 'Error in getting flavors from kaltura' );
		}
		// get high bitrate:
		$maxBit = 0;
		foreach( $result->objects as $flavor ){
			if( $flavor->bitrate > $maxBit && 
				$flavor->isOriginal != 1 && 
				$flavor->fileExt != 'ogg'
			){
				$targetFlavor = $flavor;
			}
		}
		// See if there is an audio flavor ( use that over the original )
		foreach( $result->objects as $flavor ){
			if( $flavor->width == 0 && $flavor->height == 0 && $flavor->size != 0 ){
				$targetFlavor = $flavor;
			}
		}
		if( ! $targetFlavor ){
			$this->jobError( 'Error in finding flavor for transcode');
		}
		$this->fileExt = $targetFlavor->fileExt;
		$flavorUrl =  $config->serviceUrl .'p/' . $job->partner_id . '/sp/' .
			$job->partner_id . '00/playManifest/entryId/' . $job->entry_id . 
			'/flavorId/' . $flavor->id . '/format/url/protocol/http' .
			'/a.' . $this->fileExt;
		return $flavorUrl;
	}
	function jobError( $errorMsg ){
		echo "Error: " . $errorMsg . "\n";
		$job = $this->getJob();
		$job->error = $errorMsg;
		$this->updateJob( $job );
		exit();
	}
	function updateJobStatus( $status ){
		echo "Update job status: " . $status . "\n";
		$job = $this->getJob();
		$job->status = $status;
		$this->updateJob( $job );
	}
	function updateJob( $job ){
		file_put_contents( $this->getJobPath(), json_encode( $job ) );
	}
	function getJob(){
		if( is_file( $this->getJobPath() ) ){
			return json_decode( file_get_contents( $this->getJobPath() ) );
		}
	}
	function getJobPath(){
		return dirname( __FILE__ ) . '/jobs/' . $this->getJobKey() . '.job';
	}
	function getAssetKey(){
		list( $assetKey, $na ) = explode( '__', $this->getJobKey() );
		return $assetKey;
	}
	function getJobKey(){
		global $argv;
		return isset( $argv[1] )? $argv[1] : null;
	}
}
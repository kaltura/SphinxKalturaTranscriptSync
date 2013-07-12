<?php 
// Import kaltura library: 
require_once( dirname( __FILE__ ) . '/KalturaClientLib/KalturaClient.php');

// Java bin path settings:
$gcJavaPath = 'java';

// ffmpeg path
$gcFFMPEGPath = '/usr/bin/ffmpeg';

// Sphix alignment path 
$gcSphixAlignPath = '/path/to/sphix/alignment/folder';


// check for local settings overrides:
if( is_file(  dirname( __FILE__ ) . '/settings.php' ) ){
	require_once(  dirname( __FILE__ ) . '/settings.php' );
}
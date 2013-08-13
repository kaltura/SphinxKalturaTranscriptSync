SphixKalturaTranscriptSync
==========================

Supply a Kaltura entry and transcript, get back synced transcript data with sphix magic. 

Summary of Components 
==========================

SphinxKalturaTranscriptSync.php -- Handles the web based requests for job status, and dispatches initial job if resources are available. 

SphinxKalturaTranscriptJob.php -- Handles the actual job tasks. Tasks include: 

'getSources' -- grabs sources from the Kaltura api based on supplied partner and entry id
'downloadAsset' -- downloads source asset from the Kaltura entry
'getAudio' -- extracts the audio file from the video in a format that sphix can handle. 
'alignment' -- runs the alignment.xml against the sphinx speech to text engine.

'done' -- set to done state once everything is complete. 

base.php -- Defines settings, loads settings.php in the same dir afterward

// Java bin path settings:
$gcJavaPath = 'java';

// ffmpeg path
$gcFFMPEGPath = '/usr/bin/ffmpeg';

// Sphix alignment path 
$gcSphixAlignPath = '/path/to/sphix/alignment/folder';

// If we allow adding jobs: 
$gcEnableAddJobs = true; 

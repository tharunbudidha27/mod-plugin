<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']        = 'FastPix Video';
$string['modulename']        = 'FastPix Video';
$string['modulenameplural']  = 'FastPix Videos';
$string['modulename_help']   = 'The FastPix Video activity lets teachers add a video for students to watch, with watch tracking, completion thresholds, and gradebook integration.';

$string['pluginadministration'] = 'FastPix Video administration';

$string['activityname']      = 'Activity name';

$string['fastpix:addinstance']      = 'Add a new FastPix Video activity';
$string['fastpix:view']             = 'View a FastPix Video and have completion tracked';
$string['fastpix:viewallattempts']  = 'View watch attempts for all students';
$string['fastpix:graderoverride']   = 'Override grades for FastPix Video attempts';
$string['fastpix:uploadmedia']      = 'Upload videos to FastPix from the activity edit form';

// Privacy — Phase C interim (null_provider). Real per-column metadata lands in Phase E.
$string['privacy:metadata:null_phase_e'] = 'Detailed privacy metadata will be declared in Phase E. The activity stores per-user watch progress and attempt data; no data is exported, modified, or deleted by mod_fastpix in v1.0 Phase C.';

// Phase B — video source fieldset.
$string['videosource']         = 'Video source';
$string['sourcetype']          = 'Source type';
$string['sourcetype_upload']   = 'Direct upload';
$string['sourcetype_urlpull']  = 'URL pull';

// Phase B — playback options fieldset.
$string['playbackoptions']     = 'Playback options';
$string['noskip']              = 'Disable seeking';
$string['noskip_desc']         = 'Prevent students from skipping ahead during playback.';
$string['noskip_help']         = 'When enabled, the player blocks forward seeks. Backward seeks are still allowed.';
$string['autocaptions']        = 'Show captions by default';
$string['autocaptions_desc']   = 'Captions will be displayed when the video starts.';
$string['autocaptions_help']   = 'When the asset has captions, they will be enabled on first play. Students can still toggle captions in the player.';

// Phase B — custom completion rule.
$string['completionwatchedpercent']        = 'Require watched percentage';
$string['completionwatchedpercent_group']  = 'Watched percentage';

// Phase B — validation errors.
$string['error_uploadrequired']   = 'You must upload a video before saving.';
$string['error_urlrequired']      = 'A source URL is required for URL pull.';
$string['error_urlnotvalidated']  = 'Click "Validate URL" before saving.';
$string['error_thresholdrange']   = 'Watched percentage must be between 1 and 100.';
$string['error_assetswapblocked'] = 'Students have already started watching this video, so it cannot be replaced. To use a different video, create a new activity instead — this preserves their progress.';

// Phase C — view, processing, and error states.
$string['processing_message']        = 'Your video is being prepared. This page will refresh automatically when it is ready.';
$string['processing_progress_aria']  = 'Video preparation in progress';
$string['processing_max_polls']      = 'Still preparing. Please refresh the page in a few minutes.';
$string['error_videounavailable']    = 'This video is currently unavailable.';
$string['error_drm_unsupported']     = 'Your browser cannot play this protected video. Please try a different browser.';
$string['error_capability_lost']     = 'You no longer have permission to view this video.';

// Phase C — refresh-endpoint failure modes (distinct from capability_lost so
// the AMD watch_tracker / player can take different recovery paths in Phase D).
$string['error_session_no_attempt']  = 'No active watch session was found for this activity.';
$string['error_session_finalised']   = 'Your watch session for this activity has already been completed.';
$string['error_session_invalid']     = 'The watch session token is invalid or expired.';

// Phase C — events.
$string['eventactivityviewed']  = 'FastPix Video viewed';

// UI — upload widget.
$string['upload_progress_label']    = 'Uploading your video…';
$string['upload_progress_aria']     = 'Upload progress';

// UI — processing state.
$string['processing_title']         = 'Preparing your video';

// Activity edit form — Video source section.
$string['videosource_intro']        = 'Upload a video from your device or pull from a public URL. Either method goes directly to FastPix — Moodle never stores the video bytes.';
$string['upload_droparea_title']    = 'Drag a video here, or click to browse';
$string['upload_droparea_meta']     = 'Most video formats supported · up to 5 GB · upload starts automatically';
$string['upload_supported_formats'] = 'Supported: MP4, MOV, WebM, MKV, AVI, M4V, MPEG, 3GP, FLV, OGV and more.';

// Activity edit form — URL pull card.
$string['urlpull_card_title']       = 'Public video URL';
$string['urlpull_card_help']        = 'FastPix will fetch the video directly from this URL. Must be publicly accessible — no authentication, no signed URLs.';
$string['urlpull_placeholder']      = 'https://example.com/lecture.mp4';
$string['urlpull_start_ingest']     = 'Start ingest';
$string['urlpull_supported_meta']   = 'Most video formats supported · up to 5 GB · no YouTube URLs · Dropbox links must end in {$a}';
$string['urlpull_ssrf_meta']        = 'URLs pointing to private IP ranges or internal hostnames are rejected automatically.';

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

// Privacy — Phase E provider (rule S10). One declared table: fastpix_attempt.
$string['privacy:metadata:fastpix_attempt']                   = 'Per-user watch progress and attempt state for FastPix Video activities. Stores the watched-intervals geometry that drives completion, the last playback position used for resume, and the session token that authenticates progress callbacks. Deleting an attempt clears the user\'s progress and resets their completion state.';
$string['privacy:metadata:fastpix_attempt:userid']            = 'The user the attempt belongs to.';
$string['privacy:metadata:fastpix_attempt:activity_id']       = 'The FastPix Video activity the attempt is for.';
$string['privacy:metadata:fastpix_attempt:asset_id']          = 'Reference to the FastPix asset (local_fastpix) snapshotted at session start.';
$string['privacy:metadata:fastpix_attempt:watched_intervals'] = 'JSON-encoded list of watched [start,end] ranges, used to compute coverage for completion.';
$string['privacy:metadata:fastpix_attempt:current_position']  = 'Last playback position in seconds, used to resume the video on the next view.';
$string['privacy:metadata:fastpix_attempt:has_completed']     = 'Sticky flag: 1 once the user has met the completion threshold at least once.';
$string['privacy:metadata:fastpix_attempt:seek_count']        = 'Number of seek events reported by the player during the session.';
$string['privacy:metadata:fastpix_attempt:fraud_count']       = 'Number of fraud-check violations recorded across this attempt.';
$string['privacy:metadata:fastpix_attempt:last_fraud_reason'] = 'Typed reason for the most recent fraud-check violation.';
$string['privacy:metadata:fastpix_attempt:session_token']     = 'HMAC-bound session token used to authenticate watch-progress callbacks. Redacted from data exports.';
$string['privacy:metadata:fastpix_attempt:session_start_ts']  = 'Unix timestamp marking the start of the current session window.';
$string['privacy:metadata:fastpix_attempt:last_callback_ts']  = 'Unix timestamp of the most recent watch-progress callback accepted by the server.';
$string['privacy:metadata:fastpix_attempt:completion_state']  = 'Server-side completion state for the attempt (in_progress or complete).';
$string['privacy:metadata:fastpix_attempt:milestones']        = 'Timestamps at which the user crossed the 25%, 50%, 75%, and 100% coverage milestones.';
$string['privacy:path:attempt']                               = 'Watch attempt';

// Phase B — video source fieldset.
$string['videosource']         = 'Video source';

// Phase B — playback options fieldset.
$string['playbackoptions']            = 'Playback options';
$string['playbackoptions_card_title'] = 'Player behaviour';
$string['playbackoptions_intro']      = 'Control how learners interact with the video during playback. These settings apply to this activity only.';
$string['noskip']              = 'Disable seeking';
$string['noskip_desc']         = 'Prevent students from skipping ahead during playback.';
$string['noskip_help']         = 'When enabled, the player blocks forward seeks. Backward seeks are still allowed.';
$string['autocaptions']        = 'Show captions by default';
$string['autocaptions_desc']   = 'Captions will be displayed when the video starts.';
$string['autocaptions_help']   = 'When the asset has captions, they will be enabled on first play. Students can still toggle captions in the player.';

// Phase B — custom completion rule.
$string['completionwatchedpercent']        = 'Require watched percentage';
$string['completionwatchedpercent_desc']   = 'Student must watch at least {$a}% of the video';
$string['completionwatchedpercent_group']  = 'Watched percentage';
$string['completionwatchedpercentenabled'] = 'Watched percentage';
$string['gradeitem']                       = 'FastPix Video grade';

// Phase B — validation errors.
$string['error_uploadrequired']   = 'You must upload a video before saving.';
$string['error_urlrequired']      = 'A source URL is required for URL pull.';
$string['error_urlnotvalidated']  = 'Click the Upload button to submit the URL before saving.';
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

// UI — upload progress (rendered during in-flight upload).
$string['upload_in_progress']       = 'Uploading your video…';

// UI — processing state.
$string['processing_title']         = 'Preparing your video';

// Activity edit form — unified Video source panel.
$string['videosource_intro']               = 'Upload a video from your device or pull from a public URL. Either method goes directly to FastPix — Moodle never stores the video bytes.';
$string['videosource_dropzone_text_before'] = 'Drag & drop video and audio or';
$string['videosource_dropzone_browse']     = 'Browse';
$string['videosource_dropzone_meta']       = 'You can upload multiple files at once.';
$string['videosource_urlpull_label']       = 'Or upload using video URL';
$string['videosource_urlpull_placeholder'] = 'Paste your video URL here…';
$string['videosource_urlpull_button']      = 'Upload';

// Phase D Slice A — visible progress strip below the player.
$string['watch_status_in_progress'] = 'Watched ';
$string['watch_status_complete']    = '✓ Completed · ';
$string['watch_threshold_hint']     = 'Need {$a}% to complete';

// Phase D Slice A Step 3 — fraud-check user-facing reasons + milestone event.
$string['error_fraud_exceeds_duration']   = 'Watch time exceeds video length.';
$string['error_fraud_exceeds_wall_clock'] = 'Watch time exceeds session duration.';
$string['error_fraud_regression']         = 'Watch time regressed (impossible).';
$string['error_fraud_implausible_gain']   = 'Watch time gain exceeds elapsed time.';
$string['error_fraud_capability_lost']    = 'You no longer have permission to watch.';
$string['error_fraud_seek_on_noskip']     = 'Seeking is disabled for this activity.';
$string['event_watch_milestone']          = 'Watch milestone reached';

// Segmented coverage indicator — pill labels.
$string['status_processing']               = 'Processing';
$string['status_complete']                 = 'Complete';

// Coverage bar legend strings.
$string['coverage_legend_watched']         = 'Watched';
$string['coverage_legend_needed']          = 'Needed to complete';
$string['coverage_legend_bonus']           = 'Bonus';

<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Hook function to inject chatbot into course pages
 */
function local_chatbot_before_footer() {
    global $PAGE, $COURSE;

    // Only load on course pages (not on site home or other pages)
    if ($PAGE->course->id == SITEID) {
        return;
    }

    // Check if user has upload capability in this course context
    $context = context_course::instance($COURSE->id);
    $canUpload = has_capability('local/chatbot:upload', $context);

    $backend_url = \local_chatbot\config::get_backend_url();

    // Load JavaScript module and initialize (CSS is auto-loaded from styles.css)
    $PAGE->requires->js_call_amd('local_chatbot/chatbot', 'init', [
        $COURSE->id,
        $canUpload,
        $backend_url
    ]);
}

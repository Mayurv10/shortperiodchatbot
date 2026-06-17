<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for local_chatbot.
 */
class observer
{

    /**
     * Triggered when a course module is created or updated.
     * Starts the sync task immediately.
     * 
     * @param \core\event\base $event
     */
    public static function sync_on_change($event)
    {
        // Fix #7: Only queue the adhoc task. Running sync_files() synchronously
        // here blocks the Moodle web request and scans all PDFs on every edit.
        // The adhoc task runs via cron and is sufficient for near-real-time sync.
        $task = new \local_chatbot\task\sync_adhoc_task();
        \core\task\manager::queue_adhoc_task($task);
    }
}

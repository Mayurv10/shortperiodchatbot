<?php

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

$help = "Chatbot Bulk Vector DB Sync
This script bypasses the standard 20-file limit and processes all pending PDF files sequentially.
It is designed for initial migrations with thousands of files.

Options:
--reset      Reset the sync tracking table before starting.
-d, --delay  Delay in seconds between sending each file (default: 2).
-h, --help   Print out this help.

Example:
\$ php bulk_sync.php
\$ php bulk_sync.php --reset --delay=5
";

list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'reset' => false,
    'delay' => 2, // Default 2 seconds delay between files
], [
    'h' => 'help',
    'd' => 'delay',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    echo $help;
    exit(0);
}

if ($options['reset']) {
    mtrace("Resetting local_chatbot_sync table...");
    $DB->execute("DELETE FROM {local_chatbot_sync}");
    mtrace("Table reset successfully.\n");
}

mtrace("Starting Bulk Sync for Chatbot...");

$fs = get_file_storage();

// Helper function to send to backend (copied and adapted from sync_task.php)
function bulk_send_to_backend($courseid, $filename, $content) {
    $backend_url = \local_chatbot\config::get_backend_url();
    $url = rtrim($backend_url, '/') . '/api/upload';

    $tmpdir = make_temp_directory('chatbot');
    $tmpfile = tempnam($tmpdir, 'sync');
    file_put_contents($tmpfile, $content);

    $ch = curl_init();
    $data = [
        'index_id' => $courseid,
        'file' => new \CURLFile($tmpfile, 'application/pdf', $filename)
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);
    @unlink($tmpfile);

    if ($http_code == 200 || $http_code == 202) {
        return true;
    }
    
    mtrace("  -> Native cURL error (HTTP {$http_code}): " . $response . " " . $error);
    return false;
}

$processed = 0;
$failed = 0;

// Since we have 50k files, we don't load all into memory. 
// We use a recordset to iterate over them efficiently.
$sql = "SELECT f.id, f.contenthash, f.pathnamehash, f.filename, f.contextid, 
               CASE 
                   WHEN ctx.contextlevel = 50 THEN ctx.instanceid 
                   WHEN ctx.contextlevel = 70 THEN cm.course 
                   ELSE NULL 
               END as courseid
        FROM {files} f
        JOIN {context} ctx ON f.contextid = ctx.id
        LEFT JOIN {course_modules} cm ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
        LEFT JOIN {local_chatbot_sync} sync ON f.pathnamehash = sync.pathnamehash AND (
               CASE 
                   WHEN ctx.contextlevel = 50 THEN ctx.instanceid 
                   WHEN ctx.contextlevel = 70 THEN cm.course 
                   ELSE NULL 
               END) = sync.courseid
        WHERE f.mimetype = 'application/pdf'
          AND f.filename != '.'
          AND (ctx.contextlevel = 50 OR (ctx.contextlevel = 70 AND cm.course IS NOT NULL))
          AND (sync.id IS NULL OR (sync.syncstatus != 1 AND sync.syncstatus != 3))";

mtrace("Querying pending files...");

$count_sql = "SELECT COUNT(1) " . substr($sql, strpos($sql, "FROM {files} f"));
$total_files = $DB->count_records_sql($count_sql);

if ($total_files == 0) {
    mtrace("No pending files found. Everything is synced!");
    exit(0);
}

mtrace("Found {$total_files} pending files to sync.");

$rs = $DB->get_recordset_sql($sql);

foreach ($rs as $file) {
    if (empty($file->courseid)) {
        continue;
    }

    // Double check sync status directly (in case another process is running)
    $synced = $DB->get_record('local_chatbot_sync', ['pathnamehash' => $file->pathnamehash, 'courseid' => $file->courseid]);
    if ($synced && ($synced->syncstatus == 1 || $synced->syncstatus == 3)) {
        continue;
    }

    mtrace(sprintf("Processing [%d/%d] %s (Course: %d) ... ", $processed + $failed + 1, $total_files, $file->filename, $file->courseid));

    // Mark as "syncing" (3)
    $record = new \stdClass();
    $record->filehash = $file->contenthash;
    $record->pathnamehash = $file->pathnamehash;
    $record->courseid = $file->courseid;
    $record->syncstatus = 3;
    $record->timemodified = time();

    if ($synced) {
        $record->id = $synced->id;
        $DB->update_record('local_chatbot_sync', $record);
    } else {
        $record->id = $DB->insert_record('local_chatbot_sync', $record);
    }

    $fileinstance = $fs->get_file_by_id($file->id);
    if (!$fileinstance) {
        mtrace("  -> Error: Could not retrieve file instance.");
        $record->syncstatus = 2; // Failed
        $DB->update_record('local_chatbot_sync', $record);
        $failed++;
        continue;
    }

    $content = $fileinstance->get_content();
    if (bulk_send_to_backend($file->courseid, $file->filename, $content)) {
        mtrace("  -> OK");
        $record->syncstatus = 1; // Synced
        $processed++;
    } else {
        $record->syncstatus = 2; // Failed
        $failed++;
    }
    
    $record->timemodified = time();
    $DB->update_record('local_chatbot_sync', $record);

    if ($options['delay'] > 0) {
        mtrace("  [Waiting {$options['delay']}s...]");
        sleep($options['delay']);
    }
}

$rs->close();

mtrace("\nBulk Sync Complete!");
mtrace("Successfully processed: {$processed}");
mtrace("Failed: {$failed}");

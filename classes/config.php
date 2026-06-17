<?php
namespace local_chatbot;

defined('MOODLE_INTERNAL') || die();

class config {
    const DEFAULT_BACKEND_URL = 'http://127.0.0.1:8000';

    public static function get_backend_url() {
        $url = get_config('local_chatbot', 'backend_url');
        return empty($url) ? self::DEFAULT_BACKEND_URL : $url;
    }
}

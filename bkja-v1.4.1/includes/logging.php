<?php
if (!defined('ABSPATH')) exit;

function bkja_log_error($message) {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'bkja-logs';

    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }

    $log_file = $log_dir . '/bkja-error.log';
    $time = current_time('mysql');
    $formatted = "[$time] $message" . PHP_EOL;
    file_put_contents($log_file, $formatted, FILE_APPEND);
}

<?php
if (!defined('ABSPATH')) exit;

// Handle sending existing logs to GitHub (manual)
add_action('admin_post_bkja_send_logs_request', 'bkja_process_send_logs');
add_action('admin_post_bkja_test_log', 'bkja_process_test_log');

function bkja_get_log_path() {
    $upload_dir = wp_upload_dir();
    $log_dir = trailingslashit($upload_dir['basedir']) . 'bkja-logs';
    return $log_dir . '/bkja-error.log';
}

function bkja_process_test_log() {
    if (!current_user_can('manage_options')) wp_die('no');
    // create a test log
    bkja_log_error('Test error from admin Test Log - ' . uniqid());
    // if auto-send enabled, send as issue
    $auto = get_option('bkja_auto_send', 0);
    if ($auto) {
        bkja_process_send_logs();
    }
    wp_redirect(admin_url('options-general.php?page=bkja-settings&sent=1'));
    exit;
}

function bkja_process_send_logs() {
    if (!current_user_can('manage_options')) wp_die('no');
    $log_file = bkja_get_log_path();
    if (!file_exists($log_file)) {
        wp_redirect(admin_url('options-general.php?page=bkja-settings&sent=0&no_log=1'));
        exit;
    }
    $content = file_get_contents($log_file);
    $token = trim(get_option('bkja_github_token', ''));
    $repo = trim(get_option('bkja_repo_name', ''));
    if (empty($token) || empty($repo)) {
        wp_redirect(admin_url('options-general.php?page=bkja-settings&sent=0&no_token=1'));
        exit;
    }

    $title = '🔴 BKJA Error Report ' . current_time('mysql');
    $body = "**Automatic BKJA Error Report**\n\n" . 'Server: ' . $_SERVER['SERVER_NAME'] . "\nTime: " . current_time('mysql') . "\n\n" . "```
" . $content . "\n```
";

    $post = json_encode(array(
        'title' => $title,
        'body' => $body,
        'labels' => array('bug','auto-report')
    ));

    $args = array(
        'headers' => array(
            'Authorization' => 'token ' . $token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'WordPress'
        ),
        'body' => $post,
        'timeout' => 20
    );

    $url = 'https://api.github.com/repos/' . $repo . '/issues';
    // Use wp_remote_post when running inside WordPress
    if (function_exists('wp_remote_post')) {
        $resp = wp_remote_post($url, $args);
    } else {
        // fallback: write payload to a file for manual execution
        $root = dirname(dirname(__FILE__));
        @file_put_contents($root . '/bkja_pending_issue.json', $post);
        $resp = true;
    }

    if (is_wp_error($resp)) {
        wp_redirect(admin_url('options-general.php?page=bkja-settings&sent=0&error=1'));
        exit;
    }
    wp_redirect(admin_url('options-general.php?page=bkja-settings&sent=1'));
    exit;
}

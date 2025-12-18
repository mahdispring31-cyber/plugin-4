<?php
/*
Plugin Name: BKJA Assistant
Version: 1.5.11
Description: ابزار دستیار شغلی حرفه‌ای برای وردپرس.
Author: Mahdi Mohammadi
*/

// Prevent direct file access and avoid any accidental output before WordPress hooks are registered.
if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

// BKJA enhanced includes
require_once plugin_dir_path(__FILE__) . 'includes/logging.php';
require_once plugin_dir_path(__FILE__) . 'includes/helpers.php';
require_once plugin_dir_path(__FILE__) . 'admin/settings.php';
require_once plugin_dir_path(__FILE__) . 'admin/github-issue-reporter.php';

if ( ! defined( 'BKJA_PLUGIN_VERSION' ) ) {
        define( 'BKJA_PLUGIN_VERSION', '1.5.11' );
}

if ( ! function_exists( 'bkja_get_free_message_limit' ) ) {
        function bkja_get_free_message_limit() {
                $raw = get_option( 'bkja_free_messages_per_day', null );

                // Backward compatibility: fall back to legacy option only if the new one is missing.
                if ( false === $raw || null === $raw || '' === $raw ) {
                        $raw = get_option( 'bkja_free_limit', null );
                }

                if ( false === $raw || null === $raw || '' === $raw ) {
                        $raw = 2;
                }

                // Always enforce a non-negative integer to avoid silently ignoring admin changes.
                return max( 0, absint( $raw ) );
        }
}

if ( ! function_exists( 'bkja_cleanup_legacy_free_limit_option' ) ) {
        function bkja_cleanup_legacy_free_limit_option() {
                $legacy = get_option( 'bkja_free_limit', null );
                if ( false !== $legacy && $legacy !== null && $legacy !== '' ) {
                        $new = get_option( 'bkja_free_messages_per_day', null );
                        if ( false !== $new && $new !== null && $new !== '' ) {
                                delete_option( 'bkja_free_limit' );
                        }
                }
        }
}
// Handle CSV import for jobs
add_action('admin_post_bkja_import_jobs', function() {
if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');
check_admin_referer('bkja_import_jobs', 'bkja_import_jobs_nonce');
if (!isset($_FILES['bkja_jobs_csv']) || $_FILES['bkja_jobs_csv']['error'] !== UPLOAD_ERR_OK) {
wp_redirect(add_query_arg('bkja_import_success', '0', admin_url('admin.php?page=bkja-assistant')));
exit;
}
$file = $_FILES['bkja_jobs_csv']['tmp_name'];
$handle = fopen($file, 'r');
if ($handle) {
$header = fgetcsv($handle);
while (($row = fgetcsv($handle)) !== false) {
$data = array_combine($header, $row);
if ($data && !empty($data['title'])) {
$fields = ['category_id','title','income','income_num','investment','investment_num','experience_years','employment_type','hours_per_day','days_per_week','source','city','gender','advantages','disadvantages','details'];
$job = [];
foreach ($fields as $f) {
$job[$f] = isset($data[$f]) ? $data[$f] : '';
}
if (class_exists('BKJA_Database')) {
BKJA_Database::insert_job($job);
}
}
}
fclose($handle);
}
wp_redirect(add_query_arg('bkja_import_success', '1', admin_url('admin.php?page=bkja-assistant')));
exit;
});

// API for jobs - get job records (with advanced filters)
add_action('rest_api_init', function() {
        register_rest_route('bkja/v1', '/jobs/', [
                'methods' => 'GET',
                'callback' => 'bkja_get_jobs_api',
                'permission_callback' => '__return_true',
        ]);
});

function bkja_get_jobs_api(WP_REST_Request $request) {
	global $wpdb;
	$table = $wpdb->prefix . 'bkja_jobs';
	$where = '1=1';
	$params = [];
	// فیلترهای مختلف
	if ($category = $request->get_param('category_id')) {
		$where .= ' AND category_id = %d';
		$params[] = $category;
	}
	if ($title = $request->get_param('title')) {
		$where .= ' AND title LIKE %s';
		$params[] = '%' . $wpdb->esc_like($title) . '%';
	}
	if ($city = $request->get_param('city')) {
		$where .= ' AND city LIKE %s';
		$params[] = '%' . $wpdb->esc_like($city) . '%';
	}
        // TODO: migrate these filters to numeric columns (income_num) once data is fully backfilled.
        if ($min_income = $request->get_param('min_income')) {
                $where .= ' AND income >= %f';
                $params[] = $min_income;
        }
        if ($max_income = $request->get_param('max_income')) {
                $where .= ' AND income <= %f';
                $params[] = $max_income;
        }

	// صفحه‌بندی
	$page = $request->get_param('page') ?: 1;
	$per_page = $request->get_param('per_page') ?: 10;
	$offset = ($page - 1) * $per_page;
	$sql = "SELECT * FROM {$table} WHERE $where LIMIT %d OFFSET %d";
	$params[] = $per_page;
	$params[] = $offset;

	$results = $wpdb->get_results($wpdb->prepare($sql, $params));
	return rest_ensure_response($results);
}
// AJAX: دریافت خلاصه شغل بر اساس عنوان
add_action('wp_ajax_bkja_get_job_summary','bkja_ajax_get_job_summary');
add_action('wp_ajax_nopriv_bkja_get_job_summary','bkja_ajax_get_job_summary');
function bkja_ajax_get_job_summary(){
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) {
                wp_send_json_error( array( 'error' => 'invalid_nonce' ), 403 );
        }
        $job_title     = isset($_POST['job_title']) ? sanitize_text_field( wp_unslash( $_POST['job_title'] ) ) : '';
        $job_label     = isset($_POST['job_label']) ? sanitize_text_field( wp_unslash( $_POST['job_label'] ) ) : '';
        $job_title_id  = isset($_POST['job_title_id']) ? intval($_POST['job_title_id']) : 0;
        $group_key     = isset($_POST['group_key']) ? sanitize_text_field( wp_unslash( $_POST['group_key'] ) ) : '';
        $job_title_ids_raw = isset($_POST['job_title_ids']) ? wp_unslash( $_POST['job_title_ids'] ) : '';
        $job_title_ids = array();
        if ( $job_title_ids_raw ) {
            if ( is_array( $job_title_ids_raw ) ) {
                $job_title_ids = array_map( 'intval', $job_title_ids_raw );
            } else {
                $job_title_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $job_title_ids_raw ) ) ) );
            }
        }
        if(!$job_title && !$job_title_id && !$group_key && empty($job_title_ids)) wp_send_json_error(['error'=>'empty_title'],400);

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $window_months = (int) get_option( 'bkja_stats_window_months', 12 );
                if ( $window_months <= 0 ) {
                        $window_months = 12;
                }

                error_log( 'BKJA AJAX bkja_get_job_summary payload: ' . wp_json_encode( array(
                        'job_title'      => $job_title,
                        'job_label'      => $job_label,
                        'job_title_id'   => $job_title_id,
                        'group_key'      => $group_key,
                        'job_title_ids'  => $job_title_ids,
                        'raw_ids'        => $job_title_ids_raw,
                        'window_months'  => $window_months,
                ) ) );
        }

        $filters = array(
            'gender'          => isset($_POST['gender']) ? sanitize_text_field( wp_unslash( $_POST['gender'] ) ) : '',
            'city'            => isset($_POST['city']) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
            'income_min'      => isset($_POST['income_min']) ? intval($_POST['income_min']) : 0,
            'income_max'      => isset($_POST['income_max']) ? intval($_POST['income_max']) : 0,
            'investment_min'  => isset($_POST['investment_min']) ? intval($_POST['investment_min']) : 0,
            'investment_max'  => isset($_POST['investment_max']) ? intval($_POST['investment_max']) : 0,
        );

        $resolved = class_exists( 'BKJA_Database' ) ? BKJA_Database::resolve_job_query(
            array(
                'label'         => $job_label ?: $job_title,
                'job_title_id'  => $job_title_id,
                'group_key'     => $group_key,
                'job_title_ids' => $job_title_ids,
            )
        ) : array();

        $matched_job_title_id = isset( $resolved['matched_job_title_id'] ) ? (int) $resolved['matched_job_title_id'] : ( $job_title_id ?: 0 );

        if ( $matched_job_title_id > 0 ) {
            $summary = BKJA_Jobs::get_job_summary_by_job_title_id( $matched_job_title_id, $filters );
        } else {
            $summary = BKJA_Jobs::get_job_summary(array(
                'label' => $job_label ?: $job_title,
                'job_title_id' => $job_title_id,
                'group_key' => $group_key,
                'job_title_ids' => $job_title_ids,
            ), $filters);
        }
        $free_messages = get_option('bkja_free_messages_per_day', 5);
        if(!$summary) wp_send_json_error(['error'=>'not_found'],404);
        wp_send_json_success(['summary'=>$summary]);
}

// AJAX: دریافت رکوردهای شغل بر اساس عنوان (limit, offset)
add_action('wp_ajax_bkja_get_job_records','bkja_ajax_get_job_records');
add_action('wp_ajax_nopriv_bkja_get_job_records','bkja_ajax_get_job_records');
function bkja_ajax_get_job_records(){
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) {
                wp_send_json_error( array( 'error' => 'invalid_nonce' ), 403 );
        }
        $job_title = isset($_POST['job_title']) ? sanitize_text_field( wp_unslash( $_POST['job_title'] ) ) : '';
        $job_label     = isset($_POST['job_label']) ? sanitize_text_field( wp_unslash( $_POST['job_label'] ) ) : '';
        $job_title_id  = isset($_POST['job_title_id']) ? intval($_POST['job_title_id']) : 0;
        $group_key     = isset($_POST['group_key']) ? sanitize_text_field( wp_unslash( $_POST['group_key'] ) ) : '';
        $job_title_ids_raw = isset($_POST['job_title_ids']) ? wp_unslash( $_POST['job_title_ids'] ) : '';
        $job_title_ids = array();
        if ( $job_title_ids_raw ) {
            if ( is_array( $job_title_ids_raw ) ) {
                $job_title_ids = array_map( 'intval', $job_title_ids_raw );
            } else {
                $job_title_ids = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', (string) $job_title_ids_raw ) ) ) );
            }
        }
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 5;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        if(!$job_title && !$job_title_id && !$group_key && empty($job_title_ids)) wp_send_json_error(['error'=>'empty_title'],400);

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $window_months = (int) get_option( 'bkja_stats_window_months', 12 );
                if ( $window_months <= 0 ) {
                        $window_months = 12;
                }

                error_log( 'BKJA AJAX bkja_get_job_records payload: ' . wp_json_encode( array(
                        'job_title'      => $job_title,
                        'job_label'      => $job_label,
                        'job_title_id'   => $job_title_id,
                        'group_key'      => $group_key,
                        'job_title_ids'  => $job_title_ids,
                        'raw_ids'        => $job_title_ids_raw,
                        'limit'          => $limit,
                        'offset'         => $offset,
                        'window_months'  => $window_months,
                ) ) );
        }

        $filters = array(
            'gender'          => isset($_POST['gender']) ? sanitize_text_field( wp_unslash( $_POST['gender'] ) ) : '',
            'city'            => isset($_POST['city']) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
            'income_min'      => isset($_POST['income_min']) ? intval($_POST['income_min']) : 0,
            'income_max'      => isset($_POST['income_max']) ? intval($_POST['income_max']) : 0,
            'investment_min'  => isset($_POST['investment_min']) ? intval($_POST['investment_min']) : 0,
            'investment_max'  => isset($_POST['investment_max']) ? intval($_POST['investment_max']) : 0,
        );

        $resolved = class_exists( 'BKJA_Database' ) ? BKJA_Database::resolve_job_query(
            array(
                'label'         => $job_label ?: $job_title,
                'job_title_id'  => $job_title_id,
                'group_key'     => $group_key,
                'job_title_ids' => $job_title_ids,
            )
        ) : array();

        $matched_job_title_id = isset( $resolved['matched_job_title_id'] ) ? (int) $resolved['matched_job_title_id'] : ( $job_title_id ?: 0 );

        if ( $matched_job_title_id > 0 ) {
            $records = BKJA_Jobs::get_job_records_by_job_title_id( $matched_job_title_id, $limit, $offset, $filters );
        } else {
            $records = BKJA_Jobs::get_job_records(array(
                'label' => $job_label ?: $job_title,
                'job_title_id' => $job_title_id,
                'group_key' => $group_key,
                'job_title_ids' => $job_title_ids,
            ), $limit, $offset, $filters);
        }
        $free_messages = get_option('bkja_free_messages_per_day', 5);

        if ( is_array( $records ) && isset( $records['records'] ) ) {
            wp_send_json_success( array(
                'records'      => $records['records'],
                'has_more'     => isset( $records['has_more'] ) ? (bool) $records['has_more'] : false,
                'next_offset'  => isset( $records['next_offset'] ) ? $records['next_offset'] : null,
                'limit'        => isset( $records['limit'] ) ? $records['limit'] : $limit,
                'offset'       => isset( $records['offset'] ) ? $records['offset'] : $offset,
                'group_key'    => isset( $records['group_key'] ) ? $records['group_key'] : $group_key,
                'job_title_ids'=> isset( $records['job_title_ids'] ) ? $records['job_title_ids'] : array(),
            ) );
        }

        wp_send_json_success( array( 'records' => is_array( $records ) ? $records : array() ) );
}
define( 'BKJA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BKJA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-database.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-chat.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-repair.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-frontend.php';
require_once BKJA_PLUGIN_DIR . 'includes/class-bkja-user-profile.php';
add_action( 'plugins_loaded', array( 'BKJA_Database', 'maybe_migrate_chat_created_at_default' ) );
add_action( 'plugins_loaded', array( 'BKJA_Database', 'maybe_migrate_numeric_job_fields' ) );
add_action( 'plugins_loaded', array( 'BKJA_Database', 'maybe_backfill_job_titles' ) );
add_action( 'plugins_loaded', array( 'BKJA_Database', 'maybe_run_job_title_integrity' ) );
add_action( 'plugins_loaded', array( 'BKJA_Repair', 'register_ajax_hooks' ) );
register_activation_hook( __FILE__, array( 'BKJA_Database', 'activate' ) );
register_activation_hook( __FILE__, 'bkja_cleanup_legacy_free_limit_option' );
add_action('plugins_loaded', function(){
        if ( function_exists('bkja_cleanup_legacy_free_limit_option') ) {
                bkja_cleanup_legacy_free_limit_option();
        }
});
add_action( 'plugins_loaded', function() {
        if ( get_option( 'bkja_cache_reset_1512' ) ) {
                return;
        }

        if ( class_exists( 'BKJA_Chat' ) ) {
                BKJA_Chat::clear_response_cache_prefix();
        }

        update_option( 'bkja_cache_reset_1512', 1 );
} );
add_action( 'admin_init', 'bkja_cleanup_legacy_free_limit_option' );
add_action( 'init', function(){ load_plugin_textdomain( 'bkja-assistant', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' ); if ( class_exists('BKJA_Frontend') ) BKJA_Frontend::init(); if ( class_exists('BKJA_User_Profile') ) BKJA_User_Profile::init(); });
/* BKJA builder v1.4.1 injections */
if ( file_exists(dirname(__FILE__) . '/includes/class-bkja-jobs.php') ) { require_once dirname(__FILE__) . '/includes/class-bkja-jobs.php'; }
if ( file_exists(dirname(__FILE__) . '/includes/class-bkja-chat.php') ) { require_once dirname(__FILE__) . '/includes/class-bkja-chat.php'; }

add_action('wp_ajax_bkja_get_categories','bkja_ajax_get_categories');
add_action('wp_ajax_nopriv_bkja_get_categories','bkja_ajax_get_categories');
function bkja_ajax_get_categories(){ $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ''; if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) { wp_send_json_error(['error'=>'invalid_nonce'],403); } $cats = BKJA_Jobs::get_categories(); wp_send_json_success(['categories'=>$cats]); }

add_action( 'wp_ajax_bkja_clear_cache', 'bkja_ajax_clear_cache' );
function bkja_ajax_clear_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
    }

    check_ajax_referer( 'bkja_clear_cache', 'nonce' );

    if ( ! class_exists( 'BKJA_Chat' ) ) {
        wp_send_json_error( array( 'error' => 'missing_class' ), 500 );
    }

    $result  = BKJA_Chat::clear_response_cache_prefix();
    $deleted = isset( $result['deleted'] ) ? (int) $result['deleted'] : 0;
    $version = isset( $result['version'] ) ? (int) $result['version'] : null;

    $message = sprintf( 'پاکسازی کش انجام شد. %d رکورد حذف شد.', $deleted );

    if ( null !== $version ) {
        $message .= ' (نسخه کش فعلی: ' . $version . ')';
    }

    wp_send_json_success(
        array(
            'message' => $message,
            'deleted' => $deleted,
            'version' => $version,
        )
    );
}

add_action('wp_ajax_bkja_get_jobs','bkja_ajax_get_jobs');
add_action('wp_ajax_nopriv_bkja_get_jobs','bkja_ajax_get_jobs');
function bkja_ajax_get_jobs(){ $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ''; if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) { wp_send_json_error(['error'=>'invalid_nonce'],403); } $cat = isset($_POST['category_id'])? intval($_POST['category_id']):0; $jobs = BKJA_Jobs::get_jobs_by_category($cat); wp_send_json_success(['jobs'=>$jobs]); }

add_action('wp_ajax_bkja_get_job_variants','bkja_ajax_get_job_variants');
add_action('wp_ajax_nopriv_bkja_get_job_variants','bkja_ajax_get_job_variants');
function bkja_ajax_get_job_variants(){
    $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) {
        wp_send_json_error(['error'=>'invalid_nonce'],403);
    }

    $job_title_id = isset($_POST['job_title_id']) ? intval($_POST['job_title_id']) : 0;
    if ( $job_title_id <= 0 ) {
        wp_send_json_error(['error'=>'invalid_job_title'],400);
    }

    $variants = BKJA_Jobs::get_job_variants( $job_title_id );
    wp_send_json_success( [ 'variants' => $variants ] );
}

add_action('wp_ajax_bkja_get_job_detail','bkja_ajax_get_job_detail');
add_action('wp_ajax_nopriv_bkja_get_job_detail','bkja_ajax_get_job_detail');
function bkja_ajax_get_job_detail(){ $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : ''; if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) { wp_send_json_error(['error'=>'invalid_nonce'],403); } $job_id = isset($_POST['job_id'])? intval($_POST['job_id']):0; $job = BKJA_Jobs::get_job_detail($job_id); if(!$job) wp_send_json_error(['error'=>'not_found'],404); global $wpdb; $table_chats = $wpdb->prefix.'bkja_chats'; $raw_session = isset($_POST['session']) ? $_POST['session'] : ''; if ( class_exists( 'BKJA_Frontend' ) ) { $session = BKJA_Frontend::get_session( $raw_session ); } else { $session = is_string( $raw_session ) ? sanitize_text_field( wp_unslash( $raw_session ) ) : ''; if ( strlen( $session ) < 12 ) { $session = 'bkja_' . wp_generate_password( 20, false, false ); } } if($wpdb->get_var("SHOW TABLES LIKE '{$table_chats}'") == $table_chats){ $wpdb->insert($table_chats,['user_id'=>get_current_user_id()?:null,'session_id'=>$session,'job_category'=>$job->category_id,'message'=>null,'response'=>wp_json_encode(['type'=>'job_card','data'=>$job]),'created_at'=>current_time('mysql')]); } wp_send_json_success(['job'=>$job,'server_session'=>$session]); }

add_action('wp_ajax_bkja_refresh_nonce','bkja_ajax_refresh_nonce');
add_action('wp_ajax_nopriv_bkja_refresh_nonce','bkja_ajax_refresh_nonce');
function bkja_ajax_refresh_nonce(){
        $raw_session = isset($_POST['session']) ? $_POST['session'] : '';
        if ( class_exists( 'BKJA_Frontend' ) ) {
                $session = BKJA_Frontend::get_session( $raw_session );
        } else {
                $session = is_string( $raw_session ) ? sanitize_text_field( wp_unslash( $raw_session ) ) : '';
                if ( strlen( $session ) < 12 ) {
                        $session = 'bkja_' . wp_generate_password( 20, false, false );
                }
        }

        $free_limit = bkja_get_free_message_limit();

        $data = array(
                'nonce' => wp_create_nonce('bkja_nonce'),
                'is_logged_in' => is_user_logged_in() ? 1 : 0,
                'free_limit' => $free_limit,
                'login_url' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(),
                'guest_session' => $session,
                'server_session' => $session,
        );
        wp_send_json_success( $data );
}
//mahdi

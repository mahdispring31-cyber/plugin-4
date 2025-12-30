<?php
define( 'ABSPATH', __DIR__ . '/../' );
define( 'BKJA_DEV_SCRIPT', true );

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        }
        if ( is_array( $args ) ) {
            return array_merge( $defaults, $args );
        }
        $parsed = array();
        if ( is_string( $args ) ) {
            parse_str( $args, $parsed );
        }
        return array_merge( $defaults, $parsed );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return strip_tags( $text );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        return $default;
    }
}

require_once __DIR__ . '/../includes/class-bkja-analytics.php';
require_once __DIR__ . '/../includes/class-bkja-database.php';
require_once __DIR__ . '/../includes/BKJA_State.php';
require_once __DIR__ . '/../includes/BKJA_RuleEngine.php';
require_once __DIR__ . '/../includes/class-bkja-chat.php';

$failed = false;

$assert = function( $condition, $message ) use ( &$failed ) {
    if ( ! $condition ) {
        $failed = true;
        fwrite( STDERR, "FAIL: {$message}\n" );
    } else {
        fwrite( STDOUT, "PASS: {$message}\n" );
    }
};

$candidates = array(
    array(
        'job_title_id' => 12,
        'label'        => 'کارمند/شاگرد',
        'score'        => 12,
        'match_len'    => 1,
        'match_type'   => 'fuzzy',
        'group_key'    => 'id:12',
        'slug'         => 'employee',
        'job_title_ids'=> array( 12 ),
    ),
    array(
        'job_title_id' => 14,
        'label'        => 'آشپز',
        'score'        => 10,
        'match_len'    => 1,
        'match_type'   => 'fuzzy',
        'group_key'    => 'id:14',
        'slug'         => 'cook',
        'job_title_ids'=> array( 14 ),
    ),
);

$resolved = BKJA_Database::evaluate_job_candidates( $candidates );
$assert( 0 === (int) $resolved['matched_job_title_id'], 'unrelated query keeps matched_job_title_id at 0' );
$assert( ! empty( $resolved['ambiguous'] ), 'unrelated query stays ambiguous' );

$intent = BKJA_Chat::debug_detect_intent_label( 'پردرآمدترین مشاغل', array() );
$assert( 'TOP_INCOME_JOBS' === $intent, 'high income intent routes to TOP_INCOME_JOBS' );

$intent = BKJA_Chat::debug_detect_intent_label( 'پرستار', array() );
$assert( 'JOB_INFO' === $intent, 'short job title routes to JOB_INFO' );

$intent = BKJA_Chat::debug_detect_intent_label( 'پرستار بگو', array() );
$assert( 'JOB_INFO' === $intent, 'short job title with filler routes to JOB_INFO' );

$intent = BKJA_Chat::debug_detect_intent_label( 'پردرآمدترین مشاغل چیه', array() );
$assert( 'TOP_INCOME_JOBS' === $intent, 'high income question routes to TOP_INCOME_JOBS' );

$summary = BKJA_Analytics::summarize_income_samples( array( 50000000, 60000000, 55000000, 16500000000 ) );
$max = isset( $summary['max'] ) ? (int) $summary['max'] : 0;
$assert( $max > 0 && $max < 1000000000, 'outlier removed from income summary' );

$direct_payload = BKJA_Chat::call_openai(
    'پردرآمدترین مشاغل چیه',
    array(
        'session_id'     => 'dev-session-1',
        'job_title_id'   => 123,
        'job_title_hint' => 'حسابدار',
        'job_slug'       => 'accountant',
    )
);
$direct_intent = is_array( $direct_payload ) && isset( $direct_payload['intent_label'] ) ? $direct_payload['intent_label'] : '';
$assert( 'TOP_INCOME_JOBS' === $direct_intent, 'session job context still routes to TOP_INCOME_JOBS' );
$direct_text = is_array( $direct_payload ) && isset( $direct_payload['text'] ) ? $direct_payload['text'] : '';
$assert( false === strpos( $direct_text, 'این عنوان را دقیق پیدا نکردم' ), 'direct intent avoids job clarification message' );

if ( $failed ) {
    exit( 1 );
}

fwrite( STDOUT, "All scenario checks passed.\n" );

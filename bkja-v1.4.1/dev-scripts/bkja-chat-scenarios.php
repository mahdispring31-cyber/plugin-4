<?php
define( 'ABSPATH', __DIR__ . '/../' );

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

$summary = BKJA_Analytics::summarize_income_samples( array( 50000000, 60000000, 55000000, 16500000000 ) );
$max = isset( $summary['max'] ) ? (int) $summary['max'] : 0;
$assert( $max > 0 && $max < 1000000000, 'outlier removed from income summary' );

if ( $failed ) {
    exit( 1 );
}

fwrite( STDOUT, "All scenario checks passed.\n" );

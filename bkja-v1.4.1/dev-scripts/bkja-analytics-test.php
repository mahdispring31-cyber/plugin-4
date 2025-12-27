<?php
require_once __DIR__ . '/../includes/class-bkja-analytics.php';

$failed = false;

$assert = function( $condition, $message ) use ( &$failed ) {
    if ( ! $condition ) {
        $failed = true;
        fwrite( STDERR, "FAIL: {$message}\n" );
    } else {
        fwrite( STDOUT, "PASS: {$message}\n" );
    }
};

$parsed = BKJA_Analytics::normalize_income_value( '۱۰ میلیون تومان' );
$assert( isset( $parsed['value_toman'] ) && 10000000 === $parsed['value_toman'], 'normalize_income_value parses million unit' );

$parsed_range = BKJA_Analytics::normalize_income_value( '3 تا 5 میلیون' );
$assert(
    isset( $parsed_range['min_toman'], $parsed_range['max_toman'] ) &&
    3000000 === $parsed_range['min_toman'] &&
    5000000 === $parsed_range['max_toman'],
    'normalize_income_value parses range'
);

$outlier_result = BKJA_Analytics::detect_outliers( array( 10, 11, 12, 13, 100 ) );
$assert( ! empty( $outlier_result['outliers'] ), 'detect_outliers flags outlier' );

if ( $failed ) {
    exit( 1 );
}

fwrite( STDOUT, "All tests passed.\n" );

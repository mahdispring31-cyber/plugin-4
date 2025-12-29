<?php
if ( ! defined( 'ABSPATH' ) ) {
    // Allow CLI usage in dev scripts without WordPress bootstrap.
    define( 'ABSPATH', __DIR__ . '/' );
}

class BKJA_Analytics {
    protected static function normalize_digits( $text ) {
        $persian_digits = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٫','٬' );
        $latin_digits   = array( '0','1','2','3','4','5','6','7','8','9','.',',' );
        return str_replace( $persian_digits, $latin_digits, $text );
    }

    protected static function extract_numbers( $text ) {
        $numbers = array();
        if ( preg_match_all( '/\d+(?:[.,]\d+)?/u', $text, $matches ) ) {
            foreach ( $matches[0] as $match ) {
                $clean = str_replace( ',', '', $match );
                if ( is_numeric( $clean ) ) {
                    $numbers[] = (float) $clean;
                }
            }
        }
        return $numbers;
    }

    protected static function normalize_income_numeric_value( $value ) {
        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $value = (float) $value;
        if ( $value <= 0 ) {
            return null;
        }

        if ( $value >= 1000000 ) {
            return (int) round( $value );
        }

        if ( $value >= 1000 ) {
            return (int) round( $value * 1000 );
        }

        return (int) round( $value * 1000000 );
    }

    public static function normalize_income_value( $raw ) {
        $result = array(
            'value_toman' => null,
            'min_toman'   => null,
            'max_toman'   => null,
            'status'      => 'unknown',
            'unit'        => 'unknown',
            'note'        => null,
        );

        if ( ! is_string( $raw ) ) {
            return $result;
        }

        $text = trim( $raw );
        if ( '' === $text ) {
            return $result;
        }

        $normalized = self::normalize_digits( $text );

        $has_billion  = ( false !== mb_stripos( $normalized, 'میلیارد', 0, 'UTF-8' ) );
        $has_million  = ( false !== mb_stripos( $normalized, 'میلیون', 0, 'UTF-8' ) );
        $has_thousand = ( false !== mb_stripos( $normalized, 'هزار', 0, 'UTF-8' ) );
        $has_toman    = ( false !== mb_stripos( $normalized, 'تومان', 0, 'UTF-8' ) || false !== mb_stripos( $normalized, 'تومن', 0, 'UTF-8' ) );

        $numbers = self::extract_numbers( $normalized );
        if ( empty( $numbers ) ) {
            return $result;
        }

        $multiplier = 1;
        if ( $has_billion ) {
            $multiplier = 1000000000;
            $result['unit'] = 'billion';
        } elseif ( $has_million ) {
            $multiplier = 1000000;
            $result['unit'] = 'million';
        } elseif ( $has_thousand ) {
            $multiplier = 1000;
            $result['unit'] = 'thousand';
        } elseif ( $has_toman ) {
            $multiplier = 1;
            $result['unit'] = 'toman';
        } else {
            $base = $numbers[0];
            if ( $base >= 1000 && $base <= 300000 ) {
                $multiplier    = 1000;
                $result['unit'] = 'thousand';
                $result['note'] = 'unit_assumed_thousand';
            } elseif ( $base >= 1000000 ) {
                $multiplier    = 1;
                $result['unit'] = 'toman';
                $result['note'] = 'unit_assumed_toman';
            } else {
                $multiplier    = 1000000;
                $result['unit'] = 'million';
                $result['note'] = 'unit_assumed_million';
            }
        }

        $min = null;
        $max = null;
        $value = null;

        if ( count( $numbers ) >= 2 && ( false !== mb_stripos( $normalized, 'تا', 0, 'UTF-8' ) || false !== mb_stripos( $normalized, '-', 0, 'UTF-8' ) ) ) {
            $min = min( $numbers[0], $numbers[1] ) * $multiplier;
            $max = max( $numbers[0], $numbers[1] ) * $multiplier;
            $value = ( $min + $max ) / 2;
        } else {
            $value = $numbers[0] * $multiplier;
        }

        if ( $value && $value > 2000000000 && ! $has_billion ) {
            $result['status'] = 'ambiguous_unit';
            $result['note']   = 'ambiguous_unit_outlier';
            return $result;
        }

        if ( $value && $value > 0 ) {
            $result['value_toman'] = (int) round( $value );
        }
        if ( $min && $min > 0 ) {
            $result['min_toman'] = (int) round( $min );
        }
        if ( $max && $max > 0 ) {
            $result['max_toman'] = (int) round( $max );
        }

        if ( $result['value_toman'] ) {
            $result['status'] = 'ok';
        }

        return $result;
    }

    protected static function quantile( $values, $q ) {
        $count = count( $values );
        if ( 0 === $count ) {
            return null;
        }
        sort( $values, SORT_NUMERIC );
        $pos   = ( $count - 1 ) * $q;
        $floor = (int) floor( $pos );
        $ceil  = (int) ceil( $pos );
        if ( $floor === $ceil ) {
            return $values[ $floor ];
        }
        $d0 = $values[ $floor ] * ( $ceil - $pos );
        $d1 = $values[ $ceil ] * ( $pos - $floor );
        return $d0 + $d1;
    }

    public static function detect_outliers( $values ) {
        $values = array_values( array_filter( $values, function( $v ) {
            return is_numeric( $v ) && $v > 0;
        } ) );

        $count = count( $values );
        if ( $count < 2 ) {
            return array( 'outliers' => array(), 'has_outliers' => false, 'method' => 'none' );
        }

        if ( $count >= 4 ) {
            $q1  = self::quantile( $values, 0.25 );
            $q3  = self::quantile( $values, 0.75 );
            $iqr = $q3 - $q1;
            if ( $iqr <= 0 ) {
                return array( 'outliers' => array(), 'has_outliers' => false, 'method' => 'iqr' );
            }
            $lower = $q1 - ( 1.5 * $iqr );
            $upper = $q3 + ( 1.5 * $iqr );
            $outliers = array();
            foreach ( $values as $v ) {
                if ( $v < $lower || $v > $upper ) {
                    $outliers[] = $v;
                }
            }
            return array( 'outliers' => $outliers, 'has_outliers' => ! empty( $outliers ), 'method' => 'iqr' );
        }

        $mean = array_sum( $values ) / $count;
        $variance = 0.0;
        foreach ( $values as $v ) {
            $variance += pow( ( $v - $mean ), 2 );
        }
        $variance = $variance / $count;
        $stddev   = sqrt( $variance );
        if ( $stddev <= 0 ) {
            return array( 'outliers' => array(), 'has_outliers' => false, 'method' => 'zscore' );
        }

        $outliers = array();
        foreach ( $values as $v ) {
            $z = ( $v - $mean ) / $stddev;
            if ( abs( $z ) > 2.5 ) {
                $outliers[] = $v;
            }
        }

        return array( 'outliers' => $outliers, 'has_outliers' => ! empty( $outliers ), 'method' => 'zscore' );
    }

    public static function normalize_income_amount( $raw ) {
        if ( is_numeric( $raw ) ) {
            return self::normalize_income_numeric_value( $raw );
        }

        if ( is_string( $raw ) ) {
            $parsed = self::normalize_income_value( $raw );
            if ( 'ok' === $parsed['status'] && ! empty( $parsed['value_toman'] ) ) {
                return (int) $parsed['value_toman'];
            }
        }

        return null;
    }

    public static function summarize_income_samples( $samples, $range_mins = array(), $range_maxes = array() ) {
        $samples = is_array( $samples ) ? $samples : array();
        $range_mins = is_array( $range_mins ) ? $range_mins : array();
        $range_maxes = is_array( $range_maxes ) ? $range_maxes : array();

        $values = array();
        foreach ( $samples as $sample ) {
            $normalized = self::normalize_income_amount( $sample );
            if ( $normalized ) {
                $values[] = $normalized;
            }
        }

        $range_mins_norm = array();
        foreach ( $range_mins as $min ) {
            $normalized = self::normalize_income_amount( $min );
            if ( $normalized ) {
                $range_mins_norm[] = $normalized;
            }
        }

        $range_maxes_norm = array();
        foreach ( $range_maxes as $max ) {
            $normalized = self::normalize_income_amount( $max );
            if ( $normalized ) {
                $range_maxes_norm[] = $normalized;
            }
        }

        $values = array_values( array_filter( $values, function( $v ) {
            return is_numeric( $v ) && $v > 0;
        } ) );

        sort( $values, SORT_NUMERIC );
        $outlier_info = self::detect_outliers( $values );
        $outliers     = isset( $outlier_info['outliers'] ) ? (array) $outlier_info['outliers'] : array();
        $filtered     = $values;
        if ( ! empty( $outliers ) ) {
            $filtered = array();
            foreach ( $values as $value ) {
                if ( in_array( $value, $outliers, true ) ) {
                    continue;
                }
                $filtered[] = $value;
            }
            if ( empty( $filtered ) ) {
                $filtered = $values;
            }
        }

        $count = count( $filtered );
        $median = null;
        if ( $count > 0 ) {
            $middle = (int) floor( ( $count - 1 ) / 2 );
            $median = ( $count % 2 )
                ? $filtered[ $middle ]
                : ( $filtered[ $middle ] + $filtered[ $middle + 1 ] ) / 2;
        }

        $min = ! empty( $filtered ) ? min( $filtered ) : null;
        $max = ! empty( $filtered ) ? max( $filtered ) : null;

        if ( null === $min && ! empty( $range_mins_norm ) ) {
            $min = min( $range_mins_norm );
        }
        if ( null === $max && ! empty( $range_maxes_norm ) ) {
            $max = max( $range_maxes_norm );
        }

        return array(
            'values'        => $filtered,
            'count'         => $count,
            'median'        => $median,
            'min'           => $min,
            'max'           => $max,
            'has_outliers'  => ! empty( $outliers ),
            'outlier_count' => count( $outliers ),
        );
    }
}

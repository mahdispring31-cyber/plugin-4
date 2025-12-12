<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'bkja_get_gender_label' ) ) {
    function bkja_get_gender_label( $gender ) {
        switch ( $gender ) {
            case 'male':
                return 'مرد';
            case 'female':
                return 'زن';
            case 'both':
            case 'other':
                return 'زن و مرد';
            default:
                return 'نامشخص';
        }
    }
}

if ( ! function_exists( 'bkja_get_employment_label' ) ) {
    function bkja_get_employment_label( $type ) {
        switch ( $type ) {
            case 'employee':
                return 'کارمند';
            case 'self_employed':
                return 'خوداشتغال / آزاد';
            case 'contract':
                return 'قراردادی';
            case 'freelance':
                return 'فریلنسر';
            case 'company_employee':
                return 'کارمند شرکت خصوصی';
            case 'part_time':
                return 'پاره‌وقت';
            default:
                return 'نامشخص';
        }
    }
}

if ( ! function_exists( 'bkja_format_created_at' ) ) {
    function bkja_format_created_at( $mysql_datetime ) {
        if ( empty( $mysql_datetime ) ) {
            return '';
        }

        $ts = strtotime( $mysql_datetime );

        if ( ! $ts ) {
            return $mysql_datetime;
        }

        // افزونه پارسی‌دیت روی سایت فعال است، بنابراین date_i18n خروجی شمسی می‌دهد
        return date_i18n( 'Y/m/d', $ts );
    }
}

if ( ! function_exists( 'bkja_format_job_date' ) ) {
    function bkja_format_job_date( $datetime, $format = 'Y/m/d' ) {
        if ( empty( $datetime ) ) {
            return '';
        }

        $timestamp = strtotime( $datetime );

        if ( ! $timestamp ) {
            return $datetime;
        }

        return date_i18n( $format, $timestamp );
    }
}

if ( ! function_exists( 'bkja_parse_money_to_toman' ) ) {
    /**
     * Parse a money text (Persian/English) into toman units.
     *
     * @param string $raw
     * @return array{value_toman:?int,min_toman:?int,max_toman:?int}
     */
    function bkja_parse_money_to_toman( $raw ) {
        $result = array(
            'value_toman' => null,
            'min_toman'   => null,
            'max_toman'   => null,
        );

        if ( ! is_string( $raw ) ) {
            return $result;
        }

        $text = trim( wp_strip_all_tags( (string) $raw ) );
        if ( '' === $text ) {
            return $result;
        }

        $persian_digits = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
        $latin_digits   = array( '0','1','2','3','4','5','6','7','8','9' );
        $normalized     = str_replace( $persian_digits, $latin_digits, $text );
        $normalized     = str_replace( array( ',', '٬', '،' ), '', $normalized );
        $normalized     = preg_replace( '/\s+/u', ' ', $normalized );

        // Detect unit words
        $has_billion = ( false !== mb_stripos( $text, 'میلیارد', 0, 'UTF-8' ) );
        $has_million = ( false !== mb_stripos( $text, 'میلیون', 0, 'UTF-8' ) );
        $has_thousand = ( false !== mb_stripos( $text, 'هزار', 0, 'UTF-8' ) );
        $has_toman = ( false !== mb_stripos( $text, 'تومان', 0, 'UTF-8' ) || false !== mb_stripos( $text, 'تومن', 0, 'UTF-8' ) );

        preg_match_all( '/([0-9]+(?:[\.\/][0-9]+)?)/', $normalized, $matches );
        $numbers = array();
        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $match ) {
                $num = floatval( str_replace( '/', '.', $match ) );
                if ( $num > 0 ) {
                    $numbers[] = $num;
                }
            }
        }

        if ( empty( $numbers ) ) {
            return $result;
        }

        $is_range = false !== mb_stripos( $normalized, 'تا', 0, 'UTF-8' ) || false !== mb_stripos( $normalized, 'بین', 0, 'UTF-8' );

        $base_number = $numbers[0];
        $multiplier  = 1;

        if ( $has_billion ) {
            $multiplier = 1000000000;
        } elseif ( $has_million ) {
            $multiplier = 1000000;
        } elseif ( $has_thousand ) {
            $multiplier = 1000;
        } elseif ( $has_toman ) {
            $multiplier = 1;
        } else {
            // Heuristic when unit is absent
            if ( $base_number >= 1000000 ) {
                $multiplier = 1;
            } else {
                $multiplier = 1000000;
            }
        }

        $min = null;
        $max = null;

        if ( $is_range && count( $numbers ) >= 2 ) {
            $min = (int) round( $numbers[0] * $multiplier );
            $max = (int) round( $numbers[1] * $multiplier );
            if ( $min > 0 && $max > 0 && $min > $max ) {
                $tmp = $min;
                $min = $max;
                $max = $tmp;
            }
        }

        $value = null;
        if ( count( $numbers ) >= 2 && $is_range ) {
            $value = (int) round( ( ( $numbers[0] + $numbers[1] ) / 2 ) * $multiplier );
        } else {
            $value = (int) round( $base_number * $multiplier );
        }

        if ( $value > 0 ) {
            $result['value_toman'] = $value;
        }
        if ( $min && $min > 0 ) {
            $result['min_toman'] = $min;
        }
        if ( $max && $max > 0 ) {
            $result['max_toman'] = $max;
        }

        return $result;
    }
}

if ( ! function_exists( 'bkja_format_toman_as_million_label' ) ) {
    /**
     * Format toman value into human friendly «میلیون تومان» label.
     */
    function bkja_format_toman_as_million_label( $toman ) {
        if ( ! is_numeric( $toman ) || $toman <= 0 ) {
            return 'نامشخص';
        }

        $million = (float) $toman / 1000000;

        if ( $million < 20 ) {
            $formatted = number_format( $million, 1, '.', '' );
        } else {
            $formatted = number_format( round( $million ), 0, '.', '' );
        }

        $formatted = str_replace( array( '.0', '.00' ), '', $formatted );

        // Localize digits to Persian if available
        $digits_en = array( '0','1','2','3','4','5','6','7','8','9' );
        $digits_fa = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
        $formatted = str_replace( $digits_en, $digits_fa, $formatted );

        return trim( $formatted ) . ' میلیون تومان';
    }
}

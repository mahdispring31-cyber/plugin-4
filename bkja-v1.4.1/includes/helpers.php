<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'bkja_normalize_fa_text' ) ) {
    /**
     * Normalize Persian text by unifying Arabic characters and whitespace.
     *
     * @param string $text
     * @return string
     */
    function bkja_normalize_fa_text( $text ) {
        if ( ! is_string( $text ) ) {
            $text = (string) $text;
        }

        $text = str_replace(
            array( 'ي', 'ك', '‌', "\xE2\x80\x8C" ),
            array( 'ی', 'ک', ' ', ' ' ),
            $text
        );

        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( (string) $text );
    }
}

if ( ! function_exists( 'bkja_normalize_query_text' ) ) {
    /**
     * Normalize free-text queries for job matching.
     *
     * @param string $text
     * @return string
     */
    function bkja_normalize_query_text( $text ) {
        if ( ! is_string( $text ) ) {
            $text = (string) $text;
        }

        $text = bkja_normalize_fa_text( $text );
        $text = strtr(
            $text,
            array(
                'ة' => 'ه',
                'ۀ' => 'ه',
                'ؤ' => 'و',
                'إ' => 'ا',
                'أ' => 'ا',
                'آ' => 'ا',
                'ـ' => '',
            )
        );

        // Strip emoji and punctuation-like symbols.
        $text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
        $text = preg_replace( '/\s+/u', ' ', $text );

        if ( function_exists( 'mb_strtolower' ) ) {
            $text = mb_strtolower( $text, 'UTF-8' );
        } else {
            $text = strtolower( $text );
        }

        return trim( (string) $text );
    }
}

if ( ! function_exists( 'bkja_generate_title_variants' ) ) {
    /**
     * Generate variant titles for fuzzy matching.
     *
     * @param string $text
     * @return array
     */
    function bkja_generate_title_variants( $text ) {
        $text = bkja_normalize_query_text( $text );
        if ( '' === $text ) {
            return array();
        }

        $variants = array( $text );

        $ends_with = function( $value, $suffix ) {
            if ( function_exists( 'mb_substr' ) ) {
                return $suffix === mb_substr( $value, -1 * mb_strlen( $suffix, 'UTF-8' ), null, 'UTF-8' );
            }
            return substr( $value, -1 * strlen( $suffix ) ) === $suffix;
        };

        if ( $ends_with( $text, 'ی' ) ) {
            $trimmed = function_exists( 'mb_substr' )
                ? mb_substr( $text, 0, mb_strlen( $text, 'UTF-8' ) - 1, 'UTF-8' )
                : substr( $text, 0, -1 );
            $trimmed = bkja_normalize_query_text( $trimmed );
            if ( '' !== $trimmed ) {
                $variants[] = $trimmed;
            }
        }

        foreach ( array( 'های', 'ها' ) as $suffix ) {
            if ( $ends_with( $text, $suffix ) ) {
                $trimmed = function_exists( 'mb_substr' )
                    ? mb_substr( $text, 0, mb_strlen( $text, 'UTF-8' ) - mb_strlen( $suffix, 'UTF-8' ), 'UTF-8' )
                    : substr( $text, 0, -1 * strlen( $suffix ) );
                $trimmed = bkja_normalize_query_text( $trimmed );
                if ( '' !== $trimmed ) {
                    $variants[] = $trimmed;
                }
                break;
            }
        }

        foreach ( array( 'گری', 'کاری', 'چی' ) as $suffix ) {
            if ( $ends_with( $text, $suffix ) ) {
                $trimmed = function_exists( 'mb_substr' )
                    ? mb_substr( $text, 0, mb_strlen( $text, 'UTF-8' ) - mb_strlen( $suffix, 'UTF-8' ), 'UTF-8' )
                    : substr( $text, 0, -1 * strlen( $suffix ) );
                $trimmed = bkja_normalize_query_text( $trimmed );
                if ( '' !== $trimmed ) {
                    $variants[] = $trimmed;
                }
                break;
            }
        }

        $variants = array_values( array_unique( array_filter( $variants ) ) );

        return $variants;
    }
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

        // Detect unit words
        $has_billion = ( false !== mb_stripos( $text, 'میلیارد', 0, 'UTF-8' ) );
        $has_million = ( false !== mb_stripos( $text, 'میلیون', 0, 'UTF-8' ) );
        $has_thousand = ( false !== mb_stripos( $text, 'هزار', 0, 'UTF-8' ) );
        $has_toman = ( false !== mb_stripos( $text, 'تومان', 0, 'UTF-8' ) || false !== mb_stripos( $text, 'تومن', 0, 'UTF-8' ) );

        $numeric = function_exists( 'bkja_parse_numeric_range' )
            ? bkja_parse_numeric_range( $text )
            : array();

        if ( empty( $numeric ) || ( empty( $numeric['value'] ) && empty( $numeric['min'] ) && empty( $numeric['max'] ) ) ) {
            return $result;
        }

        $base_number = isset( $numeric['value'] ) ? (float) $numeric['value'] : 0.0;
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

        if ( isset( $numeric['min'] ) || isset( $numeric['max'] ) ) {
            $min = isset( $numeric['min'] ) ? (int) round( (float) $numeric['min'] * $multiplier ) : null;
            $max = isset( $numeric['max'] ) ? (int) round( (float) $numeric['max'] * $multiplier ) : null;
            if ( $min && $max && $min > $max ) {
                $tmp = $min;
                $min = $max;
                $max = $tmp;
            }
        }

        $value = null;
        $value = $base_number > 0 ? (int) round( $base_number * $multiplier ) : null;

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

if ( ! function_exists( 'bkja_parse_numeric_range' ) ) {
    /**
     * Parse a numeric value or range from text (supports Persian digits and ranges).
     *
     * @param string $raw
     * @return array{value:?float,min:?float,max:?float}
     */
    function bkja_parse_numeric_range( $raw ) {
        $result = array(
            'value' => null,
            'min'   => null,
            'max'   => null,
        );

        if ( ! is_string( $raw ) ) {
            return $result;
        }

        $text = trim( wp_strip_all_tags( (string) $raw ) );
        if ( '' === $text ) {
            return $result;
        }

        if ( function_exists( 'bkja_normalize_fa_text' ) ) {
            $text = bkja_normalize_fa_text( $text );
        }

        $persian_digits = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
        $latin_digits   = array( '0','1','2','3','4','5','6','7','8','9' );
        $normalized     = $text;

        $word_numbers = array(
            'یک'  => '1',
            'دو'  => '2',
            'سه'  => '3',
            'چهار' => '4',
            'پنج' => '5',
            'شش'  => '6',
            'هفت' => '7',
            'هشت' => '8',
            'نه'  => '9',
            'ده'  => '10',
        );

        foreach ( $word_numbers as $word => $digit ) {
            $normalized = preg_replace( '/(?<!\p{L})' . preg_quote( $word, '/' ) . '(?!\p{L})/u', $digit, $normalized );
            $normalized = preg_replace( '/' . preg_quote( $word, '/' ) . '(?=\s*(میلیون|میلیارد|هزار|تومان|تومن))/u', $digit, $normalized );
        }

        $normalized = str_replace( $persian_digits, $latin_digits, $normalized );
        $normalized = str_replace( array( ',', '٬', '،' ), '', $normalized );
        $normalized = preg_replace( '/\s+/u', ' ', $normalized );

        $range_patterns = array(
            '/بین\s*([0-9]+(?:[\.\/][0-9]+)?)\s*(?:و|تا|الی)\s*([0-9]+(?:[\.\/][0-9]+)?)/u',
            '/([0-9]+(?:[\.\/][0-9]+)?)\s*(?:تا|الی|الى|–|-|—)\s*([0-9]+(?:[\.\/][0-9]+)?)/u',
        );

        foreach ( $range_patterns as $pattern ) {
            if ( preg_match( $pattern, $normalized, $matches ) ) {
                $min = floatval( str_replace( '/', '.', $matches[1] ) );
                $max = floatval( str_replace( '/', '.', $matches[2] ) );
                if ( $min > 0 && $max > 0 ) {
                    if ( $min > $max ) {
                        $tmp = $min;
                        $min = $max;
                        $max = $tmp;
                    }
                    $result['min']   = $min;
                    $result['max']   = $max;
                    $result['value'] = ( $min + $max ) / 2;
                    return $result;
                }
            }
        }

        if ( preg_match( '/([0-9]+(?:[\.\/][0-9]+)?)/', $normalized, $match ) ) {
            $value = floatval( str_replace( '/', '.', $match[1] ) );
            if ( $value > 0 ) {
                $result['value'] = $value;
            }
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

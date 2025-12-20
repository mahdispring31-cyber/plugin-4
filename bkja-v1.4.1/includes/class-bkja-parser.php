<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BKJA_Parser {
    /**
     * Parse investment text into toman value and status.
     *
     * @param string|null $raw
     * @return array{status:string,value:?int,note:?string}
     */
    public static function parse_investment_to_toman( $raw ) {
        return self::parse_money_text(
            $raw,
            array(
                'zero_keywords' => array(
                    'بدون سرمایه',
                    'نیاز به سرمایه اولیه ندارد',
                    'نیاز به سرمایه ندارد',
                    'سرمایه اولیه ندارد',
                    'سرمایه‌ای نیاز ندارد',
                    'سرمایه مالی نیاز نیست',
                    'سرمایه مالی نیاز ندارد',
                    'سرمایه صفر',
                    '۰',
                    '0',
                    'صفر',
                ),
                'zero_patterns' => array(
                    '/نیاز\s*(به)?\s*سرمایه\s*(نیست|ندارد|نمی.?خواهد)/u',
                    '/سرمایه(\s*مالی)?\s*(نیست|ندارد)/u',
                ),
                'unknown_keywords' => array(
                    'نامشخص',
                    'معلوم نیست',
                    'ذکر نشده',
                    'نامشخص / ذکرشده در متن',
                    'عدد مشخص نشده',
                    'بستگی دارد',
                    'حدودا',
                    'مبلغ دقیق ذکر نشده',
                    'نیاز به سرمایه دارد اما مبلغ دقیق ذکر نشده',
                ),
                'asset_keywords' => array(
                    'خودرو',
                    'ماشین',
                    'کامیون',
                    'موتور',
                    'ابزار',
                    'ماشین‌آلات',
                    'ماشین آلات',
                    'دستگاه',
                    'تجهیزات',
                ),
                'non_money_units' => array(
                    'سال',
                    'سابقه',
                    'ماه',
                    'روز',
                    'ساعت',
                    'هفته',
                    'شیفت',
                    'تعداد',
                ),
                'allow_zero' => true,
            )
        );
    }

    /**
     * Parse income text into toman value and status.
     *
     * @param string|null $raw
     * @return array{status:string,value:?int,note:?string}
     */
    public static function parse_income_to_toman( $raw ) {
        return self::parse_money_text(
            $raw,
            array(
                'zero_keywords'    => array(),
                'unknown_keywords' => array(
                    'نامشخص',
                    'معلوم نیست',
                    'ذکر نشده',
                    'نامشخص / ذکرشده در متن',
                    'درآمد دقیق ذکر نشده است',
                    'درآمدی ذکر نشده یا صفر است',
                    'حدود حقوق وزارت کار در ماه',
                    'فعلاً سود مشخصی ندارد',
                    'عدد مشخص نشده',
                    'کمتر از کارگر',
                    'بستگی دارد',
                    'حدودا',
                    'مبلغ دقیق ذکر نشده',
                ),
                'allow_zero' => false,
            )
        );
    }

    /**
     * @param string|null $raw
     * @param array{zero_keywords:array,zero_patterns?:array,unknown_keywords:array,asset_keywords?:array,non_money_units?:array,allow_zero:bool} $options
     * @return array{status:string,value:?int,note:?string}
     */
    protected static function parse_money_text( $raw, $options ) {
        $options = array_merge(
            array(
                'zero_keywords'    => array(),
                'zero_patterns'    => array(),
                'unknown_keywords' => array(),
                'asset_keywords'   => array(),
                'non_money_units'  => array(),
                'allow_zero'       => false,
            ),
            is_array( $options ) ? $options : array()
        );

        $result = array(
            'status' => 'unknown',
            'value'  => null,
            'note'   => null,
        );

        if ( null === $raw ) {
            $result['note'] = 'empty';
            return $result;
        }

        $text = is_string( $raw ) ? $raw : (string) $raw;
        $text = trim( wp_strip_all_tags( $text ) );
        if ( '' === $text ) {
            $result['note'] = 'empty';
            return $result;
        }

        if ( function_exists( 'bkja_normalize_fa_text' ) ) {
            $text = bkja_normalize_fa_text( $text );
        }

        if ( self::contains_zero_keyword( $text, $options['zero_keywords'] )
            || self::contains_zero_pattern( $text, $options['zero_patterns'] ) ) {
            $result['status'] = 'zero';
            $result['value']  = 0;
            return $result;
        }

        $parse_text = $text;
        if ( ! empty( $options['non_money_units'] ) ) {
            $parse_text = self::strip_non_money_units( $parse_text, $options['non_money_units'] );
        }

        if ( ! empty( $options['asset_keywords'] )
            && self::contains_keyword( $text, $options['asset_keywords'] )
            && null === self::detect_unit_multiplier( $text ) ) {
            $result['status'] = 'asset_or_non_cash';
            $result['note']   = 'asset';
            return $result;
        }

        $numeric = function_exists( 'bkja_parse_numeric_range' )
            ? bkja_parse_numeric_range( $parse_text )
            : array();

        $number = isset( $numeric['value'] ) ? (float) $numeric['value'] : null;
        if ( ! $number || $number <= 0 ) {
            $number = null;
        }

        if ( null === $number ) {
            $number = self::extract_word_number( $parse_text );
        }

        $multiplier = self::detect_unit_multiplier( $text );

        if ( null === $number ) {
            if ( self::contains_keyword( $text, $options['unknown_keywords'] ) ) {
                $result['status'] = 'unknown';
                $result['note']   = 'keyword';
                return $result;
            }

            $result['status'] = 'invalid';
            return $result;
        }

        if ( null === $multiplier ) {
            if ( $number >= 1000000 ) {
                $result['status'] = 'ok';
                $result['value']  = (int) round( $number );
                $result['note']   = 'unit_assumed_toman';
                return $result;
            }

            $result['status'] = 'ambiguous_unit';
            $result['note']   = (string) $number;
            return $result;
        }

        $result['status'] = 'ok';
        $result['value']  = (int) round( $number * $multiplier );
        return $result;
    }

    /**
     * @param string $text
     * @param array $patterns
     * @return bool
     */
    protected static function contains_zero_pattern( $text, $patterns ) {
        foreach ( (array) $patterns as $pattern ) {
            if ( '' === $pattern ) {
                continue;
            }
            if ( @preg_match( $pattern, $text ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $text
     * @param array $units
     * @return string
     */
    protected static function strip_non_money_units( $text, $units ) {
        $units = array_filter( array_map( 'trim', (array) $units ) );
        if ( empty( $units ) ) {
            return $text;
        }

        $escaped = array_map(
            static function ( $unit ) {
                return preg_quote( $unit, '/' );
            },
            $units
        );
        $unit_pattern = implode( '|', $escaped );

        $patterns = array(
            '/([0-9۰-۹]+)\s*(?:' . $unit_pattern . ')/u',
            '/(?:' . $unit_pattern . ')\s*([0-9۰-۹]+)/u',
        );

        return preg_replace( $patterns, ' ', $text );
    }

    /**
     * @param string $text
     * @return float|null
     */
    protected static function extract_word_number( $text ) {
        $map = array(
            'یک'  => 1,
            'دو'  => 2,
            'سه'  => 3,
            'چهار' => 4,
            'پنج' => 5,
            'شش'  => 6,
            'هفت' => 7,
            'هشت' => 8,
            'نه'  => 9,
            'ده'  => 10,
        );

        foreach ( $map as $word => $number ) {
            if ( preg_match( '/(?<!\p{L})' . preg_quote( $word, '/' ) . '(?!\p{L})/u', $text ) ) {
                return (float) $number;
            }
        }

        return null;
    }

    /**
     * @param string $text
     * @return int|null
     */
    protected static function detect_unit_multiplier( $text ) {
        $has_billion  = ( false !== mb_stripos( $text, 'میلیارد', 0, 'UTF-8' ) );
        $has_million  = ( false !== mb_stripos( $text, 'میلیون', 0, 'UTF-8' ) );
        $has_thousand = ( false !== mb_stripos( $text, 'هزار', 0, 'UTF-8' ) );
        $has_toman    = ( false !== mb_stripos( $text, 'تومان', 0, 'UTF-8' ) || false !== mb_stripos( $text, 'تومن', 0, 'UTF-8' ) );

        if ( $has_billion ) {
            return 1000000000;
        }

        if ( $has_million ) {
            return 1000000;
        }

        if ( $has_thousand ) {
            return 1000;
        }

        if ( $has_toman ) {
            return 1;
        }

        return null;
    }

    /**
     * @param string $text
     * @param array $keywords
     * @return bool
     */
    protected static function contains_keyword( $text, $keywords ) {
        foreach ( (array) $keywords as $keyword ) {
            if ( '' === $keyword ) {
                continue;
            }
            if ( false !== mb_stripos( $text, $keyword, 0, 'UTF-8' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match zero keywords with safe handling for numeric-only tokens.
     *
     * @param string $text
     * @param array $keywords
     * @return bool
     */
    protected static function contains_zero_keyword( $text, $keywords ) {
        $normalized = trim( $text );

        foreach ( (array) $keywords as $keyword ) {
            if ( '' === $keyword ) {
                continue;
            }

            if ( in_array( $keyword, array( '0', '۰' ), true ) ) {
                if ( preg_match( '/^[۰0]\s*$/u', $normalized ) ) {
                    return true;
                }
                continue;
            }

            if ( false !== mb_stripos( $text, $keyword, 0, 'UTF-8' ) ) {
                return true;
            }
        }

        return false;
    }
}

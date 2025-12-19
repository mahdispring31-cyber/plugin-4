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
                    'سرمایه صفر',
                    '۰',
                    '0',
                    'صفر',
                ),
                'unknown_keywords' => array(
                    'نامشخص',
                    'معلوم نیست',
                    'ذکر نشده',
                    'بستگی دارد',
                    'حدودا',
                    'مبلغ دقیق ذکر نشده',
                    'نیاز به سرمایه دارد اما مبلغ دقیق ذکر نشده',
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
     * @param array{zero_keywords:array,unknown_keywords:array,allow_zero:bool} $options
     * @return array{status:string,value:?int,note:?string}
     */
    protected static function parse_money_text( $raw, $options ) {
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

        if ( self::contains_zero_keyword( $text, $options['zero_keywords'] ) ) {
            $result['status'] = 'zero';
            $result['value']  = 0;
            return $result;
        }

        $numeric = function_exists( 'bkja_parse_numeric_range' )
            ? bkja_parse_numeric_range( $text )
            : array();

        $number = isset( $numeric['value'] ) ? (float) $numeric['value'] : null;
        if ( ! $number || $number <= 0 ) {
            $number = null;
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

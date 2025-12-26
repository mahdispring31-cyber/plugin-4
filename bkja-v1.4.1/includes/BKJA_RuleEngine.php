<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_RuleEngine {
    /**
     * تبدیل اعداد فارسی به انگلیسی برای پردازش ساده‌تر.
     */
    public static function fa_to_en_digits( $s ) {
        $map = array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        );

        return strtr( (string) $s, $map );
    }

    /**
     * نرمال‌سازی حقوق از متن و جزئیات بدون تکیه بر LLM.
     */
    public static function normalize_income_from_context_to_toman( $incomeText, $detailsText ) {
        $incomeText  = is_string( $incomeText ) ? $incomeText : '';
        $detailsText = is_string( $detailsText ) ? $detailsText : '';
        $full        = trim( $incomeText . "\n" . $detailsText );

        if ( '' === $full ) {
            return null;
        }

        if ( ! preg_match( '/(حقوق|حکم|دریافتی|ماهانه|ماهی|دستمزد)/u', $full ) ) {
            return null;
        }

        $normalized_digits = self::fa_to_en_digits( $full );
        $normalized_digits = str_replace( array( ',', '٬' ), '', $normalized_digits );

        if ( ! preg_match( '/([0-9]+(?:\.[0-9]+)?)/', $normalized_digits, $matches ) ) {
            return null;
        }

        $number = (float) $matches[1];
        if ( $number <= 0 ) {
            return null;
        }

        if ( $number <= 100000 ) {
            $toman = (int) round( $number * 1000 );
        } else {
            return null;
        }

        if ( $toman < 1000000 || $toman > 2000000000 ) {
            return null;
        }

        return $toman;
    }

    public static function classify( $normalized_message, $context = array(), $options = array() ) {
        $text      = is_string( $normalized_message ) ? trim( $normalized_message ) : '';
        $context   = is_array( $context ) ? $context : array();
        $options   = is_array( $options ) ? $options : array();
        $has_job   = ! empty( $context['job_title'] ) || ! empty( $context['primary_job_title_id'] );
        $needs_clarification = ! empty( $context['needs_clarification'] ) || ! empty( $context['ambiguous'] );
        $is_followup = ! empty( $options['is_followup'] );

        $type = BKJA_State::TYPE_A;
        if ( $needs_clarification ) {
            $type = BKJA_State::TYPE_B;
        } elseif ( ! $has_job ) {
            $type = BKJA_State::TYPE_C;
        }

        $meta = array(
            'has_job'            => $has_job,
            'needs_clarification'=> $needs_clarification,
            'is_followup'        => $is_followup,
            'category'           => isset( $options['category'] ) ? $options['category'] : '',
            'followup_action'    => isset( $options['followup_action'] ) ? $options['followup_action'] : '',
        );

        return new BKJA_State( $type, $context, $meta );
    }
}

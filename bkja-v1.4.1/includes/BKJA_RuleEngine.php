<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_RuleEngine {
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

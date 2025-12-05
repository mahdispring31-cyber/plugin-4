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

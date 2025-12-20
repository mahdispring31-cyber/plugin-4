<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BKJA_Repair {
    const DEFAULT_BATCH = 75;

    public static function register_ajax_hooks() {
        add_action( 'wp_ajax_bkja_repair_run_batch', array( __CLASS__, 'ajax_run_batch' ) );
        add_action( 'wp_ajax_bkja_repair_download_csv', array( __CLASS__, 'ajax_download_unresolved' ) );
    }

    protected static function verify_permissions( $nonce_action = 'bkja_repair' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
        }

        check_ajax_referer( $nonce_action, 'nonce' );
    }

    public static function ajax_run_batch() {
        self::verify_permissions();

        $offset   = isset( $_POST['offset'] ) ? max( 0, intval( $_POST['offset'] ) ) : 0;
        $limit    = isset( $_POST['limit'] ) ? max( 1, intval( $_POST['limit'] ) ) : self::DEFAULT_BATCH;
        $dry_run  = isset( $_POST['dry_run'] ) && in_array( $_POST['dry_run'], array( '1', 'true', true, 1 ), true );
        $reset    = isset( $_POST['reset'] ) && in_array( $_POST['reset'], array( '1', 'true', true, 1 ), true );

        $result = self::run_batch( $limit, $offset, $dry_run, $reset );

        wp_send_json_success( $result );
    }

    public static function ajax_download_unresolved() {
        self::verify_permissions();

        $path = self::get_unresolved_csv_path();
        if ( ! file_exists( $path ) ) {
            wp_send_json_error( array( 'error' => 'not_found' ), 404 );
        }

        $filename = 'bkja-unresolved.csv';

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );
        exit;
    }

    protected static function run_batch( $limit, $offset, $dry_run = false, $reset_csv = false ) {
        global $wpdb;

        BKJA_Database::ensure_job_title_schema();
        BKJA_Database::ensure_numeric_job_columns();

        $table = $wpdb->prefix . 'bkja_jobs';
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $processed   = 0;
        $updated     = 0;
        $unresolved  = 0;
        $log         = array();
        $stats       = array();

        $repair_dir = self::ensure_repair_dir_writable();
        $can_write_unresolved = (bool) $repair_dir;
        if ( ! $can_write_unresolved ) {
            $log[] = 'مسیر uploads/bkja-repair قابل‌نوشتن نیست. امکان ساخت unresolved.csv وجود ندارد.';
        }

        if ( $reset_csv ) {
            $reset_ok = self::reset_unresolved_csv();
            if ( ! $reset_ok ) {
                $log[] = 'امکان ریست فایل unresolved.csv وجود ندارد (مسیر قابل‌نوشتن نیست).';
            } elseif ( $dry_run ) {
                $log[] = 'Dry-run: فایل unresolved.csv از نو ساخته شد.';
            }
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        foreach ( (array) $rows as $row ) {
            $processed++;
            $result = self::repair_row( $row, $dry_run, $can_write_unresolved );

            if ( ! empty( $result['updated'] ) ) {
                $updated++;
            }

            if ( ! empty( $result['unresolved'] ) ) {
                $unresolved++;
            }

            if ( ! empty( $result['stats'] ) ) {
                foreach ( $result['stats'] as $key => $value ) {
                    if ( ! isset( $stats[ $key ] ) ) {
                        $stats[ $key ] = 0;
                    }
                    $stats[ $key ] += (int) $value;
                }
            }
        }

        $done           = ( $offset + count( $rows ) ) >= $total;
        $next           = $done ? null : ( $offset + $limit );
        $processed_total = min( $total, $offset + $processed );
        $cache_data     = null;

        if ( $done && ! $dry_run ) {
            $cache_data = self::clear_plugin_caches();
        }

        if ( $processed > 0 ) {
            $log[] = sprintf(
                'بازه %d تا %d بررسی شد (به‌روزرسانی: %d | حل‌نشده: %d).',
                $offset + 1,
                $offset + $processed,
                $updated,
                $unresolved
            );
        } else {
            $log[] = 'رکوردی برای پردازش در این مرحله باقی نمانده بود.';
        }

        $summary_lines = self::format_repair_summary( $stats );
        if ( ! empty( $summary_lines ) ) {
            $log = array_merge( $log, $summary_lines );
        }

        return array(
            'processed'        => $processed,
            'updated'          => $updated,
            'unresolved'       => $unresolved,
            'total'            => $total,
            'offset'           => $offset,
            'processed_total'  => $processed_total,
            'next_offset'      => $next,
            'done'             => $done,
            'log'              => $log,
            'stats'            => $stats,
            'cache_cleared'    => $cache_data,
            'download_url'     => self::get_download_url(),
        );
    }

    protected static function repair_row( $row, $dry_run = false, $write_unresolved = false ) {
        global $wpdb;

        $updates    = array();
        $issues     = array();
        $stats      = array();
        $unresolved = false;

        $title_data = self::resolve_job_title( $row );
        $job_title_id = isset( $title_data['job_title_id'] ) ? (int) $title_data['job_title_id'] : 0;
        if ( $job_title_id ) {
            $should_update_job_title = (int) $row->job_title_id !== $job_title_id;
        } else {
            $should_update_job_title = false;
            self::record_unresolved(
                $issues,
                $stats,
                (int) $row->id,
                'job_title_id',
                isset( $title_data['label'] ) ? $title_data['label'] : '',
                null,
                isset( $title_data['reason'] ) ? $title_data['reason'] : 'job_title_missing',
                isset( $title_data['wpdb_error'] ) ? $title_data['wpdb_error'] : '',
                isset( $title_data['last_query'] ) ? $title_data['last_query'] : ''
            );
            $unresolved = true;
        }

        if ( $title_data['category_id'] && (int) $row->category_id !== (int) $title_data['category_id'] ) {
            $updates['category_id'] = (int) $title_data['category_id'];
        }

        $gender = self::normalize_gender( $row->gender );
        if ( $gender !== $row->gender ) {
            $updates['gender'] = $gender;
        }

        $hours = self::normalize_range_value( $row->hours_per_day, 1, 18 );
        if ( $hours !== $row->hours_per_day ) {
            $updates['hours_per_day'] = $hours;
        }

        $days = self::normalize_range_value( $row->days_per_week, 1, 7 );
        if ( $days !== $row->days_per_week ) {
            $updates['days_per_week'] = $days;
        }

        $income_norm = self::normalize_money_set(
            $row->income_toman,
            $row->income_num,
            $row->income,
            $row->income_min_toman,
            $row->income_max_toman,
            'income',
            $row->id
        );
        $investment_norm = self::normalize_money_set(
            $row->investment_toman,
            $row->investment_num,
            $row->investment,
            null,
            null,
            'investment',
            $row->id
        );

        $issues = array_merge( $issues, $income_norm['issues'], $investment_norm['issues'] );
        foreach ( $income_norm['stats'] as $key => $count ) {
            $stats[ $key ] = isset( $stats[ $key ] ) ? $stats[ $key ] + $count : $count;
        }
        foreach ( $investment_norm['stats'] as $key => $count ) {
            $stats[ $key ] = isset( $stats[ $key ] ) ? $stats[ $key ] + $count : $count;
        }

        if ( null !== $income_norm['value'] ) {
            $updates['income_toman'] = $income_norm['value'];
            $updates['income_num']   = $income_norm['value'];
        } elseif ( isset( $row->income_toman ) && $row->income_toman > 0 && $income_norm['mark_null'] ) {
            $updates['income_toman'] = null;
            $updates['income_num']   = null;
        }

        if ( null !== $income_norm['min'] || null !== $income_norm['max'] ) {
            $updates['income_min_toman'] = $income_norm['min'];
            $updates['income_max_toman'] = $income_norm['max'];
        }

        if ( null !== $investment_norm['value'] ) {
            $updates['investment_toman'] = $investment_norm['value'];
            $updates['investment_num']   = $investment_norm['value'];
        } elseif ( isset( $row->investment_toman ) && $row->investment_toman > 0 && $investment_norm['mark_null'] ) {
            $updates['investment_toman'] = null;
            $updates['investment_num']   = null;
        }

        if ( null !== $investment_norm['min'] || null !== $investment_norm['max'] ) {
            $updates['investment_min_toman'] = $investment_norm['min'];
            $updates['investment_max_toman'] = $investment_norm['max'];
        }

        if ( $income_norm['unresolved'] || $investment_norm['unresolved'] ) {
            $unresolved = true;
        }

        $updated = false;
        if ( $should_update_job_title && ! $dry_run ) {
            $title_update = $wpdb->update(
                $wpdb->prefix . 'bkja_jobs',
                array( 'job_title_id' => $job_title_id ),
                array( 'id' => (int) $row->id )
            );
            if ( false === $title_update || 0 === $title_update ) {
                self::record_unresolved(
                    $issues,
                    $stats,
                    (int) $row->id,
                    'job_title_id',
                    isset( $title_data['label'] ) ? $title_data['label'] : '',
                    $job_title_id,
                    'job_title_update_failed',
                    $wpdb->last_error,
                    $wpdb->last_query
                );
                $unresolved = true;
            } else {
                $updated = true;
                if ( ! empty( $title_data['created'] ) ) {
                    $stats['job_title_created'] = isset( $stats['job_title_created'] ) ? $stats['job_title_created'] + 1 : 1;
                }
            }
        }

        if ( ! empty( $updates ) && ! $dry_run ) {
            $result = $wpdb->update(
                $wpdb->prefix . 'bkja_jobs',
                $updates,
                array( 'id' => (int) $row->id )
            );
            if ( false !== $result && $result > 0 ) {
                $updated = true;
            }
        }

        if ( ! empty( $issues ) ) {
            $unresolved = true;
        }

        if ( $unresolved && $write_unresolved ) {
            self::append_unresolved_rows( $issues );
        }

        return array(
            'updated'    => $updated,
            'unresolved' => $unresolved,
            'issues'     => $issues,
            'stats'      => $stats,
        );
    }

    protected static function normalize_gender( $gender ) {
        $gender = is_string( $gender ) ? strtolower( trim( $gender ) ) : '';

        if ( in_array( $gender, array( 'male', 'man', 'm', 'مرد' ), true ) ) {
            return 'male';
        }

        if ( in_array( $gender, array( 'female', 'f', 'woman', 'زن' ), true ) ) {
            return 'female';
        }

        return 'unknown';
    }

    protected static function normalize_range_value( $value, $min, $max ) {
        if ( ! is_numeric( $value ) && function_exists( 'bkja_parse_numeric_range' ) ) {
            $parsed = bkja_parse_numeric_range( (string) $value );
            $value  = isset( $parsed['value'] ) ? $parsed['value'] : null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $value = (int) round( $value );
        if ( $value < $min || $value > $max ) {
            return null;
        }

        return $value;
    }

    protected static function record_unresolved( &$issues, &$stats, $job_id, $field, $raw_value, $normalized_value, $reason, $wpdb_error = '', $last_query = '' ) {
        $issues[] = array(
            'job_id'           => $job_id,
            'field'            => $field,
            'raw_value'        => $raw_value,
            'normalized_value' => $normalized_value,
            'reason'           => $reason,
            'wpdb_error'       => $wpdb_error,
            'last_query'       => $last_query,
        );

        if ( ! isset( $stats[ $reason ] ) ) {
            $stats[ $reason ] = 0;
        }
        $stats[ $reason ]++;
    }

    protected static function format_repair_summary( $stats ) {
        if ( empty( $stats ) ) {
            return array();
        }

        $labels = array(
            'investment_unknown'         => 'سرمایه نامشخص بود',
            'investment_invalid'         => 'سرمایه نامعتبر بود',
            'investment_ambiguous_unit'  => 'واحد سرمایه نامشخص بود',
            'investment_asset_or_non_cash' => 'سرمایه نقدی نبود',
            'income_unknown'             => 'درآمد نامشخص بود',
            'income_invalid'             => 'درآمد نامعتبر بود',
            'income_ambiguous_unit'      => 'واحد درآمد نامشخص بود',
            'income_ambiguous_composite' => 'درآمد ترکیبی/نامشخص بود',
            'job_title_insert_failed'    => 'ثبت عنوان شغل جدید ممکن نشد',
            'job_title_insert_no_id'     => 'ثبت عنوان شغل جدید شناسه برنگرداند',
            'job_title_update_failed'    => 'به‌روزرسانی عنوان شغل با شکست مواجه شد',
            'job_title_missing'          => 'عنوان شغل خالی بود',
            'job_title_category_unknown' => 'دسته‌بندی برای عنوان شغل مشخص نبود',
            'job_title_created'          => 'عنوان شغل جدید ثبت شد (خودکار)',
        );

        $lines = array();
        foreach ( $stats as $reason => $count ) {
            if ( ! $count ) {
                continue;
            }
            $label = isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
            $lines[] = sprintf( '%s: %d مورد', $label, $count );
        }

        return $lines;
    }

    protected static function normalize_job_title_label( $label ) {
        $label = is_string( $label ) ? $label : (string) $label;
        $label = trim( wp_strip_all_tags( $label ) );
        $label = preg_replace( '/[[:cntrl:]]/u', ' ', $label );
        $label = preg_replace( '/[\x{1F000}-\x{1FFFF}]/u', ' ', $label );
        $label = preg_replace( '/\s+/u', ' ', $label );

        if ( function_exists( 'bkja_normalize_fa_text' ) ) {
            $label = bkja_normalize_fa_text( $label );
        }

        return trim( $label );
    }

    protected static function sanitize_job_title_for_insert( $label ) {
        $label = self::normalize_job_title_label( $label );

        if ( '' === $label ) {
            return '';
        }

        if ( function_exists( 'mb_strlen' ) && mb_strlen( $label, 'UTF-8' ) > 191 ) {
            $label = function_exists( 'mb_substr' )
                ? mb_substr( $label, 0, 191, 'UTF-8' )
                : substr( $label, 0, 191 );
            $label = trim( $label );
        }

        return $label;
    }

    protected static function normalize_money_set( $toman, $legacy, $text_value, $min_value, $max_value, $field_label, $job_id ) {
        $issues     = array();
        $stats      = array();
        $unresolved = false;
        $mark_null  = false;
        $allow_zero = ( 'investment' === $field_label );

        $value = self::sanitize_money_value( $toman, $field_label, $allow_zero, true );

        if ( null === $value && $legacy ) {
            $value = self::sanitize_money_value( $legacy, $field_label, $allow_zero, true );
        }

        $raw_text   = is_string( $text_value ) ? $text_value : (string) $text_value;
        $has_text   = '' !== trim( $raw_text );
        $should_parse = $has_text || ( null === $value && ! $legacy );

        if ( $should_parse && class_exists( 'BKJA_Parser' ) ) {
            $parsed = ( 'investment' === $field_label )
                ? BKJA_Parser::parse_investment_to_toman( $raw_text )
                : BKJA_Parser::parse_income_to_toman( $raw_text );

            if ( 'zero' === $parsed['status'] ) {
                $value     = 0;
                $mark_null = false;
                $min_value = null;
                $max_value = null;
            } elseif ( 'ok' === $parsed['status'] ) {
                if ( null === $value ) {
                    $value = self::sanitize_money_value( $parsed['value'], $field_label, $allow_zero, false );
                }
            } elseif ( in_array( $parsed['status'], array( 'unknown', 'invalid', 'ambiguous_unit', 'asset_or_non_cash' ), true ) ) {
                $value      = null;
                $min_value  = null;
                $max_value  = null;
                $mark_null  = true;
                $unresolved = true;
                $reason     = "{$field_label}_{$parsed['status']}";

                if ( 'income' === $field_label && 'ambiguous_unit' === $parsed['status'] && self::is_income_composite_text( $raw_text ) ) {
                    $reason = 'income_ambiguous_composite';
                }

                self::record_unresolved(
                    $issues,
                    $stats,
                    (int) $job_id,
                    $field_label,
                    $raw_text,
                    $parsed['note'],
                    $reason,
                    ''
                );
            }
        }

        $min_value = self::sanitize_money_value( $min_value, "{$field_label}_min", false, false );
        $max_value = self::sanitize_money_value( $max_value, "{$field_label}_max", false, false );

        if ( $min_value && $max_value && $min_value > $max_value ) {
            $tmp       = $min_value;
            $min_value = $max_value;
            $max_value = $tmp;
        }

        if ( null === $value && $min_value && $max_value ) {
            $value = (int) round( ( $min_value + $max_value ) / 2 );
        }

        return array(
            'value'      => $value,
            'min'        => $min_value,
            'max'        => $max_value,
            'issues'     => $issues,
            'stats'      => $stats,
            'unresolved' => $unresolved,
            'mark_null'  => $mark_null,
        );
    }

    protected static function sanitize_money_value( $value, $label, $allow_zero, $assume_million ) {
        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $value = (float) $value;
        if ( 0.0 === $value && $allow_zero ) {
            return 0;
        }

        if ( $value <= 0 ) {
            return null;
        }

        if ( $assume_million && $value < 1000000 ) {
            $value = $value * 1000000;
        }

        if ( $value > 1000000000000 ) {
            return null;
        }

        return (int) round( $value );
    }

    protected static function is_income_composite_text( $text ) {
        $text = is_string( $text ) ? $text : (string) $text;
        $text = trim( $text );
        if ( '' === $text ) {
            return false;
        }

        $keywords = array( '+', 'ترکیب', 'جمع', 'کارمندی', 'آزاد' );
        foreach ( $keywords as $keyword ) {
            if ( false !== mb_stripos( $text, $keyword, 0, 'UTF-8' ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function resolve_job_title( $row ) {
        global $wpdb;

        $category = isset( $row->category_id ) ? (int) $row->category_id : 0;
        $job_id   = isset( $row->job_title_id ) ? (int) $row->job_title_id : 0;
        $label    = '';

        if ( $job_id > 0 ) {
            $exists = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, category_id FROM {$wpdb->prefix}bkja_job_titles WHERE id = %d",
                    $job_id
                )
            );
            if ( $exists ) {
                $resolved_category = $category > 0 ? $category : (int) $exists->category_id;
                return array(
                    'job_title_id' => (int) $exists->id,
                    'category_id'  => $resolved_category,
                    'label'        => isset( $row->title ) ? $row->title : '',
                );
            }
        }

        if ( ! empty( $row->variant_title ) ) {
            $label = $row->variant_title;
        } elseif ( ! empty( $row->title ) ) {
            $label = $row->title;
        }

        $label = self::normalize_job_title_label( $label );
        if ( '' === $label ) {
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'label'        => '',
                'reason'       => 'job_title_missing',
            );
        }

        $normalized_label = function_exists( 'bkja_normalize_fa_text' ) ? bkja_normalize_fa_text( $label ) : $label;
        $variants         = function_exists( 'bkja_generate_title_variants' )
            ? bkja_generate_title_variants( $normalized_label )
            : array( $normalized_label );

        $insert_label = self::sanitize_job_title_for_insert( $label );
        if ( '' === $insert_label ) {
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'label'        => '',
                'reason'       => 'job_title_missing',
            );
        }

        $slug        = sanitize_title( $insert_label );
        $title_table = $wpdb->prefix . 'bkja_job_titles';
        $normalized_column = "TRIM(REPLACE(REPLACE(REPLACE(COALESCE(base_label, label), 'ي', 'ی'), 'ك', 'ک'), '‌', ' '))";

        $found = null;
        foreach ( $variants as $variant ) {
            $found = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, category_id FROM {$title_table} WHERE {$normalized_column} = %s OR slug = %s OR label = %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                    $variant,
                    $slug,
                    $label
                )
            );

            if ( $found && isset( $found->id ) ) {
                break;
            }

            $like = '%' . $wpdb->esc_like( $variant ) . '%';
            $found = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, category_id FROM {$title_table} WHERE {$normalized_column} LIKE %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                    $like
                )
            );

            if ( $found && isset( $found->id ) ) {
                break;
            }
        }

        if ( $found && isset( $found->id ) ) {
            return array(
                'job_title_id' => (int) $found->id,
                'category_id'  => $category > 0 ? $category : (int) $found->category_id,
                'label'        => $label,
            );
        }

        $fallback_category = $category > 0 ? $category : self::get_unknown_category_id();

        if ( $fallback_category <= 0 ) {
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'label'        => $label,
                'reason'       => 'job_title_category_unknown',
            );
        }

        $now       = current_time( 'mysql' );
        $group_key = 'auto:' . md5( $normalized_label );

        $inserted = $wpdb->insert(
            $title_table,
            array(
                'category_id' => $fallback_category,
                'slug'        => $slug,
                'label'       => $insert_label,
                'description' => null,
                'base_label'  => $insert_label,
                'base_slug'   => sanitize_title( $insert_label ),
                'group_key'   => $group_key,
                'is_primary'  => 1,
                'is_visible'  => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array(
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
            )
        );

        if ( false === $inserted ) {
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'label'        => $insert_label,
                'reason'       => 'job_title_insert_failed',
                'wpdb_error'   => $wpdb->last_error,
                'last_query'   => $wpdb->last_query,
            );
        }

        $new_id = (int) $wpdb->insert_id;
        if ( $new_id <= 0 ) {
            return array(
                'job_title_id' => null,
                'category_id'  => $fallback_category,
                'label'        => $insert_label,
                'reason'       => 'job_title_insert_no_id',
            );
        }

        return array(
            'job_title_id' => $new_id,
            'category_id'  => $fallback_category,
            'label'        => $label,
            'created'      => true,
        );
    }

    protected static function get_unknown_category_id() {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_categories';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return 0;
        }

        $name  = 'سایر/نامشخص';
        $cat_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE name = %s LIMIT 1",
                $name
            )
        );

        if ( $cat_id ) {
            return (int) $cat_id;
        }

        $wpdb->insert( $table, array( 'name' => $name ) );

        return (int) $wpdb->insert_id;
    }

    protected static function append_unresolved_rows( $issues ) {
        $path = self::get_unresolved_csv_path();
        if ( '' === $path ) {
            return;
        }
        $fh   = fopen( $path, 'a' );
        if ( ! $fh ) {
            return;
        }
        foreach ( (array) $issues as $issue ) {
            fputcsv(
                $fh,
                array(
                    isset( $issue['job_id'] ) ? $issue['job_id'] : '',
                    isset( $issue['field'] ) ? $issue['field'] : '',
                    isset( $issue['raw_value'] ) ? $issue['raw_value'] : '',
                    isset( $issue['normalized_value'] ) ? $issue['normalized_value'] : '',
                    isset( $issue['reason'] ) ? $issue['reason'] : '',
                    isset( $issue['wpdb_error'] ) ? $issue['wpdb_error'] : '',
                    isset( $issue['last_query'] ) ? $issue['last_query'] : '',
                )
            );
        }

        fclose( $fh );
    }

    protected static function reset_unresolved_csv() {
        $path = self::get_unresolved_csv_path();
        if ( '' === $path ) {
            return false;
        }
        $dir  = dirname( $path );

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $fh = fopen( $path, 'w' );
        if ( ! $fh ) {
            return false;
        }

        fputcsv( $fh, array( 'job_id', 'field', 'raw_value', 'normalized_value', 'reason', 'wpdb_error', 'last_query' ) );
        fclose( $fh );
        return true;
    }

    protected static function get_unresolved_csv_path() {
        $base = self::ensure_repair_dir_writable();
        if ( ! $base ) {
            return '';
        }
        return trailingslashit( $base ) . 'unresolved.csv';
    }

    protected static function get_download_url() {
        $url = add_query_arg(
            array(
                'action' => 'bkja_repair_download_csv',
                'nonce'  => wp_create_nonce( 'bkja_repair' ),
            ),
            admin_url( 'admin-ajax.php' )
        );

        return $url;
    }

    /**
     * Ensure the repair upload directory exists and is writable.
     */
    protected static function ensure_repair_dir_writable() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return false;
        }

        $base = trailingslashit( $uploads['basedir'] ) . 'bkja-repair';
        if ( ! file_exists( $base ) ) {
            if ( ! wp_mkdir_p( $base ) ) {
                return false;
            }
        }

        if ( ! is_writable( $base ) ) {
            return false;
        }

        return $base;
    }

    protected static function clear_plugin_caches() {
        if ( class_exists( 'BKJA_Chat' ) ) {
            if ( method_exists( 'BKJA_Chat', 'clear_all_caches_full' ) ) {
                return BKJA_Chat::clear_all_caches_full();
            }

            return BKJA_Chat::clear_response_cache_prefix();
        }

        return null;
    }
}

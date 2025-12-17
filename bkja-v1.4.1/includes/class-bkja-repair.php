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

        if ( $reset_csv && ! $dry_run ) {
            self::reset_unresolved_csv();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        $processed   = 0;
        $updated     = 0;
        $unresolved  = 0;
        $log         = array();

        foreach ( (array) $rows as $row ) {
            $processed++;
            $result = self::repair_row( $row, $dry_run );

            if ( ! empty( $result['updated'] ) ) {
                $updated++;
            }

            if ( ! empty( $result['unresolved'] ) ) {
                $unresolved++;
            }

            if ( ! empty( $result['messages'] ) ) {
                $log = array_merge( $log, $result['messages'] );
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
            'cache_cleared'    => $cache_data,
            'download_url'     => self::get_download_url(),
        );
    }

    protected static function repair_row( $row, $dry_run = false ) {
        global $wpdb;

        $updates    = array();
        $messages   = array();
        $unresolved = false;

        $title_data = self::resolve_job_title( $row );
        if ( $title_data['job_title_id'] ) {
            $updates['job_title_id'] = $title_data['job_title_id'];
        } else {
            $unresolved = true;
        }

        if ( $title_data['category_id'] && (int) $row->category_id !== (int) $title_data['category_id'] ) {
            $updates['category_id'] = (int) $title_data['category_id'];
        }

        if ( ! empty( $title_data['messages'] ) ) {
            $messages = array_merge( $messages, $title_data['messages'] );
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
            'income'
        );
        $investment_norm = self::normalize_money_set(
            $row->investment_toman,
            $row->investment_num,
            $row->investment,
            null,
            null,
            'investment'
        );

        foreach ( array( $income_norm, $investment_norm ) as $norm ) {
            if ( ! empty( $norm['messages'] ) ) {
                $messages = array_merge( $messages, $norm['messages'] );
            }
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

        if ( $unresolved && ! $dry_run ) {
            self::append_unresolved_row( $row, $messages );
        }

        $updated = false;
        if ( ! empty( $updates ) && ! $dry_run ) {
            $wpdb->update(
                $wpdb->prefix . 'bkja_jobs',
                $updates,
                array( 'id' => (int) $row->id )
            );
            $updated = true;
        }

        return array(
            'updated'    => $updated,
            'unresolved' => $unresolved,
            'messages'   => $messages,
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
        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $value = (int) $value;
        if ( $value < $min || $value > $max ) {
            return null;
        }

        return $value;
    }

    protected static function normalize_money_set( $toman, $legacy, $text_value, $min_value, $max_value, $field_label ) {
        $messages   = array();
        $unresolved = false;
        $mark_null  = false;

        $value = self::sanitize_money_value( $toman, $field_label, $messages );

        if ( null === $value && $legacy ) {
            $value = self::convert_legacy_money( $legacy, $field_label, $messages );
        }

        if ( null === $value && $text_value ) {
            $parsed = bkja_parse_money_to_toman( $text_value );
            if ( isset( $parsed['value_toman'] ) ) {
                $value = self::sanitize_money_value( $parsed['value_toman'], $field_label, $messages );
            }
            if ( isset( $parsed['min_toman'] ) || isset( $parsed['max_toman'] ) ) {
                $min_value = isset( $parsed['min_toman'] ) ? $parsed['min_toman'] : $min_value;
                $max_value = isset( $parsed['max_toman'] ) ? $parsed['max_toman'] : $max_value;
            }
        }

        $min_value = self::sanitize_money_value( $min_value, "{$field_label}_min", $messages );
        $max_value = self::sanitize_money_value( $max_value, "{$field_label}_max", $messages );

        if ( $min_value && $max_value && $min_value > $max_value ) {
            $tmp       = $min_value;
            $min_value = $max_value;
            $max_value = $tmp;
        }

        if ( null === $value && $min_value && $max_value ) {
            $value = (int) round( ( $min_value + $max_value ) / 2 );
        }

        if ( null === $value && ( $toman || $legacy || $text_value ) ) {
            $messages[] = "مقدار {$field_label} نامعتبر بود و در فایل unresolved.csv ثبت شد.";
            $unresolved = true;
            $mark_null  = true;
        }

        return array(
            'value'     => $value,
            'min'       => $min_value,
            'max'       => $max_value,
            'messages'  => $messages,
            'unresolved'=> $unresolved,
            'mark_null' => $mark_null,
        );
    }

    protected static function sanitize_money_value( $value, $label, &$messages ) {
        if ( ! is_numeric( $value ) ) {
            return null;
        }

        $value = (int) $value;
        if ( $value <= 0 ) {
            return null;
        }

        if ( $value < 1000000 ) {
            $messages[] = "{$label} برحسب میلیون تشخیص داده شد و به تومان تبدیل شد.";
            $value = $value * 1000000;
        }

        if ( $value > 1000000000000 ) {
            $messages[] = "{$label} بزرگ‌تر از حد مجاز بود و کنار گذاشته شد.";
            return null;
        }

        return $value;
    }

    protected static function convert_legacy_money( $value, $label, &$messages ) {
        return self::sanitize_money_value( $value, $label, $messages );
    }

    protected static function resolve_job_title( $row ) {
        global $wpdb;

        $messages = array();
        $category = isset( $row->category_id ) ? (int) $row->category_id : 0;
        $job_id   = isset( $row->job_title_id ) ? (int) $row->job_title_id : 0;
        $label    = '';

        if ( $job_id > 0 ) {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}bkja_job_titles WHERE id = %d",
                    $job_id
                )
            );
            if ( $exists ) {
                return array(
                    'job_title_id' => $job_id,
                    'category_id'  => $category,
                    'messages'     => $messages,
                );
            }
            $messages[] = 'شناسه عنوان شغل معتبر نبود و مجدداً تعیین شد.';
        }

        if ( ! empty( $row->variant_title ) ) {
            $label = $row->variant_title;
        } elseif ( ! empty( $row->title ) ) {
            $label = $row->title;
        }

        $label = is_string( $label ) ? trim( $label ) : '';
        if ( '' === $label ) {
            $messages[] = 'عنوان شغل خالی است.';
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'messages'     => $messages,
            );
        }

        $slug        = sanitize_title( $label );
        $title_table = $wpdb->prefix . 'bkja_job_titles';
        $found       = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, category_id FROM {$title_table} WHERE slug = %s OR label = %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                $slug,
                $label
            )
        );

        if ( $found && isset( $found->id ) ) {
            return array(
                'job_title_id' => (int) $found->id,
                'category_id'  => $category > 0 ? $category : (int) $found->category_id,
                'messages'     => $messages,
            );
        }

        if ( $category <= 0 ) {
            $messages[] = 'دسته‌بندی برای ساخت عنوان شغل مشخص نبود.';
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'messages'     => $messages,
            );
        }

        $new_id = BKJA_Database::ensure_job_title_exists( $category, $label );
        if ( $new_id ) {
            $messages[] = 'عنوان شغل جدید ثبت شد.';
            return array(
                'job_title_id' => (int) $new_id,
                'category_id'  => $category,
                'messages'     => $messages,
            );
        }

        $messages[] = 'ثبت عنوان شغل جدید ممکن نشد.';

        return array(
            'job_title_id' => null,
            'category_id'  => $category,
            'messages'     => $messages,
        );
    }

    protected static function append_unresolved_row( $row, $messages = array() ) {
        $path = self::get_unresolved_csv_path();
        $fh   = fopen( $path, 'a' );
        if ( ! $fh ) {
            return;
        }

        fputcsv(
            $fh,
            array(
                $row->id,
                isset( $row->title ) ? $row->title : '',
                isset( $row->variant_title ) ? $row->variant_title : '',
                isset( $row->category_id ) ? $row->category_id : '',
                isset( $row->job_title_id ) ? $row->job_title_id : '',
                implode( ' | ', $messages ),
            )
        );

        fclose( $fh );
    }

    protected static function reset_unresolved_csv() {
        $path = self::get_unresolved_csv_path();
        $dir  = dirname( $path );

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $fh = fopen( $path, 'w' );
        if ( ! $fh ) {
            return;
        }

        fputcsv( $fh, array( 'id', 'title', 'variant_title', 'category_id', 'job_title_id', 'issues' ) );
        fclose( $fh );
    }

    protected static function get_unresolved_csv_path() {
        $uploads = wp_upload_dir();
        $base    = trailingslashit( $uploads['basedir'] ) . 'bkja-repair';
        if ( ! file_exists( $base ) ) {
            wp_mkdir_p( $base );
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

    protected static function clear_plugin_caches() {
        if ( class_exists( 'BKJA_Chat' ) ) {
            return BKJA_Chat::clear_response_cache_prefix();
        }

        return null;
    }
}

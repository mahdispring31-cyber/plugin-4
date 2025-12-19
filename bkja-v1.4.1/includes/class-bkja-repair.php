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
            $raw_text = is_string( $text_value ) ? $text_value : (string) $text_value;
            if ( self::is_invalid_money_text( $raw_text ) ) {
                $mark_null = true;
                $min_value = null;
                $max_value = null;
            } else {
                $parsed = bkja_parse_money_to_toman( $raw_text );
                if ( isset( $parsed['value_toman'] ) ) {
                    $value = self::sanitize_money_value( $parsed['value_toman'], $field_label, $messages );
                }
                if ( isset( $parsed['min_toman'] ) || isset( $parsed['max_toman'] ) ) {
                    $min_value = isset( $parsed['min_toman'] ) ? $parsed['min_toman'] : $min_value;
                    $max_value = isset( $parsed['max_toman'] ) ? $parsed['max_toman'] : $max_value;
                }

                if ( self::is_ambiguous_money_text( $raw_text ) ) {
                    $value      = null;
                    $min_value  = null;
                    $max_value  = null;
                    $unresolved = true;
                    $mark_null  = true;
                    $messages[] = "متن {$field_label} به‌صورت عددی مبهم بود و نیاز به بررسی دارد.";
                }
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

        if ( null === $value && ( $toman || $legacy || $text_value ) && ! self::is_invalid_money_text( $text_value ) ) {
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

    protected static function is_invalid_money_text( $text ) {
        if ( ! is_string( $text ) ) {
            $text = (string) $text;
        }

        $text = trim( $text );
        if ( '' === $text ) {
            return false;
        }

        if ( function_exists( 'bkja_normalize_fa_text' ) ) {
            $text = bkja_normalize_fa_text( $text );
        }

        $needles = array(
            'نامشخص',
            'نیاز ندارد',
            'سرمایه ای ندارد',
            'سرمایه‌ای ندارد',
            'سرمایه ندارد',
            'وابسته',
            'در متن گفته',
            'در متن گفته شده',
            '—',
            '–',
            '---',
            '----',
            '-',
        );

        foreach ( $needles as $needle ) {
            if ( false !== mb_stripos( $text, $needle, 0, 'UTF-8' ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function is_ambiguous_money_text( $text ) {
        if ( ! is_string( $text ) ) {
            $text = (string) $text;
        }

        $text = trim( $text );
        if ( '' === $text ) {
            return false;
        }

        $unit_words = array( 'میلیون', 'میلیارد', 'هزار', 'تومان', 'تومن' );
        foreach ( $unit_words as $word ) {
            if ( false !== mb_stripos( $text, $word, 0, 'UTF-8' ) ) {
                return false;
            }
        }

        $has_digits = preg_match( '/[0-9۰-۹]/u', $text );
        if ( ! $has_digits ) {
            return false;
        }

        if ( preg_match( '/[0-9۰-۹]+\s*[-–—]\s*[0-9۰-۹]+/u', $text ) ) {
            return true;
        }

        if ( preg_match( '/\b(تا|الی|بین)\b/u', $text ) ) {
            return true;
        }

        return false;
    }

    protected static function resolve_job_title( $row ) {
        global $wpdb;

        $messages = array();
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

        $normalized_label = function_exists( 'bkja_normalize_fa_text' ) ? bkja_normalize_fa_text( $label ) : $label;
        $variants         = function_exists( 'bkja_generate_title_variants' )
            ? bkja_generate_title_variants( $normalized_label )
            : array( $normalized_label );

        $slug        = sanitize_title( $label );
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
                'messages'     => $messages,
            );
        }

        $fallback_category = $category > 0 ? $category : self::get_unknown_category_id();

        if ( $fallback_category <= 0 ) {
            $messages[] = 'دسته‌بندی برای ساخت عنوان شغل مشخص نبود.';
            return array(
                'job_title_id' => null,
                'category_id'  => $category,
                'messages'     => $messages,
            );
        }

        $now       = current_time( 'mysql' );
        $group_key = 'auto:' . md5( $normalized_label );

        $wpdb->insert(
            $title_table,
            array(
                'category_id' => $fallback_category,
                'slug'        => $slug,
                'label'       => $label,
                'description' => null,
                'base_label'  => $label,
                'base_slug'   => sanitize_title( $label ),
                'group_key'   => $group_key,
                'is_primary'  => 1,
                'is_visible'  => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            )
        );

        $new_id = (int) $wpdb->insert_id;
        if ( $new_id ) {
            $messages[] = 'عنوان شغل جدید ثبت شد (خودکار).';
            return array(
                'job_title_id' => $new_id,
                'category_id'  => $fallback_category,
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
                isset( $row->job_title_id ) ? $row->job_title_id : '',
                isset( $row->category_id ) ? $row->category_id : '',
                isset( $row->income ) ? $row->income : '',
                isset( $row->investment ) ? $row->investment : '',
                isset( $row->gender ) ? $row->gender : '',
                isset( $row->hours_per_day ) ? $row->hours_per_day : '',
                isset( $row->days_per_week ) ? $row->days_per_week : '',
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

        fputcsv( $fh, array( 'job_id', 'title', 'job_title_id', 'category_id', 'income_raw', 'investment_raw', 'gender_raw', 'hours_raw', 'days_raw', 'issues' ) );
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
            if ( method_exists( 'BKJA_Chat', 'clear_all_caches_full' ) ) {
                return BKJA_Chat::clear_all_caches_full();
            }

            return BKJA_Chat::clear_response_cache_prefix();
        }

        return null;
    }
}

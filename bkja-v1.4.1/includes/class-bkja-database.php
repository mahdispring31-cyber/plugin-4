<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'bkja_parse_numeric_amount' ) ) {
    /**
     * Parse a numeric value from a free-form income/investment string.
     *
     * - Converts Persian digits to English.
     * - Extracts all numeric parts; if a range is present (two numbers), returns their average.
     * - Returns 0 when no numeric value is found.
     *
     * Assumes the unit is «میلیون تومان» and only stores the numeric part.
     */
    function bkja_parse_numeric_amount( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) {
            return 0;
        }

        $english_digits = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
        $latin_digits   = array( '0','1','2','3','4','5','6','7','8','9' );

        $normalized = str_replace( $english_digits, $latin_digits, wp_strip_all_tags( $text ) );
        $normalized = str_replace( array( ',', '٬' ), '', $normalized );
        $normalized = str_replace( array( 'تا', '-' ), ' ', $normalized );

        if ( '' === $normalized ) {
            return 0;
        }

        preg_match_all( '/([0-9]+(?:[\.\/][0-9]+)?)/', $normalized, $matches );

        if ( empty( $matches[1] ) ) {
            return 0;
        }

        $numbers = array();
        foreach ( $matches[1] as $match ) {
            $num = floatval( str_replace( array( '/', '\\' ), '.', $match ) );
            if ( $num > 0 ) {
                $numbers[] = $num;
            }
        }

        if ( empty( $numbers ) ) {
            return 0;
        }

        if ( count( $numbers ) >= 2 ) {
            $value = ( $numbers[0] + $numbers[1] ) / 2;
        } else {
            $value = $numbers[0];
        }

        return (int) round( $value );
    }
}

if ( ! function_exists( 'bkja_normalize_numeric_money_to_toman' ) ) {
    /**
     * Normalize a parsed numeric value into toman based on the strict rules:
     * - If value <= 1000 treat it as «million toman»
     * - If value  > 1000 treat it as raw toman
     * - Explicit million/billion units are honored but guarded against double multipliers
     *
     * @param float     $num        Parsed numeric value.
     * @param int|null  $multiplier Explicit multiplier derived from the text (1e6, 1e9, etc.)
     * @return int|null Normalized toman value or null when invalid.
     */
    function bkja_normalize_numeric_money_to_toman( $num, $multiplier = null ) {
        $num = (float) $num;
        if ( $num <= 0 ) {
            return null;
        }

        // Default / toman path follows strict million-vs-toman rule.
        $apply_strict_rule = function( $value ) {
            return ( $value <= 1000 )
                ? (int) round( $value * 1000000 )
                : (int) round( $value );
        };

        if ( null === $multiplier || 1 === $multiplier ) {
            return $apply_strict_rule( $num );
        }

        // Guard against inputs like "38000000 میلیون" which should be treated as toman.
        if ( $multiplier >= 1000000 && $num > 1000 ) {
            return (int) round( $num );
        }

        return (int) round( $num * $multiplier );
    }
}

if ( ! function_exists( 'bkja_parse_money_to_toman' ) ) {
    /**
     * Parse a free-form money string and convert it to Tomans.
     *
     * - Supports Persian/Arabic digits
     * - Handles ranges like "بین X تا Y" or "X تا Y"
     * - Detects units: میلیارد، میلیون، هزار، تومان/تومن
     * - Strict normalization rule: values <= 1,000 are treated as «million toman», larger values are treated as toman.
     *
     * @param string $raw Raw input value.
     * @return array{value:?int,min:?int,max:?int}
     */
    function bkja_parse_money_to_toman( $raw ) {
        if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
            return array( 'value' => null, 'min' => null, 'max' => null );
        }

        $normalized = wp_strip_all_tags( $raw );
        $normalized = trim( preg_replace( '/\s+/u', ' ', $normalized ) );

        $persian_digits = array( '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' );
        $latin_digits   = array( '0','1','2','3','4','5','6','7','8','9' );
        $normalized     = str_replace( $persian_digits, $latin_digits, $normalized );
        $normalized     = str_replace( array( '٬', ',' ), '', $normalized );

        $lower   = mb_strtolower( $normalized );
        $has_mld = ( false !== strpos( $lower, 'میلیارد' ) ) || ( false !== strpos( $lower, 'ميليارد' ) );
        $has_mil = ( false !== strpos( $lower, 'میلیون' ) ) || ( false !== strpos( $lower, 'ميليون' ) );
        $has_th  = ( false !== strpos( $lower, 'هزار' ) );
        $has_to  = ( false !== strpos( $lower, 'تومان' ) ) || ( false !== strpos( $lower, 'تومن' ) );

        $multiplier = null;
        if ( $has_mld ) {
            $multiplier = 1000000000;
        } elseif ( $has_mil ) {
            $multiplier = 1000000;
        } elseif ( $has_th ) {
            $multiplier = 1000;
        } elseif ( $has_to ) {
            $multiplier = 1;
        }

        preg_match_all( '/([0-9]+(?:[\.\/][0-9]+)?)/', $normalized, $matches );
        $numbers = array();
        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $match ) {
                $numbers[] = floatval( str_replace( array( '/', '\\' ), '.', $match ) );
            }
        }

        if ( empty( $numbers ) ) {
            return array( 'value' => null, 'min' => null, 'max' => null );
        }

        if ( null === $multiplier ) {
            $multiplier = ( $numbers[0] >= 1000000 ) ? 1 : 1000000;
        }

        $amounts = array();
        foreach ( $numbers as $num ) {
            $normalized = bkja_normalize_numeric_money_to_toman( $num, $multiplier );
            if ( null !== $normalized ) {
                $amounts[] = $normalized;
            }
        }

        $min = isset( $amounts[0] ) ? $amounts[0] : null;
        $max = isset( $amounts[1] ) ? $amounts[1] : null;

        if ( null !== $min && null !== $max && $min > $max ) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        $value = $min;
        if ( null !== $min && null !== $max ) {
            $value = (int) round( ( $min + $max ) / 2 );
        }

        return array(
            'value' => $value,
            'min'   => $min,
            'max'   => $max,
        );
    }
}

if ( ! function_exists( 'bkja_parse_money_to_toman_safe' ) ) {
    /**
     * Safer toman parser with Iran-focused caps and million/billion guards.
     *
     * @param string $raw Raw income/investment text.
     * @return array{value:?int,min:?int,max:?int,invalid:bool}
     */
    function bkja_parse_money_to_toman_safe( $raw ) {
        $base = bkja_parse_money_to_toman( $raw );

        if ( ! is_array( $base ) ) {
            return array( 'value' => null, 'min' => null, 'max' => null, 'invalid' => true );
        }

        $normalized = is_string( $raw ) ? mb_strtolower( trim( wp_strip_all_tags( $raw ) ) ) : '';
        $normalized = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $normalized );
        $has_million = false !== strpos( $normalized, 'میلیون' ) || false !== strpos( $normalized, 'ميليون' );
        $has_billion = false !== strpos( $normalized, 'میلیارد' ) || false !== strpos( $normalized, 'ميليارد' );

        preg_match_all( '/([0-9]+(?:[\.\/][0-9]+)?)/', $normalized, $matches );
        $numbers = array();
        if ( ! empty( $matches[1] ) ) {
            foreach ( $matches[1] as $match ) {
                $numbers[] = floatval( str_replace( array( '/', '\\' ), '.', $match ) );
            }
        }

        $first = isset( $numbers[0] ) ? (float) $numbers[0] : 0.0;

        // Guard against extra multipliers like "20000000 میلیون"
        if ( $has_million && $first >= 10000 ) {
            $base = array( 'value' => (int) round( $first ), 'min' => null, 'max' => null );
        }

        if ( $has_billion && $first >= 1000 ) {
            $base = array( 'value' => (int) round( $first ), 'min' => null, 'max' => null );
        }

        $value = isset( $base['value'] ) ? (int) $base['value'] : null;
        $min   = isset( $base['min'] ) ? (int) $base['min'] : null;
        $max   = isset( $base['max'] ) ? (int) $base['max'] : null;

        $invalid = false;
        $hard_min = 1000000; // 1 میلیون تومان
        $hard_max = 1000000000000; // 1 تریلیون تومان

        $check_value = function( $val ) use ( $hard_min, $hard_max, &$invalid ) {
            if ( null === $val ) {
                return null;
            }
            if ( $val > $hard_max ) {
                $invalid = true;
                return null;
            }
            if ( $val < $hard_min ) {
                return null;
            }
            return $val;
        };

        $value = $check_value( $value );
        $min   = $check_value( $min );
        $max   = $check_value( $max );

        if ( $value && $min && $max ) {
            $value = (int) round( ( $min + $max ) / 2 );
        }

        return array(
            'value'   => $value,
            'min'     => $min,
            'max'     => $max,
            'invalid' => $invalid,
        );
    }
}

/**
 * BKJA_Database
 * - مدیریت جداول (activate)
 * - ثبت چت
 * - توابع پایه برای درج شغل (insert_job)
 * - توابع تاریخچه چت
 * - توابع جدید برای دریافت اطلاعات شغل (خلاصه + رکوردها)
 */
class BKJA_Database {

    // ایجاد جدول‌ها (محافظت‌شده، dbDelta idempotent)
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_chats = $wpdb->prefix . 'bkja_chats';
        $sql1 = "CREATE TABLE IF NOT EXISTS {$table_chats} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NULL,
            session_id VARCHAR(191) NULL,
            job_category VARCHAR(120) NULL,
            message LONGTEXT NULL,
            response LONGTEXT NULL,
            meta LONGTEXT NULL,
            status ENUM('active','closed') DEFAULT 'active',
            feedback ENUM('like','dislike','none') DEFAULT 'none',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY session_idx (session_id)
        ) {$charset_collate};";

        $table_jobs = $wpdb->prefix . 'bkja_jobs';
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$table_jobs}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` BIGINT UNSIGNED NOT NULL,
            `job_title_id` BIGINT UNSIGNED NULL,
            `title` VARCHAR(255) NOT NULL,
            `income` VARCHAR(255) DEFAULT NULL,
            `investment` VARCHAR(255) DEFAULT NULL,
            `income_num` BIGINT NULL,
            `investment_num` BIGINT NULL,
            `income_toman` BIGINT NULL,
            `income_toman_canonical` BIGINT NULL,
            `income_min_toman` BIGINT NULL,
            `income_max_toman` BIGINT NULL,
            `investment_toman` BIGINT NULL,
            `investment_toman_canonical` BIGINT NULL,
            `experience_years` TINYINT NULL,
            `employment_type` VARCHAR(50) NULL,
            `hours_per_day` TINYINT NULL,
            `days_per_week` TINYINT NULL,
            `source` VARCHAR(50) NULL,
            `city` VARCHAR(255) DEFAULT NULL,
            `gender` ENUM('male','female','both','unknown') DEFAULT 'unknown',
            `advantages` TEXT DEFAULT NULL,
            `disadvantages` TEXT DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX (`category_id`),
            INDEX (`job_title_id`),
            INDEX (`gender`)
        ) {$charset_collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );

        $table_feedback = $wpdb->prefix . 'bkja_feedback';
        $sql3 = "CREATE TABLE IF NOT EXISTS {$table_feedback} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            message TEXT NULL,
            response LONGTEXT NULL,
            vote TINYINT NOT NULL DEFAULT 0,
            tags VARCHAR(255) NULL,
            comment TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_idx (session_id),
            KEY user_idx (user_id),
            KEY vote_idx (vote)
        ) {$charset_collate};";

        dbDelta( $sql3 );

        self::ensure_chats_created_at_default();
        update_option( 'bkja_migrated_chats_created_at_default', 1 );

        self::ensure_numeric_job_columns();
        self::backfill_numeric_fields();
        self::backfill_money_fields();
        update_option( 'bkja_jobs_numeric_fields_migrated', 1 );
        update_option( 'bkja_jobs_extended_fields_migrated', 1 );
        update_option( 'bkja_jobs_money_fields_migrated', 1 );

        // مقدار پیش‌فرض برای تعداد پیام رایگان در روز
        if ( false === get_option( 'bkja_free_messages_per_day' ) ) {
            update_option( 'bkja_free_messages_per_day', 5 );
        }

        if ( false === get_option( 'bkja_enable_cache', false ) ) {
            update_option( 'bkja_enable_cache', '1' );
        }

        if ( false === get_option( 'bkja_enable_quick_actions', false ) ) {
            update_option( 'bkja_enable_quick_actions', '0' );
        }

        if ( false === get_option( 'bkja_enable_feedback', false ) ) {
            update_option( 'bkja_enable_feedback', '0' );
        }
    }

    /**
     * Ensure chat table exists so guest message counting/enforcement works.
     */
    public static function ensure_chats_table_exists() {
        global $wpdb;

        $table   = $wpdb->prefix . 'bkja_chats';
        $charset = $wpdb->get_charset_collate();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists === $table ) {
            return true;
        }

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NULL,
            session_id VARCHAR(191) NULL,
            job_category VARCHAR(120) NULL,
            message LONGTEXT NULL,
            response LONGTEXT NULL,
            meta LONGTEXT NULL,
            status ENUM('active','closed') DEFAULT 'active',
            feedback ENUM('like','dislike','none') DEFAULT 'none',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_idx (user_id),
            KEY session_idx (session_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $exists_after = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        return $exists_after === $table;
    }

    public static function deactivate(){}

    /**
     * insert_chat
     * ذخیره یک پیام/پاسخ در جدول چت‌ها
     */
    public static function insert_chat( $data = array() ){
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';

        if ( ! self::ensure_chats_table_exists() ) {
            return 0;
        }

        $defaults = array(
            'user_id'      => null,
            'session_id'   => '',
            'job_category' => '',
            'message'      => '',
            'response'     => null,
            'meta'         => null,
            'status'       => 'active',
            'feedback'     => 'none',
            'created_at'   => current_time( 'mysql', true ),
        );
        $row = wp_parse_args( $data, $defaults );

        if ( empty( $row['created_at'] ) || '0000-00-00 00:00:00' === $row['created_at'] ) {
            $row['created_at'] = current_time( 'mysql', true );
        } else {
            $row['created_at'] = sanitize_text_field( $row['created_at'] );
        }

        $row = array_map( function( $value ) {
            return is_string( $value ) ? wp_slash( $value ) : $value;
        }, $row ); // محافظت از داده‌ها
        $wpdb->insert( $table, $row );
        return $wpdb->insert_id;
    }

    /**
     * Update chatbot response for an existing chat row.
     */
    public static function update_chat_response( $id, $response, $meta_json = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';

        $set_clauses = array();
        $params      = array();

        if ( is_null( $response ) ) {
            $set_clauses[] = 'response = NULL';
        } else {
            $sanitized_response = wp_kses_post( $response );
            $set_clauses[]      = 'response = %s';
            $params[]           = wp_slash( $sanitized_response );
        }

        if ( null !== $meta_json ) {
            if ( '' === $meta_json ) {
                $set_clauses[] = 'meta = NULL';
            } else {
                $set_clauses[] = 'meta = %s';
                $params[]      = wp_slash( $meta_json );
            }
        }

        if ( empty( $set_clauses ) ) {
            return;
        }

        $params[] = (int) $id;

        $sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $set_clauses ) . ' WHERE id = %d';
        $prepared = $wpdb->prepare( $sql, $params );

        if ( $prepared ) {
            $wpdb->query( $prepared );
        }
    }

    /**
     * شمارش پیام‌های کاربر مهمان در بازه مشخص
     */
    public static function count_guest_messages( $session_id, $max_age_seconds = DAY_IN_SECONDS ) {
        if ( empty( $session_id ) ) {
            return 0;
        }

        if ( ! self::ensure_chats_table_exists() ) {
            // Without the table we cannot reliably count, so treat as over-limit to avoid unlimited usage.
            return PHP_INT_MAX;
        }

        global $wpdb;
        $table           = $wpdb->prefix . 'bkja_chats';
        $session_id      = sanitize_text_field( $session_id );
        $max_age_seconds = (int) $max_age_seconds;

        $sql    = "SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND message IS NOT NULL AND message <> ''";
        $params = array( $session_id );

        if ( $max_age_seconds > 0 ) {
            $timestamp_gmt = current_time( 'timestamp', true );
            $threshold     = gmdate( 'Y-m-d H:i:s', max( 0, $timestamp_gmt - $max_age_seconds ) );
            $sql          .= " AND (created_at IS NULL OR created_at = '0000-00-00 00:00:00' OR created_at >= %s)";
            $params[]      = $threshold;
        }

        $prepared = $wpdb->prepare( $sql, $params );

        if ( false === $prepared ) {
            return 0;
        }

        return (int) $wpdb->get_var( $prepared );
    }

    /**
     * Ensure the bkja_chats.created_at column has a default value and backfill empty timestamps.
     */
    public static function ensure_chats_created_at_default() {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_chats';

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $table_exists !== $table ) {
            return;
        }

        $column = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', 'created_at' ) );

        if ( ! $column ) {
            return;
        }

        $default = isset( $column->Default ) ? strtoupper( (string) $column->Default ) : '';
        if ( 'CURRENT_TIMESTAMP' !== $default && 'CURRENT_TIMESTAMP()' !== $default ) {
            $wpdb->query( "ALTER TABLE {$table} MODIFY created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP" );
        }

        $wpdb->query( "UPDATE {$table} SET created_at = IF(created_at IS NULL OR created_at = '0000-00-00 00:00:00', NOW(), created_at)" );
    }

    /**
     * Run the created_at migration once after updates when activation hook is not triggered.
     */
    public static function maybe_migrate_chat_created_at_default() {
        if ( get_option( 'bkja_migrated_chats_created_at_default' ) ) {
            return;
        }

        self::ensure_chats_created_at_default();
        update_option( 'bkja_migrated_chats_created_at_default', 1 );
    }

    /**
     * Ensure numeric income/investment columns exist on bkja_jobs and backfill once.
     */
    public static function maybe_migrate_numeric_job_fields() {
        self::ensure_numeric_job_columns();

        $needs_backfill = ( ! get_option( 'bkja_jobs_numeric_fields_migrated' ) ) || ( ! get_option( 'bkja_jobs_extended_fields_migrated' ) );
        $needs_money    = ( ! get_option( 'bkja_jobs_money_fields_migrated' ) );

        if ( $needs_backfill ) {
            self::backfill_numeric_fields();
            update_option( 'bkja_jobs_numeric_fields_migrated', 1 );
            update_option( 'bkja_jobs_extended_fields_migrated', 1 );
        }

        if ( $needs_money ) {
            self::backfill_money_fields();
            update_option( 'bkja_jobs_money_fields_migrated', 1 );
        }
    }

    /**
     * Add numeric columns for income/investment and extended job metadata if missing (idempotent for older installs).
     */
    public static function ensure_numeric_job_columns() {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_jobs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        $columns = $wpdb->get_col( "DESC {$table}", 0 );
        $add_column = function( $column, $definition, $after = null ) use ( $wpdb, $table, $columns ) {
            if ( in_array( $column, $columns, true ) ) {
                return;
            }

            $after_clause = $after ? " AFTER {$after}" : '';
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}{$after_clause}" );
        };

        $add_index = function( $index, $column ) use ( $wpdb, $table ) {
            $existing_indexes = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
                    $table,
                    $index
                )
            );

            if ( $existing_indexes > 0 ) {
                return;
            }

            $wpdb->query( "ALTER TABLE {$table} ADD INDEX {$index} ({$column})" );
        };

        $add_column( 'income_num', 'BIGINT NULL', 'investment' );
        $add_column( 'investment_num', 'BIGINT NULL', 'income_num' );
        $add_column( 'income_toman', 'BIGINT NULL', 'investment_num' );
        $add_column( 'income_toman_canonical', 'BIGINT NULL', 'income_toman' );
        $add_column( 'income_min_toman', 'BIGINT NULL', 'income_toman_canonical' );
        $add_column( 'income_max_toman', 'BIGINT NULL', 'income_min_toman' );
        $add_column( 'investment_toman', 'BIGINT NULL', 'income_max_toman' );
        $add_column( 'investment_toman_canonical', 'BIGINT NULL', 'investment_toman' );
        $add_column( 'experience_years', 'TINYINT NULL', 'investment_num' );
        $add_column( 'employment_type', 'VARCHAR(50) NULL', 'experience_years' );
        $add_column( 'hours_per_day', 'TINYINT NULL', 'employment_type' );
        $add_column( 'days_per_week', 'TINYINT NULL', 'hours_per_day' );
        $add_column( 'source', 'VARCHAR(50) NULL', 'days_per_week' );
        $add_column( 'job_title_id', 'BIGINT UNSIGNED NULL', 'category_id' );

        $gender_column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'gender'" );
        if ( $gender_column && isset( $gender_column->Type ) && false === strpos( $gender_column->Type, 'unknown' ) ) {
            $wpdb->query( "ALTER TABLE {$table} MODIFY gender ENUM('male','female','both','unknown') DEFAULT 'unknown'" );
        }

        $add_index( 'job_title_id', 'job_title_id' );
    }

    /**
     * Normalize numeric income/investment fields to canonical toman values.
     */
    public static function normalize_numeric_to_canonical_toman( $numeric_value ) {
        if ( ! is_numeric( $numeric_value ) ) {
            return null;
        }

        $value = (int) $numeric_value;

        if ( $value >= 1 && $value <= 1000 ) {
            return (int) ( $value * 1000000 );
        }

        if ( $value >= 1001 && $value <= 1000000000000 ) {
            return $value;
        }

        return null;
    }

    /**
     * Backfill numeric columns from existing textual income/investment values.
     */
    public static function backfill_numeric_fields( $limit = 500 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_jobs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        $limit = absint( $limit );
        if ( $limit <= 0 ) {
            $limit = 500;
        }

        $max_batches = 20;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, income, investment FROM {$table} WHERE income_num IS NULL OR income_num = 0 OR investment_num IS NULL OR investment_num = 0 ORDER BY id ASC LIMIT %d",
                    $limit
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $income_num     = bkja_parse_numeric_amount( $row->income );
                $investment_num = bkja_parse_numeric_amount( $row->investment );

                $data  = array(
                    'income_num'     => $income_num,
                    'investment_num' => $investment_num,
                );
                $where = array( 'id' => (int) $row->id );

                $wpdb->update( $table, $data, $where );
            }

            $max_batches--;
        } while ( $max_batches > 0 );
    }

    /**
     * Backfill canonical money columns (in Toman) from textual income/investment values.
     */
    public static function backfill_money_fields( $limit = 300 ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'bkja_jobs';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return;
        }

        self::ensure_numeric_job_columns();

        $limit = absint( $limit );
        if ( $limit <= 0 ) {
            $limit = 300;
        }

        $max_batches = 25;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, income, investment, income_num, investment_num, income_toman, income_toman_canonical, investment_toman, investment_toman_canonical, income_min_toman, income_max_toman
                     FROM {$table}
                     WHERE (income_toman_canonical IS NULL OR income_toman_canonical <= 0 OR income_toman_canonical > 1000000000000)
                        OR (income_toman IS NULL OR income_toman <= 0 OR income_toman > 1000000000000)
                        OR (investment_toman IS NULL OR investment_toman < 0 OR investment_toman > 1000000000000)
                        OR (investment_toman_canonical IS NULL OR investment_toman_canonical < 0 OR investment_toman_canonical > 1000000000000)
                     ORDER BY id ASC
                     LIMIT %d",
                    $limit
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $updates = array();

                $income_canonical = self::normalize_numeric_to_canonical_toman( $row->income_num );
                if ( null === $income_canonical ) {
                    $income = bkja_parse_money_to_toman_safe( $row->income );
                    if ( ! $income['invalid'] && isset( $income['value'] ) ) {
                        $income_canonical = $income['value'];
                        $updates['income_min_toman'] = ( isset( $income['min'] ) ) ? $income['min'] : null;
                        $updates['income_max_toman'] = ( isset( $income['max'] ) ) ? $income['max'] : null;
                    }
                }

                if ( $income_canonical && $income_canonical > 0 && $income_canonical <= 1000000000000 ) {
                    $updates['income_toman_canonical'] = $income_canonical;
                    if ( empty( $row->income_toman ) || $row->income_toman > 1000000000000 ) {
                        $updates['income_toman'] = $income_canonical;
                    }
                    if ( empty( $updates['income_min_toman'] ) ) {
                        $updates['income_min_toman'] = null;
                    }
                    if ( empty( $updates['income_max_toman'] ) ) {
                        $updates['income_max_toman'] = null;
                    }
                } else {
                    $updates['income_toman']            = null;
                    $updates['income_toman_canonical']  = null;
                    $updates['income_min_toman']        = null;
                    $updates['income_max_toman']        = null;
                }

                $investment_canonical = self::normalize_numeric_to_canonical_toman( $row->investment_num );
                if ( null === $investment_canonical ) {
                    $investment = bkja_parse_money_to_toman( $row->investment );
                    if ( isset( $investment['value'] ) && $investment['value'] >= 0 && $investment['value'] < 1000000000000 ) {
                        $investment_canonical = $investment['value'];
                    }
                }

                if ( null !== $investment_canonical && $investment_canonical >= 0 && $investment_canonical <= 1000000000000 ) {
                    $updates['investment_toman'] = $investment_canonical;
                    $updates['investment_toman_canonical'] = $investment_canonical;
                } elseif ( $row->investment_toman && $row->investment_toman > 1000000000000 ) {
                    $updates['investment_toman'] = null;
                    $updates['investment_toman_canonical'] = null;
                }

                if ( ! empty( $updates ) ) {
                    $wpdb->update( $table, $updates, array( 'id' => (int) $row->id ) );
                }
            }

            $max_batches--;
        } while ( $max_batches > 0 );
    }

    /**
     * insert_job
     * درج یک رکورد شغلی (هر رکورد متعلق به یک کاربر/مشاهده است)
     */
    public static function insert_job( $data = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        self::ensure_numeric_job_columns();

        $income_num_input     = isset( $data['income_num'] ) ? intval( $data['income_num'] ) : null;
        $investment_num_input = isset( $data['investment_num'] ) ? intval( $data['investment_num'] ) : null;
        $experience_years     = isset( $data['experience_years'] ) ? intval( $data['experience_years'] ) : null;
        $hours_per_day        = isset( $data['hours_per_day'] ) ? intval( $data['hours_per_day'] ) : null;
        $days_per_week        = isset( $data['days_per_week'] ) ? intval( $data['days_per_week'] ) : null;

        $experience_years = ( $experience_years && $experience_years > 0 ) ? $experience_years : null;
        $hours_per_day    = ( $hours_per_day && $hours_per_day >= 1 && $hours_per_day <= 18 ) ? $hours_per_day : null;
        $days_per_week    = ( $days_per_week && $days_per_week >= 1 && $days_per_week <= 7 ) ? $days_per_week : null;

        $row = [
            'category_id'        => isset( $data['category_id'] ) ? sanitize_text_field( $data['category_id'] ) : 0,
            'job_title_id'       => isset( $data['job_title_id'] ) ? intval( $data['job_title_id'] ) : null,
            'title'              => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
            'income'             => isset( $data['income'] ) ? sanitize_text_field( $data['income'] ) : '',
            'investment'         => isset( $data['investment'] ) ? sanitize_text_field( $data['investment'] ) : '',
            'income_num'         => 0,
            'investment_num'     => 0,
            'income_toman'       => null,
            'income_toman_canonical' => null,
            'income_min_toman'   => null,
            'income_max_toman'   => null,
            'investment_toman'   => null,
            'investment_toman_canonical' => null,
            'experience_years'   => $experience_years,
            'employment_type'    => isset( $data['employment_type'] ) ? sanitize_text_field( $data['employment_type'] ) : null,
            'hours_per_day'      => $hours_per_day,
            'days_per_week'      => $days_per_week,
            'source'             => isset( $data['source'] ) ? sanitize_text_field( $data['source'] ) : null,
            'city'               => isset( $data['city'] ) ? sanitize_text_field( $data['city'] ) : '',
            'gender'             => isset( $data['gender'] ) ? sanitize_text_field( $data['gender'] ) : 'unknown',
            'advantages'         => isset( $data['advantages'] ) ? sanitize_textarea_field( $data['advantages'] ) : '',
            'disadvantages'      => isset( $data['disadvantages'] ) ? sanitize_textarea_field( $data['disadvantages'] ) : '',
            'details'            => isset( $data['details'] ) ? sanitize_textarea_field( $data['details'] ) : '',
        ];

        $income_money           = bkja_parse_money_to_toman_safe( $row['income'] );
        $investment_money       = bkja_parse_money_to_toman( $row['investment'] );

        $row['income_toman']            = ( isset( $income_money['value'] ) ) ? $income_money['value'] : null;
        $row['income_toman_canonical']  = $row['income_toman'];
        $row['income_min_toman']        = ( isset( $income_money['min'] ) ) ? $income_money['min'] : null;
        $row['income_max_toman']        = ( isset( $income_money['max'] ) ) ? $income_money['max'] : null;
        $row['investment_toman']   = ( isset( $investment_money['value'] ) && $investment_money['value'] > 0 ) ? $investment_money['value'] : null;
        $row['investment_toman_canonical'] = $row['investment_toman'];

        if ( empty( $row['income_toman_canonical'] ) && $income_num_input && $income_num_input > 0 ) {
            $candidate_canonical = self::normalize_numeric_to_canonical_toman( $income_num_input );
            if ( $candidate_canonical ) {
                $row['income_toman_canonical'] = $candidate_canonical;
                if ( empty( $row['income_toman'] ) ) {
                    $row['income_toman'] = $candidate_canonical;
                }
            }
        }

        if ( empty( $row['investment_toman_canonical'] ) && $investment_num_input && $investment_num_input > 0 ) {
            $investment_candidate = self::normalize_numeric_to_canonical_toman( $investment_num_input );
            if ( null !== $investment_candidate ) {
                $row['investment_toman_canonical'] = $investment_candidate;
                if ( empty( $row['investment_toman'] ) ) {
                    $row['investment_toman'] = $investment_candidate;
                }
            }
        }

        $row['income_num']     = ( $income_num_input && $income_num_input > 0 )
            ? $income_num_input
            : ( $row['income_toman'] ? (int) round( $row['income_toman'] / 1000000 ) : bkja_parse_numeric_amount( $row['income'] ) );
        $row['investment_num'] = ( $investment_num_input && $investment_num_input > 0 )
            ? $investment_num_input
            : ( $row['investment_toman'] ? (int) round( $row['investment_toman'] / 1000000 ) : bkja_parse_numeric_amount( $row['investment'] ) );

        if ( isset( $data['created_at'] ) && ! empty( $data['created_at'] ) ) {
            $row['created_at'] = sanitize_text_field( $data['created_at'] );
        }

        $row = array_map( function( $value ) {
            return is_string( $value ) ? wp_slash( $value ) : $value;
        }, $row );

        $wpdb->insert( $table, $row );
        $insert_id = $wpdb->insert_id;

        self::flush_plugin_caches();

        return $insert_id;
    }

    public static function insert_feedback( $data = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_feedback';

        $defaults = array(
            'session_id' => '',
            'user_id'    => 0,
            'message'    => '',
            'response'   => null,
            'vote'       => 0,
            'tags'       => '',
            'comment'    => '',
            'created_at' => current_time( 'mysql' ),
        );

        $row = wp_parse_args( $data, $defaults );

        $row['session_id'] = sanitize_text_field( $row['session_id'] );
        $row['user_id']    = (int) $row['user_id'];
        $row['message']    = sanitize_textarea_field( $row['message'] );
        $row['response']   = is_null( $row['response'] ) ? null : wp_kses_post( $row['response'] );
        $row['vote']       = (int) $row['vote'];
        $row['tags']       = sanitize_text_field( $row['tags'] );
        $row['comment']    = sanitize_textarea_field( $row['comment'] );
        $row['created_at'] = sanitize_text_field( $row['created_at'] );

        $row = array_map( function( $value ) {
            return is_string( $value ) ? wp_slash( $value ) : $value;
        }, $row );

        $wpdb->insert( $table, $row );

        return $wpdb->insert_id;
    }

    public static function get_latest_feedback( $normalized_message, $session_id = '', $user_id = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bkja_feedback';

        $where  = array();
        $params = array();

        if ( $user_id ) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $user_id;
        }

        if ( $session_id ) {
            $where[]  = 'session_id = %s';
            $params[] = $session_id;
        }

        if ( $normalized_message ) {
            $where[]  = 'message = %s';
            $params[] = $normalized_message;
        }

        if ( empty( $where ) ) {
            return null;
        }

        $where_sql = implode( ' AND ', $where );
        $sql       = "SELECT id, vote, tags, comment, created_at FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT 1";

        return $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
    }

    public static function ensure_feedback_table() {
        global $wpdb;

        $table  = $wpdb->prefix . 'bkja_feedback';
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

        if ( $exists !== $table ) {
            self::activate();
        }
    }

    /**
     * تبدیل رشته به عدد صحیح (تومان) - فقط ارقام را نگه می‌دارد و اعداد فارسی را انگلیسی می‌کند
     */
    private static function bkja_parse_number($value) {
        if(!$value) return '';
        // تبدیل اعداد فارسی به انگلیسی
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($persian, $english, $value);
        // حذف هر چیزی غیر از رقم
        $value = preg_replace('/[^0-9]/', '', $value);
        return $value;
    }

    /**
     * get_user_history
     */
    public static function get_user_history( $user_id, $limit = 200 ){
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_id, job_category, message, response, created_at 
             FROM {$table} 
             WHERE user_id = %d 
             ORDER BY created_at DESC LIMIT %d",
            $user_id, (int)$limit
        ) );
    }

    /**
     * get_history_by_session
     */
    public static function get_history_by_session( $session_id, $limit = 200 ){
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_id, job_category, message, response, created_at 
             FROM {$table} 
             WHERE session_id = %s 
             ORDER BY created_at DESC LIMIT %d",
            $session_id, (int)$limit
        ) );
    }

    /**
     * get_recent_conversation
     * بر اساس شناسه جلسه یا کاربر، آخرین پیام‌ها را برمی‌گرداند (به ترتیب زمانی)
     */
    public static function get_recent_conversation( $session_id = '', $user_id = 0, $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_chats';

        $where  = '';
        $params = array();
        if ( $user_id ) {
            $where    = 'user_id = %d';
            $params[] = (int) $user_id;
        } elseif ( ! empty( $session_id ) ) {
            $where    = 'session_id = %s';
            $params[] = $session_id;
        } else {
            return array();
        }

        $params[] = (int) $limit;

        $sql = "SELECT message, response, created_at, id FROM {$table}"
             . " WHERE {$where}"
             . " AND ((message IS NOT NULL AND message <> '')"
             . "      OR (response IS NOT NULL AND response <> ''))"
             . " ORDER BY created_at DESC, id DESC LIMIT %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        if ( empty( $rows ) ) {
            return array();
        }

        $history = array();
        foreach ( array_reverse( $rows ) as $row ) {
            if ( ! empty( $row->message ) ) {
                $history[] = array(
                    'role'    => 'user',
                    'content' => $row->message,
                );
            }

            if ( ! empty( $row->response ) ) {
                $history[] = array(
                    'role'    => 'assistant',
                    'content' => $row->response,
                );
            }
        }

        return $history;
    }

    /**
     * helper: get_category_id_by_name
     */
    public static function get_category_id_by_name($name) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_categories';
        return $wpdb->get_var( $wpdb->prepare("SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name) );
    }

    /**
     * جدید: خلاصه شغل (میانگین و ترکیب داده‌ها)
     */
    public static function get_job_summary($job_title) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        self::ensure_numeric_job_columns();

        $window_months = 24;
        $where_clause  = "title = %s AND created_at >= DATE_SUB(NOW(), INTERVAL {$window_months} MONTH)";

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT income_toman_canonical, income_toman, investment_toman, investment_toman_canonical, hours_per_day, days_per_week, created_at FROM {$table} WHERE {$where_clause}",
                $job_title
            )
        );

        if ( null === $rows ) {
            return null;
        }

        $total_reports = count( $rows );
        $latest_at     = null;
        $incomes       = array();
        $investments   = array();

        $hours_per_day    = array();
        $days_per_week    = array();

        foreach ( $rows as $row ) {
            $income_canonical = isset( $row->income_toman_canonical ) ? (int) $row->income_toman_canonical : null;
            if ( $income_canonical && $income_canonical >= 1000000 && $income_canonical <= 1000000000000 ) {
                $incomes[] = $income_canonical;
            }

            $investment_canonical = isset( $row->investment_toman_canonical ) ? (int) $row->investment_toman_canonical : null;
            if ( $investment_canonical !== null && $investment_canonical >= 0 && $investment_canonical < 1000000000000 ) {
                $investments[] = $investment_canonical;
            }

            if ( isset( $row->hours_per_day ) && $row->hours_per_day >= 1 && $row->hours_per_day <= 18 ) {
                $hours_per_day[] = (int) $row->hours_per_day;
            }

            if ( isset( $row->days_per_week ) && $row->days_per_week >= 1 && $row->days_per_week <= 7 ) {
                $days_per_week[] = (int) $row->days_per_week;
            }

            if ( empty( $latest_at ) || $row->created_at > $latest_at ) {
                $latest_at = $row->created_at;
            }
        }

        $income_stats     = self::prepare_money_stats( $incomes );
        $investment_stats = self::prepare_money_stats( $investments );
        $hours_stats      = self::prepare_simple_average( $hours_per_day );
        $days_stats       = self::prepare_simple_average( $days_per_week );

        $cities = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT city FROM {$table} WHERE {$where_clause} AND city <> '' GROUP BY city ORDER BY COUNT(*) DESC, city ASC LIMIT 5",
                $job_title
            )
        );

        $adv_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT advantages FROM {$table} WHERE {$where_clause} AND advantages IS NOT NULL AND advantages <> '' ORDER BY created_at DESC LIMIT 50",
                $job_title
            )
        );
        $dis_rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT disadvantages FROM {$table} WHERE {$where_clause} AND disadvantages IS NOT NULL AND disadvantages <> '' ORDER BY created_at DESC LIMIT 50",
                $job_title
            )
        );

        $split_terms = function( $items ) {
            $counts = array();
            foreach ( $items as $item ) {
                $parts = preg_split( '/[,،\n\|]+/u', $item );
                foreach ( $parts as $part ) {
                    $term = trim( $part );
                    if ( '' === $term ) {
                        continue;
                    }
                    if ( ! isset( $counts[ $term ] ) ) {
                        $counts[ $term ] = 0;
                    }
                    $counts[ $term ]++;
                }
            }

            if ( empty( $counts ) ) {
                return array();
            }

            arsort( $counts );
            return array_slice( array_keys( $counts ), 0, 5 );
        };

        $advantages    = $split_terms( $adv_rows );
        $disadvantages = $split_terms( $dis_rows );

        $income_debug = null;
        if ( self::is_money_debug_enabled() ) {
            $income_debug = array(
                'used_count'     => $income_stats['count'],
                'raw_count'      => $income_stats['raw_count'],
                'min_canonical'  => $income_stats['used_min'],
                'max_canonical'  => $income_stats['used_max'],
            );
        }

        $avg_income_toman = self::sanitize_money_value( $income_stats['avg'] );
        $min_income_toman = self::sanitize_money_value( $income_stats['min'] );
        $max_income_toman = self::sanitize_money_value( $income_stats['max'] );
        $avg_investment_toman = self::sanitize_money_value( $investment_stats['avg'] );
        $min_investment_toman = self::sanitize_money_value( $investment_stats['min'] );
        $max_investment_toman = self::sanitize_money_value( $investment_stats['max'] );

        $summary = array(
            'job_title'         => $job_title,
            'avg_income'        => $avg_income_toman,
            'min_income'        => $min_income_toman,
            'max_income'        => $max_income_toman,
            'avg_income_toman'  => $avg_income_toman,
            'min_income_toman'  => $min_income_toman,
            'max_income_toman'  => $max_income_toman,
            'income_count'      => (int) $income_stats['count'],
            'income_debug'      => $income_debug,
            'avg_investment'    => $avg_investment_toman,
            'min_investment'    => $min_investment_toman,
            'max_investment'    => $max_investment_toman,
            'avg_investment_toman' => $avg_investment_toman,
            'min_investment_toman' => $min_investment_toman,
            'max_investment_toman' => $max_investment_toman,
            'investment_count'  => (int) $investment_stats['count'],
            'count_reports'     => (int) $total_reports,
            'latest_at'         => $latest_at,
            'numeric_reports_used' => array(
                'income'      => (int) $income_stats['count'],
                'investment'  => (int) $investment_stats['count'],
            ),
            'cities'            => $cities,
            'genders'           => null,
            'advantages'        => $advantages,
            'disadvantages'     => $disadvantages,
            'window_months'     => $window_months,
            'avg_hours_per_day' => $hours_stats['avg'],
            'hours_count'       => $hours_stats['count'],
            'avg_days_per_week' => $days_stats['avg'],
            'days_count'        => $days_stats['count'],
        );

        if ( current_user_can( 'manage_options' ) ) {
            $summary['debug'] = array(
                'avg_income_toman_raw'       => $avg_income_toman,
                'avg_income_label'           => bkja_format_toman_as_million( $avg_income_toman ),
                'min_income_toman_raw'       => $min_income_toman,
                'max_income_toman_raw'       => $max_income_toman,
                'avg_investment_toman_raw'   => $avg_investment_toman,
                'avg_investment_label'       => bkja_format_toman_as_million( $avg_investment_toman ),
                'min_investment_toman_raw'   => $min_investment_toman,
                'max_investment_toman_raw'   => $max_investment_toman,
            );
        }

        return $summary;
    }

    private static function prepare_simple_average( $values ) {
        if ( empty( $values ) || ! is_array( $values ) ) {
            return array( 'avg' => null, 'count' => 0 );
        }

        $valid = array();
        foreach ( $values as $val ) {
            if ( is_numeric( $val ) ) {
                $valid[] = (float) $val;
            }
        }

        if ( empty( $valid ) ) {
            return array( 'avg' => null, 'count' => 0 );
        }

        return array(
            'avg'   => array_sum( $valid ) / count( $valid ),
            'count' => count( $valid ),
        );
    }

    private static function prepare_money_stats( $values ) {
        if ( empty( $values ) || ! is_array( $values ) ) {
            return array(
                'avg'   => null,
                'min'   => null,
                'max'   => null,
                'count' => 0,
            );
        }

        $guarded = array_values( array_filter( $values, function( $value ) {
            return is_numeric( $value ) && $value >= 1000000 && $value <= 1000000000000;
        } ) );

        sort( $guarded );
        $filtered = self::filter_money_outliers( $guarded );

        if ( empty( $filtered ) ) {
            $filtered = $guarded;
        }

        $count = count( $filtered );

        return array(
            'avg'       => $count ? array_sum( $filtered ) / $count : null,
            'min'       => $count ? min( $filtered ) : null,
            'max'       => $count ? max( $filtered ) : null,
            'count'     => $count,
            'raw_count' => count( $guarded ),
            'used_min'  => $count ? min( $filtered ) : null,
            'used_max'  => $count ? max( $filtered ) : null,
        );
    }

    private static function sanitize_money_value( $value ) {
        if ( is_numeric( $value ) ) {
            return (int) round( $value );
        }

        if ( is_string( $value ) ) {
            $digits = preg_replace( '/[^0-9]/', '', $value );
            if ( '' !== $digits ) {
                return (int) $digits;
            }
        }

        return null;
    }

    public static function flush_plugin_caches() {
        global $wpdb;

        $report = array(
            'transients_deleted' => 0,
            'options_deleted'    => 0,
            'cache_version'      => null,
        );

        if ( class_exists( 'BKJA_Chat' ) ) {
            $prefixes = array(
                'bkja_cache_',
                'bkja_',
                'bkja_summary_',
                'bkja_answer_',
            );

            if ( defined( 'BKJA_PLUGIN_VERSION' ) ) {
                $prefixes[] = 'bkja_cache_' . BKJA_PLUGIN_VERSION;
                $prefixes[] = 'bkja_summary_' . BKJA_PLUGIN_VERSION;
                $prefixes[] = 'bkja_answer_' . BKJA_PLUGIN_VERSION;
            }

            $prefixes = array_unique( array_filter( $prefixes ) );

            foreach ( $prefixes as $prefix ) {
                $report['transients_deleted'] += (int) BKJA_Chat::flush_cache_prefix( $prefix );
            }

            $report['cache_version'] = BKJA_Chat::bump_cache_version();
        }

        if ( ! empty( $wpdb ) && ! empty( $wpdb->options ) ) {
            $option_prefixes = array( 'bkja_summary_', 'bkja_answer_' );

            if ( defined( 'BKJA_PLUGIN_VERSION' ) ) {
                $option_prefixes[] = 'bkja_summary_' . BKJA_PLUGIN_VERSION;
                $option_prefixes[] = 'bkja_answer_' . BKJA_PLUGIN_VERSION;
            }

            $option_prefixes = array_unique( array_filter( $option_prefixes ) );

            foreach ( $option_prefixes as $prefix ) {
                $like          = $wpdb->esc_like( $prefix ) . '%';
                $deleted_rows  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

                if ( false !== $deleted_rows ) {
                    $report['options_deleted'] += (int) $deleted_rows;
                }
            }
        }

        return $report;
    }

    private static function calculate_percentile( $values, $percentile ) {
        $count = count( $values );
        if ( 0 === $count ) {
            return 0;
        }

        $index = ( $percentile / 100 ) * ( $count - 1 );
        $lower = floor( $index );
        $upper = ceil( $index );

        if ( $lower === $upper ) {
            return $values[ (int) $index ];
        }

        $weight = $index - $lower;
        return $values[ $lower ] * ( 1 - $weight ) + $values[ $upper ] * $weight;
    }

    private static function filter_money_outliers( $values ) {
        $count = count( $values );
        if ( $count < 4 ) {
            return $values;
        }

        sort( $values );
        $q1  = self::calculate_percentile( $values, 25 );
        $q3  = self::calculate_percentile( $values, 75 );
        $iqr = $q3 - $q1;

        if ( $iqr <= 0 ) {
            return $values;
        }

        $lower = $q1 - ( 1.5 * $iqr );
        $upper = $q3 + ( 1.5 * $iqr );

        return array_values( array_filter( $values, function( $value ) use ( $lower, $upper ) {
            return $value >= $lower && $value <= $upper;
        } ) );
    }

    private static function is_money_debug_enabled() {
        if ( ! function_exists( 'current_user_can' ) || ! is_user_logged_in() ) {
            return false;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return isset( $_GET['bkja_debug_money'] ) || '1' === (string) get_option( 'bkja_debug_money', '0' );
    }

    /**
     * جدید: رکوردهای واقعی کاربران برای یک شغل
     */
    public static function get_job_records($job_title, $limit = 5, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, income, investment, income_num, investment_num, income_toman, income_toman_canonical, investment_toman, investment_toman_canonical, income_min_toman, income_max_toman, experience_years, employment_type, hours_per_day, days_per_week, source, city, gender, advantages, disadvantages, details, created_at
                 FROM {$table}
                 WHERE title = %s
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $job_title, $limit, $offset
            )
        );

        $records = array();
        foreach ( $results as $row ) {
                $records[] = array(
                    'id'                     => (int) $row->id,
                    'job_title'              => $row->title,
                    'income'                 => $row->income,
                    'income_num'             => isset( $row->income_num ) ? (int) $row->income_num : 0,
                    'income_toman'           => isset( $row->income_toman ) ? (int) $row->income_toman : null,
                    'income_toman_canonical' => isset( $row->income_toman_canonical ) ? (int) $row->income_toman_canonical : null,
                    'income_min_toman'       => isset( $row->income_min_toman ) ? (int) $row->income_min_toman : null,
                    'income_max_toman'       => isset( $row->income_max_toman ) ? (int) $row->income_max_toman : null,
                    'investment'             => $row->investment,
                    'investment_num'         => isset( $row->investment_num ) ? (int) $row->investment_num : 0,
                    'investment_toman'       => isset( $row->investment_toman ) ? (int) $row->investment_toman : null,
                    'investment_toman_canonical' => isset( $row->investment_toman_canonical ) ? (int) $row->investment_toman_canonical : null,
                    'experience_years'       => isset( $row->experience_years ) ? (int) $row->experience_years : null,
                    'employment_type'        => isset( $row->employment_type ) ? $row->employment_type : null,
                    'employment_type_label'  => isset( $row->employment_type ) ? bkja_get_employment_label( $row->employment_type ) : null,
                    'hours_per_day'          => ( isset( $row->hours_per_day ) && $row->hours_per_day >= 1 && $row->hours_per_day <= 18 ) ? (int) $row->hours_per_day : null,
                    'days_per_week'          => ( isset( $row->days_per_week ) && $row->days_per_week >= 1 && $row->days_per_week <= 7 ) ? (int) $row->days_per_week : null,
                    'source'                 => isset( $row->source ) ? $row->source : null,
                    'city'                   => $row->city,
                    'gender'                 => $row->gender,
                    'gender_label'           => bkja_get_gender_label( $row->gender ),
                    'advantages'             => $row->advantages,
                    'disadvantages'          => $row->disadvantages,
                    'details'                => $row->details,
                    'created_at'             => $row->created_at,
                    'created_at_display'     => bkja_format_job_date( $row->created_at ),
                );
        }
        return $records;
    }

    /**
     * Batch repair helper used by admin UI.
     */
    public static function repair_batch( $offset = 0, $limit = 100, $dry_run = true ) {
        global $wpdb;

        $jobs_table   = $wpdb->prefix . 'bkja_jobs';
        $titles_table = $wpdb->prefix . 'bkja_job_titles';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs_table ) );
        if ( $exists !== $jobs_table ) {
            return array( 'done' => true, 'processed' => 0, 'total' => 0, 'updated' => 0, 'errors' => array(), 'unresolved_count' => 0, 'next_offset' => $offset );
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table}" );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$jobs_table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ) );

        $processed = 0;
        $updated   = 0;
        $errors    = array();
        $unresolved_rows = array();

        $titles_cache = self::load_job_titles_cache( $titles_table );

        foreach ( (array) $rows as $row ) {
            $processed++;
            $updates = array();
            $job_unresolved = false;
            $matched_title = self::resolve_job_title_row( $row, $titles_cache );

            if ( $matched_title ) {
                if ( empty( $row->job_title_id ) || (int) $row->job_title_id !== (int) $matched_title['id'] ) {
                    $updates['job_title_id'] = (int) $matched_title['id'];
                }

                if ( isset( $matched_title['category_id'] ) && (int) $row->category_id !== (int) $matched_title['category_id'] ) {
                    $updates['category_id'] = (int) $matched_title['category_id'];
                }

                if ( empty( $matched_title['group_key'] ) && ! $dry_run ) {
                    $group_key = $matched_title['category_id'] . ':' . $matched_title['base_slug'];
                    $wpdb->update( $titles_table, array( 'group_key' => $group_key ), array( 'id' => (int) $matched_title['id'] ) );
                }

                // Normalize title base/visibility
                $normalized = self::extract_base_label( $matched_title['label'] );
                if ( $normalized['base_label'] && ( $normalized['base_label'] !== $matched_title['base_label'] || $normalized['base_slug'] !== $matched_title['base_slug'] ) && ! $dry_run ) {
                    $wpdb->update( $titles_table, array(
                        'base_label' => $normalized['base_label'],
                        'base_slug'  => $normalized['base_slug'],
                        'label'      => $matched_title['label'] ?: $normalized['base_label'],
                        'is_visible' => $normalized['is_visible'],
                    ), array( 'id' => (int) $matched_title['id'] ) );
                }

                if ( ! $normalized['is_visible'] ) {
                    $job_unresolved = true;
                }
            } else {
                $job_unresolved = true;
            }

            // Income parsing
            $income_money = bkja_parse_money_to_toman_safe( $row->income );
            if ( $income_money['invalid'] ) {
                $updates['income_toman']            = null;
                $updates['income_toman_canonical']  = null;
                $updates['income_min_toman']        = null;
                $updates['income_max_toman']        = null;
                $job_unresolved = true;
            } else {
                if ( isset( $income_money['value'] ) && $income_money['value'] > 0 && (int) $row->income_toman !== (int) $income_money['value'] ) {
                    $updates['income_toman'] = (int) $income_money['value'];
                }
                if ( isset( $income_money['value'] ) && $income_money['value'] > 0 && (int) $row->income_toman_canonical !== (int) $income_money['value'] ) {
                    $updates['income_toman_canonical'] = (int) $income_money['value'];
                }
                if ( isset( $income_money['min'] ) && $income_money['min'] !== null && (int) $row->income_min_toman !== (int) $income_money['min'] ) {
                    $updates['income_min_toman'] = (int) $income_money['min'];
                }
                if ( isset( $income_money['max'] ) && $income_money['max'] !== null && (int) $row->income_max_toman !== (int) $income_money['max'] ) {
                    $updates['income_max_toman'] = (int) $income_money['max'];
                }

                if ( ! isset( $income_money['value'] ) || $income_money['value'] <= 0 ) {
                    if ( $row->income_toman ) {
                        $updates['income_toman'] = null;
                    }
                    if ( $row->income_toman_canonical ) {
                        $updates['income_toman_canonical'] = null;
                    }
                }
            }

            $investment_money = bkja_parse_money_to_toman( $row->investment );
            if ( isset( $investment_money['value'] ) && $investment_money['value'] >= 0 && (int) $row->investment_toman !== (int) $investment_money['value'] ) {
                $updates['investment_toman'] = (int) $investment_money['value'];
            }

            if ( ! empty( $updates ) && ! $dry_run ) {
                $wpdb->update( $jobs_table, $updates, array( 'id' => (int) $row->id ) );
                $updated++;
            } elseif ( ! empty( $updates ) ) {
                $updated++;
            }

            if ( $job_unresolved ) {
                $unresolved_rows[] = array(
                    'id'    => (int) $row->id,
                    'title' => $row->title,
                    'income'=> $row->income,
                );
            }
        }

        $new_offset = $offset + $limit;
        $done       = $new_offset >= $total || empty( $rows );

        if ( ! $dry_run && $done ) {
            self::write_unresolved_csv( $unresolved_rows );
            self::flush_plugin_caches();
        }

        update_option( 'bkja_repair_total', $total );
        update_option( 'bkja_repair_processed', $offset + $processed );
        update_option( 'bkja_repair_updated', $updated );
        update_option( 'bkja_repair_last_run', current_time( 'mysql' ) );
        update_option( 'bkja_repair_unresolved_path', get_option( 'bkja_repair_unresolved_path', '' ) );

        return array(
            'done'             => $done,
            'processed'        => $offset + $processed,
            'total'            => $total,
            'updated'          => $updated,
            'errors'           => $errors,
            'unresolved_count' => count( $unresolved_rows ),
            'next_offset'      => $done ? $total : $new_offset,
        );
    }

    private static function load_job_titles_cache( $titles_table ) {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $titles_table ) );
        if ( $exists !== $titles_table ) {
            return array();
        }

        $rows = $wpdb->get_results( "SELECT id, label, base_label, base_slug, category_id, group_key FROM {$titles_table}" );
        $cache = array();
        foreach ( (array) $rows as $row ) {
            $cache[] = array(
                'id'          => (int) $row->id,
                'label'       => $row->label,
                'base_label'  => $row->base_label,
                'base_slug'   => $row->base_slug,
                'category_id' => (int) $row->category_id,
                'group_key'   => isset( $row->group_key ) ? $row->group_key : '',
                'slug'        => sanitize_title( $row->label ),
            );
        }
        return $cache;
    }

    private static function resolve_job_title_row( $job_row, $titles_cache ) {
        if ( empty( $titles_cache ) ) {
            return null;
        }

        $existing = null;
        if ( ! empty( $job_row->job_title_id ) ) {
            foreach ( $titles_cache as $title_row ) {
                if ( (int) $title_row['id'] === (int) $job_row->job_title_id ) {
                    $existing = $title_row;
                    break;
                }
            }
        }
        if ( $existing ) {
            return $existing;
        }

        $text = ! empty( $job_row->variant_title ) ? $job_row->variant_title : $job_row->title;
        $normalized = self::normalize_job_title_text( $text );
        $slug = sanitize_title( $normalized );

        foreach ( $titles_cache as $title_row ) {
            if ( $slug && ( $slug === $title_row['slug'] || $slug === $title_row['base_slug'] ) ) {
                return $title_row;
            }
            if ( $normalized && ( self::normalize_job_title_text( $title_row['label'] ) === $normalized || self::normalize_job_title_text( $title_row['base_label'] ) === $normalized ) ) {
                return $title_row;
            }
        }

        // Fallback prefix match
        foreach ( $titles_cache as $title_row ) {
            $candidate = self::normalize_job_title_text( $title_row['label'] );
            if ( $candidate && 0 === strpos( $normalized, $candidate ) ) {
                return $title_row;
            }
        }
        return null;
    }

    private static function normalize_job_title_text( $text ) {
        if ( ! is_string( $text ) ) {
            return '';
        }
        $text = wp_strip_all_tags( $text );
        $text = preg_replace( '/[\x{1F300}-\x{1FAFF}]/u', '', $text );
        $text = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $text );
        $text = preg_replace( '/\d+ ?سال/', '', $text );
        $text = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $text );
        $text = preg_replace( '/\s+/u', ' ', $text );
        $stop = array( 'سلام','دوستم','البته','ولی','هر','روزانه','درآمد','سرمایه','خالص','درمیاد','تلفات','ارزش','تهران','کرمان','میشه','هستم','هست','هستمش' );
        $words = array();
        foreach ( explode( ' ', trim( $text ) ) as $w ) {
            if ( '' === $w ) {
                continue;
            }
            if ( in_array( $w, $stop, true ) ) {
                continue;
            }
            if ( is_numeric( $w ) ) {
                continue;
            }
            $words[] = $w;
        }
        $text = implode( ' ', array_slice( $words, 0, 6 ) );
        return mb_strtolower( trim( $text ) );
    }

    private static function extract_base_label( $raw ) {
        $normalized = self::normalize_job_title_text( $raw );
        $words = array_filter( explode( ' ', $normalized ) );
        $base_words = array_slice( $words, 0, 3 );
        $base_label = implode( ' ', $base_words );
        if ( '' === $base_label ) {
            $base_label = 'سایر';
        }
        return array(
            'base_label' => $base_label,
            'base_slug'  => sanitize_title( $base_label ),
            'is_visible' => ( $base_label !== 'سایر' && strlen( $base_label ) >= 2 ) ? 1 : 0,
        );
    }

    private static function write_unresolved_csv( $rows ) {
        if ( empty( $rows ) ) {
            return;
        }
        $upload = wp_upload_dir();
        $dir = trailingslashit( $upload['basedir'] ) . 'bkja_repair';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $file = trailingslashit( $dir ) . 'unresolved-' . time() . '.csv';
        $h = fopen( $file, 'w' );
        if ( ! $h ) {
            return;
        }
        fputcsv( $h, array( 'id', 'title', 'income' ) );
        foreach ( $rows as $row ) {
            fputcsv( $h, array( $row['id'], $row['title'], $row['income'] ) );
        }
        fclose( $h );
        update_option( 'bkja_repair_unresolved_path', $file );
    }
}
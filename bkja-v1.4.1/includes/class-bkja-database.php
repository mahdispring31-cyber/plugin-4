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
            `title` VARCHAR(255) NOT NULL,
            `income` VARCHAR(255) DEFAULT NULL,
            `investment` VARCHAR(255) DEFAULT NULL,
            `income_num` BIGINT NULL,
            `investment_num` BIGINT NULL,
            `experience_years` TINYINT NULL,
            `employment_type` VARCHAR(50) NULL,
            `hours_per_day` TINYINT NULL,
            `days_per_week` TINYINT NULL,
            `source` VARCHAR(50) NULL,
            `city` VARCHAR(255) DEFAULT NULL,
            `gender` ENUM('male','female','both') DEFAULT 'both',
            `advantages` TEXT DEFAULT NULL,
            `disadvantages` TEXT DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX (`category_id`),
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
        update_option( 'bkja_jobs_numeric_fields_migrated', 1 );
        update_option( 'bkja_jobs_extended_fields_migrated', 1 );

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

        $needs_backfill = false;
        if ( ! get_option( 'bkja_jobs_numeric_fields_migrated' ) ) {
            $needs_backfill = true;
        }
        if ( ! get_option( 'bkja_jobs_extended_fields_migrated' ) ) {
            $needs_backfill = true;
        }

        if ( $needs_backfill ) {
            self::backfill_numeric_fields();
            update_option( 'bkja_jobs_numeric_fields_migrated', 1 );
            update_option( 'bkja_jobs_extended_fields_migrated', 1 );
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

        $add_column( 'income_num', 'BIGINT NULL', 'investment' );
        $add_column( 'investment_num', 'BIGINT NULL', 'income_num' );
        $add_column( 'experience_years', 'TINYINT NULL', 'investment_num' );
        $add_column( 'employment_type', 'VARCHAR(50) NULL', 'experience_years' );
        $add_column( 'hours_per_day', 'TINYINT NULL', 'employment_type' );
        $add_column( 'days_per_week', 'TINYINT NULL', 'hours_per_day' );
        $add_column( 'source', 'VARCHAR(50) NULL', 'days_per_week' );
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
        $hours_per_day    = ( $hours_per_day && $hours_per_day > 0 ) ? $hours_per_day : null;
        $days_per_week    = ( $days_per_week && $days_per_week > 0 ) ? $days_per_week : null;

        $row = [
            'category_id'      => isset( $data['category_id'] ) ? sanitize_text_field( $data['category_id'] ) : 0,
            'title'            => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
            'income'           => isset( $data['income'] ) ? sanitize_text_field( $data['income'] ) : '',
            'investment'       => isset( $data['investment'] ) ? sanitize_text_field( $data['investment'] ) : '',
            'income_num'       => 0,
            'investment_num'   => 0,
            'experience_years' => $experience_years,
            'employment_type'  => isset( $data['employment_type'] ) ? sanitize_text_field( $data['employment_type'] ) : null,
            'hours_per_day'    => $hours_per_day,
            'days_per_week'    => $days_per_week,
            'source'           => isset( $data['source'] ) ? sanitize_text_field( $data['source'] ) : null,
            'city'             => isset( $data['city'] ) ? sanitize_text_field( $data['city'] ) : '',
            'gender'           => isset( $data['gender'] ) ? sanitize_text_field( $data['gender'] ) : 'both',
            'advantages'       => isset( $data['advantages'] ) ? sanitize_textarea_field( $data['advantages'] ) : '',
            'disadvantages'    => isset( $data['disadvantages'] ) ? sanitize_textarea_field( $data['disadvantages'] ) : '',
            'details'          => isset( $data['details'] ) ? sanitize_textarea_field( $data['details'] ) : '',
        ];

        $row['income_num']     = ( $income_num_input && $income_num_input > 0 ) ? $income_num_input : bkja_parse_numeric_amount( $row['income'] );
        $row['investment_num'] = ( $investment_num_input && $investment_num_input > 0 ) ? $investment_num_input : bkja_parse_numeric_amount( $row['investment'] );

        if ( isset( $data['created_at'] ) && ! empty( $data['created_at'] ) ) {
            $row['created_at'] = sanitize_text_field( $data['created_at'] );
        }

        $row = array_map( function( $value ) {
            return is_string( $value ) ? wp_slash( $value ) : $value;
        }, $row );

        $wpdb->insert( $table, $row );
        $insert_id = $wpdb->insert_id;

        if ( class_exists( 'BKJA_Chat' ) ) {
            BKJA_Chat::flush_cache_prefix();
        }

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

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) AS total_reports,
                    MAX(created_at) AS latest_at,
                    AVG(CASE WHEN income_num > 0 THEN income_num END) AS avg_income,
                    MIN(CASE WHEN income_num > 0 THEN income_num END) AS min_income,
                    MAX(CASE WHEN income_num > 0 THEN income_num END) AS max_income,
                    SUM(CASE WHEN income_num > 0 THEN 1 ELSE 0 END) AS income_count,
                    AVG(CASE WHEN investment_num > 0 THEN investment_num END) AS avg_investment,
                    MIN(CASE WHEN investment_num > 0 THEN investment_num END) AS min_investment,
                    MAX(CASE WHEN investment_num > 0 THEN investment_num END) AS max_investment,
                    SUM(CASE WHEN investment_num > 0 THEN 1 ELSE 0 END) AS investment_count
                 FROM {$table}
                 WHERE {$where_clause}",
                $job_title
            )
        );

        if ( ! $row ) return null;

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

        return array(
            'job_title'         => $job_title,
            'avg_income'        => $row->avg_income ? round( (float) $row->avg_income, 1 ) : null,
            'min_income'        => $row->min_income ? (float) $row->min_income : null,
            'max_income'        => $row->max_income ? (float) $row->max_income : null,
            'income_count'      => (int) $row->income_count,
            'avg_investment'    => $row->avg_investment ? round( (float) $row->avg_investment, 1 ) : null,
            'min_investment'    => $row->min_investment ? (float) $row->min_investment : null,
            'max_investment'    => $row->max_investment ? (float) $row->max_investment : null,
            'investment_count'  => (int) $row->investment_count,
            'count_reports'     => (int) $row->total_reports,
            'latest_at'         => $row->latest_at,
            'cities'            => $cities,
            'genders'           => null,
            'advantages'        => $advantages,
            'disadvantages'     => $disadvantages,
            'window_months'     => $window_months,
        );
    }

    /**
     * جدید: رکوردهای واقعی کاربران برای یک شغل
     */
    public static function get_job_records($job_title, $limit = 5, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, income, investment, income_num, investment_num, experience_years, employment_type, hours_per_day, days_per_week, source, city, gender, advantages, disadvantages, details, created_at
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
                    'investment'             => $row->investment,
                    'investment_num'         => isset( $row->investment_num ) ? (int) $row->investment_num : 0,
                    'experience_years'       => isset( $row->experience_years ) ? (int) $row->experience_years : null,
                    'employment_type'        => isset( $row->employment_type ) ? $row->employment_type : null,
                    'employment_type_label'  => isset( $row->employment_type ) ? bkja_get_employment_label( $row->employment_type ) : null,
                    'hours_per_day'          => isset( $row->hours_per_day ) ? (int) $row->hours_per_day : null,
                    'days_per_week'          => isset( $row->days_per_week ) ? (int) $row->days_per_week : null,
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
}
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'bkja_parse_numeric_amount' ) ) {
    /**
     * Legacy wrapper kept for backward compatibility.
     * Now returns value in TOMAN using the robust parser.
     */
    function bkja_parse_numeric_amount( $text ) {
        $parsed = bkja_parse_money_to_toman( $text );
        return isset( $parsed['value_toman'] ) && $parsed['value_toman'] > 0 ? (int) $parsed['value_toman'] : 0;
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

    /**
     * Stopwords for noisy/irrelevant job titles (Persian common fillers or locations).
     *
     * @var array
     */
    protected static $job_title_stopwords = array(
        'هر',
        'ولی',
        'سلام',
        'دوستم',
        'درمیاد',
        'در میاد',
        'درامد',
        'درآمد',
        'روزی',
        'روزانه',
        'ارزش',
        'تلفات',
        'سرمایه',
        'صاحب',
        'البته',
        'خالص',
        'تهران',
    );

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
            `variant_title` VARCHAR(255) NULL,
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
            INDEX (`job_title_id`),
            INDEX (`gender`)
        ) {$charset_collate};";

        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';
        $sql_titles = "CREATE TABLE IF NOT EXISTS `{$table_job_titles}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category_id` BIGINT UNSIGNED NOT NULL,
            `slug` VARCHAR(191) NOT NULL,
            `label` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `cat_slug_unique` (`category_id`,`slug`),
            KEY `cat_idx` (`category_id`)
        ) {$charset_collate};";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql_titles );

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

        self::ensure_job_title_schema();
        self::maybe_backfill_job_titles();
        update_option( 'bkja_job_titles_migrated', 1 );

        if ( ! get_option( 'bkja_job_titles_recleaned' ) ) {
            self::reclean_job_titles();
        }

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
     * Add job titles table/columns and backfill existing rows.
     */
    public static function maybe_backfill_job_titles() {
        self::ensure_job_title_schema();

        $needs_migration = ! get_option( 'bkja_job_titles_migrated' );

        global $wpdb;
        $table_jobs = $wpdb->prefix . 'bkja_jobs';
        $null_count = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_jobs ) ) === $table_jobs ) {
            $null_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_jobs} WHERE job_title_id IS NULL" );
        }

        if ( $needs_migration || $null_count > 0 ) {
            self::backfill_job_titles();
        }

        self::backfill_job_title_groups();

        if ( ! get_option( 'bkja_job_titles_recleaned' ) ) {
            self::reclean_job_titles();
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
        $add_column( 'income_toman', 'BIGINT NULL', 'investment_num' );
        $add_column( 'income_min_toman', 'BIGINT NULL', 'income_toman' );
        $add_column( 'income_max_toman', 'BIGINT NULL', 'income_min_toman' );
        $add_column( 'investment_toman', 'BIGINT NULL', 'income_max_toman' );
        $add_column( 'experience_years', 'TINYINT NULL', 'investment_toman' );
        $add_column( 'employment_type', 'VARCHAR(50) NULL', 'experience_years' );
        $add_column( 'hours_per_day', 'TINYINT NULL', 'employment_type' );
        $add_column( 'days_per_week', 'TINYINT NULL', 'hours_per_day' );
        $add_column( 'source', 'VARCHAR(50) NULL', 'days_per_week' );
    }

    /**
     * Ensure job titles table and related columns exist.
     */
    public static function ensure_job_title_schema() {
        global $wpdb;

        $charset_collate   = $wpdb->get_charset_collate();
        $table_job_titles  = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs        = $wpdb->prefix . 'bkja_jobs';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_titles = "CREATE TABLE IF NOT EXISTS {$table_job_titles} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            category_id BIGINT UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL,
            label VARCHAR(191) NOT NULL,
            description TEXT NULL,
            base_label VARCHAR(191) NULL,
            base_slug VARCHAR(191) NULL,
            group_key VARCHAR(191) NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 1,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY cat_slug_unique (category_id, slug),
            KEY cat_idx (category_id),
            KEY group_idx (group_key),
            KEY visible_idx (is_visible)
        ) {$charset_collate};";

        dbDelta( $sql_titles );

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_jobs ) ) !== $table_jobs ) {
            return;
        }

        $columns = $wpdb->get_col( "DESC {$table_jobs}", 0 );
        $add_column = function( $column, $definition, $after = null ) use ( $wpdb, $table_jobs, $columns ) {
            if ( in_array( $column, $columns, true ) ) {
                return;
            }

            $after_clause = $after ? " AFTER {$after}" : '';
            $wpdb->query( "ALTER TABLE {$table_jobs} ADD COLUMN {$column} {$definition}{$after_clause}" );
        };

        $add_column( 'job_title_id', 'BIGINT UNSIGNED NULL', 'category_id' );
        $add_column( 'variant_title', 'VARCHAR(255) NULL', 'job_title_id' );

        $title_columns = $wpdb->get_col( "DESC {$table_job_titles}", 0 );
        $add_title_column = function( $column, $definition, $after = null ) use ( $wpdb, $table_job_titles, $title_columns ) {
            if ( in_array( $column, $title_columns, true ) ) {
                return;
            }

            $after_clause = $after ? " AFTER {$after}" : '';
            $wpdb->query( "ALTER TABLE {$table_job_titles} ADD COLUMN {$column} {$definition}{$after_clause}" );
        };

        $add_title_column( 'base_label', 'VARCHAR(191) NULL', 'description' );
        $add_title_column( 'base_slug', 'VARCHAR(191) NULL', 'base_label' );
        $add_title_column( 'group_key', 'VARCHAR(191) NULL', 'base_slug' );
        $add_title_column( 'is_primary', 'TINYINT(1) NOT NULL DEFAULT 1', 'group_key' );
        $add_title_column( 'is_visible', 'TINYINT(1) NOT NULL DEFAULT 1', 'is_primary' );

        $title_indexes     = $wpdb->get_results( "SHOW INDEX FROM {$table_job_titles}" );
        $title_index_names = wp_list_pluck( $title_indexes, 'Key_name' );

        if ( ! in_array( 'group_idx', $title_index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_job_titles} ADD INDEX group_idx (group_key)" );
        }

        if ( ! in_array( 'visible_idx', $title_index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_job_titles} ADD INDEX visible_idx (is_visible)" );
        }

        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_jobs}" );
        $index_names = wp_list_pluck( $indexes, 'Key_name' );

        if ( ! in_array( 'job_title_id', $index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_jobs} ADD INDEX job_title_id (job_title_id)" );
        }

        if ( ! in_array( 'category_id', $index_names, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_jobs} ADD INDEX category_id (category_id)" );
        }
    }

    /**
     * Compute canonical grouping fields for a job title label.
     */
    protected static function compute_grouping_from_label( $category_id, $label ) {
        $normalized = is_string( $label ) ? trim( preg_replace( '/\s+/u', ' ', $label ) ) : '';

        if ( '' === $normalized ) {
            $normalized = (string) $label;
        }

        $tokens = preg_split( '/\s+/u', $normalized );
        $tokens = array_values( array_filter( $tokens, 'strlen' ) );

        $base_label = $normalized;

        if ( ! empty( $tokens ) ) {
            $prefixes = array( 'نیروی', 'افسر' );

            $starts_with_prefix = false;
            $label_prefix       = '';
            if ( function_exists( 'mb_substr' ) ) {
                $label_prefix = mb_substr( $normalized, 0, 5, 'UTF-8' );
            } else {
                $label_prefix = substr( $normalized, 0, 10 );
            }

            foreach ( $prefixes as $prefix ) {
                if ( 0 === strpos( $label_prefix, $prefix ) ) {
                    $starts_with_prefix = true;
                    break;
                }
            }

            if ( $starts_with_prefix && count( $tokens ) >= 2 ) {
                $base_label = implode( ' ', array_slice( $tokens, 0, 2 ) );
            } else {
                $base_label = $tokens[0];
            }
        }

        $base_slug = sanitize_title( $base_label );
        $group_key = absint( $category_id ) . ':' . $base_slug;

        return array(
            'base_label' => $base_label,
            'base_slug'  => $base_slug,
            'group_key'  => $group_key,
        );
    }

    /**
     * Detect if a given title/label should be treated as noisy and hidden.
     */
    protected static function is_noisy_title( $label, $base_label, $jobs_count ) {
        $jobs_count = (int) $jobs_count;

        $normalize = function( $text ) {
            if ( ! is_string( $text ) ) {
                return '';
            }

            return trim( preg_replace( '/\s+/u', ' ', $text ) );
        };

        $get_length = function( $text ) {
            if ( '' === $text ) {
                return 0;
            }

            return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        };

        $to_lower = function( $text ) {
            return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        };

        $label_clean = $normalize( $label );
        $base_clean  = $normalize( $base_label );

        $label_len = $get_length( $label_clean );
        $base_len  = $get_length( $base_clean );

        $is_short = ( $label_len > 0 && $label_len <= 3 ) || ( $base_len > 0 && $base_len <= 3 );

        $labels_to_check = array_filter( array( $label_clean, $base_clean ) );

        $is_stopword = false;
        foreach ( $labels_to_check as $text ) {
            if ( in_array( $to_lower( $text ), self::$job_title_stopwords, true ) ) {
                $is_stopword = true;
                break;
            }
        }

        $numeric_like = false;
        foreach ( $labels_to_check as $text ) {
            if ( preg_match( '/^[0-9۰-۹]+$/u', $text ) || preg_match( '/^[0-9۰-۹]/u', $text ) ) {
                $numeric_like = true;
                break;
            }
        }

        $city_words   = array( 'تهران', 'کرمان', 'مشهد', 'شیراز', 'تبریز' );
        $matches_city = false;
        foreach ( $labels_to_check as $text ) {
            if ( in_array( $to_lower( $text ), $city_words, true ) ) {
                $matches_city = true;
                break;
            }
        }

        if ( $jobs_count <= 3 && ( $is_short || $is_stopword || $numeric_like || $matches_city ) ) {
            return true;
        }

        return false;
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
                    "SELECT id, income, investment, income_toman, income_min_toman, income_max_toman, investment_toman, income_num, investment_num FROM {$table} WHERE (income_toman IS NULL OR income_toman = 0 OR income_toman > 1000000000000) OR (investment_toman IS NULL OR investment_toman = 0 OR investment_toman > 1000000000000) OR income_num IS NULL OR investment_num IS NULL ORDER BY id ASC LIMIT %d",
                    $limit
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $parsed_income     = bkja_parse_money_to_toman( $row->income );
                $parsed_investment = bkja_parse_money_to_toman( $row->investment );

                $income_value = isset( $parsed_income['value_toman'] ) ? (int) $parsed_income['value_toman'] : 0;
                $invest_value = isset( $parsed_investment['value_toman'] ) ? (int) $parsed_investment['value_toman'] : 0;

                $data  = array(
                    'income_num'        => $income_value,
                    'investment_num'    => $invest_value,
                    'income_toman'      => $income_value,
                    'income_min_toman'  => isset( $parsed_income['min_toman'] ) ? $parsed_income['min_toman'] : null,
                    'income_max_toman'  => isset( $parsed_income['max_toman'] ) ? $parsed_income['max_toman'] : null,
                    'investment_toman'  => $invest_value,
                );
                $where = array( 'id' => (int) $row->id );

                $wpdb->update( $table, $data, $where );
            }

            $max_batches--;
        } while ( $max_batches > 0 );
    }

    /**
     * Insert or fetch a job title row.
     */
    public static function ensure_job_title_exists( $category_id, $label ) {
        global $wpdb;

        $category_id = absint( $category_id );
        $label       = is_string( $label ) ? trim( $label ) : '';

        if ( $category_id <= 0 || '' === $label ) {
            return null;
        }

        self::ensure_job_title_schema();

        $slug  = sanitize_title( $label );
        $table = $wpdb->prefix . 'bkja_job_titles';

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE category_id = %d AND slug = %s LIMIT 1",
                $category_id,
                $slug
            )
        );

        if ( $existing_id ) {
            return (int) $existing_id;
        }

        $now       = current_time( 'mysql' );
        $grouping  = self::compute_grouping_from_label( $category_id, $label );
        $wpdb->insert(
            $table,
            array(
                'category_id' => $category_id,
                'slug'        => $slug,
                'label'       => $label,
                'description' => null,
                'base_label'  => $grouping['base_label'],
                'base_slug'   => $grouping['base_slug'],
                'group_key'   => $grouping['group_key'],
                'is_primary'  => 1,
                'is_visible'  => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            )
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Backfill job_title_id and variant_title for existing jobs.
     */
    public static function backfill_job_titles() {
        global $wpdb;

        self::ensure_job_title_schema();

        $table_jobs       = $wpdb->prefix . 'bkja_jobs';
        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_jobs ) ) !== $table_jobs ) {
            return;
        }

        // Process distinct title/category combinations without a mapped job_title_id
        $pairs = $wpdb->get_results(
            "SELECT DISTINCT category_id, title FROM {$table_jobs} WHERE job_title_id IS NULL"
        );

        if ( empty( $pairs ) ) {
            self::backfill_job_title_groups();
            return;
        }

        foreach ( $pairs as $pair ) {
            $base_label = is_string( $pair->title ) ? trim( $pair->title ) : '';
            if ( '' === $base_label ) {
                continue;
            }

            $category_id   = absint( $pair->category_id );
            $slug          = sanitize_title( $base_label );
            $job_title_row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table_job_titles} WHERE category_id = %d AND slug = %s LIMIT 1",
                    $category_id,
                    $slug
                )
            );

            if ( $job_title_row && isset( $job_title_row->id ) ) {
                $job_title_id = (int) $job_title_row->id;
            } else {
                $job_title_id = self::ensure_job_title_exists( $category_id, $base_label );
            }

            if ( $job_title_id ) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table_jobs} SET job_title_id = %d, variant_title = IFNULL(variant_title, title) WHERE category_id = %d AND title = %s AND job_title_id IS NULL",
                        $job_title_id,
                        $category_id,
                        $pair->title
                    )
                );
            }
        }

        self::backfill_job_title_groups();
    }

    /**
     * Populate grouping/visibility metadata for job titles (idempotent).
     */
    public static function backfill_job_title_groups() {
        global $wpdb;

        self::ensure_job_title_schema();

        $table_jobs       = $wpdb->prefix . 'bkja_jobs';
        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_job_titles ) ) !== $table_job_titles ) {
            return;
        }

        // Base grouping fill
        $need_grouping_rows = $wpdb->get_results(
            "SELECT id, category_id, label FROM {$table_job_titles} WHERE base_label IS NULL OR base_slug IS NULL OR group_key IS NULL"
        );

        foreach ( $need_grouping_rows as $row ) {
            $grouping = self::compute_grouping_from_label( $row->category_id, $row->label );

            $wpdb->update(
                $table_job_titles,
                array(
                    'base_label' => $grouping['base_label'],
                    'base_slug'  => $grouping['base_slug'],
                    'group_key'  => $grouping['group_key'],
                    'is_primary' => 1,
                    'is_visible' => 1,
                ),
                array( 'id' => (int) $row->id )
            );
        }

        // Jobs count map
        $counts = array();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_jobs ) ) === $table_jobs ) {
            $count_rows = $wpdb->get_results( "SELECT job_title_id, COUNT(*) AS c FROM {$table_jobs} GROUP BY job_title_id" );
            foreach ( $count_rows as $c_row ) {
                $counts[ (int) $c_row->job_title_id ] = (int) $c_row->c;
            }
        }

        // Hide obvious junk titles
        $all_titles = $wpdb->get_results( "SELECT id, label, base_label, is_visible FROM {$table_job_titles}" );
        foreach ( $all_titles as $title_row ) {
            $jobs_count = isset( $counts[ (int) $title_row->id ] ) ? (int) $counts[ (int) $title_row->id ] : 0;

            if ( self::is_noisy_title( $title_row->label, $title_row->base_label, $jobs_count ) ) {
                $wpdb->update(
                    $table_job_titles,
                    array(
                        'is_visible' => 0,
                        'is_primary' => 0,
                    ),
                    array( 'id' => (int) $title_row->id )
                );
            }
        }

        // Ensure one primary per group
        $group_rows = $wpdb->get_results( "SELECT id, group_key, label, base_label, is_visible FROM {$table_job_titles} WHERE group_key IS NOT NULL" );
        $groups     = array();

        foreach ( $group_rows as $gr ) {
            $groups[ $gr->group_key ][] = $gr;
        }

        foreach ( $groups as $group_key => $rows ) {
            $candidate_rows = array_values( array_filter( $rows, function ( $r ) {
                return (int) $r->is_visible === 1;
            } ) );

            if ( empty( $candidate_rows ) ) {
                $candidate_rows = $rows;
            }

            $best_row = null;
            foreach ( $candidate_rows as $row ) {
                $row_count = isset( $counts[ (int) $row->id ] ) ? (int) $counts[ (int) $row->id ] : 0;
                $label     = $row->base_label ? $row->base_label : $row->label;
                $len       = function_exists( 'mb_strlen' ) ? mb_strlen( $label, 'UTF-8' ) : strlen( $label );

                if ( ! $best_row ) {
                    $best_row = array( 'row' => $row, 'count' => $row_count, 'len' => $len );
                    continue;
                }

                if ( $row_count > $best_row['count'] ) {
                    $best_row = array( 'row' => $row, 'count' => $row_count, 'len' => $len );
                    continue;
                }

                if ( $row_count === $best_row['count'] && $len < $best_row['len'] ) {
                    $best_row = array( 'row' => $row, 'count' => $row_count, 'len' => $len );
                }
            }

            if ( ! $best_row || empty( $best_row['row']->id ) ) {
                continue;
            }

            $best_id = (int) $best_row['row']->id;

            foreach ( $rows as $row ) {
                $is_primary = ( (int) $row->id === $best_id ) ? 1 : 0;
                $wpdb->update(
                    $table_job_titles,
                    array( 'is_primary' => $is_primary ),
                    array( 'id' => (int) $row->id )
                );
            }
        }
    }

    /**
     * Re-evaluate visibility/primary flags against current noise rules and counts.
     */
    public static function reclean_job_titles() {
        global $wpdb;

        self::ensure_job_title_schema();

        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs       = $wpdb->prefix . 'bkja_jobs';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_job_titles ) ) !== $table_job_titles ) {
            return;
        }

        $counts = array();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_jobs ) ) === $table_jobs ) {
            $count_rows = $wpdb->get_results( "SELECT job_title_id, COUNT(*) AS c FROM {$table_jobs} GROUP BY job_title_id" );
            foreach ( $count_rows as $c_row ) {
                $counts[ (int) $c_row->job_title_id ] = (int) $c_row->c;
            }
        }

        $all_titles = $wpdb->get_results( "SELECT id, label, base_label FROM {$table_job_titles}" );
        foreach ( $all_titles as $title_row ) {
            $jobs_count = isset( $counts[ (int) $title_row->id ] ) ? (int) $counts[ (int) $title_row->id ] : 0;

            if ( self::is_noisy_title( $title_row->label, $title_row->base_label, $jobs_count ) ) {
                $wpdb->update(
                    $table_job_titles,
                    array(
                        'is_visible' => 0,
                        'is_primary' => 0,
                    ),
                    array( 'id' => (int) $title_row->id )
                );
            }
        }

        self::backfill_job_title_groups();

        update_option( 'bkja_job_titles_recleaned', 1 );
    }

    /**
     * One-time maintenance to ensure job_title records carry group metadata and visibility flags.
     */
    public static function maybe_run_job_title_integrity() {
        if ( get_option( 'bkja_job_titles_integrity_checked' ) ) {
            return;
        }

        self::ensure_job_title_schema();
        self::maybe_backfill_job_titles();
        self::backfill_job_title_groups();
        self::reclean_job_titles();

        update_option( 'bkja_job_titles_integrity_checked', 1 );
    }

    /**
     * Return all job_title IDs that share a group key.
     */
    public static function get_job_title_ids_for_group( $group_key ) {
        global $wpdb;

        $group_key = is_string( $group_key ) ? trim( $group_key ) : '';
        if ( '' === $group_key ) {
            return array();
        }

        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';
        $ids              = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table_job_titles} WHERE group_key = %s", $group_key ) );

        return array_map( 'intval', (array) $ids );
    }

    /**
     * Normalize incoming free-text job queries for consistent matching.
     */
    protected static function normalize_job_query_text( $text ) {
        $text = is_string( $text ) ? $text : (string) $text;
        $text = function_exists( 'bkja_normalize_query_text' )
            ? bkja_normalize_query_text( $text )
            : $text;

        if ( function_exists( 'mb_strtolower' ) ) {
            $text = mb_strtolower( $text, 'UTF-8' );
        } else {
            $text = strtolower( $text );
        }

        return trim( (string) $text );
    }

    /**
     * Resolve a job title/group using a unified free-text resolver with ranking.
     */
    public static function resolve_job_query( $query, $options = array() ) {
        global $wpdb;

        self::ensure_job_title_schema();

        $window_months = (int) get_option( 'bkja_stats_window_months', 12 );
        if ( $window_months <= 0 ) {
            $window_months = 12;
        }

        $table_titles = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs   = $wpdb->prefix . 'bkja_jobs';
        $normalized_label_column = "REPLACE(REPLACE(REPLACE(COALESCE(jt.base_label, jt.label), 'ي', 'ی'), 'ك', 'ک'), '‌', ' ')";

        $counts_recent_sql = $wpdb->prepare(
            "SELECT job_title_id, COUNT(*) AS cnt FROM {$table_jobs} WHERE job_title_id IS NOT NULL AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MONTH) GROUP BY job_title_id",
            $window_months
        );
        $counts_total_sql  = "SELECT job_title_id, COUNT(*) AS cnt FROM {$table_jobs} WHERE job_title_id IS NOT NULL GROUP BY job_title_id";

        $recent_counts = array();
        $rows_counts   = $wpdb->get_results( $counts_recent_sql );
        foreach ( (array) $rows_counts as $row ) {
            $recent_counts[ (int) $row->job_title_id ] = (int) $row->cnt;
        }

        $total_counts = array();
        $rows_totals  = $wpdb->get_results( $counts_total_sql );
        foreach ( (array) $rows_totals as $row ) {
            $total_counts[ (int) $row->job_title_id ] = (int) $row->cnt;
        }

        $initial_context = self::resolve_job_group_context( $query );
        if ( ! empty( $initial_context['job_title_ids'] ) ) {
            $primary_id = isset( $initial_context['job_title_ids'][0] ) ? (int) $initial_context['job_title_ids'][0] : 0;
            $candidates = array();
            foreach ( $initial_context['job_title_ids'] as $jt_id ) {
                $jt_id = (int) $jt_id;
                $candidates[] = array(
                    'job_title_id'      => $jt_id,
                    'group_key'         => $initial_context['group_key'],
                    'label'             => $initial_context['label'],
                    'slug'              => $initial_context['slug'],
                    'jobs_count_recent' => isset( $recent_counts[ $jt_id ] ) ? (int) $recent_counts[ $jt_id ] : 0,
                    'jobs_count_total'  => isset( $total_counts[ $jt_id ] ) ? (int) $total_counts[ $jt_id ] : 0,
                    'score'             => 100,
                    'match_type'        => 'explicit',
                );
            }

            return array(
                'matched_job_title_id' => $primary_id,
                'group_key'            => $initial_context['group_key'],
                'label'                => $initial_context['label'],
                'slug'                 => $initial_context['slug'],
                'job_title_ids'        => $initial_context['job_title_ids'],
                'confidence'           => 1.0,
                'candidates'           => $candidates,
                'ambiguous'            => false,
            );
        }

        $normalized = self::normalize_job_query_text( $query );
        if ( '' === $normalized ) {
            return array();
        }

        $tokens        = preg_split( '/[\s،,.!?؟]+/u', $normalized );
        $tokens        = array_values( array_filter( array_map( 'trim', $tokens ) ) );
        $token_compact = preg_replace( '/\s+/u', '', $normalized );

        $candidate_terms = array();
        $filtered_tokens = array();
        if ( $tokens ) {
            $filtered_tokens = array_filter( $tokens, function( $token ) {
                $token = trim( $token );
                if ( '' === $token ) {
                    return false;
                }
                return ! in_array( $token, self::$job_title_stopwords, true );
            } );
            if ( $filtered_tokens ) {
                $candidate_terms[] = implode( ' ', $filtered_tokens );
            }
            $candidate_terms[] = implode( ' ', $tokens );
            foreach ( $tokens as $token ) {
                $candidate_terms[] = $token;
            }
            foreach ( $filtered_tokens as $token ) {
                $candidate_terms[] = $token;
            }
        }
        $candidate_terms[] = $normalized;
        $candidate_terms[] = $token_compact;

        $stemmed = '';
        if ( function_exists( 'mb_substr' ) ? 'ی' === mb_substr( $normalized, -1, 1, 'UTF-8' ) : ( 'ی' === substr( $normalized, -2 ) ) ) {
            $stemmed = function_exists( 'mb_substr' ) ? mb_substr( $normalized, 0, mb_strlen( $normalized, 'UTF-8' ) - 1, 'UTF-8' ) : substr( $normalized, 0, -2 );
        }
        if ( $stemmed ) {
            $candidate_terms[] = $stemmed;
        }

        $variant_terms = array();
        if ( function_exists( 'bkja_generate_title_variants' ) ) {
            foreach ( $candidate_terms as $term ) {
                $variant_terms = array_merge( $variant_terms, bkja_generate_title_variants( $term ) );
            }
        }

        $candidate_terms = array_values( array_unique( array_filter( array_merge( $candidate_terms, $variant_terms ) ) ) );

        $calc_match_len = function( $label ) use ( $candidate_terms ) {
            $max_len = 0;
            foreach ( $candidate_terms as $term ) {
                if ( '' === $term ) {
                    continue;
                }
                $pos = function_exists( 'mb_strpos' ) ? mb_strpos( $label, $term, 0, 'UTF-8' ) : strpos( $label, $term );
                if ( false !== $pos ) {
                    $len     = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );
                    $max_len = max( $max_len, $len );
                }
            }
            return $max_len;
        };

        $stages = array(
            array(
                'key'     => 'exact',
                'weight'  => 5,
                'builder' => function( $term ) use ( $normalized_label_column ) {
                    return array(
                        'where'  => '(jt.base_label = %s OR jt.label = %s OR jt.slug = %s OR jt.base_slug = %s OR ' . $normalized_label_column . ' = %s)',
                        'params' => array( $term, $term, $term, $term, $term ),
                    );
                },
            ),
            array(
                'key'     => 'prefix',
                'weight'  => 4,
                'builder' => function( $term ) use ( $normalized_label_column ) {
                    $prefix = $term . '%';
                    return array(
                        'where'  => '(jt.base_label LIKE %s OR jt.label LIKE %s OR ' . $normalized_label_column . ' LIKE %s)',
                        'params' => array( $prefix, $prefix, $prefix ),
                    );
                },
            ),
            array(
                'key'     => 'contains',
                'weight'  => 3,
                'builder' => function( $term ) use ( $normalized_label_column ) {
                    $like = '%' . $term . '%';
                    return array(
                        'where'  => '(jt.base_label LIKE %s OR jt.label LIKE %s OR ' . $normalized_label_column . ' LIKE %s)',
                        'params' => array( $like, $like, $like ),
                    );
                },
            ),
            array(
                'key'     => 'variant',
                'weight'  => 3,
                'builder' => function( $term ) use ( $normalized_label_column ) {
                    $like = '%' . $term . '%';
                    return array(
                        'where'  => '(jt.base_label LIKE %s OR jt.label LIKE %s OR ' . $normalized_label_column . ' LIKE %s OR EXISTS (SELECT 1 FROM %1$s j WHERE j.job_title_id = jt.id AND (j.title LIKE %s OR j.variant_title LIKE %s) LIMIT 1))',
                        'params' => array( $like, $like, $like, $like, $like ),
                    );
                },
            ),
        );

        $candidate_map = array();

        $add_candidate = function( $row, $weight, $match_len, $stage_key ) use ( &$candidate_map, $recent_counts, $total_counts ) {
            $group_key   = $row->group_key ? $row->group_key : ( 'id:' . (int) $row->id );
            $recent_cnt  = isset( $recent_counts[ (int) $row->id ] ) ? (int) $recent_counts[ (int) $row->id ] : 0;
            $total_cnt   = isset( $total_counts[ (int) $row->id ] ) ? (int) $total_counts[ (int) $row->id ] : 0;
            $score       = ( $weight * 10 ) + ( $match_len * 2 ) + log( $recent_cnt + 1 ) + log( $total_cnt + 1 );
            $job_title_ids = array( (int) $row->id );

            if ( $row->group_key && class_exists( 'BKJA_Database' ) ) {
                $job_title_ids = BKJA_Database::get_job_title_ids_for_group( $row->group_key );
            }

            if ( ! isset( $candidate_map[ $group_key ] ) || $score > $candidate_map[ $group_key ]['score'] ) {
                $candidate_map[ $group_key ] = array(
                    'job_title_id'      => (int) $row->id,
                    'group_key'         => $row->group_key ? $row->group_key : null,
                    'label'             => isset( $row->label ) ? $row->label : '',
                    'slug'              => isset( $row->slug ) ? $row->slug : '',
                    'jobs_count_recent' => $recent_cnt,
                    'jobs_count_total'  => $total_cnt,
                    'score'             => $score,
                    'match_type'        => $stage_key,
                    'match_len'         => $match_len,
                    'job_title_ids'     => array_map( 'intval', $job_title_ids ),
                );
            }
        };

        foreach ( $stages as $stage ) {
            foreach ( $candidate_terms as $term ) {
                if ( '' === $term ) {
                    continue;
                }
                $condition = call_user_func( $stage['builder'], $term );
                $where     = $condition['where'];
                $params    = $condition['params'];

                if ( false !== strpos( $where, '%1$s' ) ) {
                    $where = str_replace( '%1$s', $table_jobs, $where );
                }

                $sql = "SELECT jt.id, jt.group_key, COALESCE(jt.base_label, jt.label) AS label, COALESCE(jt.base_slug, jt.slug) AS slug, jt.is_primary, COALESCE(rc.cnt, 0) AS recent_cnt, COALESCE(tc.cnt, 0) AS total_cnt
                        FROM {$table_titles} jt
                        LEFT JOIN ({$counts_recent_sql}) rc ON rc.job_title_id = jt.id
                        LEFT JOIN ({$counts_total_sql}) tc ON tc.job_title_id = jt.id
                        WHERE {$where} AND jt.is_visible = 1
                        LIMIT 60";

                $prepared = $params;
                array_unshift( $prepared, $sql );
                $sql_prepared = call_user_func_array( array( $wpdb, 'prepare' ), $prepared );

                $rows = $wpdb->get_results( $sql_prepared );
                foreach ( (array) $rows as $row ) {
                    $match_len = $calc_match_len( $row->label );
                    $add_candidate( $row, $stage['weight'], $match_len, $stage['key'] );
                }
            }

            if ( ! empty( $candidate_map ) ) {
                break;
            }
        }

        if ( empty( $candidate_map ) ) {
            $fallback_rows = $wpdb->get_results( "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_titles} WHERE is_visible = 1 LIMIT 200" );
            foreach ( (array) $fallback_rows as $row ) {
                $similarity = 0;
                similar_text( $normalized, $row->label, $similarity );
                $weight = $similarity >= 70 ? 3 : 1;
                $add_candidate( $row, $weight, (int) round( $similarity / 10 ), 'fuzzy' );
            }
        }

        if ( empty( $candidate_map ) ) {
            return array();
        }

        $candidates = array_values( $candidate_map );
        usort( $candidates, function( $a, $b ) {
            if ( $a['score'] === $b['score'] ) {
                if ( $a['jobs_count_recent'] === $b['jobs_count_recent'] ) {
                    return ( $a['jobs_count_total'] > $b['jobs_count_total'] ) ? -1 : 1;
                }
                return ( $a['jobs_count_recent'] > $b['jobs_count_recent'] ) ? -1 : 1;
            }
            return ( $a['score'] > $b['score'] ) ? -1 : 1;
        } );

        $best         = $candidates[0];
        $best_score   = isset( $best['score'] ) ? (float) $best['score'] : 0.0;
        $second_score = isset( $candidates[1]['score'] ) ? (float) $candidates[1]['score'] : 0.0;
        $confidence   = $best_score > 0 ? min( 1.0, max( 0.35, ( $best_score - $second_score ) / ( $best_score + 1 ) + 0.6 ) ) : 0.0;
        $ambiguous    = ( count( $candidates ) > 1 && $second_score >= ( $best_score * 0.85 ) );

        if ( 1 === count( $candidates ) ) {
            $ambiguous = false;
        }

        if ( isset( $best['match_type'], $best['match_len'] ) && in_array( $best['match_type'], array( 'exact', 'prefix' ), true ) ) {
            if ( (int) $best['match_len'] >= 3 ) {
                $confidence = max( $confidence, 0.75 );
                $ambiguous  = false;
            }
        }

        return array(
            'matched_job_title_id' => isset( $best['job_title_id'] ) ? (int) $best['job_title_id'] : 0,
            'group_key'            => isset( $best['group_key'] ) ? $best['group_key'] : null,
            'label'                => isset( $best['label'] ) ? $best['label'] : '',
            'slug'                 => isset( $best['slug'] ) ? $best['slug'] : '',
            'job_title_ids'        => isset( $best['job_title_ids'] ) ? $best['job_title_ids'] : array(),
            'confidence'           => $confidence,
            'candidates'           => array_slice( $candidates, 0, 5 ),
            'ambiguous'            => $ambiguous,
        );
    }

    /**
     * Resolve a job title group (group_key + job_title_ids) from any query.
     */
    public static function resolve_job_title_group( $query ) {
        self::ensure_job_title_schema();

        $context = self::resolve_job_group_context( $query );
        if ( ! empty( $context['job_title_ids'] ) ) {
            return array(
                'group_key'     => isset( $context['group_key'] ) ? $context['group_key'] : null,
                'base_label'    => isset( $context['label'] ) ? $context['label'] : '',
                'job_title_ids' => array_values( array_unique( array_filter( array_map( 'intval', $context['job_title_ids'] ) ) ) ),
            );
        }

        if ( is_string( $query ) && '' !== trim( $query ) ) {
            $resolved = self::resolve_job_query( $query );
            if ( ! empty( $resolved['job_title_ids'] ) ) {
                return array(
                    'group_key'     => isset( $resolved['group_key'] ) ? $resolved['group_key'] : null,
                    'base_label'    => isset( $resolved['label'] ) ? $resolved['label'] : '',
                    'job_title_ids' => array_values( array_unique( array_filter( array_map( 'intval', (array) $resolved['job_title_ids'] ) ) ) ),
                );
            }
        }

        return array();
    }

    /**
     * Resolve a job title/group context from input (id, label, slug or group_key array/object).
     */
    protected static function resolve_job_group_context( $job_title ) {
        global $wpdb;

        self::ensure_job_title_schema();

        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';

        $context = array(
            'job_title_ids' => array(),
            'group_key'     => null,
            'label'         => '',
            'slug'          => '',
        );

        $incoming_group_key = null;
        $incoming_ids       = array();
        $incoming_label     = '';
        $incoming_id        = 0;
        if ( is_array( $job_title ) || is_object( $job_title ) ) {
            $incoming_group_key = isset( $job_title['group_key'] ) ? sanitize_text_field( $job_title['group_key'] ) : ( isset( $job_title->group_key ) ? sanitize_text_field( $job_title->group_key ) : '' );
            if ( isset( $job_title['job_title_ids'] ) && is_array( $job_title['job_title_ids'] ) ) {
                $incoming_ids = array_filter( array_map( 'intval', $job_title['job_title_ids'] ) );
            } elseif ( isset( $job_title->job_title_ids ) && is_array( $job_title->job_title_ids ) ) {
                $incoming_ids = array_filter( array_map( 'intval', $job_title->job_title_ids ) );
            }
            if ( isset( $job_title['job_title_id'] ) ) {
                $incoming_id = (int) $job_title['job_title_id'];
            } elseif ( isset( $job_title->job_title_id ) ) {
                $incoming_id = (int) $job_title->job_title_id;
            }
            if ( isset( $job_title['label'] ) ) {
                $incoming_label = sanitize_text_field( $job_title['label'] );
            } elseif ( isset( $job_title->label ) ) {
                $incoming_label = sanitize_text_field( $job_title->label );
            }
        }

        if ( $incoming_group_key ) {
            $ids = self::get_job_title_ids_for_group( $incoming_group_key );
            if ( $ids ) {
                $primary = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_job_titles} WHERE group_key = %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                        $incoming_group_key
                    )
                );

                $context['job_title_ids'] = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
                $context['group_key']     = $incoming_group_key;
                $context['label']         = $primary ? $primary->label : $incoming_label;
                $context['slug']          = $primary ? $primary->slug : '';

                return $context;
            }
        }

        if ( ! empty( $incoming_ids ) ) {
            $incoming_ids   = array_values( array_unique( array_filter( array_map( 'intval', $incoming_ids ) ) ) );
            $placeholders   = implode( ',', array_fill( 0, count( $incoming_ids ), '%d' ) );
            $row            = $wpdb->get_row( $wpdb->prepare( "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_job_titles} WHERE id IN ({$placeholders}) ORDER BY is_primary DESC, id ASC LIMIT 1", $incoming_ids ) );
            if ( $row ) {
                $ids                  = $row->group_key ? self::get_job_title_ids_for_group( $row->group_key ) : $incoming_ids;
                $context['job_title_ids'] = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
                $context['group_key']     = $row->group_key ? $row->group_key : null;
                $context['label']         = $row->label;
                $context['slug']          = $row->slug;
                return $context;
            }
        }

        if ( $incoming_id > 0 ) {
            $job_title_id = $incoming_id;
        } else {
            $job_title_id = self::resolve_job_title_id( $job_title );
        }

        if ( $job_title_id ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_job_titles} WHERE id = %d", $job_title_id ) );
            if ( $row ) {
                $group_key = $row->group_key;
                if ( $group_key ) {
                    $ids = self::get_job_title_ids_for_group( $group_key );
                } else {
                    $ids = array( (int) $row->id );
                }

                $context['job_title_ids'] = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
                $context['group_key']     = $group_key ? $group_key : null;
                $context['label']         = $row->label;
                $context['slug']          = $row->slug;

                return $context;
            }
        }

        if ( is_string( $job_title ) && '' !== trim( $job_title ) ) {
            $trimmed    = trim( $job_title );
            $base_slug  = sanitize_title( $trimmed );
            $candidates = array( $trimmed );

            if ( function_exists( 'bkja_generate_title_variants' ) ) {
                $candidates = array_merge( $candidates, bkja_generate_title_variants( $trimmed ) );
            }

            $candidates = array_values( array_unique( array_filter( $candidates ) ) );

            foreach ( $candidates as $term ) {
                $term_slug = sanitize_title( $term );
                $candidate = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_job_titles} WHERE base_label = %s OR base_slug = %s OR label = %s OR slug = %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                        $term,
                        $term_slug,
                        $term,
                        $term_slug
                    )
                );

                if ( $candidate ) {
                    $group_key = $candidate->group_key;
                    $ids       = $group_key ? self::get_job_title_ids_for_group( $group_key ) : array( (int) $candidate->id );

                    $context['job_title_ids'] = array_map( 'intval', $ids );
                    $context['group_key']     = $group_key ? $group_key : null;
                    $context['label']         = $candidate->label;
                    $context['slug']          = $candidate->slug;
                    break;
                }

                $prefix_term = $term . '%';
                $candidate   = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_job_titles} WHERE base_label LIKE %s OR label LIKE %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                        $prefix_term,
                        $prefix_term
                    )
                );

                if ( $candidate ) {
                    $group_key = $candidate->group_key;
                    $ids       = $group_key ? self::get_job_title_ids_for_group( $group_key ) : array( (int) $candidate->id );

                    $context['job_title_ids'] = array_map( 'intval', $ids );
                    $context['group_key']     = $group_key ? $group_key : null;
                    $context['label']         = $candidate->label;
                    $context['slug']          = $candidate->slug;
                    break;
                }

                $contains_term = '%' . $term . '%';
                $candidate     = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$table_job_titles} WHERE base_label LIKE %s OR label LIKE %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                        $contains_term,
                        $contains_term
                    )
                );

                if ( $candidate ) {
                    $group_key = $candidate->group_key;
                    $ids       = $group_key ? self::get_job_title_ids_for_group( $group_key ) : array( (int) $candidate->id );

                    $context['job_title_ids'] = array_map( 'intval', $ids );
                    $context['group_key']     = $group_key ? $group_key : null;
                    $context['label']         = $candidate->label;
                    $context['slug']          = $candidate->slug;
                    break;
                }
            }
        }

        if ( empty( $context['label'] ) && $incoming_label ) {
            $context['label'] = $incoming_label;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            if ( ( $incoming_group_key || $incoming_id || $incoming_ids ) && empty( $context['job_title_ids'] ) ) {
                error_log( 'BKJA resolve_job_group_context WARNING: incoming identifiers present but no job_title_ids resolved. input=' . print_r( $job_title, true ) );
            }
            error_log( 'BKJA resolve_job_group_context input: ' . print_r( $job_title, true ) . ' -> ' . print_r( $context, true ) );
        }

        return $context;
    }

    /**
     * insert_job
     * درج یک رکورد شغلی (هر رکورد متعلق به یک کاربر/مشاهده است)
     */
    public static function insert_job( $data = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        self::ensure_numeric_job_columns();
        self::ensure_job_title_schema();

        $income_num_input     = isset( $data['income_num'] ) ? intval( $data['income_num'] ) : null;
        $investment_num_input = isset( $data['investment_num'] ) ? intval( $data['investment_num'] ) : null;
        $experience_years     = isset( $data['experience_years'] ) ? $data['experience_years'] : null;
        $hours_per_day        = isset( $data['hours_per_day'] ) ? $data['hours_per_day'] : null;
        $days_per_week        = isset( $data['days_per_week'] ) ? $data['days_per_week'] : null;

        if ( ! is_numeric( $experience_years ) && function_exists( 'bkja_parse_numeric_range' ) ) {
            $parsed = bkja_parse_numeric_range( (string) $experience_years );
            $experience_years = isset( $parsed['value'] ) ? (int) round( $parsed['value'] ) : null;
        }
        if ( ! is_numeric( $hours_per_day ) && function_exists( 'bkja_parse_numeric_range' ) ) {
            $parsed = bkja_parse_numeric_range( (string) $hours_per_day );
            $hours_per_day = isset( $parsed['value'] ) ? (int) round( $parsed['value'] ) : null;
        }
        if ( ! is_numeric( $days_per_week ) && function_exists( 'bkja_parse_numeric_range' ) ) {
            $parsed = bkja_parse_numeric_range( (string) $days_per_week );
            $days_per_week = isset( $parsed['value'] ) ? (int) round( $parsed['value'] ) : null;
        }

        $experience_years = is_numeric( $experience_years ) ? (int) $experience_years : null;
        $hours_per_day    = is_numeric( $hours_per_day ) ? (int) $hours_per_day : null;
        $days_per_week    = is_numeric( $days_per_week ) ? (int) $days_per_week : null;

        $experience_years = ( $experience_years && $experience_years > 0 ) ? $experience_years : null;
        $hours_per_day    = ( $hours_per_day && $hours_per_day > 0 ) ? $hours_per_day : null;
        $days_per_week    = ( $days_per_week && $days_per_week > 0 ) ? $days_per_week : null;

        $category_id   = isset( $data['category_id'] ) ? intval( $data['category_id'] ) : 0;
        $base_label    = isset( $data['job_title_label'] ) ? sanitize_text_field( $data['job_title_label'] ) : '';
        $variant_title = isset( $data['variant_title'] ) ? sanitize_text_field( $data['variant_title'] ) : '';
        $job_title_id  = isset( $data['job_title_id'] ) ? absint( $data['job_title_id'] ) : 0;

        $incoming_title = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';

        if ( ! $job_title_id ) {
            $label_for_base = $base_label ? $base_label : ( $incoming_title ?: $variant_title );
            if ( $label_for_base && $category_id ) {
                $job_title_id = self::ensure_job_title_exists( $category_id, $label_for_base );
                if ( ! $base_label ) {
                    $base_label = $label_for_base;
                }
            }
        }

        if ( '' === $variant_title ) {
            $variant_title = $incoming_title ? $incoming_title : $base_label;
        }

        $title_to_store = $variant_title ? $variant_title : $base_label;

        $parsed_income     = bkja_parse_money_to_toman( isset( $data['income'] ) ? $data['income'] : '' );
        $parsed_investment = bkja_parse_money_to_toman( isset( $data['investment'] ) ? $data['investment'] : '' );

        $row = [
            'category_id'      => $category_id,
            'job_title_id'     => $job_title_id ?: null,
            'variant_title'    => $variant_title,
            'title'            => $title_to_store,
            'income'           => isset( $data['income'] ) ? sanitize_text_field( $data['income'] ) : '',
            'investment'       => isset( $data['investment'] ) ? sanitize_text_field( $data['investment'] ) : '',
            'income_num'       => 0,
            'investment_num'   => 0,
            'income_toman'     => isset( $parsed_income['value_toman'] ) ? $parsed_income['value_toman'] : null,
            'income_min_toman' => isset( $parsed_income['min_toman'] ) ? $parsed_income['min_toman'] : null,
            'income_max_toman' => isset( $parsed_income['max_toman'] ) ? $parsed_income['max_toman'] : null,
            'investment_toman' => isset( $parsed_investment['value_toman'] ) ? $parsed_investment['value_toman'] : null,
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

        if ( $row['income_num'] && empty( $row['income_toman'] ) ) {
            $row['income_toman'] = $row['income_num'];
        }
        if ( $row['investment_num'] && empty( $row['investment_toman'] ) ) {
            $row['investment_toman'] = $row['investment_num'];
        }

        if ( isset( $data['created_at'] ) && ! empty( $data['created_at'] ) ) {
            $row['created_at'] = sanitize_text_field( $data['created_at'] );
        }

        $row = array_map( function( $value ) {
            return is_string( $value ) ? wp_slash( $value ) : $value;
        }, $row );

        $wpdb->insert( $table, $row );
        $insert_id = $wpdb->insert_id;

        if ( class_exists( 'BKJA_Chat' ) ) {
            BKJA_Chat::clear_all_caches();
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
     * Resolve job_title_id from ID, slug or label.
     */
    protected static function resolve_job_title_id( $job_title ) {
        global $wpdb;

        $table_job_titles = $wpdb->prefix . 'bkja_job_titles';

        if ( is_numeric( $job_title ) ) {
            return absint( $job_title );
        }

        $slug = sanitize_title( (string) $job_title );

        $found_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_job_titles} WHERE slug = %s LIMIT 1",
                $slug
            )
        );

        if ( $found_id ) {
            return (int) $found_id;
        }

        $found_label = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table_job_titles} WHERE label = %s LIMIT 1",
                $job_title
            )
        );

        if ( $found_label ) {
            return (int) $found_label;
        }

        return null;
    }

    /**
     * خلاصه شغل بر اساس شناسه عنوان شغلی (ترجیح مسیر گروه).
     */
    public static function get_job_summary_by_job_title_id( $job_title_id, $filters = array() ) {
        $job_title_id = absint( $job_title_id );
        if ( $job_title_id <= 0 ) {
            return array();
        }

        $context = self::resolve_job_group_context( array( 'job_title_id' => $job_title_id ) );
        if ( empty( $context['job_title_ids'] ) ) {
            return array();
        }

        return self::get_job_summary( $context, $filters );
    }

    /**
     * جدید: خلاصه شغل (میانگین و ترکیب داده‌ها)
     */
    public static function get_job_summary($job_title, $filters = array()) {
        global $wpdb;
        $table        = $wpdb->prefix . 'bkja_jobs';
        $table_titles = $wpdb->prefix . 'bkja_job_titles';

        self::ensure_numeric_job_columns();
        self::ensure_job_title_schema();

        $window_months = (int) get_option( 'bkja_stats_window_months', 12 );
        if ( $window_months <= 0 ) {
            $window_months = 12;
        }

        $context      = self::resolve_job_title_group( $job_title );
        $job_title_id = ! empty( $context['job_title_ids'] ) ? (int) $context['job_title_ids'][0] : null;
        $job_label    = isset( $context['base_label'] ) ? $context['base_label'] : '';
        $job_slug     = $job_label ? sanitize_title( $job_label ) : '';
        $job_title    = $job_label ?: ( is_string( $job_title ) ? $job_title : '' );

        $job_ids = ! empty( $context['job_title_ids'] ) ? array_map( 'intval', $context['job_title_ids'] ) : array();

        $where        = self::build_job_where_clause( $context, '', $window_months, $filters, 'j' );
        $where_clause = $where['where_clause'];
        $where_params = $where['where_params'];

        $prepare_with_params = function( $sql, $extra_params = array() ) use ( $wpdb, $where_params ) {
            $params = array_merge( $where_params, (array) $extra_params );
            array_unshift( $params, $sql );

            return call_user_func_array( array( $wpdb, 'prepare' ), $params );
        };

        $latest_at = $wpdb->get_var( $prepare_with_params( "SELECT MAX(j.created_at) FROM {$table} j WHERE {$where_clause}" ) );
        $total_reports = (int) $wpdb->get_var( $prepare_with_params( "SELECT COUNT(*) FROM {$table} j WHERE {$where_clause}" ) );

        $stat_rows = $wpdb->get_results(
            $prepare_with_params(
                "SELECT j.income, j.income_toman, j.income_min_toman, j.income_max_toman, j.income_num, j.investment, j.investment_toman, j.investment_num, j.experience_years, j.hours_per_day, j.days_per_week
                 FROM {$table} j
                 WHERE {$where_clause}"
            )
        );

        $normalize_value = function( $value ) {
            if ( ! is_numeric( $value ) ) {
                return null;
            }
            $value = (int) $value;
            if ( $value <= 0 || $value > 1000000000000 ) {
                return null;
            }
            return $value;
        };

        $normalize_legacy_or_maybe_million = function( $value ) use ( $normalize_value ) {
            if ( ! is_numeric( $value ) ) {
                return null;
            }

            $value = (int) $value;
            if ( $value <= 0 ) {
                return null;
            }

            if ( $value < 1000000 ) {
                $value = $value * 1000000;
            }

            return $normalize_value( $value );
        };

        $income_values      = array();
        $income_range_mins  = array();
        $income_range_maxes = array();
        $investment_values  = array();

        $total_records          = 0;
        $income_valid_count     = 0;
        $income_unknown_count   = 0;
        $income_invalid_count   = 0;
        $investment_valid_count = 0;
        $investment_unknown_count = 0;
        $investment_invalid_count = 0;

        $sum_experience = 0;
        $exp_count      = 0;
        $sum_hours      = 0;
        $hours_count    = 0;
        $sum_days       = 0;
        $days_count     = 0;

        $is_income_composite = function( $text ) {
            $text = is_string( $text ) ? trim( $text ) : '';
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
        };

        foreach ( $stat_rows as $srow ) {
            $total_records++;
            $income_base = $normalize_value( $srow->income_toman );
            if ( null === $income_base && isset( $srow->income_num ) ) {
                $income_base = $normalize_legacy_or_maybe_million( $srow->income_num );
            }

            $income_min  = $normalize_value( $srow->income_min_toman );
            $income_max  = $normalize_value( $srow->income_max_toman );

            if ( $income_min ) {
                $income_range_mins[] = $income_min;
            }
            if ( $income_max ) {
                $income_range_maxes[] = $income_max;
            }

            $income_value = null;
            if ( $income_base ) {
                $income_value = $income_base;
            } elseif ( $income_min && $income_max ) {
                $income_value = (int) round( ( $income_min + $income_max ) / 2 );
            }

            if ( $income_value ) {
                $income_values[] = $income_value;
                $income_valid_count++;
            } else {
                $income_text = isset( $srow->income ) ? trim( (string) $srow->income ) : '';
                if ( '' !== $income_text && class_exists( 'BKJA_Parser' ) ) {
                    if ( $is_income_composite( $income_text ) ) {
                        $income_invalid_count++;
                    } else {
                        $parsed = BKJA_Parser::parse_income_to_toman( $income_text );
                        if ( 'unknown' === $parsed['status'] ) {
                            $income_unknown_count++;
                        } elseif ( in_array( $parsed['status'], array( 'invalid', 'ambiguous_unit' ), true ) ) {
                            $income_invalid_count++;
                        } elseif ( 'ok' === $parsed['status'] && ! empty( $parsed['value'] ) ) {
                            $parsed_value = $normalize_value( $parsed['value'] );
                            if ( $parsed_value ) {
                                $income_values[] = $parsed_value;
                                $income_valid_count++;
                            }
                            $parsed_min = isset( $parsed['min'] ) ? $normalize_value( $parsed['min'] ) : null;
                            $parsed_max = isset( $parsed['max'] ) ? $normalize_value( $parsed['max'] ) : null;
                            if ( $parsed_min ) {
                                $income_range_mins[] = $parsed_min;
                            }
                            if ( $parsed_max ) {
                                $income_range_maxes[] = $parsed_max;
                            }
                        }
                    }
                }
            }

            $investment_base = $normalize_value( $srow->investment_toman );
            if ( null === $investment_base && isset( $srow->investment_num ) ) {
                $investment_base = $normalize_legacy_or_maybe_million( $srow->investment_num );
            }
            if ( $investment_base ) {
                $investment_values[] = $investment_base;
                $investment_valid_count++;
            } else {
                $investment_text = isset( $srow->investment ) ? trim( (string) $srow->investment ) : '';
                if ( '' !== $investment_text && class_exists( 'BKJA_Parser' ) ) {
                    $parsed = BKJA_Parser::parse_investment_to_toman( $investment_text );
                    if ( 'unknown' === $parsed['status'] ) {
                        $investment_unknown_count++;
                    } elseif ( in_array( $parsed['status'], array( 'invalid', 'ambiguous_unit' ), true ) ) {
                        $investment_invalid_count++;
                    } elseif ( 'ok' === $parsed['status'] && ! empty( $parsed['value'] ) ) {
                        $parsed_value = $normalize_value( $parsed['value'] );
                        if ( $parsed_value ) {
                            $investment_values[] = $parsed_value;
                            $investment_valid_count++;
                        }
                    }
                }
            }

            if ( isset( $srow->experience_years ) && (int) $srow->experience_years > 0 ) {
                $sum_experience += (int) $srow->experience_years;
                $exp_count++;
            }
            if ( isset( $srow->hours_per_day ) && (int) $srow->hours_per_day > 0 ) {
                $sum_hours += (int) $srow->hours_per_day;
                $hours_count++;
            }
            if ( isset( $srow->days_per_week ) && (int) $srow->days_per_week > 0 ) {
                $sum_days += (int) $srow->days_per_week;
                $days_count++;
            }
        }

        $income_numeric_total = count( $income_values );
        $income_valid_count   = max( $income_valid_count, $income_numeric_total );

        $quantile = function( $values, $q ) {
            $count = count( $values );
            if ( $count === 0 ) {
                return null;
            }
            sort( $values, SORT_NUMERIC );
            $pos   = ( $count - 1 ) * $q;
            $floor = (int) floor( $pos );
            $ceil  = (int) ceil( $pos );
            if ( $floor === $ceil ) {
                return $values[ $floor ];
            }
            $d0 = $values[ $floor ] * ( $ceil - $pos );
            $d1 = $values[ $ceil ] * ( $pos - $floor );
            return $d0 + $d1;
        };

        $filter_outliers = function( $values ) use ( $quantile ) {
            if ( count( $values ) < 10 ) {
                return $values;
            }
            $p5  = $quantile( $values, 0.05 );
            $p95 = $quantile( $values, 0.95 );

            $filtered = array();
            foreach ( $values as $v ) {
                if ( ( null !== $p5 && $v < $p5 ) || ( null !== $p95 && $v > $p95 ) ) {
                    continue;
                }
                $filtered[] = $v;
            }

            return ! empty( $filtered ) ? $filtered : $values;
        };

        $income_filtered = $filter_outliers( $income_values );
        $income_used     = count( $income_filtered );

        $calc_median = function( $values ) {
            $values = array_filter( $values, function( $v ) { return is_numeric( $v ) && $v > 0; } );
            $count  = count( $values );
            if ( 0 === $count ) {
                return null;
            }
            sort( $values, SORT_NUMERIC );
            $mid = (int) floor( ( $count - 1 ) / 2 );
            if ( $count % 2 ) {
                return $values[ $mid ];
            }
            return ( $values[ $mid ] + $values[ $mid + 1 ] ) / 2;
        };

        $calc_avg = function( $values ) {
            $values = array_filter( $values, function( $v ) { return is_numeric( $v ) && $v > 0; } );
            if ( empty( $values ) ) {
                return null;
            }
            return array_sum( $values ) / count( $values );
        };

        $avg_income = $calc_avg( $income_filtered );
        $median_income = $calc_median( $income_filtered );
        $min_income = ! empty( $income_filtered ) ? min( $income_filtered ) : null;
        $max_income = ! empty( $income_filtered ) ? max( $income_filtered ) : null;

        $data_limited = ( $income_valid_count < 5 );
        if ( $data_limited && $median_income ) {
            $avg_income = $median_income;
        }

        $income_range_available = false;
        if ( ! empty( $income_range_mins ) || ! empty( $income_range_maxes ) ) {
            $income_range_available = true;
        } elseif ( $income_used >= 2 ) {
            $income_range_available = true;
        }

        if ( empty( $income_range_mins ) && empty( $income_range_maxes ) ) {
            $range_min = $min_income;
            $range_max = $max_income;
        } else {
            $range_min = ! empty( $income_range_mins ) ? min( $income_range_mins ) : $min_income;
            $range_max = ! empty( $income_range_maxes ) ? max( $income_range_maxes ) : $max_income;
        }

        if ( $range_min && $range_max && $range_min > $range_max ) {
            $tmp       = $range_min;
            $range_min = $range_max;
            $range_max = $tmp;
        }

        if ( $avg_income && $range_min && $range_max ) {
            if ( $avg_income < $range_min ) {
                $avg_income = $range_min;
            } elseif ( $avg_income > $range_max ) {
                $avg_income = $range_max;
            }
        }

        if ( ! $income_range_available ) {
            $range_min = null;
            $range_max = null;
        }

        $avg_investment = $calc_avg( $investment_values );
        $min_investment = ! empty( $investment_values ) ? min( $investment_values ) : null;
        $max_investment = ! empty( $investment_values ) ? max( $investment_values ) : null;

        if ( $min_investment && $max_investment && $min_investment > $max_investment ) {
            $tmp            = $min_investment;
            $min_investment = $max_investment;
            $max_investment = $tmp;
        }

        if ( $avg_investment && $min_investment && $max_investment ) {
            if ( $avg_investment < $min_investment ) {
                $avg_investment = $min_investment;
            } elseif ( $avg_investment > $max_investment ) {
                $avg_investment = $max_investment;
            }
        }

        $avg_experience_years = $exp_count > 0 ? $sum_experience / $exp_count : null;
        $avg_hours_per_day    = $hours_count > 0 ? $sum_hours / $hours_count : null;
        $avg_days_per_week    = $days_count > 0 ? $sum_days / $days_count : null;

        $cities = $wpdb->get_col(
            $prepare_with_params(
                "SELECT city FROM {$table} j WHERE {$where_clause} AND city <> '' GROUP BY city ORDER BY COUNT(*) DESC, city ASC LIMIT 5"
            )
        );

        $adv_rows = $wpdb->get_col(
            $prepare_with_params(
                "SELECT advantages FROM {$table} j WHERE {$where_clause} AND advantages IS NOT NULL AND advantages <> '' ORDER BY created_at DESC LIMIT 50"
            )
        );
        $dis_rows = $wpdb->get_col(
            $prepare_with_params(
                "SELECT disadvantages FROM {$table} j WHERE {$where_clause} AND disadvantages IS NOT NULL AND disadvantages <> '' ORDER BY created_at DESC LIMIT 50"
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

        $dominant_employment_type = $wpdb->get_var(
            $prepare_with_params(
                "SELECT employment_type
                 FROM {$table} j
                 WHERE {$where_clause} AND employment_type IS NOT NULL AND employment_type <> ''
                 GROUP BY employment_type
                 ORDER BY COUNT(*) DESC
                 LIMIT 1"
            )
        );

        $gender_rows = $wpdb->get_results(
            $prepare_with_params(
                "SELECT gender, COUNT(*) AS c
                 FROM {$table} j
                 WHERE {$where_clause}
                 GROUP BY gender"
            )
        );

        $gender_summary = null;
        if ( $gender_rows ) {
            $male   = 0;
            $female = 0;
            $other  = 0;

            foreach ( $gender_rows as $g_row ) {
                $gender = $g_row->gender;
                $count  = isset( $g_row->c ) ? (int) $g_row->c : 0;

                if ( 'male' === $gender ) {
                    $male += $count;
                } elseif ( 'female' === $gender ) {
                    $female += $count;
                } elseif ( $gender ) {
                    $other += $count;
                }
            }

            $total = $male + $female + $other;
            if ( $total > 0 ) {
                $male_ratio   = $male / $total;
                $female_ratio = $female / $total;

                if ( $male_ratio >= 0.6 ) {
                    $gender_summary = 'تجربه‌ها بیشتر از سمت مردان ثبت شده';
                } elseif ( $female_ratio >= 0.6 ) {
                    $gender_summary = 'تجربه‌ها بیشتر از سمت زنان ثبت شده';
                } else {
                    $gender_summary = 'تجربه‌ها از هر دو جنس ثبت شده است';
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                'BKJA summary stats (toman) => avg_income: ' . print_r( $avg_income, true ) .
                ' min_income: ' . print_r( $range_min, true ) .
                ' max_income: ' . print_r( $range_max, true ) .
                ' avg_investment: ' . print_r( $avg_investment, true )
            );
        }

        $format_label = function( $value ) {
            return $value ? bkja_format_toman_as_million_label( $value ) : null;
        };

        return array(
            'job_title'         => $job_title,
            'job_title_id'      => $job_title_id,
            'job_title_label'   => $job_label ?: $job_title,
            'job_title_slug'    => $job_slug ?: sanitize_title( $job_title ),
            'group_key'         => isset( $context['group_key'] ) ? $context['group_key'] : null,
            'job_title_ids'     => $job_ids,
            'avg_income'        => $avg_income ? round( (float) $avg_income, 1 ) : null,
            'avg_income_label'  => $format_label( $avg_income ),
            'avg_income_method' => $data_limited ? 'median' : 'mean',
            'median_income'     => $median_income ? (float) $median_income : null,
            'median_income_label' => $format_label( $median_income ),
            'min_income'        => $range_min ? (float) $range_min : null,
            'max_income'        => $range_max ? (float) $range_max : null,
            'min_income_label'  => $format_label( $range_min ),
            'max_income_label'  => $format_label( $range_max ),
            'income_count'      => $income_used,
            'income_numeric_total' => $income_numeric_total,
            'total_records'     => $total_records,
            'income_valid_count'   => $income_valid_count,
            'income_unknown_count' => $income_unknown_count,
            'income_invalid_count' => $income_invalid_count,
            'avg_investment'    => $avg_investment ? round( (float) $avg_investment, 1 ) : null,
            'avg_investment_label' => $format_label( $avg_investment ),
            'min_investment'    => $min_investment ? (float) $min_investment : null,
            'max_investment'    => $max_investment ? (float) $max_investment : null,
            'min_investment_label' => $format_label( $min_investment ),
            'max_investment_label' => $format_label( $max_investment ),
            'investment_count'  => count( $investment_values ),
            'investment_valid_count'   => $investment_valid_count,
            'investment_unknown_count' => $investment_unknown_count,
            'investment_invalid_count' => $investment_invalid_count,
            'data_limited'      => $data_limited,
            'avg_experience_years' => $avg_experience_years ? round( (float) $avg_experience_years, 1 ) : null,
            'avg_hours_per_day'    => $avg_hours_per_day ? round( (float) $avg_hours_per_day, 1 ) : null,
            'avg_days_per_week'    => $avg_days_per_week ? round( (float) $avg_days_per_week, 1 ) : null,
            'count_reports'     => $total_reports,
            'latest_at'         => $latest_at,
            'cities'            => $cities,
            'genders'           => null,
            'advantages'        => $advantages,
            'disadvantages'     => $disadvantages,
            'dominant_employment_type'  => $dominant_employment_type,
            'dominant_employment_label' => $dominant_employment_type ? bkja_get_employment_label( $dominant_employment_type ) : null,
            'gender_summary'    => $gender_summary,
            'window_months'     => $window_months,
        );
    }

    /**
     * جدید: رکوردهای واقعی کاربران برای یک شغل
     */
    public static function get_job_records_by_job_title_id( $job_title_id, $limit = 5, $offset = 0, $filters = array() ) {
        $job_title_id = absint( $job_title_id );
        if ( $job_title_id <= 0 ) {
            return array( 'records' => array(), 'has_more' => false, 'next_offset' => null, 'limit' => $limit, 'offset' => $offset );
        }

        $context = self::resolve_job_group_context( array( 'job_title_id' => $job_title_id ) );
        if ( empty( $context['job_title_ids'] ) ) {
            return array( 'records' => array(), 'has_more' => false, 'next_offset' => null, 'limit' => $limit, 'offset' => $offset );
        }

        return self::get_job_records( $context, $limit, $offset, $filters );
    }

    /**
     * جدید: رکوردهای واقعی کاربران برای یک شغل
     */
    public static function get_job_records($job_title, $limit = 5, $offset = 0, $filters = array()) {
        global $wpdb;
        $table        = $wpdb->prefix . 'bkja_jobs';
        $table_titles = $wpdb->prefix . 'bkja_job_titles';

        self::ensure_job_title_schema();

        $context = self::resolve_job_title_group( $job_title );

        $job_ids = ! empty( $context['job_title_ids'] ) ? array_map( 'intval', $context['job_title_ids'] ) : array();

        $window_months = (int) get_option( 'bkja_stats_window_months', 12 );
        if ( $window_months <= 0 ) {
            $window_months = 12;
        }

        $where        = self::build_job_where_clause( $context, '', $window_months, $filters, 'j' );
        $where_clause = $where['where_clause'];
        $where_params = $where['where_params'];

        $prepare_with_params = function( $sql, $extra_params = array() ) use ( $wpdb, $where_params ) {
            $params = array_merge( $where_params, (array) $extra_params );
            array_unshift( $params, $sql );

            return call_user_func_array( array( $wpdb, 'prepare' ), $params );
        };

        $limit     = max( 1, (int) $limit );
        $offset    = max( 0, (int) $offset );
        $limit_cap = $limit + 1;

        $records_sql = $prepare_with_params(
            "SELECT j.id, j.title, j.variant_title, j.income, j.investment, j.income_num, j.investment_num, j.income_toman, j.income_min_toman, j.income_max_toman, j.investment_toman, j.experience_years, j.employment_type, j.hours_per_day, j.days_per_week, j.source, j.city, j.gender, j.advantages, j.disadvantages, j.details, j.created_at, jt.label AS job_title_label, jt.slug AS job_title_slug
                 FROM {$table} j
                 LEFT JOIN {$table_titles} jt ON jt.id = j.job_title_id
                 WHERE {$where_clause}
                 ORDER BY j.created_at DESC
                 LIMIT %d OFFSET %d",
            array( $limit_cap, $offset )
        );

        $results = $wpdb->get_results( $records_sql );

        $records = array();
        foreach ( $results as $row ) {
                $income_note = null;
                $income_num  = isset( $row->income_toman ) && $row->income_toman > 0
                    ? (int) $row->income_toman
                    : ( ( isset( $row->income_num ) && $row->income_num > 0 ) ? (int) $row->income_num : 0 );

                if ( 0 === $income_num && ! empty( $row->income ) && class_exists( 'BKJA_Parser' ) ) {
                    $parsed_income = BKJA_Parser::parse_income_to_toman( (string) $row->income );
                    if ( 'ambiguous_unit' === $parsed_income['status'] ) {
                        $income_note = 'واحد نامشخص';
                    } else {
                        $income_note = 'عدد دقیق قابل استخراج نبود';
                    }
                }

                $records[] = array(
                    'id'                     => (int) $row->id,
                    'job_title'              => $row->title,
                    'job_title_label'        => $row->job_title_label ? $row->job_title_label : $row->title,
                    'job_title_slug'         => $row->job_title_slug ? $row->job_title_slug : sanitize_title( $row->title ),
                    'variant_title'          => $row->variant_title ? $row->variant_title : $row->title,
                    'income'                 => $row->income,
                    'income_num'             => $income_num,
                    'income_note'            => $income_note,
                    'income_min_toman'       => isset( $row->income_min_toman ) ? (int) $row->income_min_toman : null,
                    'income_max_toman'       => isset( $row->income_max_toman ) ? (int) $row->income_max_toman : null,
                    'investment'             => $row->investment,
                    'investment_num'         => isset( $row->investment_toman ) && $row->investment_toman > 0 ? (int) $row->investment_toman : ( ( isset( $row->investment_num ) && $row->investment_num > 0 ) ? (int) $row->investment_num : 0 ),
                    'investment_toman'       => isset( $row->investment_toman ) ? (int) $row->investment_toman : null,
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

        $has_more    = count( $records ) > $limit;
        if ( $has_more ) {
            array_pop( $records );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'BKJA get_job_records context: ' . wp_json_encode( $context ) . ' WHERE ' . $where_clause . ' params ' . wp_json_encode( $where_params ) . ' SQL ' . $records_sql . ' result_count=' . count( $records ) . ' has_more=' . ( $has_more ? '1' : '0' ) );
        }

        return array(
            'records'       => $records,
            'has_more'      => $has_more,
            'next_offset'   => $has_more ? $offset + $limit : null,
            'limit'         => $limit,
            'offset'        => $offset,
            'group_key'     => isset( $context['group_key'] ) ? $context['group_key'] : null,
            'job_title_ids' => $job_ids,
        );
    }

    /**
     * Return base job titles grouped by category with counts aggregated across variants.
     */
    public static function get_job_titles_by_category( $category_id ) {
        global $wpdb;
        $table_titles = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs   = $wpdb->prefix . 'bkja_jobs';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT primary_rows.id,
                        COALESCE(primary_rows.base_label, primary_rows.label) AS label,
                        COALESCE(primary_rows.base_slug, primary_rows.slug) AS slug,
                        primary_rows.group_key,
                        SUM(COALESCE(j_counts.cnt, 0)) AS jobs_count,
                        GROUP_CONCAT(DISTINCT other_titles.id) AS job_title_ids
                 FROM {$table_titles} primary_rows
                 LEFT JOIN {$table_titles} other_titles ON other_titles.group_key = primary_rows.group_key
                 LEFT JOIN (
                    SELECT job_title_id, COUNT(*) AS cnt FROM {$table_jobs} GROUP BY job_title_id
                 ) j_counts ON j_counts.job_title_id = other_titles.id
                 WHERE primary_rows.category_id = %d AND primary_rows.is_visible = 1 AND primary_rows.is_primary = 1
                 GROUP BY primary_rows.group_key, primary_rows.id, label, slug
                 ORDER BY label ASC",
                $category_id
            )
        );
    }

    /**
     * Build a WHERE clause and params for job queries using group context and optional filters.
     */
    protected static function build_job_where_clause( $context, $job_title, $window_months = null, $filters = array(), $table_alias = 'j' ) {
        global $wpdb;
        $clauses       = array();
        $params        = array();
        $prefix        = $table_alias ? $table_alias . '.' : '';
        $job_ids       = ! empty( $context['job_title_ids'] ) ? array_values( array_unique( array_filter( array_map( 'intval', $context['job_title_ids'] ) ) ) ) : array();
        $window_months = $window_months ? absint( $window_months ) : 0;
        $fallback_title = $job_title;

        if ( ! $fallback_title && ! empty( $context['label'] ) ) {
            $fallback_title = $context['label'];
        }

        if ( ! $fallback_title && ! empty( $context['base_label'] ) ) {
            $fallback_title = $context['base_label'];
        }

        if ( empty( $job_ids ) && ! empty( $context['group_key'] ) ) {
            $job_ids = self::get_job_title_ids_for_group( $context['group_key'] );
        }

        if ( ! empty( $job_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
            $clauses[]    = "{$prefix}job_title_id IN ({$placeholders})";
            $params       = array_merge( $params, $job_ids );
        } elseif ( '' !== $fallback_title ) {
            $clauses[] = "{$prefix}title = %s";
            $params[]  = $fallback_title;
        } else {
            $clauses[] = '1=0';
        }

        if ( $window_months > 0 ) {
            $clauses[] = "{$prefix}created_at >= DATE_SUB(NOW(), INTERVAL %d MONTH)";
            $params[]  = $window_months;
        }

        $filters = is_array( $filters ) ? $filters : array();

        if ( ! empty( $filters['gender'] ) ) {
            $clauses[] = "{$prefix}gender = %s";
            $params[]  = sanitize_text_field( $filters['gender'] );
        }

        if ( ! empty( $filters['city'] ) ) {
            $clauses[] = "{$prefix}city LIKE %s";
            $params[]  = '%' . $wpdb->esc_like( $filters['city'] ) . '%';
        }

        if ( isset( $filters['income_min'] ) && is_numeric( $filters['income_min'] ) && $filters['income_min'] > 0 ) {
            $clauses[] = "{$prefix}income_toman >= %d";
            $params[]  = (int) $filters['income_min'];
        }

        if ( isset( $filters['income_max'] ) && is_numeric( $filters['income_max'] ) && $filters['income_max'] > 0 ) {
            $clauses[] = "{$prefix}income_toman <= %d";
            $params[]  = (int) $filters['income_max'];
        }

        if ( isset( $filters['investment_min'] ) && is_numeric( $filters['investment_min'] ) && $filters['investment_min'] > 0 ) {
            $clauses[] = "{$prefix}investment_toman >= %d";
            $params[]  = (int) $filters['investment_min'];
        }

        if ( isset( $filters['investment_max'] ) && is_numeric( $filters['investment_max'] ) && $filters['investment_max'] > 0 ) {
            $clauses[] = "{$prefix}investment_toman <= %d";
            $params[]  = (int) $filters['investment_max'];
        }

        $where_clause = ! empty( $clauses ) ? implode( ' AND ', $clauses ) : '1=1';

        return array(
            'where_clause' => $where_clause,
            'where_params' => $params,
        );
    }

    /**
     * Return job variants for a base title.
     */
    public static function get_job_variants_for_title( $job_title_id, $window_months = 12 ) {
        global $wpdb;
        $table        = $wpdb->prefix . 'bkja_jobs';
        $table_titles = $wpdb->prefix . 'bkja_job_titles';

        $context = self::resolve_job_group_context( $job_title_id );
        $ids     = $context['job_title_ids'];

        if ( empty( $ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = $ids;

        $sql = "SELECT j.id, j.variant_title, j.title, j.job_title_id, j.income, j.investment, j.city, j.gender, j.created_at, jt.label AS job_title_label, jt.slug AS job_title_slug, jt.group_key
                 FROM {$table} j
                 LEFT JOIN {$table_titles} jt ON jt.id = j.job_title_id
                 WHERE j.job_title_id IN ({$placeholders}) AND j.created_at >= DATE_SUB(NOW(), INTERVAL {$window_months} MONTH)
                 ORDER BY j.created_at DESC";

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $records = array();
        foreach ( $results as $row ) {
            $records[] = array(
                'id'                 => (int) $row->id,
                'variant_title'      => $row->variant_title ? $row->variant_title : $row->title,
                'job_title_id'       => isset( $row->job_title_id ) ? (int) $row->job_title_id : null,
                'job_title_label'    => $row->job_title_label,
                'job_title_slug'     => $row->job_title_slug,
                'group_key'          => isset( $row->group_key ) ? $row->group_key : $context['group_key'],
                'income'             => $row->income,
                'investment'         => $row->investment,
                'city'               => $row->city,
                'gender'             => $row->gender,
                'created_at'         => $row->created_at,
                'created_at_display' => bkja_format_job_date( $row->created_at ),
            );
        }

        return $records;
    }
}

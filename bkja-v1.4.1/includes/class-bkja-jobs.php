<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Jobs {

    // جستجوی پیشرفته مشاغل برای پنل مدیریت و API
    public static function get_jobs($per_page=20, $page_number=1, $city_filter='', $income_min=0, $income_max=0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        $sql = "SELECT * FROM {$table} WHERE 1=1";
        $params = [];
        if ($city_filter) {
            $sql .= " AND city LIKE %s";
            $params[] = "%$city_filter%";
        }
        if ($income_min) {
            $sql .= " AND income >= %f";
            $params[] = $income_min;
        }
        if ($income_max) {
            $sql .= " AND income <= %f";
            $params[] = $income_max;
        }
        $sql .= " ORDER BY created_at DESC";
        $offset = ($page_number - 1) * $per_page;
        $sql .= " LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }

    // گرفتن دسته‌ها
    public static function get_categories() {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_categories';
        return $wpdb->get_results("SELECT id, name FROM {$table} ORDER BY id ASC");
    }

    // لیست عناوین پایه شغل در یک دسته
    public static function get_jobs_by_category($cat_id) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log('BKJA get_jobs_by_category called with category_id: ' . print_r($cat_id, true));
        }

        $titles = BKJA_Database::get_job_titles_by_category( $cat_id );

        $mapped = array();
        foreach ( $titles as $title ) {
            $mapped[] = (object) array(
                'id'         => isset( $title->id ) ? (int) $title->id : 0,
                'job_title'  => $title->label,
                'label'      => $title->label,
                'slug'       => $title->slug,
                'group_key'  => isset( $title->group_key ) ? $title->group_key : '',
                'jobs_count' => isset( $title->jobs_count ) ? (int) $title->jobs_count : 0,
                'job_title_ids' => isset( $title->job_title_ids ) ? array_map( 'intval', array_filter( explode( ',', $title->job_title_ids ) ) ) : array(),
            );
        }

        return $mapped;
    }
    // خلاصه شغل
    public static function get_job_summary($job_title, $filters = array()) {
        return BKJA_Database::get_job_summary($job_title, $filters);
    }

    // رکوردهای شغل (برای نمایش جزئیات کاربران)
    public static function get_job_records($job_title, $limit = 5, $offset = 0, $filters = array()) {
        return BKJA_Database::get_job_records($job_title, $limit, $offset, $filters);
    }

    // لیست واریانت‌های یک عنوان پایه
    public static function get_job_variants( $job_title_id, $window_months = 12 ) {
        return BKJA_Database::get_job_variants_for_title( $job_title_id, $window_months );
    }

    // گرفتن جزئیات کامل یک شغل (برای ارسال به JS)
    public static function get_job_detail($job_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT j.id, j.title, j.variant_title, j.job_title_id, j.income, j.investment, j.income_num, j.investment_num, j.income_toman, j.income_min_toman, j.income_max_toman, j.investment_toman, j.experience_years, j.employment_type, j.hours_per_day, j.days_per_week, j.source, j.city, j.gender, j.advantages, j.disadvantages, j.details, j.created_at, j.category_id, jt.label AS job_title_label, jt.slug AS job_title_slug
                 FROM {$table} j
                 LEFT JOIN {$wpdb->prefix}bkja_job_titles jt ON jt.id = j.job_title_id
                 WHERE j.id = %d LIMIT 1",
                $job_id
            )
        );
        if ( ! $row ) return null;

        return array(
            'id'            => (int)$row->id,
            'job_title'     => $row->title,
            'job_title_label' => isset( $row->job_title_label ) ? $row->job_title_label : $row->title,
            'job_title_slug'  => isset( $row->job_title_slug ) ? $row->job_title_slug : sanitize_title( $row->title ),
            'variant_title' => isset( $row->variant_title ) && $row->variant_title ? $row->variant_title : $row->title,
            'job_title_id'   => isset( $row->job_title_id ) ? (int) $row->job_title_id : null,
            'income'        => $row->income,
            'income_num'    => isset( $row->income_toman ) && $row->income_toman > 0 ? (int) $row->income_toman : ( isset( $row->income_num ) ? (int) $row->income_num : null ),
            'income_min_toman' => isset( $row->income_min_toman ) ? (int) $row->income_min_toman : null,
            'income_max_toman' => isset( $row->income_max_toman ) ? (int) $row->income_max_toman : null,
            'investment'    => $row->investment,
            'investment_num'=> isset( $row->investment_toman ) && $row->investment_toman > 0 ? (int) $row->investment_toman : ( isset( $row->investment_num ) ? (int) $row->investment_num : null ),
            'experience_years' => isset( $row->experience_years ) ? (int) $row->experience_years : null,
            'employment_type'   => $row->employment_type,
            'employment_label'  => function_exists( 'bkja_get_employment_label' ) ? bkja_get_employment_label( $row->employment_type ) : $row->employment_type,
            'hours_per_day'    => isset( $row->hours_per_day ) ? (int) $row->hours_per_day : null,
            'days_per_week'    => isset( $row->days_per_week ) ? (int) $row->days_per_week : null,
            'source'           => $row->source,
            'city'           => $row->city,
            'gender'         => $row->gender,
            'gender_label'   => function_exists( 'bkja_get_gender_label' ) ? bkja_get_gender_label( $row->gender ) : $row->gender,
            'advantages'     => $row->advantages,
            'disadvantages'  => $row->disadvantages,
            'details'        => $row->details,
            'created_at'     => $row->created_at,
            'created_label'  => function_exists( 'bkja_format_created_at' ) ? bkja_format_created_at( $row->created_at ) : $row->created_at,
            'category_id'    => (int)$row->category_id,
        );
    }
}


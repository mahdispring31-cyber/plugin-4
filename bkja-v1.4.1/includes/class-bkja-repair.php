<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BKJA_Repair_Tool {
    const DEFAULT_BATCH_LIMIT = 200;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'wp_ajax_bkja_repair_batch', array( __CLASS__, 'handle_batch' ) );
        add_action( 'wp_ajax_bkja_repair_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
    }

    public static function register_menu() {
        add_submenu_page(
            'bkja-assistant',
            __( 'BKJA Tools', 'bkja-assistant' ),
            __( 'Tools', 'bkja-assistant' ),
            'manage_options',
            'bkja-repair',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce   = wp_create_nonce( 'bkja_repair_nonce' );
        $report  = self::get_report_data();
        $total   = isset( $report['total_rows'] ) ? (int) $report['total_rows'] : 0;
        $ajaxurl = admin_url( 'admin-ajax.php' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'ابزار تعمیر پایگاه داده BKJA', 'bkja-assistant' ); ?></h1>
            <p><?php esc_html_e( 'این ابزار ارتباط بین جداول و مقادیر مالی را به‌صورت امن و مرحله‌ای بازسازی می‌کند. اجرا تنها برای مدیران مجاز است و می‌توانید ابتدا در حالت Dry Run خروجی را بررسی کنید.', 'bkja-assistant' ); ?></p>

            <div class="bkja-repair-panels">
                <div class="bkja-repair-card">
                    <h2><?php esc_html_e( 'گزارش فعلی', 'bkja-assistant' ); ?></h2>
                    <ul class="bkja-repair-list">
                        <li><?php printf( esc_html__( 'کل رکوردها: %d', 'bkja-assistant' ), isset( $report['total_rows'] ) ? (int) $report['total_rows'] : 0 ); ?></li>
                        <li><?php printf( esc_html__( 'job_title_id خالی: %d', 'bkja-assistant' ), isset( $report['job_title_null'] ) ? (int) $report['job_title_null'] : 0 ); ?></li>
                        <li><?php printf( esc_html__( 'دسته با مقدار ۰: %d', 'bkja-assistant' ), isset( $report['category_zero'] ) ? (int) $report['category_zero'] : 0 ); ?></li>
                        <li><?php printf( esc_html__( 'دسته ناهماهنگ با عنوان: %d', 'bkja-assistant' ), isset( $report['category_mismatch'] ) ? (int) $report['category_mismatch'] : 0 ); ?></li>
                        <li><?php printf( esc_html__( 'مقادیر مالی ناتمام/مشکوک: %d', 'bkja-assistant' ), isset( $report['income_invalid'] ) ? (int) $report['income_invalid'] : 0 ); ?></li>
                    </ul>
                </div>

                <div class="bkja-repair-card">
                    <h2><?php esc_html_e( 'اجرای تعمیر', 'bkja-assistant' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'هر مرحله حداکثر ۲۰۰ رکورد را پردازش می‌کند تا از تایم‌اوت جلوگیری شود. در پایان، خلاصه قبل و بعد نمایش داده می‌شود.', 'bkja-assistant' ); ?></p>
                    <div class="bkja-repair-actions">
                        <button class="button" id="bkja-repair-start-dry"><?php esc_html_e( 'Dry Run', 'bkja-assistant' ); ?></button>
                        <button class="button button-primary" id="bkja-repair-start-live"><?php esc_html_e( 'اجرای واقعی', 'bkja-assistant' ); ?></button>
                        <button class="button" id="bkja-repair-clear-cache"><?php esc_html_e( 'حذف کش پاسخ‌ها', 'bkja-assistant' ); ?></button>
                        <button class="button" id="bkja-repair-download" style="display:none; margin-right:8px;">
                            <?php esc_html_e( 'دانلود CSV موارد حل‌نشده', 'bkja-assistant' ); ?>
                        </button>
                    </div>
                    <div id="bkja-repair-progress" class="bkja-repair-progress"></div>
                    <div id="bkja-repair-summary" class="bkja-repair-summary"></div>
                </div>
            </div>
        </div>

        <style>
            .bkja-repair-panels { display:grid; gap:16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
            .bkja-repair-card { background:#fff; border:1px solid #e5e7eb; padding:16px; border-radius:10px; box-shadow:0 6px 14px rgba(0,0,0,0.04); }
            .bkja-repair-list { list-style:disc; padding-left:20px; margin:8px 0; }
            .bkja-repair-toggle { display:flex; gap:8px; align-items:center; margin:12px 0; }
            .bkja-repair-progress { margin-top:12px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e5e7eb; }
            .bkja-repair-summary { margin-top:12px; }
            .bkja-repair-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:12px 0; }
        </style>

        <script>
            (function(){
                const ajaxUrl = <?php echo wp_json_encode( $ajaxurl ); ?>;
                const nonce   = <?php echo wp_json_encode( $nonce ); ?>;
                const totalRows = <?php echo (int) $total; ?>;
                const state = {
                    running: false,
                    lastId: 0,
                    processed: 0,
                    fixed: 0,
                    skipped: 0,
                    unresolved: 0,
                    unresolvedRows: [],
                    dryRun: true,
                };

                const startBtnDry = document.getElementById('bkja-repair-start-dry');
                const startBtnLive = document.getElementById('bkja-repair-start-live');
                const progressBox = document.getElementById('bkja-repair-progress');
                const summaryBox = document.getElementById('bkja-repair-summary');
                const downloadBtn = document.getElementById('bkja-repair-download');
                const clearCacheBtn = document.getElementById('bkja-repair-clear-cache');

                function renderProgress() {
                    const pct = totalRows ? Math.min(100, Math.round((state.processed / totalRows) * 100)) : 0;
                    progressBox.innerHTML = `
                        <strong>${state.dryRun ? 'Dry Run' : 'اجرای واقعی'}:</strong> ${state.processed} / ${totalRows || '...'}
                        <br>اصلاح‌شده: ${state.fixed} | بدون تغییر: ${state.skipped} | حل‌نشده: ${state.unresolved}
                        <div style="margin-top:6px; background:#e5e7eb; border-radius:6px; height:12px; overflow:hidden;">
                            <div style="width:${pct}%; background:#0ea5e9; height:12px;"></div>
                        </div>
                    `;
                }

                function renderSummary(afterReport) {
                    if (!afterReport) return;
                    summaryBox.innerHTML = `
                        <h3>خلاصه پس از اجرا</h3>
                        <ul class="bkja-repair-list">
                            <li>کل رکوردها: ${afterReport.total_rows ? afterReport.total_rows : 0}</li>
                            <li>job_title_id خالی: ${afterReport.job_title_null ? afterReport.job_title_null : 0}</li>
                            <li>دسته با مقدار ۰: ${afterReport.category_zero ? afterReport.category_zero : 0}</li>
                            <li>دسته ناهماهنگ با عنوان: ${afterReport.category_mismatch ? afterReport.category_mismatch : 0}</li>
                            <li>مقادیر مالی ناتمام/مشکوک: ${afterReport.income_invalid ? afterReport.income_invalid : 0}</li>
                        </ul>
                    `;
                }

                function downloadCsv() {
                    if (!state.unresolvedRows.length) return;
                    const header = ['id','title','income'];
                    const rows = state.unresolvedRows.map(r => [r.id, r.title ? r.title.replace(/"/g,'""') : '', r.income ? r.income.replace(/"/g,'""') : '']);
                    const csv = [header.join(',')].concat(rows.map(r => r.map(c => `"${c}"`).join(','))).join('\n');
                    const blob = new Blob([csv], {type:'text/csv'});
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'bkja-unresolved.csv';
                    a.click();
                    URL.revokeObjectURL(url);
                }

                downloadBtn.addEventListener('click', downloadCsv);

                clearCacheBtn.addEventListener('click', function(){
                    clearCacheBtn.disabled = true;
                    clearCacheBtn.textContent = '<?php echo esc_js( __( 'در حال حذف کش...', 'bkja-assistant' ) ); ?>';
                    const form = new FormData();
                    form.append('action','bkja_repair_clear_cache');
                    form.append('nonce', nonce);
                    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:form})
                        .then(res => res.json())
                        .then(() => {
                            clearCacheBtn.disabled = false;
                            clearCacheBtn.textContent = '<?php echo esc_js( __( 'حذف کش پاسخ‌ها', 'bkja-assistant' ) ); ?>';
                        })
                        .catch(() => {
                            clearCacheBtn.disabled = false;
                            clearCacheBtn.textContent = '<?php echo esc_js( __( 'حذف کش پاسخ‌ها', 'bkja-assistant' ) ); ?>';
                        });
                });

                function runBatch() {
                    if (!state.running) return;
                    const form = new FormData();
                    form.append('action','bkja_repair_batch');
                    form.append('nonce', nonce);
                    form.append('last_id', state.lastId);
                    form.append('dry_run', state.dryRun ? '1' : '0');

                    fetch(ajaxUrl, { method:'POST', credentials:'same-origin', body: form })
                        .then(res => res.json())
                        .then(payload => {
                            if (!payload || !payload.success) {
                                state.running = false;
                                progressBox.innerHTML = '<span style="color:#b91c1c;">خطا در اجرای تعمیر</span>';
                                return;
                            }

                            const data = payload.data || {};
                            const batch = data.batch || {};
                            state.lastId = data.last_id || state.lastId;
                            state.processed += batch.processed_count || 0;
                            state.fixed += batch.fixed_count || 0;
                            state.skipped += batch.skipped_count || 0;
                            state.unresolved += batch.unresolved_count || 0;
                            if (Array.isArray(data.unresolved_rows)) {
                                state.unresolvedRows = state.unresolvedRows.concat(data.unresolved_rows);
                            }

                            renderProgress();

                            if (data.done) {
                                state.running = false;
                                renderSummary(data.after_report || null);
                                if (state.unresolvedRows.length) {
                                    downloadBtn.style.display = 'inline-block';
                                }
                                startBtnDry.disabled = false;
                                startBtnLive.disabled = false;
                                startBtnDry.textContent = 'Dry Run';
                                startBtnLive.textContent = '<?php echo esc_js( __( 'اجرای واقعی', 'bkja-assistant' ) ); ?>';
                            } else {
                                setTimeout(runBatch, 200);
                            }
                        })
                        .catch(() => {
                            state.running = false;
                            progressBox.innerHTML = '<span style="color:#b91c1c;">خطا در ارتباط با سرور</span>';
                            startBtnDry.disabled = false;
                            startBtnLive.disabled = false;
                            startBtnDry.textContent = 'Dry Run';
                            startBtnLive.textContent = '<?php echo esc_js( __( 'اجرای واقعی', 'bkja-assistant' ) ); ?>';
                        });
                }

                function startRepair(isDry){
                    if (state.running) return;
                    state.running = true;
                    state.lastId = 0;
                    state.processed = 0;
                    state.fixed = 0;
                    state.skipped = 0;
                    state.unresolved = 0;
                    state.unresolvedRows = [];
                    state.dryRun = !!isDry;
                    summaryBox.innerHTML = '';
                    downloadBtn.style.display = 'none';
                    startBtnDry.disabled = true;
                    startBtnLive.disabled = true;
                    startBtnDry.textContent = '<?php echo esc_js( __( 'در حال اجرا...', 'bkja-assistant' ) ); ?>';
                    startBtnLive.textContent = '<?php echo esc_js( __( 'در حال اجرا...', 'bkja-assistant' ) ); ?>';
                    renderProgress();
                    runBatch();
                }

                startBtnDry.addEventListener('click', function(){ startRepair(true); });
                startBtnLive.addEventListener('click', function(){ startRepair(false); });
            })();
        </script>
        <?php
    }

    public static function handle_batch() {
        check_ajax_referer( 'bkja_repair_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        }

        $last_id = isset( $_POST['last_id'] ) ? intval( $_POST['last_id'] ) : 0;
        $dry_run = ! empty( $_POST['dry_run'] );

        $result = self::process_batch( $last_id, $dry_run );

        wp_send_json_success( $result );
    }

    public static function handle_clear_cache() {
        check_ajax_referer( 'bkja_repair_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        }

        BKJA_Database::flush_plugin_caches();

        wp_send_json_success( array( 'cleared' => true ) );
    }

    private static function process_batch( $last_id, $dry_run = false ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        BKJA_Database::ensure_numeric_job_columns();

        $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $limit      = apply_filters( 'bkja_repair_batch_limit', self::DEFAULT_BATCH_LIMIT );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, category_id, job_title_id, income, investment, income_num, investment_num, income_toman, income_toman_canonical, income_min_toman, income_max_toman, investment_toman, investment_toman_canonical, hours_per_day, days_per_week, gender FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $last_id,
                absint( $limit )
            )
        );

        $fixed      = 0;
        $skipped    = 0;
        $unresolved = 0;
        $processed  = 0;
        $unresolved_rows = array();
        $last_processed  = $last_id;

        foreach ( $rows as $row ) {
            $processed++;
            $last_processed = (int) $row->id;

            $outcome = self::repair_row( $row, $dry_run );

            if ( $outcome['fixed'] ) {
                $fixed++;
            }

            if ( $outcome['unresolved'] ) {
                $unresolved++;
                $unresolved_rows[] = array(
                    'id'    => (int) $row->id,
                    'title' => $row->title,
                    'income'=> $row->income,
                );
            }

            if ( ! $outcome['fixed'] && ! $outcome['unresolved'] ) {
                $skipped++;
            }
        }

        $done = count( $rows ) < absint( $limit );

        if ( $done && ! $dry_run ) {
            BKJA_Database::flush_plugin_caches();
        }

        return array(
            'batch' => array(
                'processed_count'  => $processed,
                'fixed_count'      => $fixed,
                'skipped_count'    => $skipped,
                'unresolved_count' => $unresolved,
            ),
            'last_id'        => $last_processed,
            'done'           => $done,
            'total_rows'     => $total_rows,
            'unresolved_rows'=> $unresolved_rows,
            'after_report'   => $done ? self::get_report_data() : null,
        );
    }

    private static function repair_row( $row, $dry_run ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bkja_jobs';

        $updates    = array();
        $unresolved = false;

        $title_match = self::match_job_title( $row->title );
        $has_valid_category = self::is_valid_category_id( $row->category_id );

        if ( $title_match ) {
            if ( empty( $row->job_title_id ) || (int) $row->job_title_id !== (int) $title_match['id'] ) {
                $updates['job_title_id'] = (int) $title_match['id'];
            }

            if ( ! $has_valid_category || (int) $row->category_id !== (int) $title_match['category_id'] ) {
                $updates['category_id'] = (int) $title_match['category_id'];
            }
        } else {
            if ( empty( $row->job_title_id ) ) {
                $unresolved = true;
            }
            if ( ! $has_valid_category ) {
                $updates['category_id'] = null;
            }
        }

        $income_canonical = BKJA_Database::normalize_numeric_to_canonical_toman( $row->income_num );
        if ( $income_canonical && $income_canonical > 0 && $income_canonical <= 1000000000000 ) {
            if ( empty( $row->income_toman_canonical ) || (int) $row->income_toman_canonical !== (int) $income_canonical ) {
                $updates['income_toman_canonical'] = (int) $income_canonical;
            }
            if ( empty( $row->income_toman ) || $row->income_toman > 1000000000000 ) {
                $updates['income_toman'] = (int) $income_canonical;
            }
        } elseif ( isset( $row->income_toman_canonical ) && ( $row->income_toman_canonical <= 0 || $row->income_toman_canonical > 1000000000000 ) ) {
            $updates['income_toman_canonical'] = null;
        }

        $investment_canonical = BKJA_Database::normalize_numeric_to_canonical_toman( $row->investment_num );
        if ( null !== $investment_canonical && $investment_canonical >= 0 && $investment_canonical <= 1000000000000 ) {
            if ( $row->investment_toman_canonical === null || (int) $row->investment_toman_canonical !== (int) $investment_canonical ) {
                $updates['investment_toman_canonical'] = (int) $investment_canonical;
            }
            if ( $row->investment_toman === null || $row->investment_toman > 1000000000000 ) {
                $updates['investment_toman'] = (int) $investment_canonical;
            }
        } elseif ( isset( $row->investment_toman_canonical ) && ( $row->investment_toman_canonical < 0 || $row->investment_toman_canonical > 1000000000000 ) ) {
            $updates['investment_toman_canonical'] = null;
        }

        if ( isset( $row->hours_per_day ) && ( $row->hours_per_day < 1 || $row->hours_per_day > 18 ) ) {
            $updates['hours_per_day'] = null;
        }

        if ( isset( $row->days_per_week ) && ( $row->days_per_week < 1 || $row->days_per_week > 7 ) ) {
            $updates['days_per_week'] = null;
        }

        $normalized_gender = is_string( $row->gender ) ? trim( $row->gender ) : '';
        if ( '' === $normalized_gender || null === $row->gender ) {
            $normalized_gender = 'unknown';
        }

        $allowed_genders = array( 'male', 'female', 'both', 'unknown' );
        if ( ! in_array( $normalized_gender, $allowed_genders, true ) ) {
            $normalized_gender = 'unknown';
        }

        if ( $normalized_gender !== $row->gender ) {
            $updates['gender'] = $normalized_gender;
        }

        if ( empty( $updates ) && ! $unresolved ) {
            return array( 'fixed' => false, 'unresolved' => false );
        }

        if ( $dry_run ) {
            return array( 'fixed' => ! empty( $updates ), 'unresolved' => $unresolved );
        }

        if ( ! empty( $updates ) ) {
            $wpdb->update( $table, $updates, array( 'id' => (int) $row->id ) );
        }

        return array( 'fixed' => ! empty( $updates ), 'unresolved' => $unresolved );
    }

    private static function match_job_title( $title ) {
        global $wpdb;
        static $cache = null;

        $table = $wpdb->prefix . 'bkja_job_titles';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return null;
        }

        if ( null === $cache ) {
            $cache = array();
            $rows  = $wpdb->get_results( "SELECT id, label, base_label, category_id, is_primary FROM {$table}" );
            foreach ( $rows as $row ) {
                $cache[] = array(
                    'id'          => (int) $row->id,
                    'label'       => $row->label,
                    'base_label'  => $row->base_label,
                    'category_id' => (int) $row->category_id,
                    'is_primary'  => isset( $row->is_primary ) ? (int) $row->is_primary : 0,
                    'normalized_label'      => self::normalize_title( $row->label ),
                    'normalized_base_label' => self::normalize_title( $row->base_label ),
                    'base_first_token'      => self::first_token( self::normalize_title( $row->base_label ) ),
                );
            }
        }

        $normalized_title = self::normalize_title( $title );
        if ( '' === $normalized_title ) {
            return null;
        }

        foreach ( $cache as $item ) {
            if ( $normalized_title === $item['normalized_label'] ) {
                return $item;
            }
        }

        $first_token = self::first_token( $normalized_title );
        if ( $first_token ) {
            foreach ( $cache as $item ) {
                if ( 1 === (int) $item['is_primary'] && $first_token === $item['base_first_token'] ) {
                    return $item;
                }
            }
        }

        return null;
    }

    private static function normalize_title( $title ) {
        if ( ! is_string( $title ) ) {
            return '';
        }

        $normalized = trim( preg_replace( '/\s+/u', ' ', $title ) );
        $normalized = str_replace( array( 'ي', 'ك' ), array( 'ی', 'ک' ), $normalized );
        $normalized = preg_replace( '/[\x{1F300}-\x{1FAFF}]/u', '', $normalized );
        $normalized = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $normalized );

        $filler = array( 'هستم', 'میخوام', 'می‌خوام', 'می خوام', 'دنبال کار', 'کار می کنم', 'کار میکنم', 'به عنوان' );
        $pattern = '/\b(' . implode( '|', array_map( 'preg_quote', $filler ) ) . ')\b/u';
        $normalized = preg_replace( $pattern, ' ', $normalized );

        $normalized = trim( preg_replace( '/\s+/u', ' ', $normalized ) );
        $normalized = mb_strtolower( $normalized );

        return $normalized;
    }

    private static function first_token( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) ) {
            return '';
        }

        $parts = preg_split( '/\s+/u', trim( $text ) );
        return isset( $parts[0] ) ? $parts[0] : '';
    }

    private static function get_valid_category_ids() {
        static $ids = null;
        if ( null !== $ids ) {
            return $ids;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bkja_categories';
        $ids   = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table}" ) );

        return $ids;
    }

    private static function is_valid_category_id( $category_id ) {
        $valid = self::get_valid_category_ids();
        return in_array( (int) $category_id, $valid, true );
    }

    private static function get_report_data() {
        global $wpdb;
        $jobs_table = $wpdb->prefix . 'bkja_jobs';
        $titles_table = $wpdb->prefix . 'bkja_job_titles';

        $exists_jobs = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs_table ) );
        if ( $exists_jobs !== $jobs_table ) {
            return array();
        }

        BKJA_Database::ensure_numeric_job_columns();

        $report = array(
            'total_rows'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table}" ),
            'job_title_null'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE job_title_id IS NULL" ),
            'category_zero'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE category_id = 0" ),
            'category_mismatch'=> 0,
            'income_invalid'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_table} WHERE income_toman_canonical IS NULL OR income_toman_canonical < 1000000 OR income_toman_canonical > 1000000000000" ),
        );

        $exists_titles = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $titles_table ) );
        if ( $exists_titles === $titles_table ) {
            $report['category_mismatch'] = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$jobs_table} j INNER JOIN {$titles_table} t ON j.job_title_id = t.id WHERE j.job_title_id IS NOT NULL AND t.category_id IS NOT NULL AND j.category_id <> t.category_id"
            );
        }

        return $report;
    }
}

BKJA_Repair_Tool::init();

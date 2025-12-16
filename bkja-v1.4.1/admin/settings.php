<?php
        if ( ! defined( 'ABSPATH' ) ) exit;

	/**
	 * Admin Menu
	 */
	add_action('admin_menu', function(){
	add_menu_page('BKJA Assistant', 'BKJA Assistant', 'manage_options', 'bkja-assistant', 'bkja_admin_page', 'dashicons-admin-comments', 60);
	});

	/**
	 * Admin Page
	 */
	function bkja_admin_page(){
	if(!current_user_can('manage_options')) return;

	// Back-compat local save (kept if some places still post here)
	if(isset($_POST['bkja_save']) && check_admin_referer('bkja_save_settings')) {
		update_option('bkja_openai_api_key', sanitize_text_field($_POST['bkja_openai_api_key']));
		update_option('bkja_free_messages_per_day', intval($_POST['bkja_free_messages_per_day']));
		echo '<div class="updated"><p>تنظیمات ذخیره شد.</p></div>';
	}

	// Manage tab: filters
        $bkja_action = isset($_GET['bkja_action']) ? sanitize_text_field($_GET['bkja_action']) : '';
        $edit_id     = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $bkja_q      = isset($_GET['bkja_q']) ? sanitize_text_field($_GET['bkja_q']) : '';
        $bkja_p      = isset($_GET['bkja_p']) ? max(1, intval($_GET['bkja_p'])) : 1;
        $per_page    = 20;
        $offset      = ($bkja_p - 1) * $per_page;
        $bkja_clear_cache_nonce = wp_create_nonce('bkja_clear_cache');
        ?>

	<style>
		.bkja-tabs { display:flex; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
		.bkja-tab { padding:10px 14px; border-radius:8px; cursor:pointer; background:#f3f6fb; color:#0b6fab; font-weight:700; box-shadow:0 2px 8px rgba(16,24,40,0.03); }
		.bkja-tab.active { background:linear-gradient(90deg,#1e90ff,#0ea5e9); color:#fff; box-shadow:0 8px 28px rgba(14,108,191,0.14); }
		.bkja-panel { display:none; background:#fff; border:1px solid #e6eef5; padding:18px; border-radius:10px; box-shadow:0 6px 20px rgba(16,24,40,0.04); }
		.bkja-panel.active { display:block; }
		.bkja-actions { margin-top:12px; }
		.bkja-form-row { margin-bottom:14px; display:flex; gap:12px; align-items:center; }
		.bkja-form-row label { width:220px; font-weight:600; color:#334155; }
		.bkja-form-row input[type="text"], .bkja-form-row input[type="number"], .bkja-form-row select, .bkja-form-row textarea { flex:1; padding:8px 10px; border:1px solid #e6eef5; border-radius:8px; }
		.bkja-note { font-size:13px; color:#6b7280; margin-top:8px; }
		.bkja-table-wrap { overflow:auto; margin-top:16px; }
		.bkja-table { border-collapse: collapse; width:100%; }
		.bkja-table th, .bkja-table td { border:1px solid #e6eef5; padding:8px 10px; text-align:right; vertical-align:top; }
		.bkja-table th { background:#f8fafc; }
		.bkja-button { padding:8px 14px; border-radius:8px; border:none; cursor:pointer; background:linear-gradient(90deg,#1e90ff,#0ea5e9); color:#fff; font-weight:700; text-decoration:none; display:inline-block; }
		.bkja-button.secondary { background:#f3f6fb; color:#0b6fab; border:1px solid #e6eef5; }
		.bkja-actions .bkja-button + .bkja-button { margin-right:8px; }
		.bkja-help { font-size:12px; color:#64748b; margin-top:8px; }
		.bkja-search { margin:10px 0 16px; display:flex; gap:8px; align-items:center; }
                .bkja-pagination { display:flex; gap:8px; margin-top:12px; align-items:center; }
                .bkja-pagination a, .bkja-pagination span { padding:6px 10px; border:1px solid #e6eef5; border-radius:6px; text-decoration:none; }
                .bkja-pagination .current { background:#eef6ff; }
                .bkja-muted { color:#6b7280; font-size:12px; }
                .bkja-inline-form { display:inline; }
                .bkja-danger { color:#b91c1c; }
                @media (max-width: 782px){
                        .bkja-form-row,
                        .bkja-job-form .bkja-form-row,
                        .bkja-search { flex-direction:column; align-items:stretch; width:100%; }
                        .bkja-form-row label,
                        .bkja-job-form .bkja-form-row label { width:auto; margin-bottom:4px; }
                        .bkja-job-form .bkja-form-row input[type="text"],
                        .bkja-job-form .bkja-form-row input[type="number"],
                        .bkja-job-form .bkja-form-row select,
                        .bkja-job-form .bkja-form-row textarea,
                        .bkja-search input[type="text"],
                        .bkja-search input[type="number"] { width:100%; max-width:100%; box-sizing:border-box; }
                        .bkja-table-wrap,
                        .bkja-jobs-table-wrapper { width:100%; overflow-x:auto; box-sizing:border-box; }
                }
        </style>

	<script>
        document.addEventListener('DOMContentLoaded', function(){
                const tabs = document.querySelectorAll('.bkja-tab');
                const panels = document.querySelectorAll('.bkja-panel');
                function activate(i){
                        tabs.forEach((t,idx)=> t.classList.toggle('active', idx===i));
                        panels.forEach((p,idx)=> p.classList.toggle('active', idx===i));
                }
                tabs.forEach((t,idx)=> t.addEventListener('click', ()=> activate(idx)));
                let active = 0;
                const params = new URLSearchParams(window.location.search);
                const tabParam = params.get('tab');

                if (tabParam === 'import') {
                        active = 1;
                } else if (tabParam === 'jobs') {
                        active = 2;
                } else if (location.hash) {
                        const h = location.hash.replace('#','');
                        if(h==='import') active = 1;
                        if(h==='manage') active = 2;
                }
                activate(active);

                const clearBtn = document.getElementById('bkja-clear-cache-btn');
                const clearResult = document.getElementById('bkja-clear-cache-result');

                if (clearBtn) {
                        clearBtn.addEventListener('click', function(){
                                clearBtn.disabled = true;
                                clearBtn.classList.add('is-busy');
                                if (clearResult) {
                                        clearResult.innerHTML = '<div class="notice notice-info inline"><p>در حال پاکسازی کش...</p></div>';
                                }

                                const params = new URLSearchParams({
                                        action: 'bkja_clear_cache',
                                        nonce: '<?php echo esc_js( $bkja_clear_cache_nonce ); ?>'
                                });

                                fetch(ajaxurl, {
                                        method: 'POST',
                                        credentials: 'same-origin',
                                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                                        body: params.toString()
                                })
                                        .then(resp => resp.json())
                                        .then(data => {
                                                let message = 'خطا در پاکسازی کش.';
                                                let cssClass = 'notice notice-error inline';

                                                if (data && data.success && data.data) {
                                                        message = data.data.message ? data.data.message : 'کش با موفقیت پاک شد.';
                                                        cssClass = 'notice notice-success inline';
                                                } else if (data && data.data && data.data.error) {
                                                        message = data.data.error;
                                                }

                                                if (clearResult) {
                                                        clearResult.innerHTML = '<div class="' + cssClass + '"><p>' + message + '</p></div>';
                                                }
                                        })
                                        .catch(() => {
                                                if (clearResult) {
                                                        clearResult.innerHTML = '<div class="notice notice-error inline"><p>پاکسازی کش با خطا مواجه شد.</p></div>';
                                                }
                                        })
                                        .finally(() => {
                                                clearBtn.disabled = false;
                                                clearBtn.classList.remove('is-busy');
                                        });
                        });
                }
        });
        </script>

	<div class="wrap">
	<h1><?php esc_html_e('تنظیمات BKJA Assistant','bkja-assistant'); ?></h1>

	<?php
	// Import result notice
	if ( isset($_GET['bkja_import_success']) ) {
	$status = sanitize_text_field( wp_unslash( $_GET['bkja_import_success'] ) );
	if ( $status === '1' ) {
	echo '<div class="notice notice-success"><p>ایمپورت CSV با موفقیت انجام شد.</p></div>';
	} else {
	echo '<div class="notice notice-error"><p>ایمپورت CSV با خطا مواجه شد. لطفاً فایل را بررسی کنید.</p></div>';
	}
	}

		if(isset($_GET['bkja_manage_msg'])){
			$m = sanitize_text_field($_GET['bkja_manage_msg']);
			if($m==='deleted') echo '<div class="notice notice-success"><p>رکورد حذف شد.</p></div>';
			if($m==='edited')  echo '<div class="notice notice-success"><p>رکورد ویرایش شد.</p></div>';
			if($m==='added')   echo '<div class="notice notice-success"><p>شغل جدید افزوده شد.</p></div>';
		}
		?>

		<div class="bkja-tabs">
			<div class="bkja-tab">تنظیمات عمومی</div>
			<div class="bkja-tab">ایمپورت مشاغل</div>
			<div class="bkja-tab">مدیریت مشاغل</div>
		</div>

		<!-- Panel 1: Settings -->
		<div class="bkja-panel">
			<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
				<?php settings_fields('bkja_settings_group'); do_settings_sections('bkja_settings_group'); ?>
				<div class="bkja-form-row">
					<label>OpenAI API Key</label>
					<input type="text" name="bkja_openai_api_key" value="<?php echo esc_attr(get_option('bkja_openai_api_key','')); ?>" />
				</div>
	                                <div class="bkja-form-row">
	                                        <label>مدل پیش‌فرض</label>
	                                        <select name="bkja_model">
	                                                <?php $m = get_option('bkja_model','gpt-4o-mini'); ?>
	                                                <option value="gpt-4o-mini" <?php selected($m,'gpt-4o-mini'); ?>>gpt-4o-mini (پیشنهادی)</option>
	                                                <option value="gpt-4o" <?php selected($m,'gpt-4o'); ?>>gpt-4o</option>
	                                                <option value="gpt-4" <?php selected($m,'gpt-4'); ?>>gpt-4</option>
	                                                <option value="gpt-3.5-turbo" <?php selected($m,'gpt-3.5-turbo'); ?>>gpt-3.5-turbo</option>
	                                                <option value="gpt-5" <?php selected($m,'gpt-5'); ?>>gpt-5</option>
	                                        </select>
	                                </div>
                                        <div class="bkja-form-row">
                                                <label>کش پاسخ‌ها</label>
                                                <?php $cache = get_option('bkja_enable_cache','1'); ?>
                                                <select name="bkja_enable_cache">
                                                        <option value="1" <?php selected($cache,'1'); ?>>فعال</option>
                                                        <option value="0" <?php selected($cache,'0'); ?>>غیرفعال</option>
                                                </select>
                                                <div class="bkja-note">در صورت فعال بودن، پاسخ‌های تکراری برای مدت کوتاه در حافظه نگهداری می‌شوند تا سرعت بیشتر شود.</div>
                                        </div>
                                        <div class="bkja-form-row">
                                                <label>پاکسازی کش پاسخ‌ها</label>
                                                <button type="button" class="bkja-button secondary" id="bkja-clear-cache-btn">پاکسازی کش پاسخ‌ها</button>
                                                <div class="bkja-note">در صورت نیاز می‌توانید همه کش‌های پاسخ (و دیگر کش‌های BKJA) را پاکسازی کنید.</div>
                                        </div>
                                        <div id="bkja-clear-cache-result" class="bkja-help"></div>
                                        <div class="bkja-form-row">
                                                <label>دکمه «قدم بعدی منطقی»</label>
                                                <?php $quick_actions = get_option('bkja_enable_quick_actions','0'); ?>
                                                <select name="bkja_enable_quick_actions">
                                                        <option value="1" <?php selected($quick_actions,'1'); ?>>فعال</option>
	                                                <option value="0" <?php selected($quick_actions,'0'); ?>>غیرفعال</option>
	                                        </select>
	                                        <div class="bkja-note">در صورت غیرفعال بودن، پس از پاسخ دستیار دکمه‌های پیشنهادی مانند «قدم بعدی منطقی» نمایش داده نمی‌شود.</div>
	                                </div>
	                                <div class="bkja-form-row">
	                                        <label>فرم بازخورد پاسخ</label>
	                                        <?php $feedback_enabled = get_option('bkja_enable_feedback','0'); ?>
	                                        <select name="bkja_enable_feedback">
	                                                <option value="1" <?php selected($feedback_enabled,'1'); ?>>فعال</option>
	                                                <option value="0" <?php selected($feedback_enabled,'0'); ?>>غیرفعال</option>
	                                        </select>
	                                        <div class="bkja-note">اگر این گزینه را غیرفعال کنید، دکمه و فرم «ثبت بازخورد پاسخ» زیر پیام‌های دستیار نمایش داده نمی‌شود.</div>
	                                </div>
	                                <div class="bkja-form-row">
	                                        <label>تعداد پیام رایگان در روز</label>
	                                           <input type="number" min="0" step="1" name="bkja_free_messages_per_day" value="<?php echo intval(get_option('bkja_free_messages_per_day',5)); ?>" />
	                                </div>
				<?php submit_button('ذخیره تنظیمات'); ?>
			</form>
		</div>

<!-- Panel 2: Import -->
<div class="bkja-panel">
<h2>ایمپورت CSV مشاغل</h2>
<p class="bkja-help">فایل CSV را انتخاب کنید و روی «ایمپورت CSV» بزنید تا داده‌ها مستقیماً وارد شوند.</p>

<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
   <?php wp_nonce_field('bkja_import_jobs','bkja_import_jobs_nonce'); ?>
   <input type="hidden" name="action" value="bkja_import_jobs" />
<div class="bkja-form-row">
<label>فایل CSV</label>
<input type="file" name="bkja_jobs_csv" accept=".csv,text/csv" required />
</div>
<button class="bkja-button" type="submit">ایمپورت CSV</button>
</form>
</div>

		<!-- Panel 3: Manage Jobs -->
		<div class="bkja-panel">
			<h2>مدیریت مشاغل</h2>
			<p>لیست مشاغل در این بخش نمایش داده می‌شود.</p>

			<?php
			global $wpdb;
			$table = $wpdb->prefix . 'bkja_jobs';
			$exists = $wpdb->get_var( $wpdb->prepare("SHOW TABLES LIKE %s", $table) );
			if ( $exists !== $table ) {
				echo '<div class="notice notice-warning"><p>جدول مشاغل ('.esc_html($table).') یافت نشد. ابتدا دیتابیس را ایجاد یا ایمپورت کنید.</p></div>';
			} else {

				// If edit view requested
				if ($bkja_action === 'edit_job' && $edit_id > 0) {
					$job = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $edit_id) );
					if (!$job) {
						echo '<div class="notice notice-error"><p>شغل مورد نظر یافت نشد.</p></div>';
					} else {
						?>
						<h3>ویرایش شغل (ID: <?php echo esc_html($job->id); ?>)</h3>
                                                <form method="post" class="bkja-job-form" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
							<?php wp_nonce_field('bkja_update_job','bkja_update_job_nonce'); ?>
							<input type="hidden" name="action" value="bkja_update_job" />
							<input type="hidden" name="id" value="<?php echo esc_attr($job->id); ?>" />

                                                <div class="bkja-form-row">
                                                                <label>عنوان پایه *</label>
                                                                <?php $job_titles = class_exists('BKJA_Database') ? BKJA_Database::get_job_titles_by_category( $job->category_id ) : array(); ?>
                                                                <select name="job_title_id">
                                                                        <option value="">یک عنوان را انتخاب کنید</option>
                                                                        <?php foreach ( (array) $job_titles as $title_row ) : ?>
                                                                                <option value="<?php echo esc_attr( $title_row->id ); ?>" <?php selected( isset( $job->job_title_id ) ? $job->job_title_id : 0, $title_row->id ); ?>><?php echo esc_html( $title_row->label ); ?></option>
                                                                        <?php endforeach; ?>
                                                                        <option value="new">+ افزودن عنوان جدید</option>
                                                                </select>
                                                                <div class="bkja-note">برای افزودن عنوان جدید، آن را در فیلد زیر بنویسید.</div>
                                                        </div>
                                                        <div class="bkja-form-row">
                                                                <label>عنوان پایه جدید (اختیاری)</label>
                                                                <input type="text" name="job_title_label" value="" placeholder="مثلاً معلم" />
                                                        </div>
                                                        <div class="bkja-form-row">
                                                                <label>عنوان واریانت</label>
                                                                <input type="text" name="variant_title" value="<?php echo esc_attr( isset( $job->variant_title ) ? $job->variant_title : $job->title ); ?>" />
                                                                <div class="bkja-note">مثال: «معلم ابتدایی – ۳ سال سابقه»</div>
                                                        </div>
                                                        <div class="bkja-form-row">
                                                                <label>عنوان کامل (پشتیبانی قدیمی)</label>
                                                                <input type="text" name="title" value="<?php echo esc_attr($job->title); ?>" />
                                                        </div>
							<div class="bkja-form-row">
								<label>شهر</label>
								<input type="text" name="city" value="<?php echo esc_attr($job->city); ?>" />
							</div>
							<div class="bkja-form-row">
								<label>جنسیت</label>
								<input type="text" name="gender" value="<?php echo esc_attr($job->gender); ?>" />
							</div>
							<div class="bkja-form-row">
								<label>دسته‌بندی (category_id)</label>
								<input type="number" name="category_id" value="<?php echo esc_attr($job->category_id); ?>" />
							</div>
	                                                        <div class="bkja-form-row">
	                                                                <label>درآمد (متنی)</label>
	                                                                <input type="text" name="income" value="<?php echo esc_attr($job->income); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>درآمد عددی (میلیون تومان)</label>
	                                                                <input type="number" name="income_num" value="<?php echo esc_attr(isset($job->income_num) ? $job->income_num : ''); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>سرمایه (متنی)</label>
	                                                                <input type="text" name="investment" value="<?php echo esc_attr($job->investment); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>سرمایه عددی (میلیون تومان)</label>
	                                                                <input type="number" name="investment_num" value="<?php echo esc_attr(isset($job->investment_num) ? $job->investment_num : ''); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>سابقه (سال)</label>
	                                                                <input type="number" name="experience_years" value="<?php echo esc_attr(isset($job->experience_years) ? $job->experience_years : ''); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>نوع اشتغال</label>
	                                                                <input type="text" name="employment_type" value="<?php echo esc_attr($job->employment_type); ?>" placeholder="official / freelance / self_employed ..." />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>ساعت کار در روز</label>
	                                                                <input type="number" name="hours_per_day" value="<?php echo esc_attr(isset($job->hours_per_day) ? $job->hours_per_day : ''); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>روز کاری در هفته</label>
	                                                                <input type="number" name="days_per_week" value="<?php echo esc_attr(isset($job->days_per_week) ? $job->days_per_week : ''); ?>" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>مزایا</label>
	                                                                <textarea name="advantages" rows="3"><?php echo esc_textarea($job->advantages); ?></textarea>
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>معایب</label>
	                                                                <textarea name="disadvantages" rows="3"><?php echo esc_textarea($job->disadvantages); ?></textarea>
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>توضیحات</label>
	                                                                <textarea name="details" rows="5"><?php echo esc_textarea($job->details); ?></textarea>
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>منبع گزارش</label>
	                                                                <input type="text" name="source" value="<?php echo esc_attr($job->source); ?>" placeholder="instagram / site_form / import_csv" />
	                                                        </div>
	                                                        <div class="bkja-form-row">
	                                                                <label>تاریخ ایجاد (created_at)</label>
	                                                                <input type="text" name="created_at" value="<?php echo esc_attr($job->created_at); ?>" placeholder="YYYY-MM-DD HH:MM:SS" />
	                                                        </div>

							<button class="bkja-button" type="submit">ذخیره تغییرات</button>
                                                        <a class="bkja-button secondary" href="<?php echo esc_url( admin_url('admin.php?page=bkja-assistant&tab=jobs#manage') ); ?>">انصراف</a>
						</form>
						<hr/>
						<?php
					}
				}

                                // Search form
                                ?>
                                <form method="get" class="bkja-search" action="<?php echo esc_url( admin_url('admin.php?page=bkja-assistant#manage') ); ?>">
                                           <input type="hidden" name="page" value="bkja-assistant" />
                                           <input type="hidden" name="tab" value="jobs" />
                                           <input type="text" name="bkja_q" value="<?php echo esc_attr($bkja_q); ?>" placeholder="جستجو: عنوان یا شهر..." />
                                           <input type="text" name="city_filter" value="<?php echo esc_attr($_GET['city_filter'] ?? ''); ?>" placeholder="شهر" style="width:90px;" />
                                           <input type="number" name="income_min" value="<?php echo esc_attr($_GET['income_min'] ?? ''); ?>" placeholder="حداقل درآمد" style="width:110px;" />
                                           <input type="number" name="income_max" value="<?php echo esc_attr($_GET['income_max'] ?? ''); ?>" placeholder="حداکثر درآمد" style="width:110px;" />
                                           <button class="bkja-button secondary" type="submit">جستجو</button>
                                           <a class="bkja-button secondary" href="<?php echo esc_url( admin_url('admin.php?page=bkja-assistant&tab=jobs#manage') ); ?>">ریست</a>
				</form>
				<?php

				   // Build WHERE and args
				   $where = '1=1';
				   $args  = [];
				   if ($bkja_q !== '') {
					   $like = '%'.$wpdb->esc_like($bkja_q).'%';
					   $where .= " AND (title LIKE %s OR city LIKE %s)";
					   $args[] = $like; $args[] = $like;
				   }
				   if (!empty($_GET['city_filter'])) {
					   $city_like = '%'.$wpdb->esc_like($_GET['city_filter']).'%';
					   $where .= " AND city LIKE %s";
					   $args[] = $city_like;
				   }
				   if (!empty($_GET['income_min'])) {
					   $where .= " AND income >= %f";
					   $args[] = floatval($_GET['income_min']);
				   }
				   if (!empty($_GET['income_max'])) {
					   $where .= " AND income <= %f";
					   $args[] = floatval($_GET['income_max']);
				   }

				// Total count
				$count_sql  = "SELECT COUNT(*) FROM $table WHERE $where";
				$count = !empty($args) ? intval( $wpdb->get_var( $wpdb->prepare($count_sql, $args) ) ) : intval( $wpdb->get_var( $count_sql ) );

				// Query rows
				$sql = "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d";
				$q_args = $args;
				$q_args[] = $per_page;
				$q_args[] = $offset;
				$jobs = $wpdb->get_results( $wpdb->prepare($sql, $q_args) );

				if ($jobs) {
                                        echo '<div class="bkja-muted">تعداد کل: '.esc_html($count).'</div>';
                                        echo '<div class="bkja-table-wrap bkja-jobs-table-wrapper"><table class="bkja-table">
                                                <thead>
                                                                <tr>
                                                                        <th>عنوان</th>
                                                                        <th>شهر</th>
                                                                        <th>جنسیت</th>
	                                                                <th>دسته‌بندی</th>
	                                                                <th>درآمد متنی</th>
	                                                                <th>درآمد عددی</th>
	                                                                <th>سرمایه متنی</th>
	                                                                <th>سرمایه عددی</th>
	                                                                <th>سابقه (سال)</th>
	                                                                <th>نوع اشتغال</th>
	                                                                <th>ساعت/روز</th>
	                                                                <th>روز/هفته</th>
	                                                                <th>منبع</th>
	                                                                <th>توضیحات</th>
	                                                                <th>تاریخ ایجاد</th>
	                                                                <th>عملیات</th>
	                                                        </tr>
                                                </thead><tbody>';

                                        foreach ($jobs as $job) {
                                                $edit_link = add_query_arg([
                                                        'page'        => 'bkja-assistant',
                                                        'bkja_action' => 'edit_job',
                                                        'id'          => $job->id,
                                                ], admin_url('admin.php')) . '#manage';

                                                $delete_url = wp_nonce_url(
                                                        add_query_arg([
                                                                'action' => 'bkja_delete_job',
                                                                'id'     => $job->id,
                                                        ], admin_url('admin-post.php')),
                                                        'bkja_delete_job_'.$job->id,
                                                        '_wpnonce'
                                                );

                                                $gender_label      = function_exists( 'bkja_get_gender_label' ) ? bkja_get_gender_label( $job->gender ) : $job->gender;
                                                $employment_label  = function_exists( 'bkja_get_employment_label' ) ? bkja_get_employment_label( $job->employment_type ) : $job->employment_type;
                                                $created_at_output = function_exists( 'bkja_format_created_at' ) ? bkja_format_created_at( $job->created_at ) : $job->created_at;

                                                echo '<tr>';
                                                echo '<td>'.esc_html($job->title).'</td>';
                                                echo '<td>'.esc_html($job->city).'</td>';
                                                echo '<td>'.esc_html($gender_label).'</td>';
                                                echo '<td>'.esc_html($job->category_id).'</td>';
                                                        echo '<td>'.esc_html($job->income).'</td>';
                                                        echo '<td>'.esc_html(isset($job->income_num) ? $job->income_num : '').'</td>';
                                                        echo '<td>'.esc_html($job->investment).'</td>';
                                                        echo '<td>'.esc_html(isset($job->investment_num) ? $job->investment_num : '').'</td>';
                                                        echo '<td>'.esc_html(isset($job->experience_years) ? $job->experience_years : '').'</td>';
                                                        echo '<td>'.esc_html($employment_label).'</td>';
                                                        echo '<td>'.esc_html(isset($job->hours_per_day) ? $job->hours_per_day : '').'</td>';
                                                        echo '<td>'.esc_html(isset($job->days_per_week) ? $job->days_per_week : '').'</td>';
                                                        echo '<td>'.esc_html($job->source).'</td>';
                                                        echo '<td>'.esc_html(wp_trim_words($job->details, 18, '…')).'</td>';
                                                        echo '<td>'.esc_html($created_at_output).'</td>';
                                                echo '<td>
                                                        <a href="'.esc_url($edit_link).'" class="bkja-button secondary">ویرایش</a>
                                                        <a href="'.esc_url($delete_url).'" class="bkja-button secondary bkja-danger" onclick="return confirm(\'حذف این رکورد قطعی است. ادامه می‌دهید؟\');">حذف</a>
                                                </td>';
						echo '</tr>';
					}

					echo '</tbody></table></div>';

					// Pagination
					$total_pages = max(1, ceil($count / $per_page));
					if ($total_pages > 1) {
						echo '<div class="bkja-pagination">';
						for ($i=1; $i <= $total_pages; $i++) {
                                                        $link = add_query_arg(array_filter([
                                                                'page'   => 'bkja-assistant',
                                                                'tab'    => 'jobs',
                                                                'bkja_q' => $bkja_q !== '' ? $bkja_q : null,
                                                                'bkja_p' => $i,
                                                        ]), admin_url('admin.php'));
							$link .= '#manage';
							if ($i == $bkja_p) {
								echo '<span class="current">'.esc_html($i).'</span>';
							} else {
								echo '<a href="'.esc_url($link).'">'.esc_html($i).'</a>';
							}
						}
						echo '</div>';
					}
				} else {
					echo '<p>هیچ شغلی برای نمایش وجود ندارد.</p>';
				}
			}
			?>
		</div>
	</div>

	<?php
	} // bkja_admin_page

	/**
	 * Register settings
	 */
	if ( is_admin() ) {
	add_action('admin_init', function(){
	                register_setting('bkja_settings_group', 'bkja_openai_api_key');
	                register_setting('bkja_settings_group', 'bkja_model');
	                register_setting('bkja_settings_group', 'bkja_free_messages_per_day', array(
	                        'type'              => 'integer',
	                        'sanitize_callback' => function( $value ) {
	                                $value = is_numeric( $value ) ? (int) $value : 0;
	                                return max( 0, $value );
	                        },
	                ));
	                register_setting('bkja_settings_group', 'bkja_enable_cache');
	                register_setting('bkja_settings_group', 'bkja_enable_quick_actions', array(
	                        'type' => 'string',
	                        'sanitize_callback' => function($value){
	                                return ($value === '1') ? '1' : '0';
	                        },
	                        'default' => '0',
	                ));
	                register_setting('bkja_settings_group', 'bkja_enable_feedback', array(
	                        'type' => 'string',
	                        'sanitize_callback' => function($value){
	                                return ($value === '1') ? '1' : '0';
	                        },
	                        'default' => '0',
	                ));
	        });
	}

	/* =========================
	 * Import Helpers (CSV)
	 * ========================= */

	/** Ensure uploads subdir exists and return path. */
	if ( ! function_exists('bkja_import_uploads_dir') ) {
	function bkja_import_uploads_dir() {
		$uploads = wp_upload_dir();
		$base = trailingslashit($uploads['basedir']) . 'bkja_imports';
		if ( ! file_exists($base) ) {
			wp_mkdir_p($base);
		}
		return $base;
	}
	}

	/** Detect delimiter by sampling. */
	if ( ! function_exists('bkja_detect_delimiter') ) {
	function bkja_detect_delimiter( $file ) {
		$sample = @file_get_contents( $file, false, null, 0, 4096 );
		if ($sample === false) return ',';
		$commas = substr_count($sample, ',');
		$semis  = substr_count($sample, ';');
		$tabs   = substr_count($sample, "\t");
		if ($tabs > $commas && $tabs > $semis) return "\t";
		if ($semis > $commas) return ';';
		return ',';
	}
	}

	/** Strip UTF-8 BOM */
	if ( ! function_exists('bkja_strip_bom') ) {
	function bkja_strip_bom($s){
		if (substr($s,0,3) === "\xEF\xBB\xBF") {
			return substr($s,3);
		}
		return $s;
	}
	}

	/** Read first line as headers + next up to 10 rows */
	if ( ! function_exists('bkja_csv_preview_10') ) {
	function bkja_csv_preview_10( $file ){
		$rows = [];
		$headers = [];
		$delim = bkja_detect_delimiter($file);
		if (($h = @fopen($file, 'r')) !== false) {
			$line = fgetcsv($h, 0, $delim);
			if ($line) {
				$line[0] = bkja_strip_bom($line[0]);
				$headers = $line;
			}
			$i = 0;
					while(($r = fgetcsv($h, 0, $delim)) !== false && $i < 10){
				$rows[] = $r;
				$i++;
			}
			fclose($h);
		}
		return [$headers, $rows];
	}
	}

	/** Normalize header map (supports EN/FA synonyms) */
	if ( ! function_exists('bkja_header_index_map') ) {
	function bkja_header_index_map($headers){
		$map = [];
		$norm = [];
		foreach($headers as $i=>$h){
			$k = strtolower(trim($h));
			$k = str_replace(['‌',' '], ['','_'], $k); // normalize space/zero-width
			$norm[$i] = $k;
		}
	                $aliases = [
	                        'title'            => ['title','job','نام_شغل','عنوان','شغل'],
	                        'city'             => ['city','شهر'],
	                        'gender'           => ['gender','جنس','جنسیت'],
	                        'category_id'      => ['category_id','دسته','دسته_بندی','id_دسته'],
	                        'income'           => ['income','salary','درآمد'],
	                        'income_num'       => ['income_num','salary_num','درآمد_عددی'],
	                        'investment'       => ['investment','سرمایه','هزینه_اولیه'],
	                        'investment_num'   => ['investment_num','capital_num','سرمایه_عددی'],
	                        'experience_years' => ['experience_years','years','سابقه','سابقه_کار','experience'],
	                        'employment_type'  => ['employment_type','employment','نوع_اشتغال'],
	                        'hours_per_day'    => ['hours_per_day','hours','ساعت_کار'],
	                        'days_per_week'    => ['days_per_week','days','روز_در_هفته'],
	                        'advantages'       => ['advantages','مزایا'],
	                        'disadvantages'    => ['disadvantages','معایب'],
	                        'details'          => ['details','description','توضیحات','شرح'],
	                        'source'           => ['source','منبع'],
	                        'created_at'       => ['created_at','date','تاریخ'],
	                ];
		foreach($aliases as $col=>$keys){
			foreach($norm as $i=>$nk){
				if(in_array($nk, $keys, true)){ $map[$col] = $i; break; }
			}
		}
		return $map;
	}
	}

	/* =========================
	 * Import Actions (Preview / Confirm)
	 * ========================= */

	/** Preview: upload CSV, store file path in transient, redirect with key */
	if ( ! function_exists('bkja_import_preview_handler') ) {
	add_action('admin_post_bkja_import_preview', 'bkja_import_preview_handler');
	function bkja_import_preview_handler() {
		if ( ! current_user_can('manage_options') ) wp_die('دسترسی غیرمجاز.');
		check_admin_referer('bkja_import_jobs','bkja_import_jobs_nonce');

		if( empty($_FILES['bkja_jobs_csv']) || ! isset($_FILES['bkja_jobs_csv']['tmp_name']) ){
			wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
			exit;
		}

		$file = $_FILES['bkja_jobs_csv'];
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
			exit;
		}

		// Validate by extension (lenient)
		$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
		if ( $ext !== 'csv' ) {
			wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
			exit;
		}

		// Move to uploads/bkja_imports
		$dest_dir = bkja_import_uploads_dir();
		$unique = wp_unique_filename($dest_dir, basename($file['name']));
		$dest   = trailingslashit($dest_dir) . $unique;

		if ( ! @move_uploaded_file($file['tmp_name'], $dest) ) {
			// Fallback: copy
			if ( ! @copy($file['tmp_name'], $dest) ) {
				wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
				exit;
			}
		}

		$payload = [
			'file'   => $dest,
			'time'   => time(),
			'origin' => sanitize_file_name($file['name']),
		];
		$key = 'bkja_import_preview_' . wp_generate_password(12,false,false);
		set_transient($key, $payload, 15 * MINUTE_IN_SECONDS);

		$url = add_query_arg(['page'=>'bkja-assistant','bkja_preview'=>$key], admin_url('admin.php'));
		$url .= '#import';
		wp_redirect($url);
		exit;
	}
	}

	/** Confirm: read file from transient, insert rows to DB, redirect with counts */
	if ( ! function_exists('bkja_import_confirm_handler') ) {
	add_action('admin_post_bkja_import_confirm', 'bkja_import_confirm_handler');
	function bkja_import_confirm_handler() {
		if ( ! current_user_can('manage_options') ) wp_die('دسترسی غیرمجاز.');
		check_admin_referer('bkja_import_confirm','bkja_import_confirm_nonce');

		if ( empty($_POST['transient_key']) ) {
			wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
			exit;
		}

		$key = sanitize_text_field($_POST['transient_key']);
		$payload = get_transient($key);
		if ( ! $payload || empty($payload['file']) || ! file_exists($payload['file']) ) {
			wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
			exit;
		}

		$file = $payload['file'];
		$delim = bkja_detect_delimiter($file);
		$imported = 0;
		$skipped  = 0;

		global $wpdb;
		$table = $wpdb->prefix . 'bkja_jobs';

		if ( ($h = @fopen($file, 'r')) !== false ) {
			$headers = fgetcsv($h, 0, $delim);
			if ($headers) $headers[0] = bkja_strip_bom($headers[0]);
			$headers = $headers ? $headers : [];
			$map = bkja_header_index_map($headers);

			while( ($row = fgetcsv($h, 0, $delim)) !== false ){
				// Allow external handler to take over
				$handled = apply_filters('bkja_import_row_handle', false, $row, $headers);
				if ($handled === true) { $imported++; continue; }
				if ($handled instanceof WP_Error) { $skipped++; continue; }

	                                $data = [];

                                        // Title (required)
                                        if ( isset($map['title']) && isset($row[$map['title']]) && strlen(trim($row[$map['title']])) ) {
                                                $data['title'] = sanitize_text_field( $row[$map['title']] );
                                        } else { $skipped++; continue; }

                                        // Base job title label (optional – fallback to title)
                                        if ( isset($map['job_title_label']) && isset($row[$map['job_title_label']]) && strlen(trim($row[$map['job_title_label']]))) {
                                                $data['job_title_label'] = sanitize_text_field( $row[$map['job_title_label']] );
                                        }
                                        if ( ! isset( $data['job_title_label'] ) ) {
                                                $data['job_title_label'] = $data['title'];
                                        }
                                        // Variant title (optional)
                                        if ( isset($map['variant_title']) && isset($row[$map['variant_title']]) && strlen(trim($row[$map['variant_title']]))) {
                                                $data['variant_title'] = sanitize_text_field( $row[$map['variant_title']] );
                                        } else {
                                                $data['variant_title'] = $data['title'];
                                        }

	                                // Optional fields
	                                if ( isset($map['city']) && isset($row[$map['city']]) )               { $data['city'] = sanitize_text_field($row[$map['city']]); }
	                                if ( isset($map['gender']) && isset($row[$map['gender']]) )           { $data['gender'] = sanitize_text_field($row[$map['gender']]); }
	                                if ( isset($map['category_id']) && isset($row[$map['category_id']]) ) { $data['category_id'] = intval($row[$map['category_id']]); }
	                                if ( isset($map['income']) && isset($row[$map['income']]) )           { $data['income'] = sanitize_text_field($row[$map['income']]); }
	                                if ( isset($map['income_num']) && isset($row[$map['income_num']]) )   { $data['income_num'] = intval($row[$map['income_num']]); }
	                                if ( isset($map['investment']) && isset($row[$map['investment']]) )   { $data['investment'] = sanitize_text_field($row[$map['investment']]); }
	                                if ( isset($map['investment_num']) && isset($row[$map['investment_num']]) ) { $data['investment_num'] = intval($row[$map['investment_num']]); }
	                                if ( isset($map['experience_years']) && isset($row[$map['experience_years']]) ) { $data['experience_years'] = intval($row[$map['experience_years']]); }
	                                if ( isset($map['employment_type']) && isset($row[$map['employment_type']]) ) { $data['employment_type'] = sanitize_text_field($row[$map['employment_type']]); }
	                                if ( isset($map['hours_per_day']) && isset($row[$map['hours_per_day']]) ) { $data['hours_per_day'] = intval($row[$map['hours_per_day']]); }
	                                if ( isset($map['days_per_week']) && isset($row[$map['days_per_week']]) ) { $data['days_per_week'] = intval($row[$map['days_per_week']]); }
	                                if ( isset($map['advantages']) && isset($row[$map['advantages']]) )   { $data['advantages'] = sanitize_textarea_field($row[$map['advantages']]); }
	                                if ( isset($map['disadvantages']) && isset($row[$map['disadvantages']]) ) { $data['disadvantages'] = sanitize_textarea_field($row[$map['disadvantages']]); }
	                                if ( isset($map['details']) && isset($row[$map['details']]) ) { $data['details'] = wp_kses_post($row[$map['details']]); }
	                                if ( isset($map['source']) && isset($row[$map['source']]) ) { $data['source'] = sanitize_text_field($row[$map['source']]); }

	                                // created_at default
	                                if ( isset($map['created_at']) && isset($row[$map['created_at']]) ) {
	                                        $data['created_at'] = sanitize_text_field($row[$map['created_at']]);
	                                } else {
	                                        $data['created_at'] = current_time('mysql');
	                                }

	                                if ( class_exists( 'BKJA_Database' ) ) {
	                                        $ok = BKJA_Database::insert_job( $data );
	                                        if ( $ok ) { $imported++; } else { $skipped++; }
	                                } else {
	                                        do_action('bkja_import_confirm_process_row', $row, $headers, $data);
	                                        $imported++;
	                                }
	                        }
	                        fclose($h);
	                } else {
			wp_redirect( admin_url('admin.php?page=bkja-assistant&bkja_import_success=0#import') );
			exit;
		}

		delete_transient($key);

		$url = add_query_arg([
			'page'               => 'bkja-assistant',
			'bkja_import_success'=> ($imported>0 ? '1' : '0'),
			'bkja_imported'      => $imported,
			'bkja_skipped'       => $skipped,
		], admin_url('admin.php'));
		$url .= '#import';
		wp_redirect($url);
		exit;
	}
	}

	/* =========================
	 * Manage Actions (Delete / Update)
	 * ========================= */

	/** Delete job */
	if ( ! function_exists('bkja_delete_job_handler') ) {
	add_action('admin_post_bkja_delete_job', 'bkja_delete_job_handler');
	function bkja_delete_job_handler() {
		if ( ! current_user_can('manage_options') ) wp_die('دسترسی غیرمجاز.');
		$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
		check_admin_referer('bkja_delete_job_'.$id);

		if ($id > 0) {
			global $wpdb;
			$table = $wpdb->prefix . 'bkja_jobs';
			$wpdb->delete($table, ['id' => $id], ['%d']);
		}
		$url = add_query_arg(['page'=>'bkja-assistant','bkja_manage_msg'=>'deleted'], admin_url('admin.php'));
		$url .= '#manage';
		wp_redirect($url);
		exit;
	}
	}

	/** Update (edit) job */
	if ( ! function_exists('bkja_update_job_handler') ) {
	add_action('admin_post_bkja_update_job', 'bkja_update_job_handler');
	function bkja_update_job_handler() {
		if ( ! current_user_can('manage_options') ) wp_die('دسترسی غیرمجاز.');
		check_admin_referer('bkja_update_job','bkja_update_job_nonce');

		$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		if ($id <= 0) {
			$url = add_query_arg(['page'=>'bkja-assistant','bkja_manage_msg'=>'error'], admin_url('admin.php'));
			$url .= '#manage';
			wp_redirect($url); exit;
		}

                $data = [];
                $formats = [];

                // Required: title
                if (!empty($_POST['title'])) {
                    $data['title'] = sanitize_text_field( wp_unslash($_POST['title']) );
                    $formats[] = '%s';
                }

                $job_title_id = 0;
                if ( isset( $_POST['job_title_id'] ) ) {
                        $job_title_id = 'new' === $_POST['job_title_id'] ? 0 : intval( $_POST['job_title_id'] );
                }
                $job_title_label = isset( $_POST['job_title_label'] ) ? sanitize_text_field( wp_unslash( $_POST['job_title_label'] ) ) : '';
                $variant_title   = isset( $_POST['variant_title'] ) ? sanitize_text_field( wp_unslash( $_POST['variant_title'] ) ) : '';

                if ( class_exists( 'BKJA_Database' ) ) {
                        BKJA_Database::ensure_job_title_schema();
                        if ( $job_title_label && isset( $_POST['category_id'] ) ) {
                                $job_title_id = BKJA_Database::ensure_job_title_exists( intval( $_POST['category_id'] ), $job_title_label );
                        }
                }

                        // Optional fields
                        if (isset($_POST['city']))        { $data['city']        = sanitize_text_field( wp_unslash($_POST['city']) ); $formats[]='%s'; }
                        if (isset($_POST['gender']))      { $data['gender']      = sanitize_text_field( wp_unslash($_POST['gender']) ); $formats[]='%s'; }
                        if (isset($_POST['category_id'])) { $data['category_id'] = intval($_POST['category_id']); $formats[]='%d'; }
	                if (isset($_POST['income']))      { $data['income']      = sanitize_text_field( wp_unslash($_POST['income']) ); $formats[]='%s'; }
	                if (isset($_POST['investment']))  { $data['investment']  = sanitize_text_field( wp_unslash($_POST['investment']) ); $formats[]='%s'; }
	                if (isset($_POST['income_num']))  { $data['income_num']  = intval($_POST['income_num']); $formats[] = '%d'; }
	                if (isset($_POST['investment_num'])) { $data['investment_num'] = intval($_POST['investment_num']); $formats[] = '%d'; }
	                if (isset($_POST['experience_years'])) { $data['experience_years'] = intval($_POST['experience_years']); $formats[]='%d'; }
	                if (isset($_POST['employment_type'])) { $data['employment_type'] = sanitize_text_field( wp_unslash($_POST['employment_type']) ); $formats[]='%s'; }
	                if (isset($_POST['hours_per_day'])) { $data['hours_per_day'] = intval($_POST['hours_per_day']); $formats[]='%d'; }
	                if (isset($_POST['days_per_week'])) { $data['days_per_week'] = intval($_POST['days_per_week']); $formats[]='%d'; }
	                if (isset($_POST['advantages']))   { $data['advantages']   = sanitize_textarea_field( wp_unslash($_POST['advantages']) ); $formats[]='%s'; }
	                if (isset($_POST['disadvantages'])) { $data['disadvantages'] = sanitize_textarea_field( wp_unslash($_POST['disadvantages']) ); $formats[]='%s'; }
	                if (isset($_POST['details']))      { $data['details']      = sanitize_textarea_field( wp_unslash($_POST['details']) ); $formats[]='%s'; }
	                if (isset($_POST['source']))       { $data['source']       = sanitize_text_field( wp_unslash($_POST['source']) ); $formats[]='%s'; }
                        if (isset($_POST['created_at']) && $_POST['created_at'] !== '') { $data['created_at'] = sanitize_text_field( wp_unslash($_POST['created_at']) ); $formats[]='%s'; }

                        if ( $job_title_id > 0 ) { $data['job_title_id'] = $job_title_id; $formats[] = '%d'; }
                        if ( $variant_title ) { $data['variant_title'] = $variant_title; $formats[] = '%s'; }

                        if ( $variant_title && empty( $data['title'] ) ) {
                                $data['title'] = $variant_title;
                                $formats[] = '%s';
                        } elseif ( $job_title_label && empty( $data['title'] ) ) {
                                $data['title'] = $job_title_label;
                                $formats[] = '%s';
                        }

                        // Ensure numeric fallbacks from textual values
                        if ( isset( $data['income'] ) ) {
                                if ( ! isset( $data['income_num'] ) ) {
                                        $data['income_num'] = bkja_parse_numeric_amount( $data['income'] );
	                                $formats[] = '%d';
	                        } elseif ( $data['income_num'] <= 0 ) {
	                                $data['income_num'] = bkja_parse_numeric_amount( $data['income'] );
	                        }
	                }
	                if ( isset( $data['investment'] ) ) {
	                        if ( ! isset( $data['investment_num'] ) ) {
	                                $data['investment_num'] = bkja_parse_numeric_amount( $data['investment'] );
	                                $formats[] = '%d';
	                        } elseif ( $data['investment_num'] <= 0 ) {
	                                $data['investment_num'] = bkja_parse_numeric_amount( $data['investment'] );
	                        }
	                }

	                if (!empty($data)) {
	                        global $wpdb;
	                        if ( class_exists( 'BKJA_Database' ) ) {
	                                BKJA_Database::ensure_numeric_job_columns();
	                        }
	                        $table = $wpdb->prefix . 'bkja_jobs';
	                        $wpdb->update($table, $data, ['id'=>$id], $formats, ['%d']);
	                }

		$url = add_query_arg(['page'=>'bkja-assistant','bkja_manage_msg'=>'edited'], admin_url('admin.php'));
		$url .= '#manage';
		wp_redirect($url);
		exit;
	}
	}

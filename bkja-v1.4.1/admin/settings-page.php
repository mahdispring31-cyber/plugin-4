<?php
if (!defined('ABSPATH')) exit;

function bkja_register_admin_menu() {
    add_options_page('BKJA Settings', 'BKJA Settings', 'manage_options', 'bkja-settings', 'bkja_render_settings_page');
}
// add_action('admin_menu', 'bkja_register_admin_menu');

function bkja_register_settings() {
    register_setting('bkja_settings_group', 'bkja_github_token');
    register_setting('bkja_settings_group', 'bkja_repo_name');
    register_setting('bkja_settings_group', 'bkja_auto_send');
}
add_action('admin_init', 'bkja_register_settings');

function bkja_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $token = esc_attr(get_option('bkja_github_token', ''));
    $repo = esc_attr(get_option('bkja_repo_name', 'mahdispring31-cyber/plugin-4'));
    $auto = get_option('bkja_auto_send', 0);
    ?>
    <div class="wrap">
        <h1>BKJA Auto Bug Reporter - Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('bkja_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">GitHub Personal Access Token</th>
                    <td><input type="password" name="bkja_github_token" value="<?php echo $token; ?>" style="width:420px" /></td>
                </tr>
                <tr>
                    <th scope="row">Repository (owner/repo)</th>
                    <td><input type="text" name="bkja_repo_name" value="<?php echo $repo; ?>" style="width:420px" /></td>
                </tr>
                <tr>
                    <th scope="row">Auto send logs to GitHub Issues</th>
                    <td><input type="checkbox" name="bkja_auto_send" value="1" <?php checked(1, $auto); ?> /> Enable automatic Issue creation</td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>Manual actions</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bkja_send_logs_request" />
            <?php submit_button('📡 Send current logs to GitHub as Issue', 'secondary'); ?>
        </form>

        <p>Use the test button below to create a local test log and (if enabled) send an Issue.</p>
        <form method="post" id="bkja-test-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="bkja_test_log" />
            <?php submit_button('Run Test Log', 'primary'); ?>
        </form>

    </div>
    <?php
}

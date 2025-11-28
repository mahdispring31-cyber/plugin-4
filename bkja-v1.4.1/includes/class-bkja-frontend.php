<?php if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Frontend {
    public static function init(){
        add_shortcode('bkja_assistant', array(__CLASS__,'render_chatbox'));
        add_action('wp_enqueue_scripts', array(__CLASS__,'enqueue_assets'));
        add_action('wp_ajax_bkja_send_message', array(__CLASS__,'ajax_send_message'));
        add_action('wp_ajax_nopriv_bkja_send_message', array(__CLASS__,'ajax_send_message'));
        add_action('wp_ajax_bkja_get_history', array(__CLASS__,'ajax_get_history'));
        add_action('wp_ajax_nopriv_bkja_get_history', array(__CLASS__,'ajax_get_history'));
        add_action('wp_ajax_bkja_feedback', array(__CLASS__,'ajax_feedback'));
        add_action('wp_ajax_nopriv_bkja_feedback', array(__CLASS__,'ajax_feedback'));
    }

    private static function canonical_session_id( $posted ){
        $posted = is_string($posted) ? trim($posted) : '';
        $cookie = isset($_COOKIE['bkja_session']) ? sanitize_text_field( wp_unslash( $_COOKIE['bkja_session'] ) ) : '';
        $sid = $posted ?: $cookie; // سشن ارسالی از JS اولویت دارد
        if ( strlen($sid) < 12 ) {
            $sid = 'bkja_' . wp_generate_password(20, false, false);
            $cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
            $cookie_path   = ( defined( 'COOKIEPATH' ) && COOKIEPATH ) ? COOKIEPATH : '/';
            setcookie('bkja_session', $sid, time()+30*DAY_IN_SECONDS, $cookie_path, $cookie_domain, is_ssl(), true);
        }
        return $sid;
    }

    public static function get_session( $posted = '' ) {
        $value = '';
        if ( is_string( $posted ) ) {
            $value = sanitize_text_field( wp_unslash( $posted ) );
        }
        return self::canonical_session_id( $value );
    }

    public static function enqueue_assets(){
        $css_path = BKJA_PLUGIN_DIR . 'assets/css/bkja-frontend.css';
        $js_path  = BKJA_PLUGIN_DIR . 'assets/js/bkja-frontend.js';
        $css_version = file_exists( $css_path ) ? filemtime( $css_path ) : ( defined( 'BKJA_PLUGIN_VERSION' ) ? BKJA_PLUGIN_VERSION : time() );
        $js_version  = file_exists( $js_path ) ? filemtime( $js_path ) : ( defined( 'BKJA_PLUGIN_VERSION' ) ? BKJA_PLUGIN_VERSION : time() );

        wp_enqueue_style('bkja-frontend', BKJA_PLUGIN_URL.'assets/css/bkja-frontend.css', array(), $css_version);
        wp_enqueue_script('bkja-frontend', BKJA_PLUGIN_URL.'assets/js/bkja-frontend.js', array('jquery'), $js_version, true);

        if ( function_exists( 'bkja_get_free_message_limit' ) ) {
            $free_limit = bkja_get_free_message_limit();
        } else {
            $free_limit = 2;
        }

        $data = array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bkja_nonce'),
            'is_logged_in' => is_user_logged_in() ? 1 : 0,
            'free_limit' => $free_limit,
            'login_url' => esc_url( function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url() ),
            'enable_feedback' => get_option('bkja_enable_feedback','0') === '1' ? 1 : 0,
            'enable_quick_actions' => get_option('bkja_enable_quick_actions','0') === '1' ? 1 : 0,
            'server_session' => isset( $_COOKIE['bkja_session'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['bkja_session'] ) ) : '',
        );
        wp_localize_script('bkja-frontend','bkja_vars',$data);
        wp_localize_script('bkja-frontend','BKJA',$data);
    }

    public static function render_chatbox($atts=array()){
        $atts = shortcode_atts(
            array('title'=>__('دستیار شغلی','bkja-assistant')),
            $atts,
            'bkja_assistant'
        );
        ob_start();
        include BKJA_PLUGIN_DIR.'templates/chatbox.php';
        return ob_get_clean();
    }

    public static function ajax_send_message(){
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) {
            wp_send_json_error(array('error'=>'invalid_nonce'),403);
        }
        $message         = isset($_POST['message']) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $category        = isset($_POST['category']) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
        $raw_session     = isset($_POST['session']) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
        if ( empty( $raw_session ) && isset( $_SERVER['HTTP_X_BKJA_SESSION'] ) ) {
            $raw_session = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_BKJA_SESSION'] ) );
        }
        $session         = self::canonical_session_id( $raw_session );
        $job_title_hint  = isset($_POST['job_title']) ? sanitize_text_field(wp_unslash($_POST['job_title'])) : '';
        $job_slug        = isset($_POST['job_slug']) ? sanitize_text_field(wp_unslash($_POST['job_slug'])) : '';

        if ( empty($message) ) {
            wp_send_json_error(array('error'=>'empty_message'),400);
        }

        $user_id = get_current_user_id() ?: 0;

        if ( function_exists( 'bkja_get_free_message_limit' ) ) {
            $free_limit = bkja_get_free_message_limit();
        } else {
            $free_limit = 2;
        }

        error_log("BKJA limit debug: session={$session} user_id={$user_id} free_limit={$free_limit}");
        $login_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();

        $msg_count = null;

        if ( ! $user_id ) {
            $msg_count = BKJA_Database::count_guest_messages( $session, DAY_IN_SECONDS );

            error_log("BKJA limit check: msg_count={$msg_count} free_limit={$free_limit}");
            if ( $msg_count >= $free_limit ) {
                $limit_notice = __( 'ظرفیت پیام‌های رایگان امروز شما تمام شده است. لطفاً وارد شوید یا عضویت خود را ارتقا دهید.', 'bkja-assistant' );

                wp_send_json_success(array(
                    'ok'                    => false,
                    'error'                 => 'guest_limit',
                    'login_url'             => esc_url($login_url),
                    'limit'                 => (int) $free_limit,
                    'count'                 => (int) $msg_count,
                    'guest_message_limit'   => (int) $free_limit,
                    'guest_message_count'   => (int) $msg_count,
                    'message'               => $limit_notice,
                    'server_session'        => $session,
                    'guest_session'         => $session,
                ), 200);
            }

        }

        $_bkja_user_row_id = BKJA_Database::insert_chat(
            array(
                'user_id'      => $user_id ?: null,
                'session_id'   => $session,
                'job_category' => $category,
                'message'      => (string) $message,
                'response'     => null,
            )
        );

        $selected_model = get_option('bkja_model', '');
        $resolved_model = BKJA_Chat::resolve_model($selected_model);

        $ai_response = BKJA_Chat::call_openai($message, array(
            'session_id'     => $session,
            'user_id'        => $user_id,
            'category'       => $category,
            'model'          => $resolved_model,
            'job_title_hint' => $job_title_hint,
            'job_slug'       => $job_slug,
        ));

        $reply              = '';
        $suggestions        = array();
        $from_cache         = false;
        $meta_payload       = array();
        $normalized_message = BKJA_Chat::normalize_message($message);

        if ( is_wp_error( $ai_response ) ) {
            $reply = 'خطا یا کلید API تنظیم نشده. ' . $ai_response->get_error_message();
        } else {
            $reply        = isset( $ai_response['text'] ) ? (string) $ai_response['text'] : '';
            $suggestions  = ! empty( $ai_response['suggestions'] ) && is_array( $ai_response['suggestions'] ) ? $ai_response['suggestions'] : array();
            $from_cache   = ! empty( $ai_response['from_cache'] );
            $meta_payload = array(
                'suggestions'        => $suggestions,
                'context_used'       => ! empty( $ai_response['context_used'] ),
                'from_cache'         => $from_cache,
                'source'             => isset( $ai_response['source'] ) ? $ai_response['source'] : 'openai',
                'model'              => isset( $ai_response['model'] ) ? $ai_response['model'] : $resolved_model,
                'category'           => $category,
                'normalized_message' => $normalized_message,
                'job_title'          => ! empty( $ai_response['job_title'] ) ? $ai_response['job_title'] : '',
            );

            if ( isset( $ai_response['meta'] ) && is_array( $ai_response['meta'] ) ) {
                $meta_payload = array_merge( $meta_payload, $ai_response['meta'] );
            }

            if ( ! isset( $meta_payload['category'] ) || '' === $meta_payload['category'] ) {
                $meta_payload['category'] = $category;
            }

            if ( ! isset( $meta_payload['job_title'] ) || '' === $meta_payload['job_title'] ) {
                if ( ! empty( $ai_response['job_title'] ) ) {
                    $meta_payload['job_title'] = $ai_response['job_title'];
                } elseif ( ! empty( $job_title_hint ) ) {
                    $meta_payload['job_title'] = $job_title_hint;
                }
            }

            if ( ! isset( $meta_payload['job_slug'] ) ) {
                if ( isset( $ai_response['job_slug'] ) ) {
                    $meta_payload['job_slug'] = $ai_response['job_slug'];
                } elseif ( ! empty( $job_slug ) ) {
                    $meta_payload['job_slug'] = $job_slug;
                }
            }
        }

        $reply_meta_json = '';
        if ( ! empty( $meta_payload ) ) {
            $reply_meta_json = wp_json_encode( $meta_payload, JSON_UNESCAPED_UNICODE );
            if ( false === $reply_meta_json ) {
                $reply_meta_json = wp_json_encode( $meta_payload );
            }
        }

        if ( ! empty( $_bkja_user_row_id ) ) {
            BKJA_Database::update_chat_response( (int) $_bkja_user_row_id, $reply, $reply_meta_json );
        }

        $response_payload = array(
            'ok'          => true,
            'reply'       => $reply,
            'suggestions' => $suggestions,
            'from_cache'  => $from_cache,
            'meta'        => $meta_payload,
        );

        if ( ! $user_id ) {
            $guest_message_count = BKJA_Database::count_guest_messages( $session, DAY_IN_SECONDS );
            $response_payload['guest_message_count'] = (int) $guest_message_count;
            $response_payload['guest_message_limit'] = (int) $free_limit;
            $response_payload['guest_session']       = $session;
        }

        $response_payload['server_session'] = $session;

        wp_send_json_success($response_payload);
    }

    public static function ajax_feedback(){
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) {
            wp_send_json_error(array('error'=>'invalid_nonce'),403);
        }

        $vote = isset($_POST['vote']) ? intval($_POST['vote']) : 0;
        if (!in_array($vote, array(1,-1), true)) {
            wp_send_json_error(array('error'=>'invalid_vote'),400);
        }

        $session  = isset($_POST['session']) ? sanitize_text_field(wp_unslash($_POST['session'])) : '';
        $message  = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
        $response = isset($_POST['response']) ? wp_kses_post(wp_unslash($_POST['response'])) : '';
        $tags     = isset($_POST['tags']) ? sanitize_text_field(wp_unslash($_POST['tags'])) : '';
        $comment  = isset($_POST['comment']) ? sanitize_textarea_field(wp_unslash($_POST['comment'])) : '';
        $category = isset($_POST['category']) ? sanitize_text_field(wp_unslash($_POST['category'])) : '';
        $model    = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $job_title = isset($_POST['job_title']) ? sanitize_text_field(wp_unslash($_POST['job_title'])) : '';
        $job_slug  = isset($_POST['job_slug']) ? sanitize_text_field(wp_unslash($_POST['job_slug'])) : '';

        if ( '' === $job_title && '' !== $job_slug ) {
            $job_title = $job_slug;
        }

        $normalized_message = BKJA_Chat::normalize_message($message);
        $resolved_model     = BKJA_Chat::resolve_model($model);

        if ('' === $normalized_message) {
            wp_send_json_error(array('error'=>'empty_message'),400);
        }

        if ( class_exists('BKJA_Database') ) {
            BKJA_Database::ensure_feedback_table();
            BKJA_Database::insert_feedback(array(
                'session_id' => $session,
                'user_id'    => get_current_user_id() ?: 0,
                'message'    => $normalized_message,
                'response'   => $response,
                'vote'       => $vote,
                'tags'       => $tags,
                'comment'    => $comment,
            ));
        }

        if ( -1 === $vote ) {
            BKJA_Chat::delete_cache_for( $normalized_message, $category, $resolved_model, $job_title );
        } else {
            BKJA_Chat::extend_cache_ttl( $normalized_message, $category, $resolved_model, 3 * HOUR_IN_SECONDS, $job_title );
        }

        wp_send_json_success(array('success'=>true));
    }

    public static function ajax_get_history(){
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bkja_nonce' ) ) {
            wp_send_json_error(array('error'=>'invalid_nonce'),403);
        }

        $raw_session = isset($_POST['session']) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
        $session = self::canonical_session_id( $raw_session );
        $user_id = get_current_user_id() ?: 0;

        if ($user_id) {
            $rows = BKJA_Database::get_user_history($user_id,200);
        } else {
            $rows = BKJA_Database::get_history_by_session($session,200);
        }

        $items = array();
        if ($rows) {
            foreach ($rows as $r) {
                $items[] = array(
                    'message'    => $r->message,
                    'response'   => $r->response,
                    'created_at' => $r->created_at
                );
            }
        }

        $payload = array('items'=>$items);
        if ( ! $user_id ) {
            $payload['guest_session'] = $session;
        }
        $payload['server_session'] = $session;

        wp_send_json_success($payload);
    }
}

<?php 
if ( ! defined( 'ABSPATH' ) ) exit;

class BKJA_Chat {

    protected static $allowed_models = array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4', 'gpt-3.5-turbo', 'gpt-5' );

    // گرفتن API Key
    public static function get_api_key(){
        return trim(get_option('bkja_openai_api_key',''));
    }

    public static function normalize_message( $message ) {
        if ( ! is_string( $message ) ) {
            $message = (string) $message;
        }

        $message = preg_replace( '/\s+/u', ' ', $message );
        return trim( (string) $message );
    }

    protected static function normalize_lookup_text( $text ) {
        $text = self::normalize_message( $text );

        if ( '' === $text ) {
            return '';
        }

        $replacements = array(
            'ي' => 'ی',
            'ك' => 'ک',
            'ة' => 'ه',
            'ۀ' => 'ه',
            'ؤ' => 'و',
            'إ' => 'ا',
            'أ' => 'ا',
            'آ' => 'ا',
        );

        $text = strtr( $text, $replacements );
        $text = str_replace(
            array( '‌', "\xE2\x80\x8C", '-', '–', '—', '_', '/', '\\', '(', ')', '[', ']', '{', '}', '«', '»', '"', '\'', ':' ),
            ' ',
            $text
        );
        $text = preg_replace( '/\s+/u', ' ', $text );

        return trim( (string) $text );
    }

    protected static function build_job_lookup_phrases( $normalized_message ) {
        $text = self::normalize_lookup_text( $normalized_message );

        if ( '' === $text ) {
            return array();
        }

        $phrases = array( $text );

        $stopwords = array(
            'در','برای','به','از','که','چی','چیه','چه','چطور','چگونه','چقدر','چقد','چقدره','درآمد','درامد','درآمدش','درامدش','سرمایه','حقوق','میخوام','می‌خوام','میخواهم','میخواستم','میخوای','میخواید','میشه','می','من','کنم','کن','کردن','کرد','شروع','قدم','بعدی','منطقی','بیشتر','تحقیق','موضوع','حرفه','حوزه','شغل','کار','رشته','درمورد','درباره','اطلاعات','را','با','و','یا','اگر','آیا','ایا','است','نیست','هست','هستن','هستش','کج','کجاست','چیکار','چکار','بگو','بگید','نیاز','دارم','داریم','مورد','برا','برام','براش','براشون','توضیح','لطفا','لطفاً','معرفی','چند','چندتا','چندمه','پول','هزینه','هزینه‌','چیا','سود','درآمدزایی'
        );

        $words = preg_split( '/[\s،,.!?؟]+/u', $text );
        $words = array_filter( array_map( 'trim', $words ), function ( $word ) use ( $stopwords ) {
            if ( '' === $word ) {
                return false;
            }

            $check = function_exists( 'mb_strtolower' )
                ? mb_strtolower( $word, 'UTF-8' )
                : strtolower( $word );

            if ( in_array( $check, $stopwords, true ) ) {
                return false;
            }

            if ( function_exists( 'mb_strlen' ) ) {
                return mb_strlen( $word, 'UTF-8' ) >= 2;
            }

            return strlen( $word ) >= 2;
        } );

        $words = array_values( $words );
        $count = count( $words );

        if ( $count > 0 ) {
            $max_chunk = min( 4, $count );
            for ( $len = $max_chunk; $len >= 1; $len-- ) {
                for ( $i = 0; $i <= $count - $len; $i++ ) {
                    $chunk = implode( ' ', array_slice( $words, $i, $len ) );
                    $chunk = trim( $chunk );
                    if ( '' === $chunk ) {
                        continue;
                    }

                    if ( function_exists( 'mb_strlen' ) ) {
                        if ( mb_strlen( $chunk, 'UTF-8' ) < 2 ) {
                            continue;
                        }
                    } elseif ( strlen( $chunk ) < 2 ) {
                        continue;
                    }

                    $phrases[] = $chunk;
                }
            }
        }

        $phrases = array_values( array_unique( $phrases ) );

        usort( $phrases, function ( $a, $b ) {
            $len_a = function_exists( 'mb_strlen' ) ? mb_strlen( $a, 'UTF-8' ) : strlen( $a );
            $len_b = function_exists( 'mb_strlen' ) ? mb_strlen( $b, 'UTF-8' ) : strlen( $b );

            if ( $len_a === $len_b ) {
                return 0;
            }

            return ( $len_a < $len_b ) ? 1 : -1;
        } );

        return $phrases;
    }

    protected static function resolve_job_title_from_message( $normalized_message, $table, $title_column ) {
        global $wpdb;

        static $cache = array();

        $cache_key = md5( $normalized_message . '|' . $table . '|' . $title_column );
        if ( isset( $cache[ $cache_key ] ) ) {
            return $cache[ $cache_key ];
        }

        $job_title = '';
        $phrases   = self::build_job_lookup_phrases( $normalized_message );

        foreach ( $phrases as $phrase ) {
            $like = '%' . $wpdb->esc_like( $phrase ) . '%';
            $row  = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT {$title_column} AS job_title FROM {$table} WHERE {$title_column} LIKE %s ORDER BY CHAR_LENGTH({$title_column}) ASC LIMIT 1",
                    $like
                )
            );

            if ( $row && ! empty( $row->job_title ) ) {
                $job_title = $row->job_title;
                break;
            }
        }

        if ( '' === $job_title ) {
            $compact = preg_replace( '/\s+/u', '', self::normalize_lookup_text( $normalized_message ) );
            if ( '' !== $compact ) {
                $like = '%' . $wpdb->esc_like( $compact ) . '%';
                $row  = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT {$title_column} AS job_title FROM {$table} WHERE REPLACE(REPLACE(REPLACE({$title_column}, '‌', ''), ' ', ''), '-', '') LIKE %s LIMIT 1",
                        $like
                    )
                );

                if ( $row && ! empty( $row->job_title ) ) {
                    $job_title = $row->job_title;
                }
            }
        }

        $cache[ $cache_key ] = $job_title;

        return $job_title;
    }

    public static function resolve_model( $maybe = '' ) {
        $maybe = is_string( $maybe ) ? trim( $maybe ) : '';
        if ( $maybe && in_array( $maybe, self::$allowed_models, true ) ) {
            return $maybe;
        }

        $stored = trim( (string) get_option( 'bkja_model', '' ) );
        if ( $stored && in_array( $stored, self::$allowed_models, true ) ) {
            return $stored;
        }

        return 'gpt-4o-mini';
    }

    public static function build_cache_key( $message, $category = '', $model = '', $job_title = '' ) {
        $normalized = self::normalize_message( $message );
        $category   = is_string( $category ) ? trim( $category ) : '';
        $model      = self::resolve_model( $model );
        $job_title  = is_string( $job_title ) ? trim( $job_title ) : '';
        $version    = self::get_cache_version();

        $parts = array(
            'msg:' . $normalized,
            'cat:' . $category,
            'm:' . $model,
        );

        if ( '' !== $job_title ) {
            $parts[] = 'job:' . self::normalize_message( $job_title );
        }

        return 'bkja_cache_v' . $version . '_' . md5( implode( '|', $parts ) );
    }

    protected static function is_cache_enabled() {
        return '1' === (string) get_option( 'bkja_enable_cache', '1' );
    }

    protected static function get_cache_version() {
        $version = (int) get_option( 'bkja_cache_version', 1 );

        if ( $version <= 0 ) {
            $version = 1;
            update_option( 'bkja_cache_version', $version );
        }

        return $version;
    }

    protected static function bump_cache_version() {
        $version = self::get_cache_version() + 1;
        update_option( 'bkja_cache_version', $version );

        return $version;
    }

    protected static function get_cache_ttl( $model ) {
        $model = self::resolve_model( $model );

        $custom_mini   = absint( get_option( 'bkja_cache_ttl_mini' ) );
        $custom_others = absint( get_option( 'bkja_cache_ttl_others' ) );

        if ( 'gpt-4o-mini' === $model ) {
            return $custom_mini > 0 ? $custom_mini : HOUR_IN_SECONDS;
        }

        if ( in_array( $model, array( 'gpt-4o', 'gpt-4', 'gpt-5' ), true ) ) {
            $ttl = 2 * HOUR_IN_SECONDS;
            return $custom_others > 0 ? $custom_others : $ttl;
        }

        return $custom_others > 0 ? $custom_others : HOUR_IN_SECONDS;
    }

    protected static function should_accept_cached_payload( $normalized_message, $payload ) {
        if ( empty( $normalized_message ) || empty( $payload ) ) {
            return false;
        }

        if ( is_array( $payload ) ) {
            $source = isset( $payload['source'] ) ? $payload['source'] : '';
            if ( empty( $source ) && isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
                $source = isset( $payload['meta']['source'] ) ? $payload['meta']['source'] : '';
            }

            if ( in_array( $source, array( 'database', 'job_context' ), true ) ) {
                $api_key = self::get_api_key();
                if ( ! empty( $api_key ) ) {
                    return false;
                }
            }

            $text = isset( $payload['text'] ) ? $payload['text'] : '';
        } else {
            $text = (string) $payload;
        }

        $text = (string) $text;

        $keywords = array( 'درآمد', 'حقوق', 'سرمایه' );
        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized_message, 'UTF-8' ) : strtolower( $normalized_message );

        foreach ( $keywords as $keyword ) {
            $keyword_check = function_exists( 'mb_strpos' ) ? mb_strpos( $haystack, $keyword ) : strpos( $haystack, $keyword );
            if ( false !== $keyword_check ) {
                if ( ! preg_match( '/[0-9۰-۹]+/u', $text ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    protected static function clamp_history( $history, $limit = 4 ) {
        if ( ! is_array( $history ) || $limit <= 0 ) {
            return array();
        }

        if ( count( $history ) <= $limit ) {
            return $history;
        }

        return array_slice( $history, -1 * $limit );
    }

    /**
     * Resolve a job title/group from free-text query using base_label/group metadata.
     */
    public static function resolve_job_title_from_query( $raw_query ) {
        global $wpdb;

        if ( is_array( $raw_query ) || is_object( $raw_query ) ) {
            $group_key     = '';
            $job_title_id  = 0;
            $label_hint    = '';
            if ( is_array( $raw_query ) ) {
                $group_key    = isset( $raw_query['group_key'] ) ? sanitize_text_field( $raw_query['group_key'] ) : '';
                $job_title_id = isset( $raw_query['job_title_id'] ) ? intval( $raw_query['job_title_id'] ) : 0;
                $label_hint   = isset( $raw_query['label'] ) ? sanitize_text_field( $raw_query['label'] ) : '';
            } else {
                $group_key    = isset( $raw_query->group_key ) ? sanitize_text_field( $raw_query->group_key ) : '';
                $job_title_id = isset( $raw_query->job_title_id ) ? intval( $raw_query->job_title_id ) : 0;
                $label_hint   = isset( $raw_query->label ) ? sanitize_text_field( $raw_query->label ) : '';
            }

            if ( $group_key ) {
                $ids    = class_exists( 'BKJA_Database' ) ? BKJA_Database::get_job_title_ids_for_group( $group_key ) : array();
                $row    = $wpdb->get_row( $wpdb->prepare( "SELECT id, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$wpdb->prefix}bkja_job_titles WHERE group_key = %s ORDER BY is_primary DESC, id ASC LIMIT 1", $group_key ) );
                $label  = $row ? $row->label : $label_hint;
                $slug   = $row ? $row->slug : '';
                $id_val = $row ? (int) $row->id : ( $job_title_id ?: ( ! empty( $ids ) ? (int) $ids[0] : 0 ) );

                return array(
                    'job_title_id'  => $id_val,
                    'group_key'     => $group_key,
                    'label'         => $label,
                    'slug'          => $slug,
                    'job_title_ids' => $ids ? $ids : ( $id_val ? array( $id_val ) : array() ),
                );
            }

            if ( $job_title_id > 0 ) {
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, group_key, COALESCE(base_label, label) AS label, COALESCE(base_slug, slug) AS slug FROM {$wpdb->prefix}bkja_job_titles WHERE id = %d", $job_title_id ) );
                if ( $row ) {
                    $ids = array( (int) $row->id );
                    if ( $row->group_key && class_exists( 'BKJA_Database' ) ) {
                        $ids = BKJA_Database::get_job_title_ids_for_group( $row->group_key );
                    }

                    return array(
                        'job_title_id'  => (int) $row->id,
                        'group_key'     => $row->group_key ? $row->group_key : null,
                        'label'         => $row->label,
                        'slug'          => $row->slug,
                        'job_title_ids' => $ids,
                    );
                }
            }

            $raw_query = $label_hint ? $label_hint : (string) $raw_query;
        }

        $normalized_full = self::normalize_lookup_text( $raw_query );
        if ( '' === $normalized_full ) {
            return array();
        }

        $lowered = function_exists( 'mb_strtolower' ) ? mb_strtolower( $normalized_full, 'UTF-8' ) : strtolower( $normalized_full );

        $filler_words = array( 'درآمد', 'درامد', 'حقوق', 'حقوقش', 'حقوقشون', 'شغل', 'کار', 'چقدره', 'چقدر', 'چنده', 'در', 'میاد', 'در میاد', 'میاد' );
        $tokens       = preg_split( '/[\s،,.!?؟]+/u', $lowered );
        $tokens       = array_filter( array_map( 'trim', $tokens ) );

        $core_tokens = array();
        foreach ( $tokens as $token ) {
            if ( in_array( $token, $filler_words, true ) ) {
                continue;
            }
            if ( '' !== $token ) {
                $core_tokens[] = $token;
            }
        }

        $core = trim( implode( ' ', $core_tokens ) );
        if ( '' === $core ) {
            $core = $lowered;
        }

        $table_titles = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs   = $wpdb->prefix . 'bkja_jobs';

        $candidate_tokens = array( $core );

        if ( function_exists( 'mb_substr' ) ? 'ی' === mb_substr( $core, -1, 1, 'UTF-8' ) : ( 'ی' === substr( $core, -2 ) ) ) {
            $alt_core = function_exists( 'mb_substr' ) ? mb_substr( $core, 0, mb_strlen( $core, 'UTF-8' ) - 1, 'UTF-8' ) : substr( $core, 0, -2 );

            if ( '' !== $alt_core ) {
                $candidate_tokens[] = $alt_core;
            }
        }

        $candidate_tokens[] = $normalized_full;
        $candidate_tokens   = array_values( array_unique( array_filter( $candidate_tokens ) ) );

        $stages = array(
            'exact'    => function( $term ) {
                return array(
                        'where'  => '(jt.base_label = %s OR jt.label = %s OR jt.slug = %s OR jt.base_slug = %s)',
                        'params' => array( $term, $term, $term, $term ),
                );
            },
            'prefix'   => function( $term ) {
                $term_prefix = $term . '%';
                return array(
                    'where'  => '(jt.label LIKE %s OR jt.base_label LIKE %s)',
                    'params' => array( $term_prefix, $term_prefix ),
                );
            },
            'contains' => function( $term ) {
                $term_like = '%' . $term . '%';
                return array(
                    'where'  => '(jt.label LIKE %s OR jt.base_label LIKE %s)',
                    'params' => array( $term_like, $term_like ),
                );
            },
        );

        $calc_match_length = function( $label ) use ( $candidate_tokens ) {
            $max_len = 0;
            foreach ( $candidate_tokens as $token ) {
                if ( '' === $token ) {
                    continue;
                }

                $contains = function_exists( 'mb_strpos' ) ? mb_strpos( $label, $token, 0, 'UTF-8' ) : strpos( $label, $token );
                if ( false !== $contains ) {
                    $len     = function_exists( 'mb_strlen' ) ? mb_strlen( $token, 'UTF-8' ) : strlen( $token );
                    $max_len = max( $max_len, $len );
                }
            }

            return $max_len;
        };

        foreach ( $stages as $callback ) {
            $stage_rows = array();

            foreach ( $candidate_tokens as $term ) {
                $condition = $callback( $term );

                $sql = "SELECT jt.id, jt.group_key, COALESCE(jt.base_label, jt.label) AS label, COALESCE(jt.base_slug, jt.slug) AS slug, jt.is_primary, jt.is_visible,
                               COALESCE(j.cnt, 0) AS jobs_count, COALESCE(g.group_jobs, 0) AS group_jobs
                        FROM {$table_titles} jt
                        LEFT JOIN (SELECT job_title_id, COUNT(*) AS cnt FROM {$table_jobs} GROUP BY job_title_id) j ON j.job_title_id = jt.id
                        LEFT JOIN (
                            SELECT jt2.group_key, SUM(COALESCE(j2.cnt, 0)) AS group_jobs
                            FROM {$table_titles} jt2
                            LEFT JOIN (SELECT job_title_id, COUNT(*) AS cnt FROM {$table_jobs} GROUP BY job_title_id) j2 ON j2.job_title_id = jt2.id
                            GROUP BY jt2.group_key
                        ) g ON g.group_key = jt.group_key
                        WHERE {$condition['where']} AND jt.group_key IS NOT NULL AND jt.is_visible = 1
                        LIMIT 40";

                $params = $condition['params'];
                array_unshift( $params, $sql );
                $sql_prepared = call_user_func_array( array( $wpdb, 'prepare' ), $params );

                $rows = $wpdb->get_results( $sql_prepared );
                if ( ! empty( $rows ) ) {
                    $stage_rows = array_merge( $stage_rows, $rows );
                }
            }

            if ( empty( $stage_rows ) ) {
                continue;
            }

            $grouped = array();
            foreach ( $stage_rows as $row ) {
                $group_key = $row->group_key ? $row->group_key : $row->id;
                $label     = isset( $row->label ) ? (string) $row->label : '';
                $match_len = $calc_match_length( $label );

                if ( ! isset( $grouped[ $group_key ] ) ) {
                    $grouped[ $group_key ] = array(
                        'group_jobs'   => (int) $row->group_jobs,
                        'total_jobs'   => (int) $row->jobs_count,
                        'match_len'    => $match_len,
                        'primary_row'  => null,
                        'best_row'     => $row,
                        'rows'         => array( $row ),
                    );
                } else {
                    $grouped[ $group_key ]['total_jobs'] += (int) $row->jobs_count;
                    $grouped[ $group_key ]['rows'][]      = $row;
                    $grouped[ $group_key ]['match_len']    = max( $grouped[ $group_key ]['match_len'], $match_len );
                }

                if ( ! empty( $row->is_primary ) && ! $grouped[ $group_key ]['primary_row'] ) {
                    $grouped[ $group_key ]['primary_row'] = $row;
                }
            }

            $grouped = array_values( $grouped );
            usort( $grouped, function( $a, $b ) {
                if ( $a['match_len'] !== $b['match_len'] ) {
                    return ( $a['match_len'] > $b['match_len'] ) ? -1 : 1;
                }

                if ( $a['group_jobs'] !== $b['group_jobs'] ) {
                    return ( $a['group_jobs'] > $b['group_jobs'] ) ? -1 : 1;
                }

                if ( $a['total_jobs'] !== $b['total_jobs'] ) {
                    return ( $a['total_jobs'] > $b['total_jobs'] ) ? -1 : 1;
                }

                return 0;
            } );

            $best_group = $grouped[0];
            $best_row   = $best_group['primary_row'] ? $best_group['primary_row'] : $best_group['best_row'];

            $primary_id = (int) $best_row->id;
            if ( ! $best_row->is_primary ) {
                $primary_id = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table_titles} WHERE group_key = %s ORDER BY is_primary DESC, id ASC LIMIT 1",
                        $best_row->group_key
                    )
                );

                if ( ! $primary_id ) {
                    $primary_id = (int) $best_row->id;
                }
            }

            $group_ids = array( $primary_id );
            if ( class_exists( 'BKJA_Database' ) && ! empty( $best_row->group_key ) ) {
                $maybe_ids = BKJA_Database::get_job_title_ids_for_group( $best_row->group_key );
                if ( $maybe_ids ) {
                    $group_ids = array_map( 'intval', $maybe_ids );
                }
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf( '[BKJA] resolve_job_title_from_query: raw="%s" core="%s" resolved_group="%s" primary_id=%d label="%s"', (string) $raw_query, (string) $core, (string) $best_row->group_key, (int) $primary_id, (string) $best_row->label ) );
            }

            return array(
                'job_title_id' => $primary_id,
                'group_key'    => $best_row->group_key,
                'label'        => $best_row->label,
                'slug'         => $best_row->slug,
                'job_title_ids'=> $group_ids,
            );
        }

        return array();
    }

    /**
     * Unified resolver that mirrors sidebar resolution (group_key based).
     */
    public static function resolve_job_context_from_query( $user_text ) {
        $resolved = array();

        if ( class_exists( 'BKJA_Database' ) && method_exists( 'BKJA_Database', 'resolve_job_query' ) ) {
            $resolved = BKJA_Database::resolve_job_query( $user_text );
        } else {
            $resolved = self::resolve_job_title_from_query( $user_text );
        }

        if ( empty( $resolved ) ) {
            return array();
        }

        return array(
            'group_key'             => isset( $resolved['group_key'] ) ? $resolved['group_key'] : null,
            'primary_job_title_id'  => isset( $resolved['matched_job_title_id'] ) ? (int) $resolved['matched_job_title_id'] : ( isset( $resolved['job_title_id'] ) ? (int) $resolved['job_title_id'] : null ),
            'job_title_ids'         => isset( $resolved['job_title_ids'] ) ? (array) $resolved['job_title_ids'] : array(),
            'label'                 => isset( $resolved['label'] ) ? $resolved['label'] : '',
            'slug'                  => isset( $resolved['slug'] ) ? $resolved['slug'] : '',
            'confidence'            => isset( $resolved['confidence'] ) ? (float) $resolved['confidence'] : null,
            'candidates'            => isset( $resolved['candidates'] ) ? (array) $resolved['candidates'] : array(),
            'ambiguous'             => ! empty( $resolved['ambiguous'] ),
        );
    }

    protected static function get_feedback_hint( $normalized_message, $session_id, $user_id ) {
        if ( empty( $normalized_message ) || ! class_exists( 'BKJA_Database' ) ) {
            return '';
        }

        $row = BKJA_Database::get_latest_feedback( $normalized_message, $session_id, (int) $user_id );
        if ( empty( $row ) || (int) $row['vote'] !== -1 ) {
            return '';
        }

        $message = 'پاسخ قبلی برای این کاربر رضایت‌بخش نبود؛ لطفاً کوتاه‌تر، دقیق‌تر و عدد-محورتر پاسخ بده و در صورت وجود داده‌های داخلی، منبع را اعلام کن.';

        $tags = array();
        if ( ! empty( $row['tags'] ) ) {
            $parts = explode( ',', $row['tags'] );
            foreach ( $parts as $part ) {
                $part = trim( $part );
                if ( $part ) {
                    $tags[] = $part;
                }
            }
        }

        if ( $tags ) {
            $message .= ' نکات اعلام‌شده کاربر: ' . implode( ', ', $tags ) . '.';
        }

        if ( ! empty( $row['comment'] ) ) {
            $message .= ' توضیح کاربر: ' . trim( $row['comment'] ) . '.';
        }

        return $message;
    }

    // دریافت خلاصه و رکوردهای شغل مرتبط با پیام
    public static function get_job_context($message, $job_title_hint = '', $job_slug = '', $job_title_id = 0, $group_key = '') {
        global $wpdb;

        $normalized = self::normalize_message( $message );
        $job_title_hint = is_string( $job_title_hint ) ? trim( $job_title_hint ) : '';
        $job_slug = is_string( $job_slug ) ? trim( $job_slug ) : '';
        $job_title_id  = (int) $job_title_id;
        $group_key     = is_string( $group_key ) ? trim( $group_key ) : '';

        if ( '' === $normalized && '' === $job_title_hint && '' === $job_slug ) {
            return array();
        }

        $table = $wpdb->prefix . 'bkja_jobs';

        static $title_column = null;
        if ( null === $title_column ) {
            $columns = $wpdb->get_col( "DESC {$table}", 0 );
            if ( is_array( $columns ) && in_array( 'job_title', $columns, true ) ) {
                $title_column = 'job_title';
            } else {
                $title_column = 'title';
            }
        }

        $job_title             = '';
        $resolved_for_db       = null;
        $resolved_ids          = array();
        $primary_job_title_id  = 0;
        $resolved_confidence   = null;
        $clarification_options = array();
        $ambiguous_match       = false;

        if ( $job_title_id > 0 || $group_key ) {
            $resolved = self::resolve_job_context_from_query(
                array(
                    'job_title_id' => $job_title_id,
                    'group_key'    => $group_key,
                )
            );
            if ( $resolved ) {
                $job_title             = $resolved['label'];
                $job_slug              = isset( $resolved['slug'] ) ? $resolved['slug'] : $job_slug;
                $resolved_ids          = isset( $resolved['job_title_ids'] ) ? (array) $resolved['job_title_ids'] : array();
                $primary_job_title_id  = ! empty( $resolved['primary_job_title_id'] ) ? (int) $resolved['primary_job_title_id'] : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : 0 );
                $resolved_confidence   = isset( $resolved['confidence'] ) ? $resolved['confidence'] : $resolved_confidence;
                $clarification_options = isset( $resolved['candidates'] ) ? (array) $resolved['candidates'] : $clarification_options;
                $ambiguous_match       = ! empty( $resolved['ambiguous'] );
                $resolved_for_db       = ! empty( $resolved['group_key'] ) ? array( 'group_key' => $resolved['group_key'], 'job_title_ids' => $resolved_ids, 'label' => $resolved['label'] ) : ( ! empty( $resolved['primary_job_title_id'] ) ? $resolved['primary_job_title_id'] : $resolved['label'] );
            }
        }

        if ( '' !== $normalized ) {
            $resolved = self::resolve_job_context_from_query( $normalized );
            if ( $resolved ) {
                $job_title             = $resolved['label'];
                $job_slug              = isset( $resolved['slug'] ) ? $resolved['slug'] : $job_slug;
                $resolved_ids          = isset( $resolved['job_title_ids'] ) ? (array) $resolved['job_title_ids'] : array();
                $primary_job_title_id  = ! empty( $resolved['primary_job_title_id'] ) ? (int) $resolved['primary_job_title_id'] : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : $primary_job_title_id );
                $resolved_confidence   = isset( $resolved['confidence'] ) ? $resolved['confidence'] : $resolved_confidence;
                $clarification_options = isset( $resolved['candidates'] ) ? (array) $resolved['candidates'] : $clarification_options;
                $ambiguous_match       = ! empty( $resolved['ambiguous'] );
                $resolved_for_db       = ! empty( $resolved['group_key'] ) ? array( 'group_key' => $resolved['group_key'], 'job_title_ids' => $resolved_ids, 'label' => $resolved['label'] ) : ( ! empty( $resolved['primary_job_title_id'] ) ? $resolved['primary_job_title_id'] : $resolved['label'] );
            }
        }

        if ( '' === $job_title && '' !== $job_title_hint ) {
            $resolved_hint = self::resolve_job_context_from_query( $job_title_hint );
            if ( $resolved_hint ) {
                $job_title             = $resolved_hint['label'];
                $job_slug              = isset( $resolved_hint['slug'] ) ? $resolved_hint['slug'] : $job_slug;
                $resolved_ids          = isset( $resolved_hint['job_title_ids'] ) ? (array) $resolved_hint['job_title_ids'] : $resolved_ids;
                $primary_job_title_id  = ! empty( $resolved_hint['primary_job_title_id'] ) ? (int) $resolved_hint['primary_job_title_id'] : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : $primary_job_title_id );
                $resolved_confidence   = isset( $resolved_hint['confidence'] ) ? $resolved_hint['confidence'] : $resolved_confidence;
                $clarification_options = isset( $resolved_hint['candidates'] ) ? (array) $resolved_hint['candidates'] : $clarification_options;
                $ambiguous_match       = ! empty( $resolved_hint['ambiguous'] );
                $resolved_for_db       = ! empty( $resolved_hint['group_key'] ) ? array( 'group_key' => $resolved_hint['group_key'], 'job_title_ids' => $resolved_ids, 'label' => $resolved_hint['label'] ) : ( ! empty( $resolved_hint['primary_job_title_id'] ) ? $resolved_hint['primary_job_title_id'] : $resolved_hint['label'] );
            }
        }

        if ( '' === $job_title && '' !== $normalized ) {
            $job_title = self::resolve_job_title_from_message( $normalized, $table, $title_column );
        }

        if ( '' === $job_title && '' !== $job_title_hint ) {
            $job_title = $job_title_hint;
        }

        if ( '' === $job_title ) {
            return array();
        }

        $target_title = $resolved_for_db ? $resolved_for_db : $job_title;

        if ( $primary_job_title_id > 0 && class_exists( 'BKJA_Database' ) && method_exists( 'BKJA_Database', 'get_job_summary_by_job_title_id' ) ) {
            $summary = BKJA_Database::get_job_summary_by_job_title_id( $primary_job_title_id );
            $records_data = BKJA_Database::get_job_records_by_job_title_id( $primary_job_title_id, 5, 0 );
        } else {
            $summary = class_exists('BKJA_Database') ? BKJA_Database::get_job_summary($target_title) : null;
            $records_data = class_exists('BKJA_Database') ? BKJA_Database::get_job_records($target_title, 5, 0) : [];
        }
        $records = is_array( $records_data ) && isset( $records_data['records'] ) ? $records_data['records'] : $records_data;
        return [
            'job_title' => $job_title,
            'summary'   => $summary,
            'records'   => $records,
            'job_slug'  => '' !== $job_slug ? $job_slug : null,
            'job_title_ids' => $resolved_ids,
            'group_key' => $resolved_for_db && is_array( $resolved_for_db ) && isset( $resolved_for_db['group_key'] ) ? $resolved_for_db['group_key'] : null,
            'primary_job_title_id' => $primary_job_title_id ?: null,
            'resolved_confidence' => $resolved_confidence,
            'candidates' => $clarification_options,
            'ambiguous' => $ambiguous_match,
            'stats_executed' => is_array( $summary ),
        ];
    }

    protected static function format_amount_label( $value ) {
        return bkja_format_toman_as_million_label( $value );
    }

    protected static function format_range_label( $min, $max ) {
        if ( ! is_numeric( $min ) || ! is_numeric( $max ) || $min <= 0 || $max <= 0 ) {
            return '';
        }

        if ( $min > $max ) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        $format_number = function( $toman_value ) {
            $label = bkja_format_toman_as_million_label( $toman_value );
            return str_replace( ' میلیون تومان', '', $label );
        };

        $min_label = $format_number( $min );
        $max_label = $format_number( $max );

        return trim( $min_label ) . ' تا ' . trim( $max_label ) . ' میلیون تومان';
    }

    protected static function trim_snippet( $text, $length = 140 ) {
        $text = wp_strip_all_tags( (string) $text );
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            if ( mb_strlen( $text, 'UTF-8' ) <= $length ) {
                return $text;
            }
            return rtrim( mb_substr( $text, 0, $length - 1, 'UTF-8' ) ) . '…';
        }

        if ( strlen( $text ) <= $length ) {
            return $text;
        }

        return rtrim( substr( $text, 0, max( 0, $length - 1 ) ) ) . '…';
    }

    protected static function build_context_prompt( $context ) {
        if ( empty( $context['job_title'] ) ) {
            return '';
        }

        $title = $context['job_title'];
        $lines = array();
        $lines[] = "داده‌های ساخت‌یافته درباره شغل «{$title}»:";

        if ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) {
            $summary       = $context['summary'];
            $count_reports = isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
            $window_months = isset( $summary['window_months'] ) ? (int) $summary['window_months'] : null;

            $count_line = 'تعداد گزارش‌های معتبر';
            if ( $window_months ) {
                $count_line .= " در {$window_months} ماه اخیر";
            }
            $count_line .= ': ' . $count_reports;
            $lines[] = $count_line;

            $avg_income  = isset( $summary['avg_income'] ) ? $summary['avg_income'] : null;
            $min_income  = isset( $summary['min_income'] ) ? $summary['min_income'] : null;
            $max_income  = isset( $summary['max_income'] ) ? $summary['max_income'] : null;
            $avg_invest  = isset( $summary['avg_investment'] ) ? $summary['avg_investment'] : null;
            $min_invest  = isset( $summary['min_investment'] ) ? $summary['min_investment'] : null;
            $max_invest  = isset( $summary['max_investment'] ) ? $summary['max_investment'] : null;

            if ( $avg_income || $min_income || $max_income ) {
                $income_line = 'میانگین درآمد ماهانه: ' . self::format_amount_label( $avg_income );
                $range       = self::format_range_label( $min_income, $max_income );
                if ( $range ) {
                    $income_line .= ' | بازه رایج: ' . $range;
                }
                $lines[] = $income_line;
            }

            if ( $avg_invest || $min_invest || $max_invest ) {
                $invest_line = 'میانگین سرمایه لازم: ' . self::format_amount_label( $avg_invest );
                $range       = self::format_range_label( $min_invest, $max_invest );
                if ( $range ) {
                    $invest_line .= ' | بازه رایج: ' . $range;
                }
                $lines[] = $invest_line;
            }

            if ( ! empty( $summary['cities'] ) && is_array( $summary['cities'] ) ) {
                $lines[] = 'شهرهای پرتکرار: ' . implode( '، ', array_slice( $summary['cities'], 0, 5 ) );
            }

            if ( ! empty( $summary['advantages'] ) ) {
                $lines[] = 'مزایای پرتکرار: ' . implode( '، ', array_slice( (array) $summary['advantages'], 0, 5 ) );
            }
            if ( ! empty( $summary['disadvantages'] ) ) {
                $lines[] = 'چالش‌های پرتکرار: ' . implode( '، ', array_slice( (array) $summary['disadvantages'], 0, 5 ) );
            }
        }

        if ( ! empty( $context['records'] ) && is_array( $context['records'] ) ) {
            $records = array_slice( $context['records'], 0, 2 );
            $index   = 1;
            foreach ( $records as $record ) {
                if ( ! is_array( $record ) ) {
                    continue;
                }
                $parts = array();
                $income_value = isset( $record['income_num'] ) && $record['income_num'] > 0
                    ? self::format_amount_label( $record['income_num'] )
                    : ( ! empty( $record['income'] ) ? $record['income'] : 'نامشخص' );
                $investment_value = isset( $record['investment_num'] ) && $record['investment_num'] > 0
                    ? self::format_amount_label( $record['investment_num'] )
                    : ( ! empty( $record['investment'] ) ? $record['investment'] : 'نامشخص' );

                $parts[] = 'درآمد: ' . $income_value;
                $parts[] = 'سرمایه: ' . $investment_value;
                if ( ! empty( $record['city'] ) ) {
                    $parts[] = 'شهر: ' . $record['city'];
                }
                if ( ! empty( $record['details'] ) ) {
                    $parts[] = 'خلاصه: ' . self::trim_snippet( $record['details'], 120 );
                }
                $lines[] = 'نمونه تجربه ' . $index . ': ' . implode( ' | ', array_filter( array_map( 'trim', $parts ) ) );
                $index++;
            }
        }

        $lines[] = 'این آمار و اعداد بر اساس گزارش کاربران این سیستم است و منبع رسمی نیست. پاسخ نهایی باید عدد-محور، موجز و فقط بر مبنای همین داده‌ها باشد. اگر داده کافی نیست، «نامشخص» یا «تقریبی» اعلام شود.';

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function format_job_context_reply( $context ) {
        if ( empty( $context['job_title'] ) ) {
            return '';
        }

        $title   = $context['job_title'];
        $summary = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $records = ( ! empty( $context['records'] ) && is_array( $context['records'] ) ) ? $context['records'] : array();

        $sections = array();

        $sections[] = "📌 خلاصه داده‌های واقعی درباره «{$title}»:";
        $count_reports = isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
        $window_months = isset( $summary['window_months'] ) ? (int) $summary['window_months'] : null;

        $window_label = $window_months ? 'حدود ' . $window_months . ' ماه اخیر' : '';
        $income_numeric_total = isset( $summary['income_numeric_total'] ) ? (int) $summary['income_numeric_total'] : 0;

        if ( $count_reports > 0 ) {
            $sections[] = '• ' . ( $window_label ? $window_label . ' - ' : '' ) . $count_reports . ' گزارش کاربری ثبت شده است.';

            if ( $income_numeric_total > 0 ) {
                $sections[] = '• از ' . $count_reports . ' گزارش، ' . $income_numeric_total . ' گزارش درآمد عددی قابل تحلیل داشت.';
            } else {
                $sections[] = '• دادهٔ کافی برای محاسبهٔ دقیق درآمد ندارم (مثلاً فقط ۰ گزارش عددی). اگر چند تجربهٔ دیگر اضافه شود، میانگین دقیق‌تر می‌شود.';
            }
        } else {
            $sections[] = '• در ۱۲ ماه اخیر گزارشی برای این شغل ثبت نشده. می‌خوای کل زمان رو هم بررسی کنم؟';
        }
        $sections[] = '• اعداد زیر بر اساس گزارش‌های کاربران این سیستم است و آمار رسمی نیست.';

        $sections[] = '';
        $sections[] = '💵 درآمد ماهانه (میلیون تومان):';
        $income_line = '• میانگین: ' . self::format_amount_label( isset( $summary['avg_income'] ) ? $summary['avg_income'] : null );
        $income_range = self::format_range_label( $summary['min_income'] ?? null, $summary['max_income'] ?? null );
        if ( $income_range ) {
            $income_line .= ' | بازه رایج: ' . $income_range;
        }
        if ( $income_numeric_total > 0 && $income_numeric_total < 3 ) {
            $income_line .= ' (دقت پایین به دلیل گزارش‌های محدود)';
        }
        $sections[] = $income_line;

        $sections[] = '';
        $sections[] = '💰 سرمایه لازم (میلیون تومان):';
        $invest_line = '• میانگین: ' . self::format_amount_label( isset( $summary['avg_investment'] ) ? $summary['avg_investment'] : null );
        $invest_range = self::format_range_label( $summary['min_investment'] ?? null, $summary['max_investment'] ?? null );
        if ( $invest_range ) {
            $invest_line .= ' | بازه رایج: ' . $invest_range;
        }
        if ( isset( $summary['investment_count'] ) && $summary['investment_count'] > 0 && $summary['investment_count'] < 3 ) {
            $invest_line .= ' (دقت پایین به دلیل گزارش‌های محدود)';
        }
        $sections[] = $invest_line;

        if ( ! empty( $summary['cities'] ) ) {
            $sections[] = '';
            $sections[] = '📍 شهرهای پرتکرار: ' . implode( '، ', array_slice( (array) $summary['cities'], 0, 5 ) );
        }

        if ( ! empty( $summary['advantages'] ) || ! empty( $summary['disadvantages'] ) ) {
            $sections[] = '';
            if ( ! empty( $summary['advantages'] ) ) {
                $sections[] = '✅ مزایای پرتکرار: ' . implode( '، ', array_slice( (array) $summary['advantages'], 0, 5 ) );
            }
            if ( ! empty( $summary['disadvantages'] ) ) {
                $sections[] = '⚠️ چالش‌های پرتکرار: ' . implode( '، ', array_slice( (array) $summary['disadvantages'], 0, 5 ) );
            }
        }

        if ( ! empty( $records ) ) {
            $sections[] = '';
            $sections[] = '🧪 نمونه‌های واقعی کاربران:';
            foreach ( array_slice( $records, 0, 2 ) as $record ) {
                if ( ! is_array( $record ) ) {
                    continue;
                }
                $parts = array();
                if ( ! empty( $record['income_num'] ) ) {
                    $parts[] = 'درآمد: ' . self::format_amount_label( $record['income_num'] );
                } elseif ( ! empty( $record['income'] ) ) {
                    $parts[] = 'درآمد: ' . $record['income'];
                }
                if ( ! empty( $record['investment_num'] ) ) {
                    $parts[] = 'سرمایه: ' . self::format_amount_label( $record['investment_num'] );
                } elseif ( ! empty( $record['investment'] ) ) {
                    $parts[] = 'سرمایه: ' . $record['investment'];
                }
                if ( ! empty( $record['city'] ) ) {
                    $parts[] = 'شهر: ' . $record['city'];
                }
                if ( ! empty( $record['details'] ) ) {
                    $parts[] = 'تجربه: ' . self::trim_snippet( $record['details'], 120 );
                }
                if ( ! empty( $parts ) ) {
                    $sections[] = '• ' . implode( ' | ', $parts );
                }
            }
        }

        $sections[] = '';
        $sections[] = '🚀 جمع‌بندی و اقدام بعدی:';
        $sections[] = '• اعداد بالا تنها از گزارش‌های کاربران سایت استخراج شده است؛ پیش از تصمیم نهایی با دو فعال حوزه ' . $title . ' مشورت کن.';
        $sections[] = '• مهارت‌ها و هزینه‌های ضروری را در یک لیست کوتاه یادداشت کن و با شرایط شخصی و بودجه خود تطبیق بده.';

        if ( empty( $summary ) ) {
            $sections[] = '';
            $sections[] = 'اگر مایل بودی اطلاعات دقیق‌تری بدهی (شهر، سطح تجربه، بودجه) تا جمع‌بندی بهتری ارائه شود.';
        }

        return implode( "\n", array_filter( array_map( 'trim', $sections ), function ( $line ) {
            return $line !== '' || $line === '0';
        } ) );
    }

    protected static function build_followup_suggestions( $message, $context = array(), $answer = '' ) {
        $suggestions = array();
        $push = function( $text ) use ( &$suggestions ) {
            $text = trim( (string) $text );
            if ( $text && ! in_array( $text, $suggestions, true ) ) {
                $suggestions[] = $text;
            }
        };

        $job_title = '';
        if ( ! empty( $context['job_title'] ) ) {
            $job_title = trim( (string) $context['job_title'] );
        }

        $clarification_candidates = array();
        if ( ! empty( $context['candidates'] ) && is_array( $context['candidates'] ) ) {
            $clarification_candidates = array_slice( $context['candidates'], 0, 3 );
        }

        $needs_clarification = ! empty( $context['ambiguous'] ) || ( isset( $context['resolved_confidence'] ) && (float) $context['resolved_confidence'] < 0.55 );
        if ( $clarification_candidates && $needs_clarification ) {
            $labels = array();
            foreach ( $clarification_candidates as $cand ) {
                if ( is_array( $cand ) ) {
                    $labels[] = isset( $cand['label'] ) ? $cand['label'] : '';
                } elseif ( is_object( $cand ) && isset( $cand->label ) ) {
                    $labels[] = $cand->label;
                }
            }
            $labels = array_filter( array_map( 'trim', $labels ) );
            if ( $labels ) {
                $question = 'منظورت کدام شغل است؟ ' . implode( ' یا ', array_slice( $labels, 0, 3 ) );
                $push( $question );
            }
        }

        $normalize = function( $text ) {
            if ( ! is_string( $text ) ) {
                $text = (string) $text;
            }

            if ( function_exists( 'mb_strtolower' ) ) {
                $text = mb_strtolower( $text, 'UTF-8' );
            } else {
                $text = strtolower( $text );
            }

            return trim( preg_replace( '/\s+/u', ' ', $text ) );
        };

        $message_norm = $normalize( $message );
        $answer_norm  = $normalize( $answer );

        $topics = array(
            'income'      => array( 'درآمد', 'حقوق', 'دستمزد' ),
            'investment'  => array( 'سرمایه', 'هزینه', 'بودجه', 'تجهیز' ),
            'skills'      => array( 'مهارت', 'آموزش', 'یادگیری', 'دوره' ),
            'market'      => array( 'بازار', 'تقاضا', 'استخدام', 'فرصت' ),
            'risk'        => array( 'چالش', 'ریسک', 'مشکل', 'دغدغه', 'سختی' ),
            'growth'      => array( 'پیشرفت', 'رشد', 'مسیر', 'نقشه راه' ),
            'tools'       => array( 'ابزار', 'گواهی', 'مدرک', 'تجهیزات' ),
            'personality' => array( 'شخصیت', 'تیپ', 'روحیه' ),
            'compare'     => array( 'مقایسه', 'جایگزین', 'مشابه', 'دیگر' ),
        );

        $topic_state = array();
        foreach ( $topics as $topic => $keywords ) {
            $topic_state[ $topic ] = array(
                'message' => false,
                'answer'  => false,
            );

            foreach ( $keywords as $keyword ) {
                $keyword = trim( $keyword );
                if ( '' === $keyword ) {
                    continue;
                }

                $found_in_message = function_exists( 'mb_strpos' )
                    ? mb_strpos( $message_norm, $keyword )
                    : strpos( $message_norm, $keyword );
                $found_in_answer  = function_exists( 'mb_strpos' )
                    ? mb_strpos( $answer_norm, $keyword )
                    : strpos( $answer_norm, $keyword );

                if ( false !== $found_in_message ) {
                    $topic_state[ $topic ]['message'] = true;
                }
                if ( false !== $found_in_answer ) {
                    $topic_state[ $topic ]['answer'] = true;
                }
            }
        }

        $job_fragment = $job_title ? "«{$job_title}»" : 'این حوزه';

        $topic_prompts = array(
            'income'     => "حدود درآمد {$job_fragment} در سطوح مختلف تجربه چقدر است؟",
            'investment' => "برای شروع {$job_fragment} چه مقدار سرمایه و تجهیزات لازم است؟",
            'skills'     => "چه مهارت‌های نرم و سختی برای موفقیت در {$job_fragment} ضروری است؟",
            'market'     => "چشم‌انداز بازار کار {$job_fragment} در یک تا سه سال آینده چگونه است؟",
            'risk'       => "مهم‌ترین چالش‌ها و ریسک‌های {$job_fragment} چیست و چطور باید مدیریت‌شان کرد؟",
            'growth'     => "یک نقشه راه مرحله‌به‌مرحله برای پیشرفت در {$job_fragment} پیشنهاد بده.",
            'tools'      => "کدام ابزار، گواهی یا دوره برای شروع {$job_fragment} توصیه می‌شود؟",
        );

        foreach ( $topic_prompts as $topic => $prompt ) {
            if ( empty( $topic_state[ $topic ] ) ) {
                continue;
            }

            $was_asked   = ! empty( $topic_state[ $topic ]['message'] );
            $was_answered = ! empty( $topic_state[ $topic ]['answer'] );

            if ( $was_asked && ! $was_answered ) {
                $push( $prompt );
            }
        }

        if ( $job_title ) {
            if ( empty( $topic_state['skills']['answer'] ) ) {
                $push( "برای موفقیت در {$job_fragment} چه مهارت‌هایی را باید از همین حالا تمرین کنم؟" );
            }
            if ( empty( $topic_state['market']['answer'] ) ) {
                $push( "بازار کار {$job_fragment} در ایران و خارج چه تفاوت‌هایی دارد؟" );
            }
            if ( empty( $topic_state['risk']['answer'] ) ) {
                $push( "بزرگ‌ترین اشتباهات رایج در مسیر {$job_fragment} چیست و چطور از آن‌ها دوری کنم؟" );
            }
            if ( empty( $topic_state['compare']['message'] ) ) {
                $push( "شغل‌های جایگزین نزدیک به {$job_fragment} که ارزش بررسی دارند را معرفی کن." );
            }
        }

        if ( empty( $suggestions ) ) {
            if ( empty( $topic_state['personality']['message'] ) ) {
                if ( $job_title ) {
                    $push( "آیا {$job_fragment} با ویژگی‌های شخصیتی من هماهنگ است؟ اگر لازم است سوال بپرس." );
                } else {
                    $push( 'اگر بخوای بررسی کنی این حوزه با شخصیت من هماهنگ است از چه سوالاتی شروع می‌کنی؟' );
                }
            }
            $push( 'به من کمک کن بدانم قدم بعدی منطقی برای تحقیق بیشتر درباره این موضوع چیست.' );
        }

        $capital_keywords = '/سرمایه|بودجه|سرمایه‌گذاری|پول|سرمایه گذاری/u';
        if ( preg_match( $capital_keywords, $message_norm ) ) {
            $capital_prompt = '';
            if ( preg_match( '/([0-9۰-۹]+[0-9۰-۹\.,]*)\s*(میلیارد|میلیون|هزار)?\s*(تومان|تومن|ریال)?/u', $message_norm, $amount_match ) ) {
                $amount_text = trim( $amount_match[0] );
                if ( $amount_text ) {
                    $capital_prompt = 'برای سرمایه ' . $amount_text . ' چه مسیرهای شغلی مطمئن و قابل راه‌اندازی پیشنهاد می‌کنی؟';
                }
            }

            if ( '' === $capital_prompt ) {
                $capital_prompt = 'اگر سرمایه مشخصی دارم چطور انتخاب کنم کدام شغل با آن بودجه قابل شروع است؟';
            }

            $capital_prompt = trim( $capital_prompt );
            if ( $capital_prompt && ! in_array( $capital_prompt, $suggestions, true ) ) {
                array_unshift( $suggestions, $capital_prompt );
            }
        }

        return array_slice( $suggestions, 0, 3 );
    }

    protected static function try_answer_from_db( $original_message, &$context = null, $model = '', $category = '', $normalized_message = null, $job_title_hint = '', $job_slug = '' ) {
        if ( null === $normalized_message ) {
            $normalized_message = self::normalize_message( $original_message );
        }

        if ( null === $context ) {
            $context = self::get_job_context( $normalized_message, $job_title_hint, $job_slug );
        }

        if ( empty( $context['job_title'] ) ) {
            return null;
        }

        $reply = self::format_job_context_reply( $context );
        if ( '' === trim( (string) $reply ) ) {
            return null;
        }

        return self::build_response_payload(
            $reply,
            $context,
            $original_message,
            false,
            'database',
            array(
                'model'              => self::resolve_model( $model ),
                'category'           => is_string( $category ) ? $category : '',
                'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : '',
                'job_slug'           => isset( $context['job_slug'] ) ? $context['job_slug'] : '',
                'job_title_id'       => isset( $context['primary_job_title_id'] ) ? $context['primary_job_title_id'] : null,
                'group_key'          => isset( $context['group_key'] ) ? $context['group_key'] : '',
                'normalized_message' => $normalized_message,
            )
        );
    }

    protected static function refresh_job_stats_payload( array $payload, $context = array() ) {
        $summary = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $stats_executed = ( is_array( $context ) && ! empty( $context['stats_executed'] ) ) || ! empty( $summary );

        $primary_job_title_id = isset( $context['primary_job_title_id'] ) ? (int) $context['primary_job_title_id'] : null;
        $job_title_ids        = isset( $context['job_title_ids'] ) ? (array) $context['job_title_ids'] : array();
        $group_key            = isset( $context['group_key'] ) ? $context['group_key'] : null;

        $job_report_count     = $stats_executed && isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
        $job_avg_income       = $stats_executed && isset( $summary['avg_income'] ) ? (float) $summary['avg_income'] : null;
        $job_income_range     = $stats_executed ? array( $summary['min_income'] ?? null, $summary['max_income'] ?? null ) : array( null, null );
        $job_avg_investment   = $stats_executed && isset( $summary['avg_investment'] ) ? (float) $summary['avg_investment'] : null;
        $job_investment_range = $stats_executed ? array( $summary['min_investment'] ?? null, $summary['max_investment'] ?? null ) : array( null, null );

        $used_job_stats = $stats_executed && $job_report_count > 0;

        $clarification_options = array();
        if ( isset( $context['candidates'] ) && is_array( $context['candidates'] ) ) {
            foreach ( array_slice( $context['candidates'], 0, 3 ) as $candidate ) {
                if ( ! is_array( $candidate ) && ! is_object( $candidate ) ) {
                    continue;
                }

                $label = is_array( $candidate ) ? ( $candidate['label'] ?? '' ) : ( isset( $candidate->label ) ? $candidate->label : '' );
                $cid   = is_array( $candidate ) ? ( $candidate['job_title_id'] ?? 0 ) : ( isset( $candidate->job_title_id ) ? $candidate->job_title_id : 0 );
                $gkey  = is_array( $candidate ) ? ( $candidate['group_key'] ?? '' ) : ( isset( $candidate->group_key ) ? $candidate->group_key : '' );
                $slug  = is_array( $candidate ) ? ( $candidate['slug'] ?? '' ) : ( isset( $candidate->slug ) ? $candidate->slug : '' );

                $clarification_options[] = array(
                    'label'         => (string) $label,
                    'job_title_id'  => (int) $cid,
                    'group_key'     => $gkey,
                    'slug'          => $slug,
                    'jobs_count'    => is_array( $candidate ) ? ( $candidate['jobs_count_recent'] ?? null ) : ( isset( $candidate->jobs_count_recent ) ? $candidate->jobs_count_recent : null ),
                    'confidence'    => is_array( $candidate ) ? ( $candidate['score'] ?? null ) : ( isset( $candidate->score ) ? $candidate->score : null ),
                );
            }
        }

        $payload['job_report_count']     = $stats_executed ? $job_report_count : null;
        $payload['job_avg_income']       = $stats_executed ? $job_avg_income : null;
        $payload['job_income_range']     = $job_income_range;
        $payload['job_avg_investment']   = $stats_executed ? $job_avg_investment : null;
        $payload['job_investment_range'] = $job_investment_range;
        $payload['used_job_stats']       = $used_job_stats;
        $payload['job_title_id']         = $primary_job_title_id;
        $payload['job_title_ids']        = $job_title_ids;
        $payload['group_key']            = $group_key;
        $payload['clarification_options']= $clarification_options;

        if ( ! isset( $payload['meta'] ) || ! is_array( $payload['meta'] ) ) {
            $payload['meta'] = array();
        }

        $payload['meta']['job_report_count']     = $payload['job_report_count'];
        $payload['meta']['job_avg_income']       = $payload['job_avg_income'];
        $payload['meta']['job_income_range']     = $payload['job_income_range'];
        $payload['meta']['job_avg_investment']   = $payload['job_avg_investment'];
        $payload['meta']['job_investment_range'] = $payload['job_investment_range'];
        $payload['meta']['used_job_stats']       = $payload['used_job_stats'];
        $payload['meta']['job_title_id']         = $payload['job_title_id'];
        $payload['meta']['job_title_ids']        = $payload['job_title_ids'];
        $payload['meta']['group_key']            = $payload['group_key'];
        $payload['meta']['clarification_options']= $payload['clarification_options'];

        return $payload;
    }

    protected static function build_response_payload( $text, $context, $message, $from_cache = false, $source = 'openai', $extra = array() ) {
        $context_used = ! empty( $context['job_title'] );

        $payload = array(
            'text'         => (string) $text,
            'suggestions'  => self::build_followup_suggestions( $message, $context, $text ),
            'context_used' => $context_used,
            'from_cache'   => (bool) $from_cache,
            'source'       => $source,
            'job_title'    => ! empty( $context['job_title'] ) ? $context['job_title'] : '',
            'job_slug'     => isset( $context['job_slug'] ) ? $context['job_slug'] : '',
            'job_title_id' => isset( $context['primary_job_title_id'] ) ? (int) $context['primary_job_title_id'] : null,
            'group_key'    => isset( $context['group_key'] ) ? $context['group_key'] : '',
            'clarification_options' => isset( $context['candidates'] ) && is_array( $context['candidates'] ) ? array_slice( $context['candidates'], 0, 3 ) : array(),
            'resolved_confidence'   => isset( $context['resolved_confidence'] ) ? $context['resolved_confidence'] : null,
        );

        if ( ! empty( $extra ) && is_array( $extra ) ) {
            $payload = array_merge( $payload, $extra );
        }

        $resolved_category = null;
        if ( isset( $payload['category'] ) && '' !== $payload['category'] ) {
            $resolved_category = $payload['category'];
        } elseif ( isset( $extra['category'] ) && '' !== $extra['category'] ) {
            $resolved_category = $extra['category'];
        }

        $resolved_job_title = null;
        if ( ! empty( $context['job_title'] ) ) {
            $resolved_job_title = $context['job_title'];
        } elseif ( isset( $payload['job_title'] ) && '' !== $payload['job_title'] ) {
            $resolved_job_title = $payload['job_title'];
        }

        $resolved_job_slug = null;
        if ( isset( $context['job_slug'] ) && '' !== $context['job_slug'] ) {
            $resolved_job_slug = $context['job_slug'];
        } elseif ( isset( $payload['job_slug'] ) && '' !== $payload['job_slug'] ) {
            $resolved_job_slug = $payload['job_slug'];
        }

        $payload['meta'] = array(
            'context_used' => $context_used,
            'from_cache'   => (bool) $from_cache,
            'source'       => $source,
            'category'     => $resolved_category,
            'job_title'    => $resolved_job_title,
            'job_slug'     => $resolved_job_slug,
            'job_title_id' => isset( $payload['job_title_id'] ) ? $payload['job_title_id'] : null,
            'group_key'    => isset( $payload['group_key'] ) ? $payload['group_key'] : null,
            'clarification_options' => isset( $payload['clarification_options'] ) ? $payload['clarification_options'] : array(),
            'resolved_confidence'   => isset( $payload['resolved_confidence'] ) ? $payload['resolved_confidence'] : null,
        );

        return self::refresh_job_stats_payload( $payload, $context );
    }

    public static function delete_cache_for( $message, $category = '', $model = '', $job_title = '' ) {
        $key = self::build_cache_key( $message, $category, $model, $job_title );
        delete_transient( $key );

        if ( '' !== $job_title ) {
            $legacy_key = self::build_cache_key( $message, $category, $model );
            delete_transient( $legacy_key );
        }
    }

    public static function extend_cache_ttl( $message, $category = '', $model = '', $ttl = 0, $job_title = '' ) {
        if ( ! self::is_cache_enabled() ) {
            return;
        }

        $key      = self::build_cache_key( $message, $category, $model, $job_title );
        $payload  = get_transient( $key );
        if ( false === $payload && '' !== $job_title ) {
            $legacy_key = self::build_cache_key( $message, $category, $model );
            $legacy     = get_transient( $legacy_key );
            if ( false !== $legacy ) {
                $key     = $legacy_key;
                $payload = $legacy;
            }
        }
        if ( false === $payload ) {
            return;
        }

        $ttl = (int) $ttl;
        if ( $ttl <= 0 ) {
            $ttl = 3 * HOUR_IN_SECONDS;
        }

        set_transient( $key, $payload, $ttl );
    }

    public static function flush_cache_prefix( $prefix = 'bkja_cache_' ) {
        self::clear_all_caches( array( $prefix ) );
    }

    protected static function delete_transients_by_prefix( $prefix ) {
        global $wpdb;

        if ( empty( $wpdb ) || empty( $wpdb->options ) ) {
            return 0;
        }

        $like    = $wpdb->esc_like( $prefix ) . '%';
        $count   = 0;
        $queries = array(
            $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . $like ),
            $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_' . $like ),
        );

        foreach ( $queries as $sql ) {
            $rows = $wpdb->query( $sql );
            if ( false !== $rows ) {
                $count += (int) $rows;
            }
        }

        return $count;
    }

    protected static function delete_options_by_prefix( $prefix ) {
        global $wpdb;

        if ( empty( $wpdb ) || empty( $wpdb->options ) ) {
            return 0;
        }

        $like  = $wpdb->esc_like( $prefix ) . '%';
        $sql   = $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like );
        $rows  = $wpdb->query( $sql );

        return false === $rows ? 0 : (int) $rows;
    }

    public static function clear_response_cache_prefix( $extra_prefixes = array() ) {
        $base_prefixes = array(
            'bkja_cache_',
            'bkja_summary_',
            'bkja_job_summary_',
            'bkja_job_records_',
            'bkja_answer_',
            'bkja_stats_',
            'bkja_jobs_',
            'bkja_chat_',
            'bkja_',
        );

        $extra_prefixes = array_filter( array_map( 'strval', (array) $extra_prefixes ) );
        $prefixes       = array_values( array_unique( array_merge( $base_prefixes, $extra_prefixes ) ) );

        return self::clear_all_caches( $prefixes );
    }

    public static function clear_all_caches( $transient_prefixes = array( 'bkja_cache_', 'bkja_summary_', 'bkja_job_summary_', 'bkja_job_records_', 'bkja_answer_', 'bkja_stats_', 'bkja_jobs_', 'bkja_chat_', 'bkja_' ) ) {
        $transient_prefixes = (array) $transient_prefixes;

        if ( empty( $transient_prefixes ) ) {
            $transient_prefixes = array( 'bkja_cache_', 'bkja_summary_', 'bkja_answer_' );
        }

        $deleted = 0;

        foreach ( $transient_prefixes as $prefix ) {
            $deleted += self::delete_transients_by_prefix( $prefix );
        }

        // Clean up any option-based caches that may have been stored without the transient API.
        foreach ( array( 'bkja_summary_', 'bkja_answer_', 'bkja_job_summary_' ) as $option_prefix ) {
            $deleted += self::delete_options_by_prefix( $option_prefix );
        }

        $version = self::bump_cache_version();

        return array(
            'deleted' => $deleted,
            'version' => $version,
        );
    }

    public static function call_openai( $message, $args = array() ) {
        if ( empty( $message ) ) {
            return new WP_Error( 'empty_message', 'Message is empty' );
        }

        if ( class_exists( 'BKJA_Database' ) ) {
            BKJA_Database::ensure_feedback_table();
        }

        $defaults = array(
            'system'         => 'تو یک دستیار شغلی داده‌محور هستی. اعداد درآمد و سرمایه که می‌بینی فقط از گزارش کاربران این سایت استخراج شده و آمار رسمی نیست. پاسخ را در بخش‌های بولت‌دار کوتاه مثل «خلاصه آماری»، «درآمد»، «سرمایه»، «نکات مثبت/چالش‌ها»، «قدم بعدی» ارائه کن. فقط از اعداد موجود در کانتکست استفاده کن؛ اگر داده عددی نداریم یا تعداد گزارش‌ها کم است صریحاً بگو «نامشخص» یا «دقت پایین» و عدد نساز. موضوع گفتگو را تغییر نده و در پایان یک اقدام عملی کوتاه پیشنهاد بده.',
            'model'          => '',
            'session_id'     => '',
            'user_id'        => 0,
            'category'       => '',
            'job_title_hint' => '',
            'job_slug'       => '',
            'job_title_id'   => 0,
            'job_group_key'  => '',
        );
        $args              = wp_parse_args( $args, $defaults );
        $model             = self::resolve_model( $args['model'] );
        $system            = ! empty( $args['system'] ) ? $args['system'] : $defaults['system'];
        $resolved_category = is_string( $args['category'] ) ? $args['category'] : '';
        $job_title_hint    = is_string( $args['job_title_hint'] ) ? trim( $args['job_title_hint'] ) : '';
        $job_slug          = is_string( $args['job_slug'] ) ? trim( $args['job_slug'] ) : '';
        $job_title_id      = isset( $args['job_title_id'] ) ? (int) $args['job_title_id'] : 0;
        $job_group_key     = is_string( $args['job_group_key'] ) ? trim( $args['job_group_key'] ) : '';

        $normalized_message = self::normalize_message( $message );
        $context            = self::get_job_context( $normalized_message, $job_title_hint, $job_slug, $job_title_id, $job_group_key );

        $api_key = self::get_api_key();

        $cache_enabled   = self::is_cache_enabled();
        $cache_job_title = '';
        if ( ! empty( $context['job_title'] ) ) {
            $cache_job_title = $context['job_title'];
        } elseif ( '' !== $job_title_hint ) {
            $cache_job_title = $job_title_hint;
        }

        $cache_key           = self::build_cache_key( $normalized_message, $resolved_category, $model, $cache_job_title );
        $legacy_cache_key    = '';
        if ( $cache_enabled && '' !== $cache_job_title ) {
            $legacy_cache_key = self::build_cache_key( $normalized_message, $resolved_category, $model );
        }
        if ( $cache_enabled ) {
            $cached = get_transient( $cache_key );
            if ( false === $cached && '' !== $legacy_cache_key ) {
                $cached = get_transient( $legacy_cache_key );
            }
            if ( false !== $cached && self::should_accept_cached_payload( $normalized_message, $cached ) ) {
                if ( is_array( $cached ) ) {
                    $cached['from_cache']        = true;
                    $cached['model']             = isset( $cached['model'] ) ? $cached['model'] : $model;
                    $cached['category']          = $resolved_category;
                    $cached_job_title = '';
                    if ( ! empty( $context['job_title'] ) ) {
                        $cached_job_title = $context['job_title'];
                    } elseif ( ! empty( $cached['job_title'] ) ) {
                        $cached_job_title = $cached['job_title'];
                    }
                    if ( '' !== $cached_job_title ) {
                        $cached['job_title'] = $cached_job_title;
                    } else {
                        $cached['job_title'] = '';
                    }
                    $cached['normalized_message'] = $normalized_message;
                    if ( ! isset( $cached['meta'] ) || ! is_array( $cached['meta'] ) ) {
                        $cached['meta'] = array();
                    }
                    $cached['meta']['category'] = $resolved_category;
                    $cached['meta']['job_title'] = $cached_job_title;
                    $job_slug_value = '';
                    if ( ! empty( $context['job_slug'] ) ) {
                        $job_slug_value = $context['job_slug'];
                    } elseif ( '' !== $job_slug ) {
                        $job_slug_value = $job_slug;
                    }

                    if ( '' !== $job_slug_value ) {
                        $cached['job_slug']            = $job_slug_value;
                        $cached['meta']['job_slug']    = $job_slug_value;
                    } else {
                        $cached['job_slug']         = '';
                        $cached['meta']['job_slug'] = '';
                    }
                    if ( ! empty( $context ) ) {
                        $cached['meta']['category']   = $context['category'] ?? ( $cached['meta']['category'] ?? null );
                        $cached['meta']['job_title']  = $context['job_title'] ?? ( $cached['meta']['job_title'] ?? null );
                        $cached['meta']['job_slug']   = $context['job_slug'] ?? ( $cached['meta']['job_slug'] ?? null );
                    }
                    $cached = self::refresh_job_stats_payload( $cached, $context );
                    return $cached;
                }

                return self::build_response_payload(
                    $cached,
                    $context,
                    $message,
                    true,
                    'cache',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                        'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                        'job_title_id'       => isset( $context['primary_job_title_id'] ) ? $context['primary_job_title_id'] : $job_title_id,
                        'group_key'          => isset( $context['group_key'] ) ? $context['group_key'] : $job_group_key,
                        'normalized_message' => $normalized_message,
                    )
                );
            }
        }

        if ( empty( $api_key ) ) {
            $db_payload = self::try_answer_from_db( $message, $context, $model, $resolved_category, $normalized_message, $job_title_hint, $job_slug );
            if ( $db_payload ) {
                $db_payload['model']              = $model;
                $db_payload['category']           = $resolved_category;
                $db_payload['normalized_message'] = $normalized_message;

                if ( $cache_enabled ) {
                    set_transient( $cache_key, $db_payload, self::get_cache_ttl( $model ) );
                }

                return $db_payload;
            }

            if ( ! empty( $context ) ) {
                $fallback = self::build_response_payload(
                    self::format_job_context_reply( $context ),
                    $context,
                    $message,
                    false,
                    'job_context',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                        'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                        'job_title_id'       => isset( $context['primary_job_title_id'] ) ? $context['primary_job_title_id'] : $job_title_id,
                        'group_key'          => isset( $context['group_key'] ) ? $context['group_key'] : $job_group_key,
                        'normalized_message' => $normalized_message,
                    )
                );
                if ( $cache_enabled ) {
                    set_transient( $cache_key, $fallback, self::get_cache_ttl( $model ) );
                }
                return $fallback;
            }

            return new WP_Error( 'no_api_key', 'API key not configured' );
        }

        $messages = array(
            array(
                'role'    => 'system',
                'content' => $system,
            ),
        );

        if ( ! empty( $context ) ) {
            $context_prompt = self::build_context_prompt( $context );
            if ( $context_prompt ) {
                $messages[] = array(
                    'role'    => 'system',
                    'content' => $context_prompt,
                );
            }
        }

        $feedback_hint = self::get_feedback_hint( $normalized_message, $args['session_id'], (int) $args['user_id'] );
        if ( $feedback_hint ) {
            $messages[] = array(
                'role'    => 'system',
                'content' => $feedback_hint,
            );
        }

        if ( class_exists( 'BKJA_Database' ) ) {
            $history = BKJA_Database::get_recent_conversation( $args['session_id'], (int) $args['user_id'], 6 );
            $history = self::clamp_history( $history, 4 );
            foreach ( $history as $item ) {
                if ( empty( $item['content'] ) ) {
                    continue;
                }
                $messages[] = array(
                    'role'    => $item['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $item['content'],
                );
            }
        }

        $messages[] = array(
            'role'    => 'user',
            'content' => $message,
        );

        $payload = array(
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2,
            'max_tokens'  => 500,
        );

        $request_args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60,
        );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', $request_args );
        if ( is_wp_error( $response ) ) {
            if ( ! empty( $context ) ) {
                $fallback = self::build_response_payload(
                    self::format_job_context_reply( $context ),
                    $context,
                    $message,
                    false,
                    'job_context',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                        'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                        'job_title_id'       => isset( $context['primary_job_title_id'] ) ? $context['primary_job_title_id'] : $job_title_id,
                        'group_key'          => isset( $context['group_key'] ) ? $context['group_key'] : $job_group_key,
                        'normalized_message' => $normalized_message,
                    )
                );
                if ( $cache_enabled ) {
                    set_transient( $cache_key, $fallback, self::get_cache_ttl( $model ) );
                }
                return $fallback;
            }

            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code < 200 || $code >= 300 || empty( $data['choices'][0]['message']['content'] ) ) {
            if ( ! empty( $context ) ) {
                $fallback = self::build_response_payload(
                    self::format_job_context_reply( $context ),
                    $context,
                    $message,
                    false,
                    'job_context',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                        'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                        'job_title_id'       => isset( $context['primary_job_title_id'] ) ? $context['primary_job_title_id'] : $job_title_id,
                        'group_key'          => isset( $context['group_key'] ) ? $context['group_key'] : $job_group_key,
                        'normalized_message' => $normalized_message,
                    )
                );
                if ( $cache_enabled ) {
                    set_transient( $cache_key, $fallback, self::get_cache_ttl( $model ) );
                }
                return $fallback;
            }

            return new WP_Error( 'api_error', 'OpenAI error: ' . substr( $body, 0, 250 ) );
        }

        $answer = trim( $data['choices'][0]['message']['content'] );
        $source = 'openai';

        if ( '' === $answer && ! empty( $context ) ) {
            $answer = self::format_job_context_reply( $context );
            $source = 'job_context';
        } elseif ( '' === $answer ) {
            return new WP_Error( 'empty_response', 'Empty response from model' );
        }

        $result = self::build_response_payload(
            $answer,
            $context,
            $message,
            false,
            $source,
            array(
                'model'              => $model,
                'category'           => $resolved_category,
                'job_title'          => ! empty( $context['job_title'] ) ? $context['job_title'] : $cache_job_title,
                'job_slug'           => ! empty( $context['job_slug'] ) ? $context['job_slug'] : $job_slug,
                'job_title_id'       => isset( $context['primary_job_title_id'] ) ? $context['primary_job_title_id'] : $job_title_id,
                'group_key'          => isset( $context['group_key'] ) ? $context['group_key'] : $job_group_key,
                'normalized_message' => $normalized_message,
            )
        );

        if ( $cache_enabled ) {
            $result_job_title = '';
            if ( isset( $result['meta'] ) && is_array( $result['meta'] ) && ! empty( $result['meta']['job_title'] ) ) {
                $result_job_title = $result['meta']['job_title'];
            } elseif ( ! empty( $result['job_title'] ) ) {
                $result_job_title = $result['job_title'];
            }

            if ( '' !== $result_job_title && $result_job_title !== $cache_job_title ) {
                $legacy_key_to_clear = self::build_cache_key( $normalized_message, $resolved_category, $model, $cache_job_title );
                $cache_key           = self::build_cache_key( $normalized_message, $resolved_category, $model, $result_job_title );

                if ( $legacy_key_to_clear !== $cache_key ) {
                    delete_transient( $legacy_key_to_clear );
                }
            }

            set_transient( $cache_key, $result, self::get_cache_ttl( $model ) );
        }

        return $result;
    }

}

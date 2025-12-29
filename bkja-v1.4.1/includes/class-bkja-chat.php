<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/BKJA_State.php';
require_once __DIR__ . '/BKJA_RuleEngine.php';

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

        if ( function_exists( 'bkja_normalize_query_text' ) ) {
            $text = bkja_normalize_query_text( $text );
        } elseif ( function_exists( 'bkja_normalize_fa_text' ) ) {
            $text = bkja_normalize_fa_text( $text );
        }

        return trim( (string) $text );
    }

    protected static function normalize_fa_text_basic( $text ) {
        $text = self::normalize_lookup_text( $text );
        $map  = array(
            'ي' => 'ی',
            'ك' => 'ک',
            "‌" => ' ',
        );

        return strtr( $text, $map );
    }

    protected static function tokenize_meaningful_terms( $text ) {
        $normalized = self::normalize_fa_text_basic( $text );
        $tokens     = preg_split( '/[\s،,.!?؟;:؛\\\-]+/u', $normalized );
        $stopwords  = array( 'کار', 'شغل', 'درآمد', 'چقدر', 'مسیر', 'رشد', 'مقایسه', 'در', 'به', 'از', 'برای', 'همین' );

        $clean = array();
        foreach ( (array) $tokens as $token ) {
            $token = trim( (string) $token );
            if ( '' === $token ) {
                continue;
            }

            $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $token, 'UTF-8' ) : strtolower( $token );
            if ( in_array( $lower, $stopwords, true ) ) {
                continue;
            }

            $clean[] = $lower;
        }

        return array_values( array_unique( $clean ) );
    }

    protected static function get_job_alias_map() {
        return array(
            'کیک خانگی' => array(
                'titles' => array( 'شیرینی‌پزی خانگی' ),
                'tags'   => array( 'home', 'food' ),
            ),
            'کیک خونگی' => array(
                'titles' => array( 'شیرینی‌پزی خانگی' ),
                'tags'   => array( 'home', 'food' ),
            ),
            'قنادی' => array(
                'titles' => array( 'شیرینی‌پزی', 'کیک', 'دسر' ),
                'tags'   => array( 'food' ),
            ),
            'آشپزی خانگی' => array(
                'titles' => array( 'آشپزی خانگی', 'تهیه غذا', 'کیترینگ کوچک' ),
                'tags'   => array( 'home', 'food' ),
            ),
            'آشپزی خونگی' => array(
                'titles' => array( 'آشپزی خانگی', 'تهیه غذا', 'کیترینگ کوچک' ),
                'tags'   => array( 'home', 'food' ),
            ),
            'شغل خانگی خانم' => array(
                'titles' => array(),
                'tags'   => array( 'home', 'low_capital' ),
            ),
            'شغل خانگی برای خانم' => array(
                'titles' => array(),
                'tags'   => array( 'home', 'low_capital' ),
            ),
            'کار در خانه' => array(
                'titles' => array(),
                'tags'   => array( 'home', 'low_capital' ),
            ),
            'کار خانگی' => array(
                'titles' => array(),
                'tags'   => array( 'home', 'low_capital' ),
            ),
        );
    }

    protected static function resolve_alias_data( $normalized_message ) {
        $normalized_message = self::normalize_lookup_text( $normalized_message );
        if ( '' === $normalized_message ) {
            return array();
        }

        $aliases = self::get_job_alias_map();
        $matched = array();

        foreach ( $aliases as $alias => $data ) {
            $alias_norm = self::normalize_lookup_text( $alias );
            if ( '' === $alias_norm ) {
                continue;
            }

            $contains = function_exists( 'mb_strpos' )
                ? mb_strpos( $normalized_message, $alias_norm, 0, 'UTF-8' )
                : strpos( $normalized_message, $alias_norm );

            if ( false === $contains ) {
                continue;
            }

            $matched = array(
                'alias'  => $alias,
                'titles' => isset( $data['titles'] ) ? (array) $data['titles'] : array(),
                'tags'   => isset( $data['tags'] ) ? (array) $data['tags'] : array(),
            );
            break;
        }

        return $matched;
    }

    protected static function filter_closest_candidates( $query, $candidates, $threshold = 0.55, $max = 3 ) {
        $query_tokens = self::tokenize_meaningful_terms( $query );
        if ( empty( $query_tokens ) ) {
            return array();
        }

        $normalized_query = self::normalize_fa_text_basic( $query );
        $filtered         = array();

        foreach ( (array) $candidates as $candidate ) {
            $label = '';
            if ( is_array( $candidate ) ) {
                $label = isset( $candidate['label'] ) ? $candidate['label'] : '';
            } elseif ( is_object( $candidate ) ) {
                $label = isset( $candidate->label ) ? $candidate->label : '';
            }

            if ( '' === trim( (string) $label ) ) {
                continue;
            }

            $label_tokens = self::tokenize_meaningful_terms( $label );
            if ( empty( array_intersect( $query_tokens, $label_tokens ) ) ) {
                continue;
            }

            $normalized_label = self::normalize_fa_text_basic( $label );
            $percent          = 0.0;
            similar_text( $normalized_query, $normalized_label, $percent );
            $similarity = (float) $percent / 100;

            if ( $similarity < (float) $threshold ) {
                continue;
            }

            if ( is_array( $candidate ) ) {
                $candidate['similarity'] = $similarity;
            } else {
                $candidate->similarity = $similarity;
            }

            $filtered[] = $candidate;
        }

        usort( $filtered, function( $a, $b ) {
            $get_similarity = function( $item ) {
                if ( is_array( $item ) ) {
                    return isset( $item['similarity'] ) ? (float) $item['similarity'] : 0.0;
                }

                return isset( $item->similarity ) ? (float) $item->similarity : 0.0;
            };

            $sim_a = $get_similarity( $a );
            $sim_b = $get_similarity( $b );

            if ( $sim_a === $sim_b ) {
                $cnt_a = is_array( $a ) ? ( $a['jobs_count_recent'] ?? 0 ) : ( isset( $a->jobs_count_recent ) ? $a->jobs_count_recent : 0 );
                $cnt_b = is_array( $b ) ? ( $b['jobs_count_recent'] ?? 0 ) : ( isset( $b->jobs_count_recent ) ? $b->jobs_count_recent : 0 );

                if ( $cnt_a === $cnt_b ) {
                    return 0;
                }

                return ( $cnt_a < $cnt_b ) ? 1 : -1;
            }

            return ( $sim_a < $sim_b ) ? 1 : -1;
        } );

        return array_slice( $filtered, 0, max( 1, (int) $max ) );
    }

    protected static function get_safe_job_suggestions( $limit = 4 ) {
        global $wpdb;

        $limit         = max( 1, (int) $limit );
        $table_titles  = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs    = $wpdb->prefix . 'bkja_jobs';
        $sql           = $wpdb->prepare(
            "SELECT jt.id, jt.group_key, COALESCE(jt.base_label, jt.label) AS label, COALESCE(jt.base_slug, jt.slug) AS slug, COUNT(j.id) AS cnt
             FROM {$table_titles} jt
             LEFT JOIN {$table_jobs} j ON j.job_title_id = jt.id
             WHERE jt.is_visible = 1
             GROUP BY jt.id
             ORDER BY cnt DESC
             LIMIT %d",
            $limit
        );

        $rows = $wpdb->get_results( $sql );
        if ( empty( $rows ) ) {
            return array();
        }

        $options = array();
        foreach ( (array) $rows as $row ) {
            if ( empty( $row->label ) ) {
                continue;
            }

            $options[] = array(
                'label'        => (string) $row->label,
                'job_title_id' => isset( $row->id ) ? (int) $row->id : null,
                'group_key'    => isset( $row->group_key ) ? $row->group_key : '',
                'slug'         => isset( $row->slug ) ? $row->slug : '',
            );
        }

        return $options;
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
        $normalized_column = "TRIM(REPLACE(REPLACE(REPLACE({$title_column}, 'ي', 'ی'), 'ك', 'ک'), '‌', ' '))";

        foreach ( $phrases as $phrase ) {
            $normalized_phrase = function_exists( 'bkja_normalize_fa_text' ) ? bkja_normalize_fa_text( $phrase ) : $phrase;
            $variants = function_exists( 'bkja_generate_title_variants' )
                ? bkja_generate_title_variants( $normalized_phrase )
                : array( $normalized_phrase );

            foreach ( $variants as $variant ) {
                $row = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT {$title_column} AS job_title FROM {$table} WHERE {$normalized_column} = %s ORDER BY CHAR_LENGTH({$title_column}) ASC LIMIT 1",
                        $variant
                    )
                );

                if ( $row && ! empty( $row->job_title ) ) {
                    $job_title = $row->job_title;
                    break 2;
                }

                $like = '%' . $wpdb->esc_like( $variant ) . '%';
                $row  = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT {$title_column} AS job_title FROM {$table} WHERE {$normalized_column} LIKE %s ORDER BY CHAR_LENGTH({$title_column}) ASC LIMIT 1",
                        $like
                    )
                );

                if ( $row && ! empty( $row->job_title ) ) {
                    $job_title = $row->job_title;
                    break 2;
                }
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

    public static function build_cache_key( $message, $category = '', $model = '', $job_title = '', $query_intent = '' ) {
        $normalized = self::normalize_message( $message );
        $category   = is_string( $category ) ? trim( $category ) : '';
        $model      = self::resolve_model( $model );
        $job_title  = is_string( $job_title ) ? trim( $job_title ) : '';
        $query_intent = is_string( $query_intent ) ? trim( $query_intent ) : '';
        $version    = self::get_cache_version();

        $parts = array(
            'msg:' . $normalized,
            'cat:' . $category,
            'm:' . $model,
        );

        if ( '' !== $job_title ) {
            $parts[] = 'job:' . self::normalize_message( $job_title );
        }

        if ( '' !== $query_intent ) {
            $parts[] = 'intent:' . $query_intent;
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
        $normalized_label_column = "REPLACE(REPLACE(REPLACE(COALESCE(jt.base_label, jt.label), 'ي', 'ی'), 'ك', 'ک'), '‌', ' ')";

        $candidate_tokens = array( $core );

        if ( function_exists( 'mb_substr' ) ? 'ی' === mb_substr( $core, -1, 1, 'UTF-8' ) : ( 'ی' === substr( $core, -2 ) ) ) {
            $alt_core = function_exists( 'mb_substr' ) ? mb_substr( $core, 0, mb_strlen( $core, 'UTF-8' ) - 1, 'UTF-8' ) : substr( $core, 0, -2 );

            if ( '' !== $alt_core ) {
                $candidate_tokens[] = $alt_core;
            }
        }

        $candidate_tokens[] = $normalized_full;

        if ( function_exists( 'bkja_generate_title_variants' ) ) {
            $variant_tokens = array();
            foreach ( $candidate_tokens as $token ) {
                $variant_tokens = array_merge( $variant_tokens, bkja_generate_title_variants( $token ) );
            }
            $candidate_tokens = array_merge( $candidate_tokens, $variant_tokens );
        }

        $candidate_tokens   = array_values( array_unique( array_filter( $candidate_tokens ) ) );

        $stages = array(
            'exact'    => function( $term ) use ( $normalized_label_column ) {
                return array(
                        'where'  => '(jt.base_label = %s OR jt.label = %s OR jt.slug = %s OR jt.base_slug = %s OR ' . $normalized_label_column . ' = %s)',
                        'params' => array( $term, $term, $term, $term, $term ),
                );
            },
            'prefix'   => function( $term ) use ( $normalized_label_column ) {
                $term_prefix = $term . '%';
                return array(
                    'where'  => '(jt.label LIKE %s OR jt.base_label LIKE %s OR ' . $normalized_label_column . ' LIKE %s)',
                    'params' => array( $term_prefix, $term_prefix, $term_prefix ),
                );
            },
            'contains' => function( $term ) use ( $normalized_label_column ) {
                $term_like = '%' . $term . '%';
                return array(
                    'where'  => '(jt.label LIKE %s OR jt.base_label LIKE %s OR ' . $normalized_label_column . ' LIKE %s)',
                    'params' => array( $term_like, $term_like, $term_like ),
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
        $needs_clarification   = false;
        $resolution_source     = '';

        $explicit_terms       = array();
        $explicit_resolution  = null;
        $explicit_confidence  = null;
        $explicit_candidates  = array();
        $explicit_ambiguous   = false;
        $alias_data           = array();
        $alias_titles         = array();

        if ( '' !== $normalized ) {
            $explicit_terms = self::build_job_lookup_phrases( $normalized );
            $explicit_resolution = self::resolve_job_context_from_query( $normalized );
            if ( $explicit_resolution ) {
                $explicit_confidence = isset( $explicit_resolution['confidence'] ) ? (float) $explicit_resolution['confidence'] : null;
                $explicit_candidates = isset( $explicit_resolution['candidates'] ) ? (array) $explicit_resolution['candidates'] : array();
                $explicit_ambiguous  = ! empty( $explicit_resolution['ambiguous'] ) || ( null !== $explicit_confidence && $explicit_confidence < 0.55 );
            }

            $alias_data = self::resolve_alias_data( $normalized );
            if ( ! empty( $alias_data['titles'] ) ) {
                $alias_titles = array_values( array_filter( (array) $alias_data['titles'] ) );
            }
        }

        $explicit_signal = ( ! empty( $explicit_terms ) || ( $explicit_resolution && ( ! empty( $explicit_resolution['job_title_ids'] ) || ! empty( $explicit_candidates ) ) ) );

        if ( $explicit_signal ) {
            if ( $explicit_resolution && ! $explicit_ambiguous && ! empty( $explicit_resolution['job_title_ids'] ) ) {
                $job_title             = $explicit_resolution['label'];
                $job_slug              = isset( $explicit_resolution['slug'] ) ? $explicit_resolution['slug'] : $job_slug;
                $resolved_ids          = isset( $explicit_resolution['job_title_ids'] ) ? (array) $explicit_resolution['job_title_ids'] : array();
                $primary_job_title_id  = ! empty( $explicit_resolution['primary_job_title_id'] )
                    ? (int) $explicit_resolution['primary_job_title_id']
                    : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : $primary_job_title_id );
                $resolved_confidence   = $explicit_confidence;
                $clarification_options = $explicit_candidates;
                $ambiguous_match       = ! empty( $explicit_resolution['ambiguous'] );
                $resolved_for_db       = ! empty( $explicit_resolution['group_key'] )
                    ? array( 'group_key' => $explicit_resolution['group_key'], 'job_title_ids' => $resolved_ids, 'base_label' => $explicit_resolution['label'] )
                    : ( ! empty( $explicit_resolution['primary_job_title_id'] ) ? $explicit_resolution['primary_job_title_id'] : $explicit_resolution['label'] );
                $resolution_source     = 'explicit_in_text';
            } else {
                if ( ! empty( $alias_titles ) ) {
                    foreach ( $alias_titles as $alias_title ) {
                        $alias_resolution = self::resolve_job_context_from_query( $alias_title );
                        if ( $alias_resolution && ! empty( $alias_resolution['job_title_ids'] ) ) {
                            $job_title             = $alias_resolution['label'];
                            $job_slug              = isset( $alias_resolution['slug'] ) ? $alias_resolution['slug'] : $job_slug;
                            $resolved_ids          = isset( $alias_resolution['job_title_ids'] ) ? (array) $alias_resolution['job_title_ids'] : array();
                            $primary_job_title_id  = ! empty( $alias_resolution['primary_job_title_id'] )
                                ? (int) $alias_resolution['primary_job_title_id']
                                : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : $primary_job_title_id );
                            $resolved_confidence   = isset( $alias_resolution['confidence'] ) ? $alias_resolution['confidence'] : $resolved_confidence;
                            $clarification_options = isset( $alias_resolution['candidates'] ) ? (array) $alias_resolution['candidates'] : $clarification_options;
                            $ambiguous_match       = ! empty( $alias_resolution['ambiguous'] );
                            $resolved_for_db       = ! empty( $alias_resolution['group_key'] )
                                ? array( 'group_key' => $alias_resolution['group_key'], 'job_title_ids' => $resolved_ids, 'base_label' => $alias_resolution['label'] )
                                : ( ! empty( $alias_resolution['primary_job_title_id'] ) ? $alias_resolution['primary_job_title_id'] : $alias_resolution['label'] );
                            $resolution_source     = 'alias_map';
                            break;
                        }
                    }
                }

                if ( '' === $resolution_source ) {
                    $needs_clarification = true;
                    return array(
                        'job_title'           => '',
                        'summary'             => null,
                        'records'             => array(),
                        'job_slug'            => null,
                        'job_title_ids'       => array(),
                        'group_key'           => null,
                        'primary_job_title_id'=> null,
                        'resolved_confidence' => $explicit_confidence,
                        'candidates'          => $explicit_candidates,
                        'ambiguous'           => true,
                        'needs_clarification' => true,
                        'resolution_source'   => 'explicit_in_text',
                        'stats_executed'      => false,
                    );
                }
            }
        }

        if ( '' === $resolution_source && ( $job_title_id > 0 || $group_key ) ) {
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
                $resolved_for_db       = ! empty( $resolved['group_key'] ) ? array( 'group_key' => $resolved['group_key'], 'job_title_ids' => $resolved_ids, 'base_label' => $resolved['label'] ) : ( ! empty( $resolved['primary_job_title_id'] ) ? $resolved['primary_job_title_id'] : $resolved['label'] );
                $resolution_source     = 'context_followup';
            }
        }

        if ( '' === $resolution_source && '' !== $normalized ) {
            $resolved = self::resolve_job_context_from_query( $normalized );
            if ( $resolved ) {
                $job_title             = $resolved['label'];
                $job_slug              = isset( $resolved['slug'] ) ? $resolved['slug'] : $job_slug;
                $resolved_ids          = isset( $resolved['job_title_ids'] ) ? (array) $resolved['job_title_ids'] : array();
                $primary_job_title_id  = ! empty( $resolved['primary_job_title_id'] ) ? (int) $resolved['primary_job_title_id'] : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : $primary_job_title_id );
                $resolved_confidence   = isset( $resolved['confidence'] ) ? $resolved['confidence'] : $resolved_confidence;
                $clarification_options = isset( $resolved['candidates'] ) ? (array) $resolved['candidates'] : $clarification_options;
                $ambiguous_match       = ! empty( $resolved['ambiguous'] );
                $resolved_for_db       = ! empty( $resolved['group_key'] ) ? array( 'group_key' => $resolved['group_key'], 'job_title_ids' => $resolved_ids, 'base_label' => $resolved['label'] ) : ( ! empty( $resolved['primary_job_title_id'] ) ? $resolved['primary_job_title_id'] : $resolved['label'] );
                $resolution_source     = 'explicit_in_text';
            }
        }

        if ( '' === $resolution_source && ! empty( $alias_titles ) ) {
            foreach ( $alias_titles as $alias_title ) {
                $resolved_alias = self::resolve_job_context_from_query( $alias_title );
                if ( $resolved_alias ) {
                    $job_title             = $resolved_alias['label'];
                    $job_slug              = isset( $resolved_alias['slug'] ) ? $resolved_alias['slug'] : $job_slug;
                    $resolved_ids          = isset( $resolved_alias['job_title_ids'] ) ? (array) $resolved_alias['job_title_ids'] : array();
                    $primary_job_title_id  = ! empty( $resolved_alias['primary_job_title_id'] ) ? (int) $resolved_alias['primary_job_title_id'] : ( ! empty( $resolved_ids ) ? (int) $resolved_ids[0] : $primary_job_title_id );
                    $resolved_confidence   = isset( $resolved_alias['confidence'] ) ? $resolved_alias['confidence'] : $resolved_confidence;
                    $clarification_options = isset( $resolved_alias['candidates'] ) ? (array) $resolved_alias['candidates'] : $clarification_options;
                    $ambiguous_match       = ! empty( $resolved_alias['ambiguous'] );
                    $resolved_for_db       = ! empty( $resolved_alias['group_key'] ) ? array( 'group_key' => $resolved_alias['group_key'], 'job_title_ids' => $resolved_ids, 'base_label' => $resolved_alias['label'] ) : ( ! empty( $resolved_alias['primary_job_title_id'] ) ? $resolved_alias['primary_job_title_id'] : $resolved_alias['label'] );
                    $resolution_source     = 'alias_map';
                    break;
                }
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
                $resolved_for_db       = ! empty( $resolved_hint['group_key'] ) ? array( 'group_key' => $resolved_hint['group_key'], 'job_title_ids' => $resolved_ids, 'base_label' => $resolved_hint['label'] ) : ( ! empty( $resolved_hint['primary_job_title_id'] ) ? $resolved_hint['primary_job_title_id'] : $resolved_hint['label'] );
                $resolution_source     = 'manual_pick';
            }
        }

        if ( '' === $job_title && '' !== $normalized ) {
            $job_title = self::resolve_job_title_from_message( $normalized, $table, $title_column );
            if ( $job_title ) {
                $resolution_source = 'explicit_in_text';
            }
        }

        if ( '' === $job_title && '' !== $job_title_hint ) {
            $job_title = $job_title_hint;
            if ( '' === $resolution_source ) {
                $resolution_source = 'manual_pick';
            }
        }

        if ( '' === $job_title ) {
            return array();
        }

        $target_title = $resolved_for_db ? $resolved_for_db : $job_title;

        if ( class_exists( 'BKJA_Database' ) && method_exists( 'BKJA_Database', 'resolve_job_title_group' ) && ! empty( $target_title ) ) {
            $group_context = BKJA_Database::resolve_job_title_group( $target_title );
            if ( ! empty( $group_context['job_title_ids'] ) ) {
                $target_title = $group_context;
                if ( ! $primary_job_title_id && ! empty( $group_context['job_title_ids'][0] ) ) {
                    $primary_job_title_id = (int) $group_context['job_title_ids'][0];
                }
            }
        }

        if ( $primary_job_title_id > 0 && class_exists( 'BKJA_Database' ) && method_exists( 'BKJA_Database', 'get_job_summary_by_job_title_id' ) ) {
            $summary = BKJA_Database::get_job_summary_by_job_title_id( $primary_job_title_id );
            $records_data = BKJA_Database::get_job_records_by_job_title_id( $primary_job_title_id, 5, 0 );
        } else {
            $summary = class_exists('BKJA_Database') ? BKJA_Database::get_job_summary($target_title) : null;
            $records_data = class_exists('BKJA_Database') ? BKJA_Database::get_job_records($target_title, 5, 0) : [];
        }
        $records = is_array( $records_data ) && isset( $records_data['records'] ) ? $records_data['records'] : $records_data;
        $records_has_more  = is_array( $records_data ) && isset( $records_data['has_more'] ) ? (bool) $records_data['has_more'] : false;
        $records_next      = is_array( $records_data ) && array_key_exists( 'next_offset', $records_data ) ? $records_data['next_offset'] : null;
        $records_total     = is_array( $records_data ) && isset( $records_data['total_count'] ) ? (int) $records_data['total_count'] : null;
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
            'resolution_source' => $resolution_source,
            'needs_clarification' => $needs_clarification,
            'records_has_more' => $records_has_more,
            'records_next_offset' => $records_next,
            'records_total' => $records_total,
        ];
    }

    protected static function format_amount_label( $value ) {
        return bkja_format_toman_as_million_label( $value );
    }

    protected static function format_range_label( $min, $max, $unit_label = 'میلیون تومان' ) {
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

        $unit_label = trim( (string) $unit_label );
        $suffix     = $unit_label ? ' ' . $unit_label : '';

        return trim( $min_label ) . ' تا ' . trim( $max_label ) . $suffix;
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

    protected static function format_record_block( $record, $index = null ) {
        if ( ! is_array( $record ) ) {
            return '';
        }

        $parts = array();

        if ( ! empty( $record['variant_title'] ) ) {
            $parts[] = '🔖 ' . trim( (string) $record['variant_title'] );
        }

        if ( ! empty( $record['income_num'] ) ) {
            $income_text = self::format_amount_label( $record['income_num'] );
            if ( ! empty( $record['income_note'] ) ) {
                $income_text .= ' (' . $record['income_note'] . ')';
            }
            $parts[] = '💵 درآمد: ' . $income_text;
        } elseif ( ! empty( $record['income'] ) ) {
            $parts[] = '💵 درآمد: ' . trim( (string) $record['income'] );
        }

        if ( ! empty( $record['investment_num'] ) ) {
            $parts[] = '💰 سرمایه: ' . self::format_amount_label( $record['investment_num'] );
        } elseif ( ! empty( $record['investment'] ) ) {
            $parts[] = '💰 سرمایه: ' . trim( (string) $record['investment'] );
        }

        if ( ! empty( $record['city'] ) ) {
            $parts[] = '📍 شهر: ' . trim( (string) $record['city'] );
        }

        if ( ! empty( $record['details'] ) ) {
            $parts[] = '📝 تجربه: ' . self::trim_snippet( $record['details'], 120 );
        }

        if ( empty( $parts ) ) {
            return '';
        }

        $prefix = ( null !== $index ) ? '• تجربه ' . (int) $index . ': ' : '• ';

        return $prefix . implode( ' | ', $parts );
    }

    protected static function detect_job_category( $title ) {
        $title = is_string( $title ) ? $title : '';
        if ( '' === $title ) {
            return 'general';
        }

        $categories = array(
            'technical' => array( 'مکانیک', 'برق', 'تعمیر', 'تاسیسات', 'لوله', 'جوش', 'نجار', 'کابینت', 'تراشکار', 'نصاب' ),
            'office'    => array( 'اداری', 'کارمند', 'منشی', 'حسابدار', 'کارشناس', 'مدیر', 'بانک', 'دفتری', 'کارگزینی' ),
            'health'    => array( 'پزشک', 'پزشکی', 'پرستار', 'دارو', 'درمان', 'بهداشت', 'کلینیک', 'دندان', 'آزمایشگاه' ),
        );

        foreach ( $categories as $key => $keywords ) {
            foreach ( $keywords as $keyword ) {
                if ( false !== mb_stripos( $title, $keyword, 0, 'UTF-8' ) ) {
                    return $key;
                }
            }
        }

        return 'general';
    }

    protected static function is_high_income_query( $normalized_message ) {
        $text = is_string( $normalized_message ) ? $normalized_message : '';
        if ( '' === $text ) {
            return false;
        }

        $patterns = array(
            '/پردرآمدترین/u',
            '/پر\s*درآمد/u',
            '/بیشترین\s+درآمد/u',
            '/highest\s+pay/i',
            '/top\s+pay/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function is_compare_similar_intent( $normalized_message ) {
        $text = is_string( $normalized_message ) ? trim( $normalized_message ) : '';
        if ( '' === $text ) {
            return false;
        }

        $patterns = array(
            '/مقایسه\s*(?:با)?\s*شغل(?:‌|\s*)?(?:ها)?ی?\s*مشابه/u',
            '/شغل(?:‌|\s*)?(?:ها)?ی?\s*مشابه/u',
            '/مشاغل\s*مشابه/u',
            '/compare\s+similar/i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $text ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function build_compare_fallback_message() {
        return "برای این شغل داده مقایسه‌ای کافی نداریم. می‌توانم:\n• مسیر رشد درآمد را توضیح بدهم\n• شغل‌های هم‌خانواده با داده بیشتر پیشنهاد بدهم";
    }

    protected static function handle_compare_similar_jobs( $context, $message, $category, $model ) {
        $has_base_job = ! empty( $context['primary_job_title_id'] ) || ! empty( $context['group_key'] ) || ! empty( $context['job_title'] );

        if ( ! $has_base_job ) {
            return self::ensure_context_meta( self::build_response_payload(
                self::build_compare_fallback_message(),
                array(),
                $message,
                false,
                'compare_similar_jobs',
                array(
                    'model'                  => self::resolve_model( $model ),
                    'category'               => is_string( $category ) ? $category : '',
                    'clarification_options'  => array(),
                    'suggestions'            => array( 'مسیر رشد درآمد در همین شغل', 'شغل‌های هم‌خانواده با داده بیشتر' ),
                    'used_job_stats'         => false,
                    'job_report_count'       => null,
                )
            ), $context );
        }

        if ( ! class_exists( 'BKJA_Database' ) || ! method_exists( 'BKJA_Database', 'get_similar_jobs_in_group' ) ) {
            return null;
        }

        $similar_jobs = BKJA_Database::get_similar_jobs_in_group( $context, 5 );

        if ( empty( $similar_jobs ) ) {
            return self::ensure_context_meta( self::build_response_payload(
                self::build_compare_fallback_message(),
                $context,
                $message,
                false,
                'compare_similar_jobs',
                array(
                    'model'                 => self::resolve_model( $model ),
                    'category'              => is_string( $category ) ? $category : '',
                    'clarification_options' => array(),
                    'suggestions'           => array( 'مسیر رشد درآمد در همین شغل', 'شغل‌های هم‌خانواده با داده بیشتر' ),
                )
            ), $context );
        }

        $base_label = isset( $context['job_title'] ) && $context['job_title'] ? $context['job_title'] : 'این شغل';
        $lines      = array();
        $lines[]    = "شغل‌های هم‌خانواده با «{$base_label}» که داده ثبت‌شده دارند:";

        foreach ( array_slice( $similar_jobs, 0, 4 ) as $job ) {
            if ( empty( $job['label'] ) ) {
                continue;
            }
            $note = '';
            if ( isset( $job['jobs_count'] ) && $job['jobs_count'] > 0 ) {
                $note = ' — ' . (int) $job['jobs_count'] . ' گزارش';
            }
            $lines[] = '• ' . $job['label'] . $note;
        }

        $lines[] = 'بگو کدام گزینه را مقایسه کنم تا درآمد و شرایط هر دو را کنار هم بگذارم. همچنین می‌توانم مسیر رشد درآمد همین شغل را توضیح بدهم.';

        return self::ensure_context_meta( self::build_response_payload(
            implode( "\n", array_filter( array_map( 'trim', $lines ) ) ),
            $context,
            $message,
            false,
            'compare_similar_jobs',
            array(
                'model'                 => self::resolve_model( $model ),
                'category'              => is_string( $category ) ? $category : '',
                'clarification_options' => array(),
                'suggestions'           => array( 'مسیر رشد درآمد در همین شغل', 'دیدن تجربه‌های مرتبط' ),
            )
        ), $context );
    }

    protected static function build_high_income_guidance( $context ) {
        $job_title = isset( $context['job_title'] ) ? $context['job_title'] : '';
        $category  = self::detect_job_category( $job_title );

        $lines   = array();
        $lines[] = 'پردرآمد بودن به شهر، مهارت و نوع فعالیت بستگی دارد.';
        if ( $job_title ) {
            $lines[] = "بر اساس تجربه‌های ثبت‌شده درباره «{$job_title}» می‌تونیم این مسیرها رو جلو ببریم:";
        } else {
            $lines[] = 'با تکیه بر گزارش‌های کاربران همین پلتفرم می‌تونیم این مسیرها رو جلو ببریم:';
        }

        if ( 'technical' === $category ) {
            $lines[] = '• مهارت یا زیرحوزه فنی پرتکرار را پیدا کنیم و درآمد پروژه‌ای/شرکتی گزارش‌شده را مقایسه کنیم.';
            $lines[] = '• از تجربه‌های ثبت‌شده ببینیم چه نوع پروژه یا استک تکنولوژی درآمد بهتری داشته است.';
            $lines[] = '• مسیر ساخت نمونه‌کار یا قرارداد کوتاه‌مدت را از گزارش‌های مشابه بررسی کنیم.';
        } elseif ( 'office' === $category ) {
            $lines[] = '• تفاوت درآمد بین نقش‌های کارشناس، سرپرست یا مدیر را در گزارش‌های این شغل مقایسه کنیم.';
            $lines[] = '• بررسی کنیم قرارداد ثابت، پورسانتی یا ترکیبی در کدام شهر/صنعت درآمد بالاتری داشته است.';
            $lines[] = '• از داده‌ها ببینیم چه سابقه یا مدرکی باعث ارتقای درآمد شده است.';
        } else {
            $lines[] = '• شغل‌های مشابه را در همین پلتفرم مقایسه کنیم تا ببینیم کدام مسیر درآمد بهتری گزارش شده است.';
            $lines[] = '• ترکیب نوع قرارداد و سابقه را در داده‌های کاربران بررسی کنیم تا مسیر افزایش درآمد مشخص شود.';
            $lines[] = '• شهر یا صنعت پرتقاضا را از گزارش‌های ثبت‌شده فیلتر کنیم.';
        }

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function build_high_income_response( $items ) {
        $lines = array();

        if ( empty( $items ) ) {
            $lines[] = '🏆 پردرآمدترین شغل‌ها';
            $lines[] = '• داده مستقیم نداریم تا رتبه‌بندی قابل اتکا بدهیم.';
            $lines[] = '• اگر حوزه یا مهارت خاصی مدنظر داری بگو تا تحلیل مشاوره‌ای ارائه بدهم.';
            $lines[] = '🧾 جمع‌بندی: فعلاً داده مستقیم کافی نیست.';
            $lines[] = '➡️ قدم بعدی: حوزه، شهر یا سرمایه‌ات را بگو تا دقیق‌تر راهنمایی کنم.';
            $lines[] = 'این پاسخ ترکیبی از تحلیل مشاوره‌ای و داده‌های ثبت‌شده کاربران است.';
            return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
        }

        $lines[] = '🏆 پردرآمدترین شغل‌ها بر اساس میانه درآمد ثبت‌شده کاربران:';
        foreach ( $items as $item ) {
            $title  = isset( $item['label'] ) ? (string) $item['label'] : '';
            $median = isset( $item['median_income'] ) ? (float) $item['median_income'] : 0;
            $count  = isset( $item['report_count'] ) ? (int) $item['report_count'] : 0;
            if ( '' === trim( $title ) || $median <= 0 ) {
                continue;
            }

            $note = '';
            if ( ! empty( $item['data_limited'] ) ) {
                $note = ' (داده محدود)';
            }

            $lines[] = '• ' . $title . ' — میانه درآمد: ' . self::format_amount_label( $median ) . ' | ' . $count . ' گزارش' . $note;
        }

        $lines[] = '🧾 جمع‌بندی: این رتبه‌بندی فقط بر اساس داده‌های ثبت‌شده کاربران است.';
        $lines[] = '➡️ قدم بعدی: اگر شهر/مهارت خاصی مدنظر داری بگو تا فیلتر کنم.';

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function build_suggestion_response( $intent_label, $normalized_message, $items, $alias_data = array() ) {
        $intent_label = is_string( $intent_label ) ? $intent_label : '';
        $normalized_message = is_string( $normalized_message ) ? $normalized_message : '';
        $alias_data = is_array( $alias_data ) ? $alias_data : array();

        $title_map = array(
            'CAREER_SUGGESTION'     => 'پیشنهاد مسیر شغلی',
            'GENERAL_BUSINESS_QUERY'=> 'ایده‌های کسب‌وکار کوچک/خانگی',
            'LOW_CAPITAL_QUERY'     => 'ایده‌های شغلی با سرمایه کم',
        );

        $heading = isset( $title_map[ $intent_label ] ) ? $title_map[ $intent_label ] : 'پیشنهاد شغلی';

        $lines = array();
        $lines[] = '🧭 ' . $heading;

        $analysis = array();
        $budget = self::extract_budget_from_message( $normalized_message );
        if ( ! empty( $budget['min'] ) && ! empty( $budget['max'] ) ) {
            $analysis[] = 'بازه سرمایه هدف: ' . self::format_range_label( $budget['min'], $budget['max'] );
        } elseif ( ! empty( $budget['max'] ) ) {
            $analysis[] = 'سرمایه هدف: تا ' . self::format_amount_label( $budget['max'] );
        } elseif ( ! empty( $budget['min'] ) ) {
            $analysis[] = 'سرمایه هدف: حداقل ' . self::format_amount_label( $budget['min'] );
        }

        if ( ! empty( $alias_data['tags'] ) && in_array( 'home', (array) $alias_data['tags'], true ) ) {
            $analysis[] = 'ترجیح: شغل خانگی/در خانه';
        }

        if ( empty( $analysis ) ) {
            $analysis[] = 'ترجیحات، مهارت‌ها یا شهر مشخص نشده؛ پیشنهادها عمومی است.';
        }

        $lines[] = '📋 تحلیل سریع:';
        foreach ( $analysis as $point ) {
            $lines[] = '• ' . $point;
        }

        if ( ! empty( $items ) ) {
            $lines[] = '💡 نمونه شغل‌های قابل بررسی (بر اساس داده‌های ثبت‌شده کاربران):';
            $lines   = array_merge( $lines, self::build_job_list_lines( $items ) );
        } else {
            $lines[] = '💡 داده مستقیم نداریم تا نمونه دقیق پیشنهاد دهیم.';
        }

        $lines[] = '🧾 جمع‌بندی: این پیشنهادها برای شروع مسیر است و نیاز به شخصی‌سازی دارد.';
        $lines[] = '➡️ قدم بعدی: شهر، مهارت‌ها و زمان آزاد را بگو تا دقیق‌تر پیشنهاد بدهم.';
        $lines[] = 'این تحلیل بیشتر مشاوره‌ای است و لزوماً مبتنی بر آمار مستقیم نیست.';

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function detect_intent_label( $normalized_message, $context = array() ) {
        $text = is_string( $normalized_message ) ? trim( $normalized_message ) : '';
        $context = is_array( $context ) ? $context : array();

        $has_job_hint = ! empty( $context['job_title'] )
            || ! empty( $context['job_title_hint'] )
            || ! empty( $context['job_slug'] )
            || ! empty( $context['job_title_id'] );
        $is_followup = ! empty( $context['is_followup'] );

        if ( '' === $text ) {
            return $has_job_hint || $is_followup ? 'JOB_INFO' : 'CAREER_SUGGESTION';
        }

        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

        if ( self::is_compare_similar_intent( $text ) || preg_match( '/مقایسه|compare|vs|versus/u', $lower ) ) {
            return 'JOB_COMPARE';
        }

        if ( self::is_high_income_query( $text ) ) {
            return 'HIGH_INCOME_QUERY';
        }

        if ( preg_match( '/چطور\s*بشم|چگونه\s*بشم|از\s*کجا\s*شروع|مسیر\s*یادگیری|یاد\s*بگیرم|راه\s*یادگیری|چطور\s*وارد/u', $lower ) ) {
            return 'SKILL_PATH_QUERY';
        }

        if ( ! $has_job_hint && preg_match( '/سرمایه|بودجه|هزینه|سرمایه\s*اولیه|راه\s*اندازی|سرمایه‌گذاری|سرمایه\s*گذاری/u', $lower )
            && preg_match( '/کم|کمتر\s*از|زیر|حداکثر|پایین/u', $lower ) ) {
            return 'LOW_CAPITAL_QUERY';
        }

        if ( ! $has_job_hint && preg_match( '/شخصیت|تیپ\s*شخصیتی|mbti|با\s*شرایط\s*من|مناسب\s*من|چه\s*شغل|چه\s*کار|پیشنهاد\s*شغل/u', $lower ) ) {
            return 'CAREER_SUGGESTION';
        }

        if ( ! $has_job_hint && preg_match( '/کسب\s*و\s*کار|کسب‌وکار|کسبوکار|خانگی|در\s*خانه|کار\s*در\s*خانه|کار\s*خانگی|ایده\s*درآمدی|بیزینس|کوچک/u', $lower ) ) {
            return 'GENERAL_BUSINESS_QUERY';
        }

        return $has_job_hint || $is_followup ? 'JOB_INFO' : 'CAREER_SUGGESTION';
    }

    protected static function detect_query_intent( $normalized_message, $context = array() ) {
        $text        = is_string( $normalized_message ) ? trim( $normalized_message ) : '';
        $has_job     = ! empty( $context['job_title'] );
        $ambiguous   = ! empty( $context['ambiguous'] ) || ! empty( $context['needs_clarification'] );

        if ( '' === $text ) {
            return $ambiguous ? 'clarification' : 'unknown';
        }

        if ( $ambiguous ) {
            return 'clarification';
        }

        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );

        if ( preg_match( '/شخصیت|تیپ\s*شخصیتی|mbti|با\s*شرایط\s*من|مناسب\s*من/u', $lower ) ) {
            return 'personality_advice';
        }

        if ( preg_match( '/چطور\s*بشم|چگونه\s*بشم|از\s*کجا\s*شروع|مسیر\s*یادگیری|یاد\s*بگیرم|راه\s*یادگیری|چطور\s*وارد/u', $lower ) ) {
            return 'learning_path';
        }

        if ( preg_match( '/خانگی|در\s*خانه|کار\s*در\s*خانه|کار\s*خانگی/u', $lower ) ) {
            return 'home_business';
        }

        $income_pattern = '/درآمد|حقوق|دستمزد|salary|income/i';
        if ( preg_match( $income_pattern, $lower ) ) {
            return 'income_query';
        }

        $capital_pattern = '/سرمایه|بودجه|هزینه|سرمایه\s*اولیه|راه\s*اندازی|سرمایه‌گذاری|سرمایه\s*گذاری|investment/i';
        if ( preg_match( $capital_pattern, $lower ) ) {
            return 'capital_query';
        }

        if ( preg_match( '/چه\s*شغل|چه\s*کار|پیشنهاد\s*شغل|شغل(?:‌|\s*)?های?\s*خوب|مشاغل\s*پرطرفدار|پرطرفدارترین/u', $lower ) ) {
            return 'job_suggestion';
        }

        if ( self::is_high_income_query( $text ) && ! $has_job ) {
            return 'job_suggestion';
        }

        return $has_job ? 'unknown' : 'fuzzy';
    }

    protected static function extract_budget_from_message( $normalized_message ) {
        $text = is_string( $normalized_message ) ? $normalized_message : '';
        if ( '' === $text || ! function_exists( 'bkja_parse_money_to_toman' ) ) {
            return array( 'min' => null, 'max' => null );
        }

        $parsed = bkja_parse_money_to_toman( $text );
        $value  = isset( $parsed['value_toman'] ) ? (int) $parsed['value_toman'] : 0;
        $min    = isset( $parsed['min_toman'] ) ? (int) $parsed['min_toman'] : 0;
        $max    = isset( $parsed['max_toman'] ) ? (int) $parsed['max_toman'] : 0;

        $limit_min = $min > 0 ? $min : null;
        $limit_max = $max > 0 ? $max : null;

        if ( $value > 0 && ! $limit_min && ! $limit_max ) {
            $limit_min = $value;
            $limit_max = $value;
        }

        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        if ( $value > 0 ) {
            if ( preg_match( '/زیر|کمتر\s*از|تا|حداکثر/u', $lower ) ) {
                $limit_min = null;
                $limit_max = $value;
            } elseif ( preg_match( '/بیشتر\s*از|حداقل/u', $lower ) ) {
                $limit_min = $value;
                $limit_max = null;
            }
        }

        return array(
            'min' => $limit_min,
            'max' => $limit_max,
        );
    }

    protected static function estimate_amount_range( $type, $title = '', $tags = array() ) {
        $type  = is_string( $type ) ? $type : '';
        $title = is_string( $title ) ? $title : '';
        $tags  = array_map( 'strval', (array) $tags );

        $is_home       = in_array( 'home', $tags, true ) || false !== mb_stripos( $title, 'خانگی', 0, 'UTF-8' );
        $is_low_cap    = in_array( 'low_capital', $tags, true );
        $is_food       = in_array( 'food', $tags, true ) || preg_match( '/غذا|کیک|شیرینی|دسر|قنادی|آشپزی/u', $title );
        $category      = self::detect_job_category( $title );

        $ranges = array(
            'income' => array(
                'home'     => array( 5000000, 25000000 ),
                'food'     => array( 8000000, 35000000 ),
                'technical'=> array( 15000000, 60000000 ),
                'office'   => array( 12000000, 40000000 ),
                'general'  => array( 10000000, 35000000 ),
            ),
            'capital' => array(
                'home'     => array( 5000000, 60000000 ),
                'food'     => array( 10000000, 80000000 ),
                'technical'=> array( 20000000, 120000000 ),
                'office'   => array( 10000000, 50000000 ),
                'general'  => array( 10000000, 80000000 ),
            ),
        );

        if ( $is_low_cap ) {
            return $type === 'capital' ? array( 5000000, 40000000 ) : array( 5000000, 25000000 );
        }

        if ( $is_food ) {
            return $ranges[ $type ]['food'];
        }

        if ( $is_home ) {
            return $ranges[ $type ]['home'];
        }

        if ( isset( $ranges[ $type ][ $category ] ) ) {
            return $ranges[ $type ][ $category ];
        }

        return $ranges[ $type ]['general'];
    }

    protected static function get_risk_level( $investment_max ) {
        $investment_max = (int) $investment_max;
        if ( $investment_max <= 0 ) {
            return 'متوسط';
        }

        if ( $investment_max <= 30000000 ) {
            return 'کم';
        }

        if ( $investment_max <= 80000000 ) {
            return 'متوسط';
        }

        return 'زیاد';
    }

    protected static function get_personality_fit( $title, $tags = array() ) {
        $title = is_string( $title ) ? $title : '';
        $tags  = array_map( 'strval', (array) $tags );

        if ( preg_match( '/فروش|بازاریابی|مشتری|مذاکره/u', $title ) ) {
            return 'برون‌گرا و ارتباطی';
        }

        if ( preg_match( '/آموزش|مدرس|کودک|مشاور/u', $title ) ) {
            return 'صبور و آموزش‌محور';
        }

        if ( preg_match( '/طراحی|هنر|خلاق/u', $title ) ) {
            return 'خلاق و ایده‌پرداز';
        }

        if ( in_array( 'home', $tags, true ) ) {
            return 'مستقل و مسئولیت‌پذیر';
        }

        return 'منظم و نتیجه‌گرا';
    }

    protected static function get_job_list_candidates( $filters = array(), $limit = 7 ) {
        global $wpdb;

        $table_titles = $wpdb->prefix . 'bkja_job_titles';
        $table_jobs   = $wpdb->prefix . 'bkja_jobs';
        $limit        = max( 3, (int) $limit );

        static $has_tags_column = null;
        if ( null === $has_tags_column ) {
            $columns = $wpdb->get_col( "DESC {$table_jobs}", 0 );
            $has_tags_column = is_array( $columns ) && in_array( 'tags', $columns, true );
        }

        $tags_select = $has_tags_column ? 'GROUP_CONCAT(DISTINCT j.tags SEPARATOR \',\') AS tags' : "'' AS tags";

        $sql = "SELECT jt.id, COALESCE(jt.base_label, jt.label) AS label, jt.group_key,
                       COUNT(j.id) AS cnt,
                       AVG(NULLIF(COALESCE(j.income_toman, j.income_num), 0)) AS avg_income,
                       MIN(NULLIF(COALESCE(j.income_toman, j.income_num), 0)) AS min_income,
                       MAX(NULLIF(COALESCE(j.income_toman, j.income_num), 0)) AS max_income,
                       AVG(NULLIF(COALESCE(j.investment_toman, j.investment_num), 0)) AS avg_investment,
                       MIN(NULLIF(COALESCE(j.investment_toman, j.investment_num), 0)) AS min_investment,
                       MAX(NULLIF(COALESCE(j.investment_toman, j.investment_num), 0)) AS max_investment,
                       {$tags_select}
                FROM {$table_titles} jt
                LEFT JOIN {$table_jobs} j ON j.job_title_id = jt.id
                WHERE jt.is_visible = 1
                GROUP BY jt.id
                HAVING cnt > 0
                ORDER BY cnt DESC
                LIMIT %d";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit * 4 ) );
        if ( empty( $rows ) ) {
            return array();
        }

        $filters = is_array( $filters ) ? $filters : array();
        $needs_home = ! empty( $filters['home'] );
        $max_invest = isset( $filters['investment_max'] ) ? (int) $filters['investment_max'] : 0;

        $candidates = array();
        foreach ( $rows as $row ) {
            $label = isset( $row->label ) ? (string) $row->label : '';
            if ( '' === trim( $label ) ) {
                continue;
            }

            $tags = array();
            if ( ! empty( $row->tags ) ) {
                $tags = array_map( 'trim', explode( ',', (string) $row->tags ) );
            }

            $has_home = in_array( 'home', $tags, true ) || false !== mb_stripos( $label, 'خانگی', 0, 'UTF-8' );
            if ( $needs_home && ! $has_home ) {
                continue;
            }

            $max_investment = isset( $row->max_investment ) ? (int) $row->max_investment : 0;
            if ( $max_invest > 0 && $max_investment > 0 && $max_investment > $max_invest ) {
                continue;
            }

            $candidates[] = array(
                'label'           => $label,
                'avg_income'      => isset( $row->avg_income ) ? (int) $row->avg_income : null,
                'min_income'      => isset( $row->min_income ) ? (int) $row->min_income : null,
                'max_income'      => isset( $row->max_income ) ? (int) $row->max_income : null,
                'avg_investment'  => isset( $row->avg_investment ) ? (int) $row->avg_investment : null,
                'min_investment'  => isset( $row->min_investment ) ? (int) $row->min_investment : null,
                'max_investment'  => $max_investment,
                'tags'            => $tags,
            );
        }

        return array_slice( $candidates, 0, $limit );
    }

    protected static function build_job_list_lines( $items ) {
        if ( empty( $items ) ) {
            return array();
        }

        $lines = array();
        foreach ( $items as $item ) {
            $title = isset( $item['label'] ) ? $item['label'] : '';
            if ( '' === trim( (string) $title ) ) {
                continue;
            }

            $tags = isset( $item['tags'] ) ? (array) $item['tags'] : array();
            $income_range = self::format_range_label( $item['min_income'] ?? null, $item['max_income'] ?? null, 'میلیون تومان در ماه' );
            if ( '' === $income_range ) {
                $estimate = self::estimate_amount_range( 'income', $title, $tags );
                $income_range = self::format_range_label( $estimate[0], $estimate[1], 'میلیون تومان در ماه' );
            }

            $capital_range = self::format_range_label( $item['min_investment'] ?? null, $item['max_investment'] ?? null );
            if ( '' === $capital_range ) {
                $estimate = self::estimate_amount_range( 'capital', $title, $tags );
                $capital_range = self::format_range_label( $estimate[0], $estimate[1] );
            }

            $risk = self::get_risk_level( $item['max_investment'] ?? 0 );
            $fit  = self::get_personality_fit( $title, $tags );

            $lines[] = '• ' . $title . ' | سرمایه: ' . $capital_range . ' | درآمد: ' . $income_range . ' | ریسک: ' . $risk . ' | مناسب برای: ' . $fit;
        }

        return $lines;
    }

    protected static function build_job_list_response( $items, $intro = '', $disclaimer = '' ) {
        if ( empty( $items ) ) {
            return '';
        }

        $lines = array();
        if ( '' !== $intro ) {
            $lines[] = $intro;
        }

        $lines = array_merge( $lines, self::build_job_list_lines( $items ) );
        if ( '' !== $disclaimer ) {
            $lines[] = $disclaimer;
        }

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function should_use_market_analysis( $context, $intent ) {
        if ( empty( $context['summary'] ) || ! is_array( $context['summary'] ) ) {
            return true;
        }

        $summary = $context['summary'];
        $count_reports = isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
        $income_valid = isset( $summary['income_valid_count'] ) ? (int) $summary['income_valid_count'] : 0;
        $invest_valid = isset( $summary['investment_valid_count'] ) ? (int) $summary['investment_valid_count'] : 0;

        if ( $count_reports <= 0 ) {
            return true;
        }

        if ( 'income_query' === $intent && $income_valid <= 1 ) {
            return true;
        }

        if ( 'capital_query' === $intent && $invest_valid <= 1 ) {
            return true;
        }

        return $count_reports < 2;
    }

    protected static function build_market_analysis_reply( $intent, $context, $normalized_message, $alias_data = array() ) {
        $title = isset( $context['job_title'] ) && $context['job_title'] ? $context['job_title'] : '';
        $alias_titles = ! empty( $alias_data['titles'] ) ? (array) $alias_data['titles'] : array();
        if ( '' === $title && ! empty( $alias_titles[0] ) ) {
            $title = $alias_titles[0];
        }

        $tags = array();
        if ( ! empty( $alias_data['tags'] ) ) {
            $tags = (array) $alias_data['tags'];
        }

        $summary = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $min_income = $summary['min_income'] ?? null;
        $max_income = $summary['max_income'] ?? null;
        $min_invest = $summary['min_investment'] ?? null;
        $max_invest = $summary['max_investment'] ?? null;

        $income_range = self::format_range_label( $min_income, $max_income, 'میلیون تومان در ماه' );
        if ( '' === $income_range ) {
            $estimate = self::estimate_amount_range( 'income', $title, $tags );
            $income_range = self::format_range_label( $estimate[0], $estimate[1], 'میلیون تومان در ماه' );
        }

        $capital_range = self::format_range_label( $min_invest, $max_invest );
        if ( '' === $capital_range ) {
            $estimate = self::estimate_amount_range( 'capital', $title, $tags );
            $capital_range = self::format_range_label( $estimate[0], $estimate[1] );
        }

        $lines = array();
        $subject = $title ? 'برای «' . $title . '»' : 'در این حوزه';
        $lines[] = '⚠️ داده مستقیم نداریم؛ بنابراین این پاسخ تحلیلی است.';
        $lines[] = '• ' . $subject . ' بازه تقریبی درآمد: ' . $income_range;
        $lines[] = '• سرمایه اولیه تقریبی: ' . $capital_range;
        $lines[] = '• عوامل اثرگذار: شهر/محله، کیفیت نمونه‌کار، مهارت بازاریابی، ثبات در سفارش‌ها، هزینه مواد اولیه.';
        $lines[] = '• اگر داده دقیق می‌خواهی، شغل‌های هم‌خانواده با گزارش بیشتر را بررسی کنیم.';
        $lines[] = 'این پاسخ ترکیبی از تحلیل مشاوره‌ای و داده‌های ثبت‌شده کاربران است.';

        if ( empty( $title ) ) {
            $lines[] = 'اگر عنوان دقیق شغل را بگویی، پاسخ دقیق‌تر می‌شود.';
        }

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
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
            $income_numeric_total = isset( $summary['income_numeric_total'] ) ? (int) $summary['income_numeric_total'] : 0;
            $data_limited = ! empty( $summary['data_limited'] );
            $total_records = isset( $summary['total_records'] ) ? (int) $summary['total_records'] : $count_reports;
            $income_valid_count = isset( $summary['income_valid_count'] ) ? (int) $summary['income_valid_count'] : 0;
            $income_data_low = ( $total_records <= 2 || $income_valid_count <= 2 );

            $count_line = 'تعداد گزارش‌های معتبر';
            if ( $window_months ) {
                $count_line .= " در {$window_months} ماه اخیر";
            }
            $count_line .= ': ' . $count_reports;
            $lines[] = $count_line;
            if ( $count_reports > 0 && $count_reports < 3 ) {
                $lines[] = 'هشدار: داده‌های بسیار محدود است و نتایج تقریبی است.';
            }
            if ( $income_numeric_total > 0 && $income_numeric_total < 3 ) {
                $lines[] = 'هشدار: تعداد گزارش‌های عددی کم است و دقت پایین است.';
            }
            if ( $data_limited && $count_reports > 0 ) {
                $lines[] = 'داده‌های ما برای این شغل هنوز کم است (' . $count_reports . ' تجربه) و نتایج تقریبی است.';
            }

            $avg_income  = isset( $summary['avg_income'] ) ? $summary['avg_income'] : null;
            $min_income  = isset( $summary['min_income'] ) ? $summary['min_income'] : null;
            $max_income  = isset( $summary['max_income'] ) ? $summary['max_income'] : null;
            $avg_invest  = isset( $summary['avg_investment'] ) ? $summary['avg_investment'] : null;
            $min_invest  = isset( $summary['min_investment'] ) ? $summary['min_investment'] : null;
            $max_invest  = isset( $summary['max_investment'] ) ? $summary['max_investment'] : null;
            $income_method = isset( $summary['avg_income_method'] ) && 'median' === $summary['avg_income_method'] ? 'میانه' : 'میانگین';

            if ( $total_records > 0 && $income_valid_count <= 0 ) {
                $lines[] = 'درآمد: داده کافی برای عدد دقیق نداریم.';
            } elseif ( $avg_income || $min_income || $max_income ) {
                $label_prefix = $income_data_low ? 'برآورد تقریبی' : $income_method;
                $income_line = $label_prefix . ' درآمد ماهانه: ' . self::format_amount_label( $avg_income );
                $range       = self::format_range_label( $min_income, $max_income, 'میلیون تومان در ماه' );
                if ( $range ) {
                    $income_line .= ' | بازه رایج: ' . $range;
                } else {
                    $income_line .= ' | بازه رایج: نامشخص';
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
                if ( ! empty( $record['income_note'] ) ) {
                    $income_value .= ' (' . $record['income_note'] . ')';
                }
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

        $lines[] = 'این آمار و اعداد بر اساس گزارش کاربران این سیستم است و منبع رسمی نیست. پاسخ نهایی باید عدد-محور، موجز و بر مبنای همین داده‌ها باشد. اگر داده کافی نیست، محدودیت داده را بگو و بازه تقریبی بازار + عوامل مؤثر را تحلیل کن و ذکر کن «این پاسخ ترکیبی از تحلیل مشاوره‌ای و داده‌های ثبت‌شده کاربران است.»';

        return implode( "\n", array_filter( array_map( 'trim', $lines ) ) );
    }

    protected static function format_job_context_reply( $context ) {
        if ( empty( $context['job_title'] ) ) {
            return '';
        }

        $title   = $context['job_title'];
        $summary = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $records = ( ! empty( $context['records'] ) && is_array( $context['records'] ) ) ? $context['records'] : array();

        $count_reports   = isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
        $income_count    = isset( $summary['income_valid_count'] ) ? (int) $summary['income_valid_count'] : 0;
        $window_months   = isset( $summary['window_months'] ) ? (int) $summary['window_months'] : null;
        $window_label    = $window_months ? 'حدود ' . $window_months . ' ماه اخیر' : '۱۲ ماه اخیر';
        $data_limited    = ( $count_reports > 0 && $count_reports < 3 ) || ! empty( $summary['data_limited'] );

        $sections   = array();
        $sections[] = "📌 داده‌های واقعی درباره «{$title}»:";

        if ( $count_reports > 0 ) {
            $sections[] = '• ' . $window_label . ' | ' . $count_reports . ' گزارش کاربری ثبت شده.';
        } else {
            $sections[] = '• هنوز گزارشی در بازه اخیر نداریم.';
        }
        $sections[] = '• اعداد فقط بر اساس گزارش کاربران است و رسمی نیست.';

        $sections[] = '';
        $sections[] = '💵 درآمد ماهانه (میلیون تومان):';
        if ( $income_count <= 0 ) {
            $sections[] = '• هنوز گزارش عددی کافی برای درآمد نداریم (نامشخص).';
        } else {
            $median_label = isset( $summary['median_income_label'] ) ? $summary['median_income_label'] : null;
            $avg_label    = isset( $summary['avg_income_label'] ) ? $summary['avg_income_label'] : null;
            $value_label  = $median_label ?: $avg_label;
            $range_label  = self::format_range_label( $summary['min_income'] ?? null, $summary['max_income'] ?? null, 'میلیون تومان در ماه' );

            if ( $income_count >= 5 && $median_label ) {
                $income_line = '• میانه درآمد: ' . $median_label . ' (بر اساس ' . $income_count . ' گزارش عددی).';
            } else {
                $income_line = '• برآورد تقریبی درآمد: ' . ( $value_label ? $value_label : 'نامشخص' ) . ' (داده عددی محدود).';
            }

            if ( $range_label ) {
                $income_line .= ' | بازه رایج: ' . $range_label;
            }

            $sections[] = $income_line;
        }

        $sections[] = '';
        $invest_label = isset( $summary['avg_investment'] ) ? self::format_amount_label( $summary['avg_investment'] ) : null;
        $invest_line  = '💰 سرمایه میانگین: ' . ( $invest_label ? $invest_label : 'نامشخص' );
        $invest_range = self::format_range_label( $summary['min_investment'] ?? null, $summary['max_investment'] ?? null );
        if ( $invest_range ) {
            $invest_line .= ' | بازه رایج: ' . $invest_range;
        }
        if ( isset( $summary['investment_count'] ) && $summary['investment_count'] > 0 && $summary['investment_count'] < 3 ) {
            $invest_line .= ' (دقت پایین به دلیل گزارش‌های محدود)';
        }
        $sections[] = $invest_line;

        if ( ! empty( $summary['cities'] ) ) {
            $sections[] = '📍 شهرهای پرتکرار: ' . implode( '، ', array_slice( (array) $summary['cities'], 0, 5 ) );
        }

        if ( ! empty( $summary['advantages'] ) || ! empty( $summary['disadvantages'] ) ) {
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
            $index = 1;
            foreach ( array_slice( $records, 0, 2 ) as $record ) {
                $sections[] = self::format_record_block( $record, $index );
                $index++;
            }
        }

        if ( $data_limited ) {
            $sections[] = '⚠️ داده محدود است؛ اعداد تقریبی تلقی شوند.';
        }

        return implode( "\n", array_filter( array_map( 'trim', $sections ), function ( $line ) {
            return $line !== '' || $line === '0';
        } ) );
    }

    protected static function build_followup_suggestions( $message, $context = array(), $answer = '' ) {
        $context  = is_array( $context ) ? $context : array();
        $summary  = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $job_title = isset( $context['job_title'] ) ? trim( (string) $context['job_title'] ) : '';
        $job_id    = ! empty( $context['primary_job_title_id'] ) ? (int) $context['primary_job_title_id'] : 0;

        if ( isset( $context['state_type'] ) && BKJA_State::TYPE_B === $context['state_type'] ) {
            return array();
        }

        if ( '' === $job_title || $job_id <= 0 ) {
            return array();
        }

        $actions_map = array(
            'show_more_records'        => 'نمایش بیشتر تجربه کاربران',
            'compare_similar_jobs'     => 'مقایسه با شغل مشابه',
            'income_growth_path'       => 'مسیر رشد درآمد در همین شغل',
            'show_related_experiences' => 'دیدن تجربه‌های مرتبط',
        );

        $count_reports   = isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
        $data_limited    = ( $count_reports > 0 && $count_reports < 3 ) || ! empty( $summary['data_limited'] );
        $has_more_records = ! empty( $context['records_has_more'] );

        $suggestions = array();

        if ( $has_more_records && isset( $actions_map['show_more_records'] ) ) {
            $suggestions[] = array(
                'action' => 'show_more_records',
                'label'  => $actions_map['show_more_records'],
                'offset' => isset( $context['records_next_offset'] ) ? (int) $context['records_next_offset'] : 0,
            );
        }

        if ( isset( $actions_map['compare_similar_jobs'] ) ) {
            $suggestions[] = $actions_map['compare_similar_jobs'];
        }

        if ( isset( $actions_map['income_growth_path'] ) ) {
            $suggestions[] = $actions_map['income_growth_path'];
        }

        if ( $data_limited && isset( $actions_map['show_related_experiences'] ) ) {
            $suggestions[] = $actions_map['show_related_experiences'];
        }

        return array_values( array_unique( $suggestions, SORT_REGULAR ) );
    }

    protected static function normalize_followup_action_key( $action ) {
        $action = self::normalize_message( $action );
        if ( '' === $action ) {
            return '';
        }

        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $action, 'UTF-8' ) : strtolower( $action );

        if ( false !== strpos( $haystack, 'نمایش بیشتر' ) || false !== strpos( $haystack, 'show more' ) ) {
            return 'show_more_records';
        }

        if ( false !== strpos( $haystack, 'مقایسه' ) || false !== strpos( $haystack, 'similar' ) || false !== strpos( $haystack, 'compare' ) ) {
            return 'compare_similar_jobs';
        }

        if ( false !== strpos( $haystack, 'تجربه' ) || false !== strpos( $haystack, 'experience' ) ) {
            return 'show_related_experiences';
        }

        if ( false !== strpos( $haystack, 'مسیر رشد' ) || false !== strpos( $haystack, 'رشد درآمد' ) || false !== strpos( $haystack, 'growth' ) || false !== strpos( $haystack, 'income_growth' ) ) {
            return 'income_growth_path';
        }

        return $haystack;
    }

    protected static function handle_followup_action( $action, $context, $message, $category, $model, $normalized_message, $request_meta = array() ) {
        $action_key   = self::normalize_followup_action_key( $action );
        $context      = is_array( $context ) ? $context : array();
        $model        = self::resolve_model( $model );
        $category     = is_string( $category ) ? $category : '';
        $request_meta = is_array( $request_meta ) ? $request_meta : array();

        if ( 'compare_similar_jobs' === $action_key ) {
            $payload = self::handle_compare_similar_jobs( $context, $message, $category, $model );
            if ( is_array( $payload ) ) {
                return $payload;
            }
        }

        if ( 'income_growth_path' === $action_key ) {
            $reply = self::build_high_income_guidance( $context );

            return self::ensure_context_meta( self::build_response_payload(
                $reply,
                $context,
                $message,
                false,
                'followup_action',
                array(
                    'model'              => $model,
                    'category'           => $category,
                    'normalized_message' => $normalized_message,
                )
            ), $context );
        }

        if ( 'show_more_records' === $action_key ) {
            $job_title_id = isset( $context['primary_job_title_id'] ) ? (int) $context['primary_job_title_id'] : null;
            $summary_context = ( isset( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
            if ( ! $job_title_id && ! empty( $summary_context['job_title_id'] ) ) {
                $job_title_id = (int) $summary_context['job_title_id'];
            }
            if ( ! $job_title_id && ! empty( $context['job_title_ids'][0] ) ) {
                $job_title_id = (int) $context['job_title_ids'][0];
            }

            $current_group_key = isset( $context['group_key'] ) ? $context['group_key'] : null;
            $group_key = $current_group_key ? $current_group_key : ( $summary_context['group_key'] ?? null );
            $offset    = isset( $request_meta['offset'] ) ? max( 0, (int) $request_meta['offset'] ) : 0;
            $limit     = 5;

            $records_data = class_exists( 'BKJA_Database' ) ? BKJA_Database::get_job_records( $job_title_id, $limit, $offset ) : array( 'records' => array(), 'has_more' => false, 'next_offset' => null );
            $records      = isset( $records_data['records'] ) && is_array( $records_data['records'] ) ? $records_data['records'] : array();

            $context['records']            = $records;
            $context['records_has_more']   = ! empty( $records_data['has_more'] );
            $context['records_next_offset'] = isset( $records_data['next_offset'] ) ? $records_data['next_offset'] : null;
            $context['group_key']          = $current_group_key ?: $group_key;

            $reply_lines = array();
            if ( ! empty( $records ) ) {
                $reply_lines[] = '🧪 تجربه‌های بیشتر کاربران:';
                $index = $offset + 1;
                foreach ( $records as $record ) {
                    $reply_lines[] = self::format_record_block( $record, $index );
                    $index++;
                }
            } else {
                $reply_lines[] = '📭 تجربه دیگری برای نمایش وجود ندارد.';
            }

            $payload = self::ensure_context_meta( self::build_response_payload(
                implode( "\n", array_filter( array_map( 'trim', $reply_lines ) ) ),
                $context,
                $message,
                false,
                'followup_action',
                array(
                    'model'              => $model,
                    'category'           => $category,
                    'normalized_message' => $normalized_message,
                )
            ), $context );

            $payload['meta']['has_more']    = ! empty( $records_data['has_more'] );
            $payload['meta']['next_offset'] = isset( $records_data['next_offset'] ) ? $records_data['next_offset'] : null;
            $payload['meta']['group_key']   = $payload['meta']['group_key'] ?: $group_key;
            $payload['meta']['job_title_id'] = $payload['meta']['job_title_id'] ?: $job_title_id;

            return $payload;
        }

        $reply = self::format_job_context_reply( $context );
        if ( '' === trim( (string) $reply ) ) {
            $reply = self::build_compare_fallback_message();
        }

        return self::ensure_context_meta( self::build_response_payload(
            $reply,
            $context,
            $message,
            false,
            'followup_action',
            array(
                'model'              => $model,
                'category'           => $category,
                'normalized_message' => $normalized_message,
            )
        ), $context );
    }

    protected static function is_followup_message( $message ) {
        $text = is_string( $message ) ? trim( $message ) : '';
        if ( '' === $text ) {
            return false;
        }

        $word_count = preg_split( '/\s+/u', $text );
        $word_count = is_array( $word_count ) ? count( $word_count ) : 0;
        $is_short   = ( $word_count > 0 && $word_count <= 7 ) || ( function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) <= 60 : strlen( $text ) <= 60 );

        if ( ! $is_short ) {
            return false;
        }

        $keywords = array(
            'درآمد', 'درامد', 'حقوق', 'حقوقش', 'درآمدش', 'چقدر', 'چقد', 'چقدره', 'چنده', 'چقدر درمیاره',
            'دستمزد', 'سرمایه', 'سرمایه میخواد', 'سرمایه می‌خواد', 'هزینه', 'هزینه شروع', 'بودجه',
            'مزایا', 'معایب', 'چالش', 'بازار', 'بازار کار', 'خارج', 'مهارت', 'مهارت‌ها', 'قدم بعدی',
            'مقایسه', 'مشابه', 'از کجا شروع کنم', 'شغل‌های جایگزین', 'شغلهای جایگزین', 'میانگین', 'بازه', 'شرایط'
        );
        foreach ( $keywords as $keyword ) {
            if ( false !== mb_stripos( $text, $keyword, 0, 'UTF-8' ) ) {
                return true;
            }
        }

        return false;
    }

    protected static function is_followup_reference( $message ) {
        $text = is_string( $message ) ? trim( $message ) : '';
        if ( '' === $text ) {
            return false;
        }

        $lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        return (bool) preg_match( '/(?:این|همین|اون|آن|همون|شغل(?:ش)?|کار(?:ش)?|درآمدش|حقوقش|سرمایه(?:‌|)ش|بازارش|شرایطش|معایبش|مزایاش|چالش(?:‌|)هاش)/u', $lower );
    }

    protected static function get_last_job_context( $session_id, $user_id ) {
        $session_id = is_string( $session_id ) ? trim( $session_id ) : '';
        $user_id    = (int) $user_id;
        $data       = null;

        if ( $user_id > 0 ) {
            $data = get_user_meta( $user_id, 'bkja_last_job_context', true );
        }

        if ( empty( $data ) && $session_id ) {
            $data = get_transient( 'bkja_last_job_context_' . $session_id );
        }

        if ( empty( $data ) || ! is_array( $data ) ) {
            return 0;
        }

        $timestamp = isset( $data['timestamp'] ) ? (int) $data['timestamp'] : 0;
        $job_id    = isset( $data['job_title_id'] ) ? (int) $data['job_title_id'] : 0;
        if ( $job_id <= 0 || $timestamp <= 0 ) {
            return 0;
        }

        $max_age = 2 * HOUR_IN_SECONDS;
        if ( ( current_time( 'timestamp' ) - $timestamp ) > $max_age ) {
            return 0;
        }

        return $job_id;
    }

    protected static function store_last_job_context( $job_title_id, $session_id, $user_id ) {
        $job_title_id = (int) $job_title_id;
        $session_id   = is_string( $session_id ) ? trim( $session_id ) : '';
        $user_id      = (int) $user_id;

        if ( $job_title_id <= 0 ) {
            return;
        }

        $data = array(
            'job_title_id' => $job_title_id,
            'timestamp'    => current_time( 'timestamp' ),
        );

        if ( $user_id > 0 ) {
            update_user_meta( $user_id, 'bkja_last_job_context', $data );
        }

        if ( $session_id ) {
            set_transient( 'bkja_last_job_context_' . $session_id, $data, 6 * HOUR_IN_SECONDS );
        }
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

        return self::ensure_context_meta( self::build_response_payload(
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
        ), $context );
    }

    protected static function refresh_job_stats_payload( array $payload, $context = array() ) {
        $summary = ( ! empty( $context['summary'] ) && is_array( $context['summary'] ) ) ? $context['summary'] : array();
        $stats_executed = ( is_array( $context ) && ! empty( $context['stats_executed'] ) ) || ! empty( $summary );

        $primary_job_title_id = isset( $context['primary_job_title_id'] ) ? (int) $context['primary_job_title_id'] : null;
        $job_title_ids        = isset( $context['job_title_ids'] ) ? (array) $context['job_title_ids'] : array();
        $group_key            = isset( $context['group_key'] ) ? $context['group_key'] : null;

        if ( ! $primary_job_title_id && ! empty( $summary['job_title_id'] ) ) {
            $primary_job_title_id = (int) $summary['job_title_id'];
        }
        if ( ! $primary_job_title_id && ! empty( $job_title_ids ) ) {
            $primary_job_title_id = (int) $job_title_ids[0];
        }

        $job_report_count     = $stats_executed && isset( $summary['count_reports'] ) ? (int) $summary['count_reports'] : 0;
        $job_avg_income       = $stats_executed && isset( $summary['avg_income'] ) ? (float) $summary['avg_income'] : null;
        $job_income_range     = $stats_executed ? array( $summary['min_income'] ?? null, $summary['max_income'] ?? null ) : array( null, null );
        $job_avg_investment   = $stats_executed && isset( $summary['avg_investment'] ) ? (float) $summary['avg_investment'] : null;
        $job_investment_range = $stats_executed ? array( $summary['min_investment'] ?? null, $summary['max_investment'] ?? null ) : array( null, null );

        $used_job_stats       = $stats_executed && $job_report_count > 0;
        $needs_clarification  = ! empty( $context['needs_clarification'] );

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

        if ( $used_job_stats && ! $needs_clarification ) {
            $clarification_options = array();
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
        $payload['resolution_source']    = isset( $context['resolution_source'] ) ? $context['resolution_source'] : null;
        $payload['resolved_job_title_id']= $primary_job_title_id;

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
        if ( ! isset( $payload['meta']['job_group_key'] ) || '' === (string) $payload['meta']['job_group_key'] ) {
            $payload['meta']['job_group_key'] = $payload['group_key'];
        }
        $payload['meta']['clarification_options']= $payload['clarification_options'];
        $payload['meta']['resolution_source']    = $payload['resolution_source'];
        $payload['meta']['resolved_job_title_id']= $payload['resolved_job_title_id'];
        $payload['meta']['records_has_more']     = isset( $context['records_has_more'] ) ? (bool) $context['records_has_more'] : null;
        $payload['meta']['records_next_offset']  = isset( $context['records_next_offset'] ) ? $context['records_next_offset'] : null;
        $payload['meta']['records_total']        = isset( $context['records_total'] ) ? $context['records_total'] : null;
        if ( ! isset( $payload['meta']['has_more'] ) && isset( $context['records_has_more'] ) ) {
            $payload['meta']['has_more'] = (bool) $context['records_has_more'];
        }
        if ( ! isset( $payload['meta']['next_offset'] ) && isset( $context['records_next_offset'] ) ) {
            $payload['meta']['next_offset'] = $context['records_next_offset'];
        }

        if ( ! isset( $payload['meta']['job_title'] ) || '' === (string) $payload['meta']['job_title'] ) {
            if ( isset( $summary['job_title_label'] ) && '' !== (string) $summary['job_title_label'] ) {
                $payload['meta']['job_title'] = (string) $summary['job_title_label'];
            } elseif ( isset( $summary['job_title'] ) && '' !== (string) $summary['job_title'] ) {
                $payload['meta']['job_title'] = (string) $summary['job_title'];
            }
        }

        if ( $payload['job_title_id'] && ( ! isset( $payload['meta']['job_title_id'] ) || empty( $payload['meta']['job_title_id'] ) ) ) {
            $payload['meta']['job_title_id'] = $payload['job_title_id'];
        }

        if ( isset( $context['state_type'] ) && $context['state_type'] ) {
            $payload['state_type'] = $context['state_type'];
            $payload['meta']['state_type'] = $context['state_type'];
        }

        return $payload;
    }

    protected static function build_response_payload( $text, $context, $message, $from_cache = false, $source = 'openai', $extra = array() ) {
        $context_used = ! empty( $context['job_title'] );

        $normalized_message = isset( $extra['normalized_message'] ) ? (string) $extra['normalized_message'] : self::normalize_message( $message );
        $query_intent        = self::detect_query_intent( $normalized_message, $context );
        $state_type          = isset( $extra['state_type'] ) ? $extra['state_type'] : ( isset( $context['state_type'] ) ? $context['state_type'] : null );
        $intent_label        = isset( $extra['intent_label'] ) ? (string) $extra['intent_label'] : '';
        if ( '' === $intent_label ) {
            $intent_label = self::detect_intent_label(
                $normalized_message,
                array(
                    'job_title'      => $context['job_title'] ?? '',
                    'job_title_hint' => $extra['job_title'] ?? '',
                    'job_slug'       => $extra['job_slug'] ?? '',
                    'job_title_id'   => $extra['job_title_id'] ?? 0,
                )
            );
        }

        $suggestions = $state_type === BKJA_State::TYPE_B
            ? array()
            : self::build_followup_suggestions( $message, $context, $text );

        $payload = array(
            'text'         => (string) $text,
            'suggestions'  => $suggestions,
            'context_used' => $context_used,
            'from_cache'   => (bool) $from_cache,
            'source'       => $source,
            'state_type'   => $state_type,
            'job_title'    => ! empty( $context['job_title'] ) ? $context['job_title'] : '',
            'job_slug'     => isset( $context['job_slug'] ) ? $context['job_slug'] : '',
            'job_title_id' => isset( $context['primary_job_title_id'] ) ? (int) $context['primary_job_title_id'] : null,
            'group_key'    => isset( $context['group_key'] ) ? $context['group_key'] : '',
            'clarification_options' => isset( $context['candidates'] ) && is_array( $context['candidates'] ) ? array_slice( $context['candidates'], 0, 3 ) : array(),
            'resolved_confidence'   => isset( $context['resolved_confidence'] ) ? $context['resolved_confidence'] : null,
            'resolution_source'     => isset( $context['resolution_source'] ) ? $context['resolution_source'] : null,
            'query_intent'          => $query_intent,
            'intent_label'          => $intent_label,
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
            'state_type'   => $state_type,
            'category'     => $resolved_category,
            'job_title'    => $resolved_job_title,
            'job_slug'     => $resolved_job_slug,
            'job_title_id' => isset( $payload['job_title_id'] ) ? $payload['job_title_id'] : null,
            'group_key'    => isset( $payload['group_key'] ) ? $payload['group_key'] : null,
            'clarification_options' => isset( $payload['clarification_options'] ) ? $payload['clarification_options'] : array(),
            'resolved_confidence'   => isset( $payload['resolved_confidence'] ) ? $payload['resolved_confidence'] : null,
            'resolution_source'     => isset( $payload['resolution_source'] ) ? $payload['resolution_source'] : null,
            'query_intent'          => $query_intent,
            'intent_label'          => $intent_label,
        );

        return self::refresh_job_stats_payload( $payload, $context );
    }

    public static function delete_cache_for( $message, $category = '', $model = '', $job_title = '', $query_intent = '' ) {
        $key = self::build_cache_key( $message, $category, $model, $job_title, $query_intent );
        delete_transient( $key );

        if ( '' !== $job_title ) {
            $legacy_key = self::build_cache_key( $message, $category, $model, '', $query_intent );
            delete_transient( $legacy_key );
        }
    }

    public static function extend_cache_ttl( $message, $category = '', $model = '', $ttl = 0, $job_title = '', $query_intent = '' ) {
        if ( ! self::is_cache_enabled() ) {
            return;
        }

        $key      = self::build_cache_key( $message, $category, $model, $job_title, $query_intent );
        $payload  = get_transient( $key );
        if ( false === $payload && '' !== $job_title ) {
            $legacy_key = self::build_cache_key( $message, $category, $model, '', $query_intent );
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

    public static function clear_all_caches_full( $extra_prefixes = array() ) {
        $result = self::clear_response_cache_prefix( $extra_prefixes );

        $deleted_options = 0;
        if ( delete_option( 'bkja_cache_version' ) ) {
            $deleted_options++;
        }

        $version = self::get_cache_version();
        $result['version'] = $version;
        $result['deleted_options'] = $deleted_options;

        $object_cache_flushed = null;
        if ( function_exists( 'wp_cache_flush' ) ) {
            $object_cache_flushed = wp_cache_flush();
        }

        $result['object_cache_flushed'] = $object_cache_flushed;

        return $result;
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
            'system'         => "تو دستیار شغلی داده‌محور BKJA هستی.\n\nقواعد سخت:\n1) اگر «کارت شغلی/درآمد یک شغل» خواسته شد: از داده‌های کانتکست/DB استفاده کن و عدد نساز. اگر داده کم است، محدودیت داده را بگو و یک بازه تقریبی بازار + عوامل مؤثر ارائه بده و حتماً بنویس: «این پاسخ ترکیبی از تحلیل مشاوره‌ای و داده‌های ثبت‌شده کاربران است.»\n2) اگر کاربر سوال عمومی پرسید (سرمایه‌گذاری، ترید، وام، بیکاری، معرفی شغل در شهر، کار در خانه، ایده درآمدی): وارد کارت شغلی نشو. در حالت SHORT MODE پاسخ بده.\n3) SHORT MODE: حداکثر 6 خط بولت. حداکثر 1 سوال شفاف‌سازی. بدون متن طولانی، بدون مزایا/معایب کلی.\n4) اگر کاربر گفت «از فالوورها بپرس»: فقط یک متن خیلی کوتاه برای استوری/پست بده که این موارد را بپرسد: عنوان شغل، شهر، درآمد ماهانه، سابقه، ساعت کار، سرمایه اولیه. سپس دعوت به ارسال تجربه شخصی.\n5) مدیریت توکن: هرگز لیست طولانی تولید نکن. اگر تجربه‌ها زیاد بود فقط 5 مورد اول را خلاصه کن و بگو «برای ادامه از دکمه نمایش بیشتر استفاده کنید».\n6) پاسخ‌ها فارسی، ساده، کاربرپسند، با اقدام عملی آخر.\n\nفرمت خروجی:\n- همیشه بولت‌دار\n- اگر پاسخ تحلیلی است، منبع را صریح اعلام کن\n- از تکرار خودداری کن",
            'model'          => '',
            'session_id'     => '',
            'user_id'        => 0,
            'category'       => '',
            'job_title_hint' => '',
            'job_slug'       => '',
            'job_title_id'   => 0,
            'job_group_key'  => '',
            'followup_action'=> '',
            'offset'         => 0,
            'request_meta'   => array(),
        );
        $args              = wp_parse_args( $args, $defaults );
        $model             = self::resolve_model( $args['model'] );
        $system            = ! empty( $args['system'] ) ? $args['system'] : $defaults['system'];
        $resolved_category = is_string( $args['category'] ) ? $args['category'] : '';
        $job_title_hint    = is_string( $args['job_title_hint'] ) ? trim( $args['job_title_hint'] ) : '';
        $job_slug          = is_string( $args['job_slug'] ) ? trim( $args['job_slug'] ) : '';
        $job_title_id      = isset( $args['job_title_id'] ) ? (int) $args['job_title_id'] : 0;
        $job_group_key     = is_string( $args['job_group_key'] ) ? trim( $args['job_group_key'] ) : '';
        $followup_action   = is_string( $args['followup_action'] ) ? trim( $args['followup_action'] ) : '';
        $request_meta      = is_array( $args['request_meta'] ) ? $args['request_meta'] : array();
        if ( ! array_key_exists( 'offset', $request_meta ) ) {
            $request_meta['offset'] = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
        }
        $normalized_action = self::normalize_message( $followup_action );
        $is_followup_action = '' !== $normalized_action;

        $normalized_message  = self::normalize_message( $message );
        $is_followup_only    = self::is_followup_message( $normalized_message );
        $intent_label        = self::detect_intent_label(
            $normalized_message,
            array(
                'job_title_hint' => $job_title_hint,
                'job_title_id'   => $job_title_id,
                'job_slug'       => $job_slug,
                'is_followup'    => $is_followup_action || $is_followup_only,
            )
        );
        $pre_intent          = self::detect_query_intent( $normalized_message, array() );
        $followup_reference  = self::is_followup_reference( $normalized_message );
        $broad_intent_types  = array( 'job_suggestion', 'home_business', 'capital_query', 'income_query', 'personality_advice', 'learning_path' );
        if ( $is_followup_only && ! $followup_reference && in_array( $pre_intent, $broad_intent_types, true ) ) {
            $is_followup_only = false;
        }

        if ( $job_title_id <= 0 && ! $is_followup_action && $is_followup_only ) {
            $recent_job_id = self::get_last_job_context( $args['session_id'], (int) $args['user_id'] );
            if ( $recent_job_id > 0 ) {
                $job_title_id = $recent_job_id;
            }
        }

        if ( $is_followup_action && $job_title_id <= 0 && '' === $job_title_hint ) {
            $payload = self::build_response_payload(
                'برای انجام این کار، اول کارت یک شغل مشخص را باز کن.',
                array(),
                $message,
                false,
                'followup_missing_job',
                array(
                    'model'                 => $model,
                    'category'              => $resolved_category,
                    'clarification_options' => array(),
                    'suggestions'           => array(),
                )
            );

            $payload['meta'] = array();
            $payload['suggestions'] = array();

            return $payload;
        }

        $context_query = ( $is_followup_action && '' !== $job_title_hint ) ? $job_title_hint : $normalized_message;
        $skip_context = in_array(
            $intent_label,
            array( 'CAREER_SUGGESTION', 'HIGH_INCOME_QUERY', 'LOW_CAPITAL_QUERY', 'GENERAL_BUSINESS_QUERY' ),
            true
        )
            && ! $is_followup_action
            && ! $is_followup_only
            && $job_title_id <= 0
            && '' === $job_title_hint
            && '' === $job_slug;
        $context = $skip_context ? array() : self::get_job_context( $context_query, $job_title_hint, $job_slug, $job_title_id, $job_group_key );
        $alias_data = self::resolve_alias_data( $normalized_message );

        $state = BKJA_RuleEngine::classify(
            $normalized_message,
            $context,
            array(
                'category'        => $resolved_category,
                'is_followup'     => $is_followup_action || $is_followup_only,
                'followup_action' => $normalized_action,
            )
        );
        $context['state_type'] = $state->get_type();
        $context['state_meta'] = $state->get_meta();

        if ( BKJA_State::TYPE_A === $state->get_type() && '' === $resolved_category ) {
            $resolved_category = 'job';
        }

        if ( $is_followup_action && empty( $context['job_title'] ) ) {
            return self::ensure_context_meta( self::build_response_payload(
                'این عنوان را دقیق پیدا نکردم. لطفاً یک نام نزدیک‌تر یا کوتاه‌تر بنویس.',
                array(),
                $message,
                false,
                'followup_missing_context',
                array(
                    'model'                 => $model,
                    'category'              => $resolved_category,
                    'job_title'             => '',
                    'job_slug'              => '',
                    'job_title_id'          => null,
                    'group_key'             => '',
                    'clarification_options' => array(),
                    'suggestions'           => array(),
                )
            ), $context );
        }

        if ( ! $is_followup_action && empty( $context['job_title'] ) && ! empty( $context['candidates'] ) && ! $is_followup_only ) {
            $filtered_candidates   = self::filter_closest_candidates( $normalized_message, $context['candidates'] );
            $context['candidates'] = $filtered_candidates;

            if ( empty( $filtered_candidates ) ) {
                return self::ensure_context_meta( self::build_response_payload(
                    'این عنوان را دقیق پیدا نکردم. لطفاً یک نام نزدیک‌تر یا کوتاه‌تر بنویس.',
                    array(),
                    $message,
                    false,
                    'clarification_empty',
                    array(
                        'model'                 => $model,
                        'category'              => $resolved_category,
                        'job_title'             => '',
                        'job_slug'              => '',
                        'job_title_id'          => null,
                        'group_key'             => '',
                        'clarification_options' => array(),
                        'suggestions'           => array(),
                    )
                ), $context );
            }

            $context['needs_clarification'] = true;
            return self::ensure_context_meta( self::build_response_payload(
                'چند مورد نزدیک پیدا کردم. لطفاً یکی را انتخاب کن یا نام دقیق‌تر بنویس.',
                $context,
                $message,
                false,
                'clarification',
                array(
                    'model'                 => $model,
                    'category'              => $resolved_category,
                    'clarification_options' => $filtered_candidates,
                    'suggestions'           => array(),
                )
            ), $context );
        }

        if ( $is_followup_action ) {
            $context['candidates']            = array();
            $context['needs_clarification']   = false;
            $context['resolved_confidence']   = isset( $context['resolved_confidence'] ) ? $context['resolved_confidence'] : null;
            $context['clarification_options'] = array();

            return self::ensure_context_meta( self::handle_followup_action( $normalized_action, $context, $message, $resolved_category, $model, $normalized_message, $request_meta ), $context );
        }
        if ( ! empty( $context['primary_job_title_id'] )
            && empty( $context['needs_clarification'] )
            && empty( $context['ambiguous'] )
            && ! $is_followup_action ) {
            self::store_last_job_context( (int) $context['primary_job_title_id'], $args['session_id'], (int) $args['user_id'] );
        }

        if ( 'JOB_COMPARE' === $intent_label || self::is_compare_similar_intent( $normalized_message ) ) {
            $compare_payload = self::handle_compare_similar_jobs( $context, $message, $resolved_category, $model );
            if ( is_array( $compare_payload ) ) {
                return $compare_payload;
            }
        }

        $api_key = self::get_api_key();

        if ( 'HIGH_INCOME_QUERY' === $intent_label ) {
            $top_items = class_exists( 'BKJA_Database' ) ? BKJA_Database::get_top_income_jobs( 6, 2 ) : array();
            $guided_answer = self::build_high_income_response( $top_items );

            return self::ensure_context_meta( self::build_response_payload(
                $guided_answer,
                $context,
                $message,
                false,
                'guided_high_income',
                array(
                    'model'    => $model,
                    'category' => $resolved_category,
                    'intent_label' => $intent_label,
                )
            ), $context );
        }

        if ( in_array( $intent_label, array( 'CAREER_SUGGESTION', 'GENERAL_BUSINESS_QUERY', 'LOW_CAPITAL_QUERY' ), true )
            && empty( $context['job_title'] ) ) {
            $filters = array();
            if ( 'GENERAL_BUSINESS_QUERY' === $intent_label
                || ! empty( $alias_data['tags'] )
                || preg_match( '/خانگی|در\s*خانه|کار\s*در\s*خانه|کار\s*خانگی/u', $normalized_message ) ) {
                if ( in_array( 'home', $alias_data['tags'] ?? array(), true )
                    || preg_match( '/خانگی|در\s*خانه|کار\s*در\s*خانه|کار\s*خانگی/u', $normalized_message ) ) {
                    $filters['home'] = true;
                }
            }

            $budget = self::extract_budget_from_message( $normalized_message );
            if ( ! empty( $budget['max'] ) ) {
                $filters['investment_max'] = (int) $budget['max'];
            } elseif ( 'LOW_CAPITAL_QUERY' === $intent_label ) {
                $filters['investment_max'] = 50000000;
            }

            $items = self::get_job_list_candidates( $filters, 6 );
            if ( empty( $items ) ) {
                $fallbacks = self::get_safe_job_suggestions( 6 );
                foreach ( $fallbacks as $fallback ) {
                    $items[] = array( 'label' => $fallback['label'], 'tags' => array() );
                }
            }

            $reply = self::build_suggestion_response( $intent_label, $normalized_message, $items, $alias_data );
            if ( '' !== $reply ) {
                $payload = self::ensure_context_meta( self::build_response_payload(
                    $reply,
                    $context,
                    $message,
                    false,
                    'intent_suggestion',
                    array(
                        'model'         => $model,
                        'category'      => $resolved_category,
                        'intent_label'  => $intent_label,
                        'normalized_message' => $normalized_message,
                    )
                ), $context );

                if ( self::is_cache_enabled() ) {
                    $cache_key = self::build_cache_key( $normalized_message, $resolved_category, $model, '', self::detect_query_intent( $normalized_message, $context ) );
                    set_transient( $cache_key, $payload, self::get_cache_ttl( $model ) );
                }

                return $payload;
            }
        }

        $cache_enabled   = self::is_cache_enabled();
        if ( $is_followup_action ) {
            $cache_enabled = false;
        }
        $cache_job_title = '';
        if ( ! empty( $context['job_title'] ) ) {
            $cache_job_title = $context['job_title'];
        } elseif ( ! empty( $context['resolved_job_title'] ) ) {
            $cache_job_title = $context['resolved_job_title'];
        } elseif ( '' !== $job_title_hint ) {
            $cache_job_title = $job_title_hint;
        }

        $followup_only = $is_followup_only;
        if ( '' === $cache_job_title && $followup_only ) {
            $cache_enabled   = false;
            $cache_job_title = '__missing__';
        }

        $query_intent = self::detect_query_intent( $normalized_message, $context );

        $cache_key           = self::build_cache_key( $normalized_message, $resolved_category, $model, $cache_job_title, $query_intent );
        $legacy_cache_key    = '';
        if ( $cache_enabled && '' !== $cache_job_title ) {
            $legacy_cache_key = self::build_cache_key( $normalized_message, $resolved_category, $model, '', $query_intent );
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

                return self::ensure_context_meta( self::build_response_payload(
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
                ), $context );
            }
        }

        $resolved_intent = $query_intent;
        if ( 'fuzzy' === $resolved_intent && ! empty( $pre_intent ) && 'unknown' !== $pre_intent ) {
            $resolved_intent = $pre_intent;
        }

        if ( 'personality_advice' === $resolved_intent ) {
            $lines = array(
                'برای اینکه مشاوره دقیق‌تر بدهم، این موارد را کوتاه بگو:',
                '• علاقه‌مندی‌ها و کارهایی که از انجامشان انرژی می‌گیری',
                '• مهارت‌های فعلی و تجربه‌های قبلی',
                '• محدودیت‌ها (سرمایه، زمان، شهر)',
                '• ترجیح تو بین کار تیمی/مستقل یا خلاق/ساختاریافته',
                'بعد از این اطلاعات، چند مسیر شغلی مناسب پیشنهاد می‌دهم.',
                'این تحلیل بر اساس پاسخ‌های شماست، نه صرفاً داده آماری.',
            );

            $reply = implode( "\n", array_filter( array_map( 'trim', $lines ) ) );

            $payload = self::ensure_context_meta( self::build_response_payload(
                $reply,
                array(),
                $message,
                false,
                'personality_advice',
                array(
                    'model'              => $model,
                    'category'           => $resolved_category,
                    'normalized_message' => $normalized_message,
                )
            ), $context );

            if ( $cache_enabled ) {
                set_transient( $cache_key, $payload, self::get_cache_ttl( $model ) );
            }

            return $payload;
        }

        if ( 'learning_path' === $resolved_intent ) {
            $title = '';
            if ( ! empty( $context['job_title'] ) ) {
                $title = $context['job_title'];
            } elseif ( ! empty( $alias_data['titles'][0] ) ) {
                $title = $alias_data['titles'][0];
            }

            $label = $title ? 'برای ورود به «' . $title . '» معمولاً این مسیر جواب می‌دهد:' : 'برای ورود به هر شغل جدید معمولاً این مسیر جواب می‌دهد:';
            $lines = array(
                $label,
                '• شناخت بازار، نمونه‌کارها و سطح قیمت‌های رایج',
                '• یادگیری مهارت‌های پایه (دوره کوتاه + تمرین عملی)',
                '• ساخت نمونه‌کار کوچک و دریافت بازخورد',
                '• شروع با پروژه/سفارش کوچک و بهبود کیفیت',
                '• تثبیت قیمت‌گذاری و توسعه شبکه مشتری',
            );

            if ( '' === $title ) {
                $lines[] = 'اگر عنوان دقیق شغل را بگویی، مسیر دقیق‌تر می‌شود.';
            }

            $reply = implode( "\n", array_filter( array_map( 'trim', $lines ) ) );

            $payload = self::ensure_context_meta( self::build_response_payload(
                $reply,
                $context,
                $message,
                false,
                'learning_path',
                array(
                    'model'              => $model,
                    'category'           => $resolved_category,
                    'normalized_message' => $normalized_message,
                )
            ), $context );

            if ( $cache_enabled ) {
                set_transient( $cache_key, $payload, self::get_cache_ttl( $model ) );
            }

            return $payload;
        }

        if ( in_array( $resolved_intent, array( 'job_suggestion', 'home_business', 'capital_query' ), true ) && empty( $context['job_title'] ) ) {
            $filters = array();
            if ( 'home_business' === $resolved_intent || in_array( 'home', $alias_data['tags'] ?? array(), true ) ) {
                $filters['home'] = true;
            }

            $budget = self::extract_budget_from_message( $normalized_message );
            if ( ! empty( $budget['max'] ) ) {
                $filters['investment_max'] = (int) $budget['max'];
            } elseif ( in_array( 'low_capital', $alias_data['tags'] ?? array(), true ) ) {
                $filters['investment_max'] = 50000000;
            }

            $items = self::get_job_list_candidates( $filters, 7 );
            if ( empty( $items ) ) {
                $fallbacks = self::get_safe_job_suggestions( 6 );
                foreach ( $fallbacks as $fallback ) {
                    $items[] = array( 'label' => $fallback['label'], 'tags' => array() );
                }
            }

            $intro = 'چند پیشنهاد بر اساس داده‌های موجود و فیلترهای شما:';
            $reply = self::build_job_list_response(
                $items,
                $intro,
                'این پاسخ ترکیبی از تحلیل مشاوره‌ای و داده‌های ثبت‌شده کاربران است.'
            );
            if ( '' !== $reply ) {
                $payload = self::ensure_context_meta( self::build_response_payload(
                    $reply,
                    $context,
                    $message,
                    false,
                    'job_list',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'normalized_message' => $normalized_message,
                    )
                ), $context );

                if ( $cache_enabled ) {
                    set_transient( $cache_key, $payload, self::get_cache_ttl( $model ) );
                }

                return $payload;
            }
        }

        if ( in_array( $resolved_intent, array( 'income_query', 'capital_query' ), true ) && self::should_use_market_analysis( $context, $resolved_intent ) ) {
            $analysis_reply = self::build_market_analysis_reply( $resolved_intent, $context, $normalized_message, $alias_data );
            if ( '' !== $analysis_reply ) {
                $payload = self::ensure_context_meta( self::build_response_payload(
                    $analysis_reply,
                    $context,
                    $message,
                    false,
                    'market_analysis',
                    array(
                        'model'              => $model,
                        'category'           => $resolved_category,
                        'normalized_message' => $normalized_message,
                    )
                ), $context );

                if ( $cache_enabled ) {
                    set_transient( $cache_key, $payload, self::get_cache_ttl( $model ) );
                }

                return $payload;
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
                $fallback = self::ensure_context_meta( self::build_response_payload(
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
                ), $context );
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
                $fallback = self::ensure_context_meta( self::build_response_payload(
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
                ), $context );
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
                $legacy_key_to_clear = self::build_cache_key( $normalized_message, $resolved_category, $model, $cache_job_title, $query_intent );
                $cache_key           = self::build_cache_key( $normalized_message, $resolved_category, $model, $result_job_title, $query_intent );

                if ( $legacy_key_to_clear !== $cache_key ) {
                    delete_transient( $legacy_key_to_clear );
                }
            }

            set_transient( $cache_key, $result, self::get_cache_ttl( $model ) );
        }

        return self::ensure_context_meta( $result, $context );
    }

    protected static function ensure_context_meta( $payload, $context ) {
        if ( ! is_array( $payload ) ) {
            return $payload;
        }

        if ( ! isset( $payload['meta'] ) || ! is_array( $payload['meta'] ) ) {
            $payload['meta'] = array();
        }

        $context      = is_array( $context ) ? $context : array();
        $job_title    = isset( $context['job_title'] ) ? (string) $context['job_title'] : '';
        $job_title_id = null;
        if ( isset( $context['primary_job_title_id'] ) ) {
            $job_title_id = (int) $context['primary_job_title_id'];
        } elseif ( isset( $context['job_title_id'] ) ) {
            $job_title_id = (int) $context['job_title_id'];
        }

        $group_key = '';
        if ( isset( $context['group_key'] ) ) {
            $group_key = (string) $context['group_key'];
        } elseif ( isset( $context['job_group_key'] ) ) {
            $group_key = (string) $context['job_group_key'];
        }

        if ( '' !== $job_title && ( ! isset( $payload['meta']['job_title'] ) || '' === (string) $payload['meta']['job_title'] ) ) {
            $payload['meta']['job_title'] = $job_title;
        }

        if ( $job_title_id && ( ! isset( $payload['meta']['job_title_id'] ) || empty( $payload['meta']['job_title_id'] ) ) ) {
            $payload['meta']['job_title_id'] = $job_title_id;
        }

        if ( '' !== $group_key ) {
            if ( ! isset( $payload['meta']['group_key'] ) || '' === (string) $payload['meta']['group_key'] ) {
                $payload['meta']['group_key'] = $group_key;
            }
            if ( ! isset( $payload['meta']['job_group_key'] ) || '' === (string) $payload['meta']['job_group_key'] ) {
                $payload['meta']['job_group_key'] = $group_key;
            }
        }

        return $payload;
    }

}

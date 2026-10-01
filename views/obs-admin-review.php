<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OBS_Admin_Review {

    public function __construct() {

        // Registered from the main plugin's admin_menu later
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_obs_review_toggle_featured', [ $this, 'ajax_toggle_featured' ] );
        add_action( 'wp_ajax_obs_review_log_usage',      [ $this, 'ajax_log_usage' ] );
    }
    
    /**
     * Enqueue assets only on the admin review page.
     */
    public function enqueue( $hook ) {
        if ( strpos( $hook, 'obs-comments-review' ) === false ) return;

        $admin_css = OBS_COMMENT_MANAGER_PATH . 'assets/obs-review.css';

        wp_enqueue_style(
            'obs-review',
            OBS_COMMENT_MANAGER_URL . 'assets/obs-review.css',
            [],
            file_exists(  $admin_css ) ? filemtime(  $admin_css ) : OBS_COMMENT_MANAGER_VERSION,
        );

        $admin_js = OBS_COMMENT_MANAGER_PATH . 'assets/obs-review.js';
        
        wp_enqueue_script(
            'obs-review',
            OBS_COMMENT_MANAGER_URL . 'assets/obs-review.js',
            [ 'jquery' ],
            file_exists( $admin_js ) ? filemtime( $admin_js ) : OBS_COMMENT_MANAGER_VERSION,
            true
        );
    }

    /**
     * The admin page renderer.
     */
    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // --- Read filters from the URL ---
        $filter  = isset( $_GET['filter'] )  ? sanitize_key( wp_unslash( $_GET['filter'] ) )  : 'all';
        $tag     = isset( $_GET['tag'] )     ? sanitize_text_field( wp_unslash( $_GET['tag'] ) )     : '';
        $country = isset( $_GET['country'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['country'] ) ) ) : '';
        $order   = isset( $_GET['order'] )   ? sanitize_key( wp_unslash( $_GET['order'] ) )   : 'random';
        $count   = isset( $_GET['count'] )   ? max( 1, min( 500, intval( $_GET['count'] ) ) ) : 50;

        global $wpdb;
        $table = $wpdb->prefix . 'obs_comments';

        $where  = 'WHERE deleted_at IS NULL';
        $params = [];

        if ( $filter === 'featured' ) $where .= ' AND featured = 1';
        if ( $filter === 'unused'   ) $where .= ' AND usage_count = 0';

        if ( $tag ) {
            $where   .= ' AND FIND_IN_SET(%s, tags)';
            $params[] = $tag;
        }
        if ( $country ) {
            $where   .= ' AND country = %s';
            $params[] = $country;
        }

        switch ( $order ) {
            case 'newest': $order_sql = 'source_date DESC, created_at DESC'; break;
            case 'oldest': $order_sql = 'source_date ASC, created_at ASC';   break;
            case 'random':
            default:       $order_sql = 'RAND()'; break;
        }

        $params[] = $count;

        $sql  = "SELECT id, comment_text, author_name, country, tags, usage_count, featured
                 FROM $table $where
                 ORDER BY $order_sql
                 LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        // --- Build payload for JS ---
        $payload = array_map( function( $r ) {
            return [
                'id'          => (int) $r->id,
                'text'        => $r->comment_text,
                'author'      => $r->author_name,
                'country'     => $r->country,
                'countryName' => $r->country ? obs_country_name( $r->country ) : '',
                'tags'        => $r->tags,
                'uses'        => (int) $r->usage_count,
                'featured'    => (int) $r->featured,
            ];
        }, $rows );

        // --- Available filter options ---
        $all_tags      = $wpdb->get_col( "SELECT DISTINCT tags FROM $table WHERE tags != '' AND deleted_at IS NULL" );
        $tag_list      = [];
        foreach ( $all_tags as $t ) {
            foreach ( explode( ',', $t ) as $one ) {
                $one = trim( $one );
                if ( $one ) $tag_list[ $one ] = true;
            }
        }
        $tag_list = array_keys( $tag_list );
        sort( $tag_list );

        $country_list = $wpdb->get_col( "SELECT DISTINCT country FROM $table WHERE country != '' AND deleted_at IS NULL ORDER BY country" );

        $places = $this->get_places();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Review Comments</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments' ) ); ?>" class="page-title-action">All Comments</a>
            <hr class="wp-header-end">

            <form method="get" class="obs-review-filters" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="page" value="obs-comments-review">

                <label>
                    Show:
                    <select name="filter">
                        <option value="all"      <?php selected( $filter, 'all' ); ?>>All</option>
                        <option value="featured" <?php selected( $filter, 'featured' ); ?>>Featured only</option>
                        <option value="unused"   <?php selected( $filter, 'unused' ); ?>>Never used</option>
                    </select>
                </label>

                <label>
                    Order:
                    <select name="order">
                        <option value="random" <?php selected( $order, 'random' ); ?>>Random</option>
                        <option value="newest" <?php selected( $order, 'newest' ); ?>>Newest received</option>
                        <option value="oldest" <?php selected( $order, 'oldest' ); ?>>Oldest received</option>
                    </select>
                </label>

                <label>
                    Tag:
                    <select name="tag">
                        <option value="">Any</option>
                        <?php foreach ( $tag_list as $t ) : ?>
                            <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>><?php echo esc_html( $t ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Country:
                    <select name="country">
                        <option value="">Any</option>
                        <?php foreach ( $country_list as $c ) : ?>
                            <option value="<?php echo esc_attr( $c ); ?>" <?php selected( $country, $c ); ?>><?php echo esc_html( obs_country_name( $c ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Queue size:
                    <input type="number" name="count" value="<?php echo (int) $count; ?>" min="1" max="500" style="width:70px;">
                </label>

                <button class="button">Apply</button>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-review' ) ); ?>">Reset</a>
            </form>

            <?php if ( empty( $rows ) ) : ?>
                <div class="notice notice-warning"><p>No comments match the current filters.</p></div>
            <?php else : ?>

            <div class="obs-review" data-mode="review">
                <div class="obs-review-header">
                    <span class="obs-review-counter">Comment <strong class="obs-review-current">1</strong> of <strong class="obs-review-total"><?php echo count( $rows ); ?></strong></span>
                    <span class="obs-review-actions-top">
                        <button type="button" class="obs-review-btn obs-review-feature-btn" title="Toggle featured">
                            <span class="obs-star">★</span> <span class="obs-review-feature-label">Feature</span>
                        </button>
                        <a class="obs-review-btn" target="_blank" rel="noopener"
                           href="#" id="obs-review-edit-link" style="margin-left:8px;text-decoration:none;">Edit in admin ↗</a>
                    </span>
                </div>

                <div class="obs-review-card">
                    <blockquote class="obs-review-quote"></blockquote>
                    <div class="obs-review-meta">
                        <span class="obs-review-author"></span>
                        <span class="obs-review-country"></span>
                        <span class="obs-review-usage"></span>
                    </div>
                    <div class="obs-review-tags"></div>
                </div>

                <div class="obs-review-nav">
                    <button type="button" class="obs-review-btn obs-review-prev">← Prev</button>
                    <button type="button" class="obs-review-btn obs-review-random">🎲 Random</button>
                    <button type="button" class="obs-review-btn obs-review-next">Next →</button>
                </div>

                <div class="obs-review-log" style="display:none;">
                    <hr>
                    <h4>Log usage</h4>
                    <p>
                        <select class="obs-review-place" style="min-width:220px;">
                            <option value="__new__">— New place —</option>
                            <?php foreach ( $places as $p ) : ?>
                                <option value="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="obs-review-place-new" placeholder="New place name" style="display:none;min-width:220px;">
                        <select class="obs-review-month">
                            <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == (int) date( 'n' ) ? 'selected' : ''; ?>><?php echo date( 'F', mktime( 0, 0, 0, $m, 1 ) ); ?></option>
                            <?php endfor; ?>
                        </select>
                        <input type="number" class="obs-review-year" value="<?php echo (int) date( 'Y' ); ?>" style="width:80px;">
                    </p>
                    <p>
                        <label><input type="checkbox" class="obs-review-rec" value="students"> Students</label>
                        <label style="margin-left:10px;"><input type="checkbox" class="obs-review-rec" value="supporters"> Supporters</label>
                        <label style="margin-left:10px;"><input type="checkbox" class="obs-review-rec" value="general"> General</label>
                    </p>
                    <p>
                        <button type="button" class="button button-primary obs-review-log-save">Mark as used</button>
                        <span class="obs-review-log-msg" style="margin-left:10px;"></span>
                    </p>
                </div>
            </div>

            <?php endif; ?>
        </div>

        <?php
        // Pass data via localize — same key the JS already reads
        wp_localize_script( 'obs-review', 'obsReview', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'obs_review_nonce' ),
            'mode'       => 'review',
            'isAdmin'    => true,
            'editUrlBase'=> admin_url( 'admin.php?page=obs-comments-new&id=' ),
            'comments'   => $payload,
        ] );
    }

    private function get_places() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT place_name FROM " . $wpdb->prefix . 'obs_usage' . "
             WHERE place_name != '' ORDER BY place_name ASC"
        );
    }

    // ---------- AJAX handlers (same as before) ----------

    public function ajax_toggle_featured() {
        check_ajax_referer( 'obs_review_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );

        global $wpdb;
        $table   = $wpdb->prefix . 'obs_comments';
        $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT featured FROM $table WHERE id = %d", $id ) );
        $new     = $current ? 0 : 1;
        $wpdb->update( $table, [
            'featured'   => $new,
            'updated_at' => current_time( 'mysql' ),
        ], [ 'id' => $id ] );

        wp_send_json_success( [ 'featured' => $new ] );
    }

    public function ajax_log_usage() {
        check_ajax_referer( 'obs_review_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $id         = intval( $_POST['id'] ?? 0 );
        $place      = sanitize_text_field( wp_unslash( $_POST['place'] ?? '' ) );
        $month      = intval( $_POST['month'] ?? 0 );
        $year       = intval( $_POST['year'] ?? 0 );
        $recipients = isset( $_POST['recipients'] ) && is_array( $_POST['recipients'] )
            ? implode( ',', array_map( 'sanitize_text_field', wp_unslash( $_POST['recipients'] ) ) )
            : '';
        if ( ! $id || ! $place ) wp_send_json_error( 'Missing data' );

        $place = str_replace( [ "\\'", '\\\\' ], [ "'", '\\' ], $place );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'obs_usage', [
            'comment_id' => $id,
            'place_name' => $place,
            'month'      => $month ?: null,
            'year'       => $year  ?: null,
            'recipients' => $recipients,
            'note'       => '',
            'created_at' => current_time( 'mysql' ),
        ] );

        // Refresh usage_count
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . $wpdb->prefix . 'obs_usage' . " WHERE comment_id = %d", $id
        ) );
        $wpdb->update( $wpdb->prefix . 'obs_comments', [ 'usage_count' => $count ], [ 'id' => $id ] );

        wp_send_json_success( [ 'uses' => $count ] );
    }
}
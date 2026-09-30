<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OBS_Review {

    public function __construct() {
        add_shortcode( 'obs_review', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );

        // AJAX: toggle featured
        add_action( 'wp_ajax_obs_review_toggle_featured', [ $this, 'ajax_toggle_featured' ] );
        // AJAX: log usage
        add_action( 'wp_ajax_obs_review_log_usage',      [ $this, 'ajax_log_usage' ] );
    }

    public function assets() {
        wp_register_style( 'obs-review', plugin_dir_url( __FILE__ ) . 'obs-review.css', [], '1.0.0' );
        wp_register_script( 'obs-review', plugin_dir_url( __FILE__ ) . 'obs-review.js', [ 'jquery' ], '1.0.0', true );
    }

    public function render( $atts ) {
        $atts = shortcode_atts( [
            'mode'         => 'review',   // 'review' (admin tools) or 'view' (read-only)
            'filter'       => 'all',      // all | featured | unused
            'tag'          => '',
            'country'      => '',
            'count'        => 50,         // max queue size
            'order'        => 'random',   // random | newest | oldest
        ], $atts, 'obs_review' );

        if ( $atts['mode'] === 'review' && ! current_user_can( 'manage_options' ) ) {
            return '<!-- OBS Review: admin only -->';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'obs_comments';

        $where  = 'WHERE deleted_at IS NULL';
        $params = [];

        if ( $atts['filter'] === 'featured' ) $where .= ' AND featured = 1';
        if ( $atts['filter'] === 'unused'   ) $where .= ' AND usage_count = 0';

        if ( ! empty( $atts['tag'] ) ) {
            $where   .= ' AND FIND_IN_SET(%s, tags)';
            $params[] = sanitize_text_field( $atts['tag'] );
        }
        if ( ! empty( $atts['country'] ) ) {
            $where   .= ' AND country = %s';
            $params[] = strtoupper( sanitize_text_field( $atts['country'] ) );
        }

        switch ( $atts['order'] ) {
            case 'newest': $order_sql = 'source_date DESC, created_at DESC'; break;
            case 'oldest': $order_sql = 'source_date ASC, created_at ASC';   break;
            case 'random':
            default:       $order_sql = 'RAND()'; break;
        }

        $count    = max( 1, min( 500, intval( $atts['count'] ) ) );
        $params[] = $count;

        $sql  = "SELECT id, comment_text, author_name, country, tags, usage_count, featured
                 FROM $table $where
                 ORDER BY $order_sql
                 LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        if ( empty( $rows ) ) return '<!-- OBS Review: no comments -->';

        wp_enqueue_style( 'obs-review' );
        wp_enqueue_script( 'obs-review' );

        wp_localize_script( 'obs-review', 'obsReview', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'obs_review_nonce' ),
            'mode'    => $atts['mode'],
            'isAdmin' => current_user_can( 'manage_options' ),
            'places'  => $this->get_places(),
        ] );

        ob_start();
        ?>
        <div class="obs-review" data-mode="<?php echo esc_attr( $atts['mode'] ); ?>">
            <div class="obs-review-header">
                <span class="obs-review-counter">Comment <strong class="obs-review-current">1</strong> of <strong class="obs-review-total"><?php echo count( $rows ); ?></strong></span>
                <span class="obs-review-actions-top">
                    <?php if ( $atts['mode'] === 'review' ) : ?>
                        <button type="button" class="obs-review-btn obs-review-feature-btn" title="Toggle featured">
                            <span class="obs-star">★</span> <span class="obs-review-feature-label">Feature</span>
                        </button>
                    <?php endif; ?>
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

            <?php if ( $atts['mode'] === 'review' ) : ?>
                <div class="obs-review-log" style="display:none;">
                    <hr>
                    <h4>Log usage</h4>
                    <p>
                        <select class="obs-review-place" style="min-width:200px;">
                            <option value="__new__">— New place —</option>
                            <?php foreach ( $this->get_places() as $p ) : ?>
                                <option value="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="obs-review-place-new" placeholder="New place name" style="display:none;min-width:200px;">
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

                    <p><button type="button" class="button button-primary obs-review-log-save">Mark as used</button>
                       <span class="obs-review-log-msg" style="margin-left:10px;"></span></p>
                </div>
                <?php endif; ?>

                <script type="application/json" class="obs-review-data">
                    <?php echo wp_json_encode( array_map( function( $r ) {
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
                    }, $rows ) ); ?>
                </script>
            </div>
            <?php
        return ob_get_clean();
    }

    private function get_places() {
        global $wpdb;
        return $wpdb->get_col( "SELECT DISTINCT place_name FROM " . $wpdb->prefix . 'obs_usage' . " WHERE place_name != '' ORDER BY place_name ASC" );
    }

    // --- AJAX ---

    public function ajax_toggle_featured() {
        check_ajax_referer( 'obs_review_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Missing ID' );

        global $wpdb;
        $table   = $wpdb->prefix . 'obs_comments';
        $current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT featured FROM $table WHERE id = %d", $id ) );
        $new     = $current ? 0 : 1;
        $wpdb->update( $table, [ 'featured' => $new, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        wp_send_json_success( [ 'featured' => $new ] );
    }

    public function ajax_log_usage() {
        check_ajax_referer( 'obs_review_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );

        $id         = intval( $_POST['id'] ?? 0 );
        $place      = sanitize_text_field( $_POST['place'] ?? '' );
        $month      = intval( $_POST['month'] ?? 0 );
        $year       = intval( $_POST['year'] ?? 0 );
        $recipients = isset( $_POST['recipients'] ) && is_array( $_POST['recipients'] )
            ? implode( ',', array_map( 'sanitize_text_field', $_POST['recipients'] ) )
            : '';
        if ( ! $id || ! $place ) wp_send_json_error( 'Missing data' );

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

new OBS_Review();
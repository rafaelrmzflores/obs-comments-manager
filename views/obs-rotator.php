<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Random rotating comments shortcode: [obs_rotator]
 */
class OBS_Rotator {

    private static $instance_count = 0;

    public function __construct() {
        add_shortcode( 'obs_rotator', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    public function register_assets() {

        $css_path = OBS_COMMENT_MANAGER_PATH . 'assets/obs-rotator.css';

        wp_register_style(
            'obs-rotator',
            OBS_COMMENT_MANAGER_URL . 'assets/obs-rotator.css',
            [],
            file_exists(  $css_path ) ? filemtime(  $css_path ) : OBS_COMMENT_MANAGER_VERSION,
        );

        $js_path = OBS_COMMENT_MANAGER_PATH . 'assets/obs-rotator.js';
        
        wp_register_script(
            'obs-rotator',
            OBS_COMMENT_MANAGER_URL . 'assets/obs-rotator.js',
            [ 'jquery' ],
            file_exists( $js_path ) ? filemtime( $js_path ) : OBS_COMMENT_MANAGER_VERSION,
            true
        );

    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( [
            'ids'          => '',
            'count'        => 20,
            'interval'     => 6,
            'tag'          => '',
            'country'      => '',
            'unused'       => 'no',
            'min_uses'     => '',
            'max_uses'     => '',
            'featured'     => '',   // NEW
            'show_author'  => 'yes',
            'show_country' => 'no',
            'transition'   => 'fade',
            'class'        => '',
        ], $atts, 'obs_rotator' );

        $comments = $this->fetch_comments( $atts );
        if ( empty( $comments ) ) {
            return '<!-- OBS Rotator: no matching comments -->';
        }

        self::$instance_count++;
        $id = 'obs-rotator-' . self::$instance_count;

        wp_enqueue_script( 'obs-rotator' );
        wp_enqueue_style( 'obs-rotator' );

        $show_author  = $atts['show_author']  === 'yes';
        $show_country = $atts['show_country'] === 'yes';
        $transition   = in_array( $atts['transition'], [ 'fade', 'slide', 'none' ], true ) ? $atts['transition'] : 'fade';
        $interval_ms  = max( 0, intval( $atts['interval'] ) ) * 1000;
        $extra_class  = sanitize_html_class( $atts['class'] );

        ob_start();
        ?>
        <div class="obs-rotator <?php echo esc_attr( $extra_class ); ?>"
             id="<?php echo esc_attr( $id ); ?>"
             data-interval="<?php echo esc_attr( $interval_ms ); ?>"
             data-transition="<?php echo esc_attr( $transition ); ?>">

            <div class="obs-rotator-stage">
                <?php foreach ( $comments as $i => $c ) : ?>
                    <blockquote class="obs-rotator-item<?php echo $i === 0 ? ' is-active' : ''; ?>">
                        <p class="obs-rotator-quote"><?php echo esc_html( $c->comment_text ); ?></p>
                        <?php if ( $show_author || $show_country ) : ?>
                            <footer class="obs-rotator-meta">
                                <?php if ( $show_author && $c->author_name ) : ?>
                                    <span class="obs-rotator-author">— <?php echo esc_html( $c->author_name ); ?></span>
                                <?php endif; ?>
                                <?php if ( $show_country && $c->country ) : ?>
                                    <span class="obs-rotator-country"><?php echo esc_html( obs_country_name( $c->country ) ); ?></span>
                                <?php endif; ?>
                            </footer>
                        <?php endif; ?>
                    </blockquote>
                <?php endforeach; ?>
            </div>

            <?php if ( count( $comments ) > 1 && $interval_ms > 0 ) : ?>
                <div class="obs-rotator-dots" aria-hidden="true">
                    <?php foreach ( $comments as $i => $c ) : ?>
                        <button type="button" class="obs-rotator-dot<?php echo $i === 0 ? ' is-active' : ''; ?>" data-index="<?php echo $i; ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function fetch_comments( $atts ) {
        global $wpdb;
        $table = $wpdb->prefix . 'obs_comments';

        $count = max( 1, min( 100, intval( $atts['count'] ) ) );

        $where  = 'WHERE deleted_at IS NULL';
        $params = [];

        // if ( isset( $atts['featured'] ) && $atts['featured'] === 'yes' ) {
        //     $where .= ' AND featured = 1';
        // }

      
       switch ( $atts['featured'] ) {
            case 'yes':
                $where .= ' AND featured = 1';
                break;
            case 'no':
                $where .= ' AND featured = 0';
                break;
        }


        if ( ! empty( $atts['tag'] ) ) {
            $where   .= ' AND FIND_IN_SET(%s, tags)';
            $params[] = sanitize_text_field( $atts['tag'] );
        }

        if ( ! empty( $atts['country'] ) ) {
            $where   .= ' AND country = %s';
            $params[] = strtoupper( sanitize_text_field( $atts['country'] ) );
        }

        if ( $atts['unused'] === 'yes' ) {
            $where .= ' AND usage_count = 0';
        }

        if ( $atts['min_uses'] !== '' ) {
            $where   .= ' AND usage_count >= %d';
            $params[] = intval( $atts['min_uses'] );
        }

        if ( $atts['max_uses'] !== '' ) {
            $where   .= ' AND usage_count <= %d';
            $params[] = intval( $atts['max_uses'] );
        }

        // Prefer comments with a real author name (cosmetic)
        $sql = "SELECT id, comment_text, author_name, country, usage_count
                FROM $table
                $where
                ORDER BY RAND()
                LIMIT %d";
        $params[] = $count;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }
}
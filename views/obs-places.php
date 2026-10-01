<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OBS_Places {

    public function __construct() {
        add_action( 'admin_post_obs_rename_place', [ $this, 'handle_rename' ] );
        add_action( 'admin_post_obs_delete_place', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_obs_merge_places', [ $this, 'handle_merge' ] );
    }

    public function page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        global $wpdb;
        $usage_table = $wpdb->prefix . 'obs_usage';

        $rows = $wpdb->get_results(
            "SELECT place_name,
                    COUNT(*) AS total,
                    MIN(CONCAT(year, '-', LPAD(month, 2, '0'))) AS first_use,
                    MAX(CONCAT(year, '-', LPAD(month, 2, '0'))) AS last_use,
                    GROUP_CONCAT(DISTINCT recipients ORDER BY recipients SEPARATOR ', ') AS all_recipients
             FROM $usage_table
             WHERE place_name != ''
             GROUP BY place_name
             ORDER BY total DESC, place_name ASC"
        );

        $total_uses = 0;
        foreach ( $rows as $r ) $total_uses += (int) $r->total;
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Places</h1>
            <p class="description">Every distinct "where used" place across all comments. Rename, delete, or merge.</p>

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                <?php
                switch ( $_GET['msg'] ) {
                    case 'renamed': echo 'Place renamed across all usages.'; break;
                    case 'deleted': echo 'All usages for that place were deleted.'; break;
                    case 'merged':  echo 'Places merged successfully.'; break;
                }
                ?>
                </p></div>
            <?php endif; ?>

            <h2 style="margin-top:32px;">Merge two places</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                class="obs-merge-form">
                <input type="hidden" name="action" value="obs_merge_places">
                <?php wp_nonce_field( 'obs_merge_places' ); ?>

                <div class="obs-merge-row">
                    <div class="obs-merge-field">
                        <label for="obs-merge-source">Merge this place</label>
                        <select name="source" id="obs-merge-source" required>
                            <option value="">— Select a place —</option>
                            <?php foreach ( $rows as $r ) : ?>
                                <option value="<?php echo esc_attr( $r->place_name ); ?>">
                                    <?php echo esc_html( $r->place_name ); ?> (<?php echo (int) $r->total; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="obs-merge-arrow" aria-hidden="true">→</div>

                    <div class="obs-merge-field">
                        <label for="obs-merge-target">Into this place</label>
                        <select name="target" id="obs-merge-target" required>
                            <option value="">— Select a place —</option>
                            <?php foreach ( $rows as $r ) : ?>
                                <option value="<?php echo esc_attr( $r->place_name ); ?>">
                                    <?php echo esc_html( $r->place_name ); ?> (<?php echo (int) $r->total; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="obs-merge-submit">
                        <button class="button button-primary">Merge</button>
                    </div>
                </div>

                <p class="obs-merge-hint">
                    All usages of the first place will be reassigned to the second, then the first is removed.
                </p>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Place</th>
                        <th style="width:90px;">Uses</th>
                        <th style="width:130px;">First use</th>
                        <th style="width:130px;">Last use</th>
                        <th style="width:220px;">Recipients</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="6">No usage entries yet.</td></tr>
                <?php else : foreach ( $rows as $r ) :
                    $first = $r->first_use && $r->first_use !== '-' ? date( 'M Y', strtotime( $r->first_use . '-01' ) ) : '—';
                    $last  = $r->last_use  && $r->last_use  !== '-' ? date( 'M Y', strtotime( $r->last_use  . '-01' ) ) : '—';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $r->place_name ); ?></strong></td>
                        <td><?php echo (int) $r->total; ?></td>
                        <td><?php echo esc_html( $first ); ?></td>
                        <td><?php echo esc_html( $last ); ?></td>
                        <td style="font-size:12px;color:#555;"><?php echo esc_html( $r->all_recipients ); ?></td>
                        <td>
                            <a href="#" class="button button-small obs-rename-place"
                               data-place="<?php echo esc_attr( $r->place_name ); ?>">Rename</a>
                            <a href="<?php echo esc_url( wp_nonce_url(
                                admin_url( 'admin-post.php?action=obs_delete_place&place=' . urlencode( $r->place_name ) ),
                                'obs_delete_place_' . md5( $r->place_name )
                            ) ); ?>"
                               class="button button-small"
                               onclick="return confirm('Delete all <?php echo (int) $r->total; ?> usages of this place? This cannot be undone.');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <p style="margin-top:16px;color:#666;">
                Total usages: <strong><?php echo (int) $total_uses; ?></strong> across <strong><?php echo count( $rows ); ?></strong> places.
            </p>
        </div>

        <!-- Rename modal -->
        <div id="obs-rename-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;">
            <div style="background:#fff;max-width:480px;margin:100px auto;padding:20px;border-radius:6px;">
                <h2>Rename place</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="obs_rename_place">
                    <input type="hidden" name="old_name" id="obs-rename-old" value="">
                    <?php wp_nonce_field( 'obs_rename_place' ); ?>
                    <p><label>New name</label><br>
                        <input type="text" name="new_name" id="obs-rename-new" class="widefat" required>
                    </p>
                    <p class="description">Every usage row with the old name will be updated.</p>
                    <p>
                        <button class="button button-primary">Rename</button>
                        <button type="button" class="button" onclick="document.getElementById('obs-rename-modal').style.display='none';">Cancel</button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        jQuery(function($){
            $(document).on('click', '.obs-rename-place', function(e){
                e.preventDefault();
                var p = $(this).data('place');
                $('#obs-rename-old').val(p);
                $('#obs-rename-new').val(p).focus().select();
                $('#obs-rename-modal').show();
            });
        });
        </script>
        
        <?php
    }

    public function handle_rename() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_rename_place' );

        $old = sanitize_text_field( wp_unslash( $_POST['old_name'] ?? '' ) );
        $new = sanitize_text_field( wp_unslash( $_POST['new_name'] ?? '' ) );
        if ( ! $old || ! $new ) {
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-places' ) );
            exit;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'obs_usage',
            [ 'place_name' => $new ],
            [ 'place_name' => $old ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-places&msg=renamed' ) );
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $place = sanitize_text_field( wp_unslash( $_GET['place'] ?? '' ) );
        check_admin_referer( 'obs_delete_place_' . md5( $place ) );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'obs_usage', [ 'place_name' => $place ] );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-places&msg=deleted' ) );
        exit;
    }

    public function handle_merge() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_merge_places' );

        $source = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
        $target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
        if ( ! $source || ! $target || $source === $target ) {
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-places' ) );
            exit;
        }

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'obs_usage',
            [ 'place_name' => $target ],
            [ 'place_name' => $source ]
        );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-places&msg=merged' ) );
        exit;
    }
}
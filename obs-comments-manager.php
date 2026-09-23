<?php
/**
 * Plugin Name: OBS Comments Manager
 * Description: Store, tag, search, and track usage of comments received via email for newsletters and fundraising letters.
 * Version: 2.3.0
 * Author: Custom
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'obs-countries.php';
require_once plugin_dir_path( __FILE__ ) . 'obs-recipients.php';

class OBS_Comments_Manager {

    const DB_VERSION  = '2.3';
    const TABLE       = 'obs_comments';
    const USAGE_TABLE = 'obs_usage';

    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'plugins_loaded',        [ $this, 'maybe_upgrade' ] );
        add_action( 'admin_menu',            [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        // Comment handlers
        add_action( 'admin_post_obs_save_comment',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_obs_delete_comment', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_obs_restore',        [ $this, 'handle_restore' ] );
        add_action( 'admin_post_obs_purge',          [ $this, 'handle_purge' ] );
        add_action( 'admin_post_obs_empty_trash',    [ $this, 'handle_empty_trash' ] );
        add_action( 'admin_post_obs_export_csv',     [ $this, 'handle_export' ] );
        add_action( 'admin_post_obs_bulk',           [ $this, 'handle_bulk' ] );
        add_action( 'admin_post_obs_rename_tag',     [ $this, 'handle_rename_tag' ] );
        add_action( 'admin_post_obs_import',         [ $this, 'handle_import' ] );

        // Structured usage handlers
        add_action( 'admin_post_obs_log_usage',      [ $this, 'handle_log_usage' ] );      // create entry
        add_action( 'admin_post_obs_update_usage',   [ $this, 'handle_update_usage' ] );   // edit entry
        add_action( 'admin_post_obs_delete_usage',   [ $this, 'handle_delete_usage' ] );

        // Recipient management
        add_action( 'admin_post_obs_save_recipients',[ $this, 'handle_save_recipients' ] );

        // Dashboard
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
    }

    /* ============================================================
     * DATABASE
     * ============================================================ */

    public function activate() {
        $this->create_or_upgrade_tables();
        obs_get_recipients(); // seed defaults if missing
        update_option( 'obs_db_version', self::DB_VERSION );
    }

    public function maybe_upgrade() {
        if ( get_option( 'obs_db_version' ) !== self::DB_VERSION ) {
            $this->create_or_upgrade_tables();
            obs_get_recipients();
            update_option( 'obs_db_version', self::DB_VERSION );
        }
    }

    private function create_or_upgrade_tables() {
        global $wpdb;
        $charset  = $wpdb->get_charset_collate();
        $comments = $wpdb->prefix . self::TABLE;
        $usage    = $wpdb->prefix . self::USAGE_TABLE;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Comments table (unchanged shape; usage_log kept for legacy data)
        dbDelta( "CREATE TABLE $comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_text LONGTEXT NOT NULL,
            author_name VARCHAR(191) DEFAULT '',
            author_email VARCHAR(191) DEFAULT '',
            country CHAR(2) DEFAULT '',
            source_date DATE DEFAULT NULL,
            tags VARCHAR(500) DEFAULT '',
            usage_count INT UNSIGNED DEFAULT 0,
            usage_log LONGTEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY tags (tags(100)),
            KEY usage_count (usage_count),
            KEY created_at (created_at),
            KEY deleted_at (deleted_at),
            KEY country (country)
        ) $charset;" );

        // NEW: structured usage entries
        dbDelta( "CREATE TABLE $usage (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_id BIGINT UNSIGNED NOT NULL,
            place_name VARCHAR(191) NOT NULL DEFAULT '',
            month TINYINT UNSIGNED DEFAULT NULL,
            year SMALLINT UNSIGNED DEFAULT NULL,
            recipients VARCHAR(500) DEFAULT '',
            note TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY comment_id (comment_id),
            KEY place_name (place_name(100)),
            KEY idx_year_month (year, month)
        ) $charset;" );
    }

    /* ============================================================
     * ADMIN MENU & ASSETS
     * ============================================================ */

    public function admin_menu() {
        add_menu_page(
            'OBS Comments', 'OBS Comments', 'manage_options',
            'obs-comments', [ $this, 'page_list' ], 'dashicons-format-quote', 25
        );
        add_submenu_page( 'obs-comments', 'Add New Comment', 'Add New', 'manage_options', 'obs-comments-new', [ $this, 'page_edit' ] );
        add_submenu_page( 'obs-comments', 'Import from CSV', 'Import CSV', 'manage_options', 'obs-comments-import', [ $this, 'page_import' ] );
        add_submenu_page( 'obs-comments', 'Manage Tags', 'Tags', 'manage_options', 'obs-comments-tags', [ $this, 'page_tags' ] );
        add_submenu_page( 'obs-comments', 'Recipient Groups', 'Recipients', 'manage_options', 'obs-comments-recipients', [ $this, 'page_recipients' ] );

        $trash_count = $this->get_trash_count();
        $trash_label = $trash_count
            ? 'Trash <span class="update-plugins count-' . $trash_count . '"><span class="update-count">' . $trash_count . '</span></span>'
            : 'Trash';
        add_submenu_page( 'obs-comments', 'Trash', $trash_label, 'manage_options', 'obs-comments-trash', [ $this, 'page_trash' ] );
    }

    public function admin_assets( $hook ) {
        if ( strpos( $hook, 'obs-comments' ) === false && $hook !== 'index.php' ) return;
        wp_enqueue_script(
            'obs-admin',
            plugin_dir_url( __FILE__ ) . 'obs-admin.js',
            [ 'jquery' ],
            '2.3.0',
            true
        );
        wp_localize_script( 'obs-admin', 'obsData', [
            'copiedMsg'   => __( 'Copied!', 'obs' ),
            'failedMsg'   => __( 'Copy failed — select manually', 'obs' ),
            'confirmBulk' => __( 'Apply this bulk action to the selected comments?', 'obs' ),
            'confirmPurge'=> __( 'Permanently delete? This cannot be undone.', 'obs' ),
            'confirmUsageDelete' => __( 'Delete this usage entry?', 'obs' ),
        ]);
    }

    /* ============================================================
     * LIST PAGE
     * ============================================================ */

    public function page_list() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $search    = isset( $_GET['s'] )       ? sanitize_text_field( $_GET['s'] )       : '';
        $tag       = isset( $_GET['tag'] )     ? sanitize_text_field( $_GET['tag'] )     : '';
        $country   = isset( $_GET['country'] ) ? strtoupper( sanitize_text_field( $_GET['country'] ) ) : '';
        $unused    = ! empty( $_GET['unused'] );
        // Default to source_date DESC (newest received first)
        $orderby   = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'source_date';
        $order     = ( isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';
        $paged     = isset( $_GET['paged'] )   ? max( 1, intval( $_GET['paged'] ) )      : 1;
        $per_page  = 20;
        $offset    = ( $paged - 1 ) * $per_page;

        $allowed_orderby = [ 'created_at', 'source_date', 'usage_count', 'author_name', 'id', 'country' ];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) $orderby = 'source_date';

        $where  = 'WHERE deleted_at IS NULL';
        $params = [];

        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where   .= ' AND (comment_text LIKE %s OR author_name LIKE %s OR notes LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ( $tag ) {
            $where   .= ' AND FIND_IN_SET(%s, tags)';
            $params[] = $tag;
        }
        if ( $country ) {
            $where   .= ' AND country = %s';
            $params[] = $country;
        }
        if ( $unused ) {
            $where .= ' AND usage_count = 0';
        }

        $sql         = "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $data_params = array_merge( $params, [ $per_page, $offset ] );
        $rows        = $wpdb->get_results( $wpdb->prepare( $sql, $data_params ) );

        $count_sql = "SELECT COUNT(*) FROM $table $where";
        $total     = $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : $wpdb->get_var( $count_sql );
        $pages     = ceil( $total / $per_page );

        $unused_count    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE usage_count = 0 AND deleted_at IS NULL" );
        $all_tags        = $this->get_all_tags();
        $all_countries   = $this->get_countries_in_use();
        $base_url        = admin_url( 'admin.php?page=obs-comments' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">OBS Comments</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-new' ) ); ?>" class="page-title-action">Add New</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-import' ) ); ?>" class="page-title-action">Import CSV</a>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_export_csv' ), 'obs_export' ) ); ?>" class="page-title-action">Export CSV</a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->msg_text( $_GET['msg'] ) ); ?></p></div>
            <?php endif; ?>

            <form method="get" style="margin:15px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="page" value="obs-comments">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search comments..." style="min-width:220px;">
                <select name="tag">
                    <option value="">All tags</option>
                    <?php foreach ( $all_tags as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="country">
                    <option value="">All countries</option>
                    <?php foreach ( $all_countries as $code ) : ?>
                        <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>>
                            <?php echo esc_html( obs_country_name( $code ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Filter</button>
                <a href="<?php echo esc_url( $unused ? $base_url : add_query_arg( 'unused', '1', $base_url ) ); ?>"
                   class="button <?php echo $unused ? 'button-primary' : ''; ?>">
                    Never Used (<?php echo $unused_count; ?>)
                </a>
                <?php if ( $search || $tag || $unused || $country ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="obs-bulk-form">
                <input type="hidden" name="action" value="obs_bulk">
                <?php wp_nonce_field( 'obs_bulk' ); ?>

                <div style="margin:10px 0;display:flex;gap:8px;align-items:center;">
                    <select name="bulk_action" id="obs-bulk-action">
                        <option value="">Bulk actions</option>
                        <option value="trash">Move to Trash</option>
                        <option value="add_tag">Add tag</option>
                        <option value="remove_tag">Remove tag</option>
                    </select>
                    <input type="text" name="bulk_tag" id="obs-bulk-tag" placeholder="Tag (for add/remove)" style="display:none;">
                    <button class="button" onclick="return confirm(obsData.confirmBulk);">Apply</button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td style="width:30px;"><input type="checkbox" id="obs-select-all"></td>
                            <th style="width:50px;">ID</th>
                            <th>Comment</th>
                            <th style="width:120px;">Author</th>
                            <th style="width:110px;">
                                <a href="<?php echo esc_url( add_query_arg( [ 'orderby' => 'country', 'order' => ( $orderby === 'country' && $order === 'DESC' ) ? 'asc' : 'desc' ] ) ); ?>">Country</a>
                            </th>
                            <th style="width:140px;">Tags</th>
                            <th style="width:60px;">
                                <a href="<?php echo esc_url( add_query_arg( [ 'orderby' => 'usage_count', 'order' => ( $orderby === 'usage_count' && $order === 'DESC' ) ? 'asc' : 'desc' ] ) ); ?>">Used</a>
                            </th>
                            <th style="width:120px;">
                                <a href="<?php echo esc_url( add_query_arg( [ 'orderby' => 'source_date', 'order' => ( $orderby === 'source_date' && $order === 'DESC' ) ? 'asc' : 'desc' ] ) ); ?>">
                                    Received <?php echo ( $orderby === 'source_date' ) ? ( $order === 'DESC' ? '▼' : '▲' ) : ''; ?>
                                </a>
                            </th>
                            <th style="width:220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="9">No comments found.</td></tr>
                        <?php else : foreach ( $rows as $row ) :
                            $full = $row->comment_text;
                            $trim = wp_trim_words( $full, 30, '…' );
                            $usage_entries = $this->get_usage_entries( $row->id );
                            ?>
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="<?php echo intval( $row->id ); ?>"></td>
                                <td><?php echo intval( $row->id ); ?></td>
                                <td>
                                    <div class="obs-quote" id="obs-quote-<?php echo intval( $row->id ); ?>" data-full="<?php echo esc_attr( $full ); ?>"><?php echo esc_html( $trim ); ?></div>
                                    <div class="row-actions">
                                        <a href="#" class="obs-toggle" data-target="obs-quote-<?php echo intval( $row->id ); ?>">View Full</a>
                                        &nbsp;|&nbsp;
                                        <a href="#" class="obs-copy" data-target="obs-quote-<?php echo intval( $row->id ); ?>">Copy Full Text</a>
                                    </div>
                                </td>
                                <td><?php echo esc_html( $row->author_name ); ?></td>
                                <td>
                                    <?php if ( $row->country ) : ?>
                                        <a href="<?php echo esc_url( add_query_arg( [ 'country' => $row->country ] ) ); ?>" class="obs-tag">
                                            <?php echo esc_html( obs_country_name( $row->country ) ); ?>
                                        </a>
                                    <?php else : ?>
                                        <span style="color:#999;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $row_tags = array_filter( array_map( 'trim', explode( ',', $row->tags ) ) );
                                    foreach ( $row_tags as $t ) {
                                        echo '<a href="' . esc_url( add_query_arg( [ 'tag' => $t ] ) ) . '" class="obs-tag">' . esc_html( $t ) . '</a> ';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <strong><?php echo intval( $row->usage_count ); ?></strong>
                                    <?php if ( $usage_entries ) : ?>
                                        <br><a href="#" class="obs-show-usage" data-comment="<?php echo intval( $row->id ); ?>">details</a>
                                    <?php endif; ?>
                                </td>
                               <td>
                                    <?php
                                    // Prefer Date Received; fall back to created_at
                                    $display_date = $row->source_date ?: $row->created_at;
                                    $is_fallback  = ! $row->source_date;
                                    echo esc_html( date( 'M j, Y', strtotime( $display_date ) ) );
                                    if ( $is_fallback ) {
                                        echo '<br><span style="color:#999;font-size:10px;">(entered)</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-new&id=' . $row->id ) ); ?>" class="button button-small">Edit</a>
                                    <a href="#" class="button button-small button-primary obs-use" data-id="<?php echo intval( $row->id ); ?>" data-place="">Log Use</a>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_delete_comment&id=' . $row->id ), 'obs_delete_' . $row->id ) ); ?>"
                                       class="button button-small"
                                       onclick="return confirm('Move to trash?');">Trash</a>
                                </td>
                            </tr>

                            <!-- Inline usage detail row (hidden until toggled) -->
                            <?php if ( $usage_entries ) : ?>
                            <tr class="obs-usage-row" id="obs-usage-row-<?php echo intval( $row->id ); ?>" style="display:none;background:#f8f9fa;">
                                <td colspan="9" style="padding-left:40px;">
                                    <strong>Usage history for comment #<?php echo intval( $row->id ); ?>:</strong>
                                    <table style="width:auto;margin-top:6px;">
                                        <thead>
                                            <tr style="background:transparent;">
                                                <th style="text-align:left;padding-right:20px;">Place</th>
                                                <th style="text-align:left;padding-right:20px;">Date</th>
                                                <th style="text-align:left;padding-right:20px;">Recipients</th>
                                                <th style="text-align:left;padding-right:20px;">Note</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ( $usage_entries as $entry ) : ?>
                                            <tr>
                                                <td style="padding-right:20px;"><?php echo esc_html( $entry->place_name ); ?></td>
                                                <td style="padding-right:20px;"><?php echo esc_html( $this->format_usage_date( $entry->month, $entry->year ) ); ?></td>
                                                <td style="padding-right:20px;">
                                                    <?php
                                                    $recs = array_filter( array_map( 'trim', explode( ',', $entry->recipients ) ) );
                                                    foreach ( $recs as $r ) {
                                                        echo '<span class="obs-tag">' . esc_html( ucfirst( $r ) ) . '</span> ';
                                                    }
                                                    ?>
                                                </td>
                                                <td style="padding-right:20px;"><?php echo esc_html( $entry->note ); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>

            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'add_args'  => array_filter( [
                            's'       => $search ?: null,
                            'tag'     => $tag ?: null,
                            'country' => $country ?: null,
                            'unused'  => $unused ? 1 : null,
                            'orderby' => $orderby,
                            'order'   => $order,
                        ] ),
                        'format'    => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total'     => $pages,
                        'current'   => $paged,
                    ] );
                    ?>
                </div></div>
            <?php endif; ?>
        </div>

        <?php $this->render_usage_modal(); ?>

        <style>
            .obs-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;text-decoration:none;margin:1px;}
            .obs-quote{font-style:italic;}
        </style>
        <?php
    }

    /* ============================================================
     * USAGE MODAL (shared by list + edit pages)
     * ============================================================ */

    private function render_usage_modal( $prefill = null ) {
        $places    = $this->get_distinct_places();
        $recipients = obs_get_recipients();
        $prefill   = $prefill ? (array) $prefill : [];

        $prefill_comment_id = $prefill['comment_id'] ?? 0;
        $prefill_usage_id   = $prefill['usage_id'] ?? 0;   // non-zero when editing
        $is_edit            = (bool) $prefill_usage_id;

        $months = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
        ];

        $cur_month = (int) date( 'n' );
        $cur_year  = (int) date( 'Y' );
        ?>
        <div id="obs-use-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;overflow-y:auto;">
            <div style="background:#fff;max-width:640px;margin:60px auto;padding:24px;border-radius:6px;">
                <h2 id="obs-use-modal-title"><?php echo $is_edit ? 'Edit Usage Entry' : 'Log Usage'; ?></h2>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="obs-usage-form">
                    <input type="hidden" name="action" value="<?php echo $is_edit ? 'obs_update_usage' : 'obs_log_usage'; ?>">
                    <input type="hidden" name="comment_id" id="obs-use-comment-id" value="<?php echo intval( $prefill_comment_id ); ?>">
                    <input type="hidden" name="usage_id"   id="obs-use-usage-id"   value="<?php echo intval( $prefill_usage_id ); ?>">
                    <?php wp_nonce_field( 'obs_log_usage' ); ?>

                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th style="width:130px;"><label>Place *</label></th>
                            <td>
                                <select name="place_existing" id="obs-place-existing" style="min-width:260px;">
                                    <option value="__new__">— Add new place —</option>
                                    <?php foreach ( $places as $p ) : ?>
                                        <option value="<?php echo esc_attr( $p ); ?>" <?php selected( $prefill['place_name'] ?? '', $p ); ?>>
                                            <?php echo esc_html( $p ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="obs-new-place-fields" style="margin-top:8px;">
                                    <input type="text" name="place_new" id="obs-place-new" placeholder="e.g. Spring 2025 Newsletter" class="regular-text">
                                    <p class="description">Name of the newsletter, letter, campaign, etc.</p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Month / Year</label></th>
                            <td>
                                <select name="month" id="obs-month" style="min-width:130px;">
                                    <?php foreach ( $months as $num => $name ) : ?>
                                        <option value="<?php echo $num; ?>" <?php selected( (int) ( $prefill['month'] ?? $cur_month ), $num ); ?>>
                                            <?php echo esc_html( $name ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="year" id="obs-year" min="1990" max="2100"
                                       value="<?php echo esc_attr( $prefill['year'] ?? $cur_year ); ?>"
                                       style="width:90px;">
                            </td>
                        </tr>
                        <tr>
                            <th><label>Recipients</label></th>
                            <td>
                                <?php
                                $sel_recs = isset( $prefill['recipients'] )
                                    ? array_filter( array_map( 'trim', explode( ',', $prefill['recipients'] ) ) )
                                    : [];
                                obs_render_recipient_checkboxes( $sel_recs );
                                ?>
                                <p class="description">
                                    Manage these on the
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-recipients' ) ); ?>">Recipients</a> page.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Note</label></th>
                            <td><textarea name="note" id="obs-usage-note" rows="2" class="large-text"><?php echo esc_textarea( $prefill['note'] ?? '' ); ?></textarea></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" class="button button-primary"><?php echo $is_edit ? 'Update' : 'Save'; ?></button>
                        <button type="button" class="button obs-modal-close">Cancel</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /* ============================================================
     * ADD / EDIT PAGE
     * ============================================================ */

    public function page_edit() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $row   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ) : null;

        $duplicate_of = isset( $_GET['duplicate'] ) ? intval( $_GET['duplicate'] ) : 0;
        $dup_url      = $duplicate_of ? admin_url( 'admin.php?page=obs-comments-new&id=' . $duplicate_of ) : '';
        $countries    = obs_get_countries();
        $usage_entries = $id ? $this->get_usage_entries( $id ) : [];
        ?>
        <div class="wrap">
            <h1><?php echo $row ? 'Edit Comment' : 'Add New Comment'; ?></h1>

            <?php if ( $duplicate_of ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>Possible duplicate.</strong>
                        An identical comment already exists (ID #<?php echo $duplicate_of; ?>).
                        <a href="<?php echo esc_url( $dup_url ); ?>">View existing</a> —
                        or <a href="#" id="obs-force-save">save anyway</a>.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'usage_saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Usage entry saved.</p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'usage_deleted' ) : ?>
                <div class="notice notice-success is-dismissible"><p>Usage entry deleted.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="obs-edit-form">
                <input type="hidden" name="action" value="obs_save_comment">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <input type="hidden" name="force" id="obs-force" value="<?php echo $duplicate_of ? '0' : '1'; ?>">
                <?php wp_nonce_field( 'obs_save_comment' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="comment_text">Comment *</label></th>
                        <td><textarea name="comment_text" id="comment_text" rows="8" class="large-text" required autofocus><?php echo esc_textarea( $row->comment_text ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="author_name">Author Name</label></th>
                        <td><input type="text" name="author_name" id="author_name" class="regular-text" value="<?php echo esc_attr( $row->author_name ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="author_email">Author Email</label></th>
                        <td><input type="email" name="author_email" id="author_email" class="regular-text" value="<?php echo esc_attr( $row->author_email ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="country">Country</label></th>
                        <td>
                            <select name="country" id="country" style="min-width:260px;">
                                <option value="">— Select country —</option>
                                <?php
                                $current_country = $row->country ?? '';
                                foreach ( $countries as $code => $name ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_country, $code ); ?>>
                                        <?php echo esc_html( $name ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Where did this comment come from?</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="source_date">Date Received</label></th>
                        <td><input type="date" name="source_date" id="source_date" value="<?php echo esc_attr( $row->source_date ?? '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="tags">Tags</label></th>
                        <td>
                            <input type="text" name="tags" id="tags" class="regular-text" value="<?php echo esc_attr( $row->tags ?? '' ); ?>" placeholder="Comma-separated">
                            <p class="description">Separate with commas. Ctrl+Enter saves.</p>
                            <?php $all = $this->get_all_tags(); if ( $all ) : ?>
                                <p>Existing: <?php foreach ( $all as $t ) : ?><a href="#" class="obs-add-tag" data-tag="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></a> <?php endforeach; ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notes">Internal Notes</label></th>
                        <td><textarea name="notes" id="notes" rows="3" class="large-text"><?php echo esc_textarea( $row->notes ?? '' ); ?></textarea></td>
                    </tr>
                </table>

                <p>
                    <?php submit_button( $row ? 'Update Comment' : 'Save Comment', 'primary', 'submit', false ); ?>
                    <?php if ( ! $row ) : ?>
                        <button type="submit" name="save_and_new" value="1" class="button">Save &amp; Add Another</button>
                    <?php endif; ?>
                </p>
            </form>

            <?php if ( $row ) : ?>
            <hr>
            <h2>Where Used (<?php echo count( $usage_entries ); ?>)</h2>
            <p>
                <button type="button" class="button button-primary obs-use" data-id="<?php echo intval( $row->id ); ?>" data-place="">+ Log New Usage</button>
            </p>

            <?php if ( empty( $usage_entries ) ) : ?>
                <p><em>Not used yet.</em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="max-width:960px;">
                    <thead>
                        <tr>
                            <th>Place</th>
                            <th style="width:140px;">Date</th>
                            <th style="width:260px;">Recipients</th>
                            <th>Note</th>
                            <th style="width:180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $usage_entries as $entry ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $entry->place_name ); ?></strong></td>
                            <td><?php echo esc_html( $this->format_usage_date( $entry->month, $entry->year ) ); ?></td>
                            <td>
                                <?php
                                $recs = array_filter( array_map( 'trim', explode( ',', $entry->recipients ) ) );
                                foreach ( $recs as $r ) {
                                    echo '<span class="obs-tag">' . esc_html( ucfirst( $r ) ) . '</span> ';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $entry->note ); ?></td>
                            <td>
                                <a href="#" class="button button-small obs-edit-usage"
                                   data-usage-id="<?php echo intval( $entry->id ); ?>"
                                   data-comment-id="<?php echo intval( $row->id ); ?>"
                                   data-place="<?php echo esc_attr( $entry->place_name ); ?>"
                                   data-month="<?php echo esc_attr( $entry->month ); ?>"
                                   data-year="<?php echo esc_attr( $entry->year ); ?>"
                                   data-recipients="<?php echo esc_attr( $entry->recipients ); ?>"
                                   data-note="<?php echo esc_attr( $entry->note ); ?>">Edit</a>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_delete_usage&usage_id=' . $entry->id . '&comment_id=' . $row->id ), 'obs_delete_usage_' . $entry->id ) ); ?>"
                                   class="button button-small"
                                   onclick="return confirm(obsData.confirmUsageDelete);">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php $this->render_usage_modal(); ?>

        <style>.obs-add-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;text-decoration:none;margin:2px;}</style>
        <?php
    }

    /* ============================================================
     * RECIPIENTS PAGE
     * ============================================================ */

    public function page_recipients() {
        $list = obs_get_recipients();
        if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Recipient groups updated.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Recipient Groups</h1>
            <p>These are the checkboxes shown when you log a usage (e.g. students, supporters, general). Add or remove freely.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:520px;">
                <input type="hidden" name="action" value="obs_save_recipients">
                <?php wp_nonce_field( 'obs_save_recipients' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label>One per line</label></th>
                        <td>
                            <textarea name="recipients" rows="10" class="large-text" style="font-family:monospace;"><?php echo esc_textarea( implode( "\n", $list ) ); ?></textarea>
                        </td>
                    </tr>
                </table>
                <p><button class="button button-primary">Save Recipients</button></p>
            </form>
        </div>
        <?php
    }

    public function handle_save_recipients() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_save_recipients' );

        $raw   = $_POST['recipients'] ?? '';
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        $clean = [];
        foreach ( $lines as $line ) {
            $line = sanitize_text_field( trim( $line ) );
            if ( $line !== '' ) $clean[] = $line;
        }
        obs_save_recipients( $clean );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-recipients&msg=saved' ) );
        exit;
    }

    /* ============================================================
     * STRUCTURED USAGE HANDLERS
     * ============================================================ */

    public function handle_log_usage() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_log_usage' );

        $comment_id = intval( $_POST['comment_id'] ?? 0 );
        if ( ! $comment_id ) wp_die( 'Missing comment ID' );

        $data = $this->read_usage_form();
        if ( ! $data ) wp_die( 'Place name is required.' );

        global $wpdb;
        $data['comment_id'] = $comment_id;
        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $wpdb->prefix . self::USAGE_TABLE, $data );

        $this->recalculate_usage_count( $comment_id );

        // Redirect based on referrer — stay on edit page if we came from there
        $redirect = wp_get_referer();
        if ( ! $redirect ) $redirect = admin_url( 'admin.php?page=obs-comments&msg=logged' );
        $redirect = add_query_arg( 'msg', 'usage_saved', remove_query_arg( 'msg', $redirect ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_update_usage() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_log_usage' );

        $usage_id   = intval( $_POST['usage_id'] ?? 0 );
        $comment_id = intval( $_POST['comment_id'] ?? 0 );
        if ( ! $usage_id || ! $comment_id ) wp_die( 'Missing IDs' );

        $data = $this->read_usage_form();
        if ( ! $data ) wp_die( 'Place name is required.' );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::USAGE_TABLE, $data, [ 'id' => $usage_id ] );

        $redirect = wp_get_referer();
        if ( ! $redirect ) $redirect = admin_url( 'admin.php?page=obs-comments-new&id=' . $comment_id );
        $redirect = add_query_arg( 'msg', 'usage_saved', remove_query_arg( 'msg', $redirect ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_delete_usage() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $usage_id   = intval( $_GET['usage_id'] ?? 0 );
        $comment_id = intval( $_GET['comment_id'] ?? 0 );
        check_admin_referer( 'obs_delete_usage_' . $usage_id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::USAGE_TABLE, [ 'id' => $usage_id ] );
        $this->recalculate_usage_count( $comment_id );

        $redirect = wp_get_referer();
        if ( ! $redirect ) $redirect = admin_url( 'admin.php?page=obs-comments-new&id=' . $comment_id );
        $redirect = add_query_arg( 'msg', 'usage_deleted', remove_query_arg( 'msg', $redirect ) );

        wp_safe_redirect( $redirect );
        exit;
    }

    /** Read + sanitize the usage form. Returns false if place missing. */
    private function read_usage_form() {
        $existing_place = sanitize_text_field( $_POST['place_existing'] ?? '__new__' );
        if ( $existing_place === '__new__' ) {
            $place = sanitize_text_field( $_POST['place_new'] ?? '' );
        } else {
            $place = $existing_place;
        }
        if ( ! $place ) return false;

        $month = intval( $_POST['month'] ?? 0 );
        if ( $month < 1 || $month > 12 ) $month = null;

        $year = intval( $_POST['year'] ?? 0 );
        if ( $year < 1900 || $year > 2200 ) $year = null;

        $recipients = isset( $_POST['recipients'] ) && is_array( $_POST['recipients'] )
            ? array_map( 'sanitize_text_field', $_POST['recipients'] )
            : [];
        $recipients = array_unique( array_filter( $recipients ) );

        return [
            'place_name' => $place,
            'month'      => $month,
            'year'       => $year,
            'recipients' => implode( ',', $recipients ),
            'note'       => sanitize_textarea_field( $_POST['note'] ?? '' ),
        ];
    }

    /** Recompute usage_count on the comment row. */
    private function recalculate_usage_count( $comment_id ) {
        global $wpdb;
        $usage_table   = $wpdb->prefix . self::USAGE_TABLE;
        $comment_table = $wpdb->prefix . self::TABLE;

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $usage_table WHERE comment_id = %d",
            $comment_id
        ) );

        // Add legacy count if usage_log has content but no structured entries
        $legacy = $wpdb->get_var( $wpdb->prepare(
            "SELECT usage_log FROM $comment_table WHERE id = %d",
            $comment_id
        ) );

        $legacy_count = 0;
        if ( $legacy ) {
            $legacy_count = count( array_filter( explode( "\n", $legacy ) ) );
        }

        $wpdb->update( $comment_table, [
            'usage_count' => $count + $legacy_count,
            'updated_at'  => current_time( 'mysql' ),
        ], [ 'id' => $comment_id ] );
    }

    /** Fetch structured usage entries for a comment. */
    private function get_usage_entries( $comment_id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::USAGE_TABLE;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE comment_id = %d ORDER BY year DESC, month DESC, id DESC",
            $comment_id
        ) );
    }

    /** Distinct place names, for the dropdown. */
    private function get_distinct_places() {
        global $wpdb;
        $table = $wpdb->prefix . self::USAGE_TABLE;
        return $wpdb->get_col( "SELECT DISTINCT place_name FROM $table WHERE place_name != '' ORDER BY place_name ASC" );
    }

    private function format_usage_date( $month, $year ) {
        if ( ! $month && ! $year ) return '—';
        $m = $month ? date( 'F', mktime( 0, 0, 0, (int) $month, 1 ) ) : '';
        return trim( $m . ' ' . ( $year ?: '' ) );
    }

    /* ============================================================
     * TAGS PAGE
     * ============================================================ */

    public function page_tags() {
        $tags = $this->get_all_tags_with_counts();
        if ( isset( $_GET['renamed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Tag updated.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Manage Tags</h1>
            <div class="card" style="max-width:600px;padding:16px;margin-bottom:20px;">
                <h2>Rename / Merge Tag</h2>
                <p>Rename a tag or merge two tags into one.</p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="obs_rename_tag">
                    <?php wp_nonce_field( 'obs_rename_tag' ); ?>
                    <p>
                        <select name="old_tag" required style="min-width:180px;">
                            <option value="">Select tag…</option>
                            <?php foreach ( array_keys( $tags ) as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span style="margin:0 10px;">→</span>
                        <input type="text" name="new_tag" placeholder="new tag name" required>
                        <button class="button button-primary">Rename / Merge</button>
                    </p>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Tag</th><th style="width:120px;">Comments</th><th style="width:220px;">Actions</th></tr></thead>
                <tbody>
                <?php if ( empty( $tags ) ) : ?>
                    <tr><td colspan="3">No tags yet.</td></tr>
                <?php else : foreach ( $tags as $tag => $count ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $tag ); ?></strong></td>
                        <td><?php echo intval( $count ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments&tag=' . urlencode( $tag ) ) ); ?>">View</a>
                            <a class="button button-small obs-prefill-rename"
                               data-tag="<?php echo esc_attr( $tag ); ?>"
                               href="#">Rename</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ============================================================
     * TRASH PAGE
     * ============================================================ */

    public function page_trash() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results( "SELECT * FROM $table WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC" );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Trash</h1>
            <?php if ( ! empty( $rows ) ) : ?>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_empty_trash' ), 'obs_empty_trash' ) ); ?>"
                   class="page-title-action"
                   onclick="return confirm('Permanently delete ALL trashed comments? This cannot be undone.');">Empty Trash</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->msg_text( $_GET['msg'] ) ); ?></p></div>
            <?php endif; ?>

            <p>Comments here are soft-deleted and can be restored.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Comment</th>
                        <th style="width:120px;">Author</th>
                        <th style="width:120px;">Country</th>
                        <th style="width:150px;">Tags</th>
                        <th style="width:150px;">Trashed</th>
                        <th style="width:280px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="7">Trash is empty.</td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo intval( $row->id ); ?></td>
                            <td>
                                <div class="obs-quote" id="obs-quote-<?php echo intval( $row->id ); ?>" data-full="<?php echo esc_attr( $row->comment_text ); ?>">
                                    <?php echo esc_html( wp_trim_words( $row->comment_text, 30, '…' ) ); ?>
                                </div>
                                <div class="row-actions">
                                    <a href="#" class="obs-toggle" data-target="obs-quote-<?php echo intval( $row->id ); ?>">View Full</a>
                                </div>
                            </td>
                            <td><?php echo esc_html( $row->author_name ); ?></td>
                            <td>
                                <?php if ( $row->country ) : ?>
                                    <span class="obs-tag"><?php echo esc_html( obs_country_name( $row->country ) ); ?></span>
                                <?php else : ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $row_tags = array_filter( array_map( 'trim', explode( ',', $row->tags ) ) );
                                foreach ( $row_tags as $t ) {
                                    echo '<span class="obs-tag">' . esc_html( $t ) . '</span> ';
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( date( 'M j, Y g:ia', strtotime( $row->deleted_at ) ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_restore&id=' . $row->id ), 'obs_restore_' . $row->id ) ); ?>"
                                   class="button button-small button-primary">Restore</a>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_purge&id=' . $row->id ), 'obs_purge_' . $row->id ) ); ?>"
                                   class="button button-small"
                                   onclick="return confirm(obsData.confirmPurge);">Delete Permanently</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <style>
            .obs-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;margin:1px;}
            .obs-quote{font-style:italic;color:#666;}
        </style>
        <?php
    }

    /* ============================================================
     * CSV IMPORT PAGE
     * ============================================================ */

    public function page_import() {
        if ( isset( $_GET['stage'] ) && $_GET['stage'] === 'map' && ! empty( $_GET['token'] ) ) {
            $this->render_import_mapping( sanitize_text_field( $_GET['token'] ) );
            return;
        }
        if ( isset( $_GET['stage'] ) && $_GET['stage'] === 'done' ) {
            $imported = intval( $_GET['imported'] ?? 0 );
            $skipped  = intval( $_GET['skipped'] ?? 0 );
            $errors   = intval( $_GET['errors'] ?? 0 );
            ?>
            <div class="wrap">
                <h1>Import Complete</h1>
                <div class="notice notice-success"><p>
                    <strong><?php echo $imported; ?></strong> comments imported.
                    <strong><?php echo $skipped; ?></strong> skipped as duplicates.
                    <strong><?php echo $errors; ?></strong> rows skipped due to errors.
                </p></div>
                <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments' ) ); ?>">View All Comments</a></p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="wrap">
            <h1>Import from CSV</h1>
            <p>Upload a CSV file. The first row must be the header. Recommended columns:</p>
            <ul style="list-style:disc;margin-left:20px;">
                <li><code>comment_text</code> (required)</li>
                <li><code>author_name</code></li>
                <li><code>author_email</code></li>
                <li><code>country</code> (2-letter ISO code OR country name)</li>
                <li><code>source_date</code> (YYYY-MM-DD)</li>
                <li><code>tags</code> (comma-separated inside the cell)</li>
                <li><code>notes</code></li>
            </ul>
            <p><em>Structured "where used" entries are not imported — they can be logged afterward.</em></p>

            <?php if ( isset( $_GET['msg'] ) && $_GET['msg'] === 'upload_failed' ) : ?>
                <div class="notice notice-error"><p>Upload failed. Please try again.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:20px;">
                <input type="hidden" name="action" value="obs_import">
                <input type="hidden" name="stage" value="upload">
                <?php wp_nonce_field( 'obs_import_upload' ); ?>
                <p><input type="file" name="csv_file" accept=".csv,text/csv" required></p>
                <p><button class="button button-primary">Upload &amp; Preview</button></p>
            </form>
        </div>
        <?php
    }

    private function render_import_mapping( $token ) {
        $file = $this->get_upload_path( $token );
        if ( ! $file || ! file_exists( $file ) ) {
            echo '<div class="wrap"><h1>Import</h1><p>File not found. Please re-upload.</p></div>';
            return;
        }

        $handle  = fopen( $file, 'r' );
        $header  = fgetcsv( $handle );
        $preview = [];
        while ( ( $row = fgetcsv( $handle ) ) !== false && count( $preview ) < 3 ) {
            $preview[] = $row;
        }
        fclose( $handle );

        if ( ! $header ) {
            echo '<div class="wrap"><h1>Import</h1><p>Could not read CSV header.</p></div>';
            return;
        }

        $fields = [
            ''             => '— Skip this column —',
            'comment_text' => 'Comment Text (required)',
            'author_name'  => 'Author Name',
            'author_email' => 'Author Email',
            'country'      => 'Country',
            'source_date'  => 'Date Received (YYYY-MM-DD)',
            'tags'         => 'Tags',
            'notes'        => 'Notes',
        ];

        $auto = [];
        foreach ( $header as $i => $col ) {
            $key = strtolower( trim( $col ) );
            $key = str_replace( [ ' ', '-' ], '_', $key );
            if ( isset( $fields[ $key ] ) ) $auto[ $i ] = $key;
        }
        ?>
        <div class="wrap">
            <h1>Map Columns</h1>
            <p>Match each column from your CSV to a field. Required: <strong>Comment Text</strong>.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="obs_import">
                <input type="hidden" name="stage" value="process">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
                <?php wp_nonce_field( 'obs_import_process' ); ?>

                <table class="widefat striped" style="max-width:900px;">
                    <thead>
                        <tr><th style="width:30%;">CSV Column</th><th style="width:35%;">Maps To</th><th>Sample</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $header as $i => $col ) :
                            $sample = isset( $preview[0][ $i ] ) ? $preview[0][ $i ] : '';
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $col ); ?></strong></td>
                                <td>
                                    <select name="map[<?php echo intval( $i ); ?>]" style="min-width:200px;">
                                        <?php foreach ( $fields as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $auto[ $i ] ?? '', $val ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><em><?php echo esc_html( mb_substr( $sample, 0, 80 ) ); ?><?php echo mb_strlen( $sample ) > 80 ? '…' : ''; ?></em></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:15px;">
                    <label><input type="checkbox" name="skip_duplicates" value="1" checked> Skip rows where the comment text already exists</label>
                </p>

                <p>
                    <button class="button button-primary">Import Now</button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-import' ) ); ?>">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }

    /* ============================================================
     * DASHBOARD WIDGET
     * ============================================================ */

    public function register_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        wp_add_dashboard_widget(
            'obs_unused_comments',
            'OBS — Oldest Unused Comments',
            [ $this, 'render_dashboard_widget' ]
        );
    }

    public function render_dashboard_widget() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results(
            "SELECT id, comment_text, author_name, country, created_at
             FROM $table
             WHERE usage_count = 0 AND deleted_at IS NULL
             ORDER BY created_at ASC
             LIMIT 5"
        );

        if ( empty( $rows ) ) {
            echo '<p><em>No unused comments. Nice work!</em></p>';
            return;
        }

        $total_unused = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE usage_count = 0 AND deleted_at IS NULL" );

        echo '<ul style="margin:0;">';
        foreach ( $rows as $row ) {
            $id   = intval( $row->id );
            $full = $row->comment_text;
            $trim = wp_trim_words( $full, 15, '…' );
            $url  = admin_url( 'admin.php?page=obs-comments-new&id=' . $id );
            ?>
            <li style="padding:10px 0;border-bottom:1px solid #eee;">
                <div class="obs-dash-quote"
                     id="obs-dash-quote-<?php echo $id; ?>"
                     data-full="<?php echo esc_attr( $full ); ?>"
                     style="font-style:italic;margin-bottom:4px;">
                    <?php echo esc_html( $trim ); ?>
                </div>
                <div style="font-size:11px;color:#666;">
                    <?php echo esc_html( $row->author_name ?: 'Anonymous' ); ?>
                    <?php if ( $row->country ) : ?>
                        · <?php echo esc_html( obs_country_name( $row->country ) ); ?>
                    <?php endif; ?>
                    · <?php echo esc_html( date( 'M j, Y', strtotime( $row->created_at ) ) ); ?>
                    · <a href="#" class="obs-copy" data-target="obs-dash-quote-<?php echo $id; ?>">Copy</a>
                    · <a href="<?php echo esc_url( $url ); ?>">Edit</a>
                </div>
            </li>
            <?php
        }
        echo '</ul>';

        echo '<p style="margin-top:10px;"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=obs-comments&unused=1' ) ) . '">View all ' . $total_unused . ' unused →</a></p>';
    }

    /* ============================================================
     * CORE HANDLERS (comments)
     * ============================================================ */

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_save_comment' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = intval( $_POST['id'] ?? 0 );
        $force = ! empty( $_POST['force'] ) && $_POST['force'] === '1';

        $comment_text = wp_kses_post( wp_unslash( $_POST['comment_text'] ) );

        if ( ! $id && ! $force ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE comment_text = %s AND deleted_at IS NULL LIMIT 1",
                $comment_text
            ) );
            if ( $existing ) {
                wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-new&duplicate=' . intval( $existing ) ) );
                exit;
            }
        }

        $country = strtoupper( sanitize_text_field( $_POST['country'] ?? '' ) );
        if ( $country && ! isset( obs_get_countries()[ $country ] ) ) {
            $country = '';
        }

        $data = [
            'comment_text' => $comment_text,
            'author_name'  => sanitize_text_field( $_POST['author_name'] ?? '' ),
            'author_email' => sanitize_email( $_POST['author_email'] ?? '' ),
            'country'      => $country,
            'source_date'  => ! empty( $_POST['source_date'] ) ? sanitize_text_field( $_POST['source_date'] ) : null,
            'tags'         => $this->normalize_tags( $_POST['tags'] ?? '' ),
            'notes'        => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'updated_at'   => current_time( 'mysql' ),
        ];

        if ( $id ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=saved' ) );
            exit;
        }

        $data['created_at'] = current_time( 'mysql' );
        $wpdb->insert( $table, $data );

        if ( ! empty( $_POST['save_and_new'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-new&msg=saved_new' ) );
        } else {
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=saved' ) );
        }
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $id = intval( $_GET['id'] ?? 0 );
        check_admin_referer( 'obs_delete_' . $id );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::TABLE, [ 'deleted_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=trashed' ) );
        exit;
    }

    public function handle_restore() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $id = intval( $_GET['id'] ?? 0 );
        check_admin_referer( 'obs_restore_' . $id );

        global $wpdb;
        $wpdb->update( $wpdb->prefix . self::TABLE, [ 'deleted_at' => null ], [ 'id' => $id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-trash&msg=restored' ) );
        exit;
    }

    public function handle_purge() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $id = intval( $_GET['id'] ?? 0 );
        check_admin_referer( 'obs_purge_' . $id );

        global $wpdb;
        // Also remove structured usage entries for this comment
        $wpdb->delete( $wpdb->prefix . self::USAGE_TABLE, [ 'comment_id' => $id ] );
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-trash&msg=purged' ) );
        exit;
    }

    public function handle_empty_trash() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_empty_trash' );

        global $wpdb;
        $comments = $wpdb->prefix . self::TABLE;
        $usage    = $wpdb->prefix . self::USAGE_TABLE;

        // Collect IDs first, then purge usage entries
        $ids = $wpdb->get_col( "SELECT id FROM $comments WHERE deleted_at IS NOT NULL" );
        if ( $ids ) {
            $in = implode( ',', array_map( 'intval', $ids ) );
            $wpdb->query( "DELETE FROM $usage WHERE comment_id IN ($in)" );
        }
        $wpdb->query( "DELETE FROM $comments WHERE deleted_at IS NOT NULL" );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-trash&msg=emptied' ) );
        exit;
    }

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_export' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results( "SELECT * FROM $table WHERE deleted_at IS NULL ORDER BY id DESC", ARRAY_A );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="obs-comments-' . date( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'Comment', 'Author', 'Email', 'Country', 'Country Name', 'Date Received', 'Tags', 'Usage Count', 'Where Used', 'Notes', 'Added' ] );

        foreach ( $rows as $r ) {
            // Flatten structured usage entries into a readable string
            $entries = $this->get_usage_entries( $r['id'] );
            $lines   = [];
            foreach ( $entries as $e ) {
                $parts = [ $e->place_name ];
                $date  = $this->format_usage_date( $e->month, $e->year );
                if ( $date !== '—' ) $parts[] = $date;
                if ( $e->recipients ) $parts[] = '(' . $e->recipients . ')';
                $lines[] = implode( ' — ', $parts );
            }
            $where_used = implode( ' | ', $lines );

            fputcsv( $out, [
                $r['id'], $r['comment_text'], $r['author_name'], $r['author_email'],
                $r['country'], obs_country_name( $r['country'] ),
                $r['source_date'], $r['tags'], $r['usage_count'],
                $where_used, $r['notes'], $r['created_at'],
            ] );
        }
        fclose( $out );
        exit;
    }

    public function handle_bulk() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_bulk' );

        $ids    = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
        $action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        $tag    = sanitize_text_field( $_POST['bulk_tag'] ?? '' );

        if ( empty( $ids ) || ! $action ) {
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=bulk_none' ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        foreach ( $ids as $id ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) );
            if ( ! $row ) continue;

            switch ( $action ) {
                case 'trash':
                    $wpdb->update( $table, [ 'deleted_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
                    break;

                case 'add_tag':
                    if ( ! $tag ) break;
                    $existing = array_filter( array_map( 'trim', explode( ',', $row->tags ) ) );
                    if ( ! in_array( $tag, $existing, true ) ) {
                        $existing[] = $tag;
                        $wpdb->update( $table, [
                            'tags'       => implode( ',', $existing ),
                            'updated_at' => current_time( 'mysql' ),
                        ], [ 'id' => $id ] );
                    }
                    break;

                case 'remove_tag':
                    if ( ! $tag ) break;
                    $existing = array_filter( array_map( 'trim', explode( ',', $row->tags ) ) );
                    $existing = array_diff( $existing, [ $tag ] );
                    $wpdb->update( $table, [
                        'tags'       => implode( ',', $existing ),
                        'updated_at' => current_time( 'mysql' ),
                    ], [ 'id' => $id ] );
                    break;
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=bulk_done' ) );
        exit;
    }

    public function handle_rename_tag() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_rename_tag' );

        $old = sanitize_text_field( $_POST['old_tag'] ?? '' );
        $new = sanitize_text_field( $_POST['new_tag'] ?? '' );

        if ( ! $old || ! $new || $old === $new ) {
            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-tags' ) );
            exit;
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results( "SELECT id, tags FROM $table WHERE FIND_IN_SET('" . esc_sql( $old ) . "', tags)" );

        foreach ( $rows as $r ) {
            $parts = array_filter( array_map( 'trim', explode( ',', $r->tags ) ) );
            $parts = array_map( function( $t ) use ( $old, $new ) {
                return $t === $old ? $new : $t;
            }, $parts );
            $parts = array_unique( $parts );
            $wpdb->update( $table, [
                'tags'       => implode( ',', $parts ),
                'updated_at' => current_time( 'mysql' ),
            ], [ 'id' => $r->id ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-tags&renamed=1' ) );
        exit;
    }

    /* ============================================================
     * IMPORT
     * ============================================================ */

    public function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $stage = sanitize_text_field( $_POST['stage'] ?? '' );

        if ( $stage === 'upload' ) {
            check_admin_referer( 'obs_import_upload' );

            if ( empty( $_FILES['csv_file']['tmp_name'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
                wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-import&msg=upload_failed' ) );
                exit;
            }

            $token = wp_generate_password( 20, false, false );
            $dir   = $this->get_upload_dir();
            $dest  = $dir . '/' . $token . '.csv';

            if ( ! move_uploaded_file( $_FILES['csv_file']['tmp_name'], $dest ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-import&msg=upload_failed' ) );
                exit;
            }

            wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-import&stage=map&token=' . $token ) );
            exit;
        }

        if ( $stage === 'process' ) {
            check_admin_referer( 'obs_import_process' );

            $token      = sanitize_text_field( $_POST['token'] ?? '' );
            $map        = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? array_map( 'sanitize_text_field', $_POST['map'] ) : [];
            $skip_dupes = ! empty( $_POST['skip_duplicates'] );

            $file = $this->get_upload_path( $token );
            if ( ! $file || ! file_exists( $file ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-import&msg=upload_failed' ) );
                exit;
            }

            $result = $this->process_import( $file, $map, $skip_dupes );
            @unlink( $file );

            wp_safe_redirect( add_query_arg( [
                'page'     => 'obs-comments-import',
                'stage'    => 'done',
                'imported' => $result['imported'],
                'skipped'  => $result['skipped'],
                'errors'   => $result['errors'],
            ], admin_url( 'admin.php' ) ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-import' ) );
        exit;
    }

    private function process_import( $file, $map, $skip_dupes ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $handle = fopen( $file, 'r' );
        if ( ! $handle ) return [ 'imported' => 0, 'skipped' => 0, 'errors' => 1 ];

        fgetcsv( $handle );
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        $comment_index = array_search( 'comment_text', $map, true );
        if ( $comment_index === false ) {
            fclose( $handle );
            return [ 'imported' => 0, 'skipped' => 0, 'errors' => 1 ];
        }

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $data = [
                'comment_text' => '',
                'author_name'  => '',
                'author_email' => '',
                'country'      => '',
                'source_date'  => null,
                'tags'         => '',
                'notes'        => '',
            ];

            foreach ( $map as $col_index => $field ) {
                if ( ! $field || ! isset( $row[ $col_index ] ) ) continue;
                $value = trim( $row[ $col_index ] );

                if ( $field === 'comment_text' ) {
                    $data['comment_text'] = wp_kses_post( $value );
                } elseif ( $field === 'author_email' ) {
                    $data['author_email'] = sanitize_email( $value );
                } elseif ( $field === 'source_date' ) {
                    $ts = strtotime( $value );
                    $data['source_date'] = $ts ? date( 'Y-m-d', $ts ) : null;
                } elseif ( $field === 'tags' ) {
                    $data['tags'] = $this->normalize_tags( $value );
                } elseif ( $field === 'notes' ) {
                    $data['notes'] = sanitize_textarea_field( $value );
                } elseif ( $field === 'author_name' ) {
                    $data['author_name'] = sanitize_text_field( $value );
                } elseif ( $field === 'country' ) {
                    $data['country'] = obs_resolve_country( $value );
                }
            }

            if ( empty( $data['comment_text'] ) ) {
                $errors++;
                continue;
            }

            if ( $skip_dupes ) {
                $exists = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM $table WHERE comment_text = %s AND deleted_at IS NULL LIMIT 1",
                    $data['comment_text']
                ) );
                if ( $exists ) {
                    $skipped++;
                    continue;
                }
            }

            $data['created_at'] = current_time( 'mysql' );
            $data['updated_at'] = current_time( 'mysql' );
            $ok = $wpdb->insert( $table, $data );
            if ( $ok ) $imported++;
            else $errors++;
        }

        fclose( $handle );
        return compact( 'imported', 'skipped', 'errors' );
    }

    private function get_upload_dir() {
        $dir = wp_upload_dir()['basedir'] . '/obs-imports';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) file_put_contents( $htaccess, "Deny from all\n" );
        $index = $dir . '/index.html';
        if ( ! file_exists( $index ) ) file_put_contents( $index, '' );
        return $dir;
    }

    private function get_upload_path( $token ) {
        $token = preg_replace( '/[^A-Za-z0-9]/', '', $token );
        if ( ! $token ) return '';
        return $this->get_upload_dir() . '/' . $token . '.csv';
    }

    /* ============================================================
     * HELPERS
     * ============================================================ */

    private function get_trash_count() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $wpdb->prefix . self::TABLE . " WHERE deleted_at IS NOT NULL" );
    }

    private function get_countries_in_use() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $codes = $wpdb->get_col( "SELECT DISTINCT country FROM $table WHERE country != '' AND deleted_at IS NULL ORDER BY country ASC" );
        return array_filter( array_map( 'strtoupper', $codes ) );
    }

    private function normalize_tags( $raw ) {
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $parts = array_unique( array_map( 'sanitize_text_field', $parts ) );
        $parts = array_filter( $parts );
        return implode( ',', $parts );
    }

    private function get_all_tags() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_col( "SELECT tags FROM $table WHERE tags != '' AND deleted_at IS NULL" );
        $tags  = [];
        foreach ( $rows as $r ) {
            foreach ( explode( ',', $r ) as $t ) {
                $t = trim( $t );
                if ( $t ) $tags[ $t ] = true;
            }
        }
        $tags = array_keys( $tags );
        sort( $tags );
        return $tags;
    }

    private function get_all_tags_with_counts() {
        global $wpdb;
        $table  = $wpdb->prefix . self::TABLE;
        $rows   = $wpdb->get_col( "SELECT tags FROM $table WHERE tags != '' AND deleted_at IS NULL" );
        $counts = [];
        foreach ( $rows as $r ) {
            foreach ( explode( ',', $r ) as $t ) {
                $t = trim( $t );
                if ( $t ) $counts[ $t ] = ( $counts[ $t ] ?? 0 ) + 1;
            }
        }
        ksort( $counts );
        return $counts;
    }

    private function msg_text( $key ) {
        return [
            'saved'      => 'Comment saved.',
            'saved_new'  => 'Comment saved. Add another below.',
            'trashed'    => 'Comment moved to trash.',
            'restored'   => 'Comment restored.',
            'purged'     => 'Comment permanently deleted.',
            'emptied'    => 'Trash emptied.',
            'logged'     => 'Usage logged.',
            'bulk_done'  => 'Bulk action applied.',
            'bulk_none'  => 'No comments selected.',
        ][ $key ] ?? '';
    }
}

new OBS_Comments_Manager();
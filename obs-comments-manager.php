<?php
/**
 * Plugin Name: OBS Comments Manager
 * Description: Store, tag, search, and track usage of comments received via email for newsletters and fundraising letters.
 * Version: 2.0.0
 * Author: Custom
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OBS_Comments_Manager {

    const DB_VERSION = '2.0';
    const TABLE      = 'obs_comments';

    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'admin_menu',            [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );

        // Form handlers
        add_action( 'admin_post_obs_save_comment',   [ $this, 'handle_save' ] );
        add_action( 'admin_post_obs_delete_comment', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_obs_log_usage',      [ $this, 'handle_log_usage' ] );
        add_action( 'admin_post_obs_export_csv',     [ $this, 'handle_export' ] );
        add_action( 'admin_post_obs_bulk',           [ $this, 'handle_bulk' ] );
        add_action( 'admin_post_obs_rename_tag',     [ $this, 'handle_rename_tag' ] );
    }

    /* ============================================================
     * DATABASE
     * ============================================================ */

    public function activate() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            comment_text LONGTEXT NOT NULL,
            author_name VARCHAR(191) DEFAULT '',
            author_email VARCHAR(191) DEFAULT '',
            source_date DATE DEFAULT NULL,
            tags VARCHAR(500) DEFAULT '',
            usage_count INT UNSIGNED DEFAULT 0,
            usage_log LONGTEXT DEFAULT '',
            notes TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY tags (tags(100)),
            KEY usage_count (usage_count),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'obs_db_version', self::DB_VERSION );
    }

    /* ============================================================
     * ADMIN MENU & ASSETS
     * ============================================================ */

    public function admin_menu() {
        add_menu_page(
            'OBS Comments', 'OBS Comments', 'manage_options',
            'obs-comments', [ $this, 'page_list' ], 'dashicons-format-quote', 25
        );
        add_submenu_page(
            'obs-comments', 'Add New Comment', 'Add New', 'manage_options',
            'obs-comments-new', [ $this, 'page_edit' ]
        );
        add_submenu_page(
            'obs-comments', 'Manage Tags', 'Tags', 'manage_options',
            'obs-comments-tags', [ $this, 'page_tags' ]
        );
    }

    public function admin_assets( $hook ) {
        if ( strpos( $hook, 'obs-comments' ) === false ) return;
        wp_enqueue_script(
            'obs-admin',
            plugin_dir_url( __FILE__ ) . 'obs-admin.js',
            [ 'jquery' ],
            '2.0.0',
            true
        );
        wp_localize_script( 'obs-admin', 'obsData', [
            'copiedMsg'   => __( 'Copied!', 'obs' ),
            'failedMsg'   => __( 'Copy failed — select manually', 'obs' ),
            'confirmBulk' => __( 'Apply this bulk action to the selected comments?', 'obs' ),
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
        $unused    = ! empty( $_GET['unused'] );
        $orderby   = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'created_at';
        $order     = ( isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ) ? 'ASC' : 'DESC';
        $paged     = isset( $_GET['paged'] )   ? max( 1, intval( $_GET['paged'] ) )      : 1;
        $per_page  = 20;
        $offset    = ( $paged - 1 ) * $per_page;

        $allowed_orderby = [ 'created_at', 'usage_count', 'author_name', 'id' ];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) $orderby = 'created_at';

        // Build WHERE
        $where  = 'WHERE 1=1';
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
        if ( $unused ) {
            $where .= ' AND usage_count = 0';
        }

        // Data query
        $sql = "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $data_params = array_merge( $params, [ $per_page, $offset ] );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $data_params ) );

        // Total count for pagination
        $count_sql = "SELECT COUNT(*) FROM $table $where";
        $total = $params
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
            : $wpdb->get_var( $count_sql );
        $pages = ceil( $total / $per_page );

        // Unused count for the quick filter badge
        $unused_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE usage_count = 0" );

        $all_tags = $this->get_all_tags();
        $base_url = admin_url( 'admin.php?page=obs-comments' );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">OBS Comments</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-new' ) ); ?>" class="page-title-action">Add New</a>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_export_csv' ), 'obs_export' ) ); ?>" class="page-title-action">Export CSV</a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->msg_text( $_GET['msg'] ) ); ?></p></div>
            <?php endif; ?>

            <!-- Filter toolbar -->
            <form method="get" style="margin:15px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="page" value="obs-comments">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search comments..." style="min-width:260px;">
                <select name="tag">
                    <option value="">All tags</option>
                    <?php foreach ( $all_tags as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Filter</button>
                <a href="<?php echo esc_url( $unused ? $base_url : add_query_arg( 'unused', '1', $base_url ) ); ?>"
                   class="button <?php echo $unused ? 'button-primary' : ''; ?>">
                    Never Used (<?php echo $unused_count; ?>)
                </a>
                <?php if ( $search || $tag || $unused ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="obs-bulk-form">
                <input type="hidden" name="action" value="obs_bulk">
                <?php wp_nonce_field( 'obs_bulk' ); ?>

                <div style="margin:10px 0;display:flex;gap:8px;align-items:center;">
                    <select name="bulk_action" id="obs-bulk-action">
                        <option value="">Bulk actions</option>
                        <option value="delete">Delete</option>
                        <option value="add_tag">Add tag</option>
                        <option value="remove_tag">Remove tag</option>
                        <option value="mark_used">Mark as used</option>
                    </select>
                    <input type="text" name="bulk_tag" id="obs-bulk-tag" placeholder="Tag (for add/remove)" style="display:none;">
                    <input type="text" name="bulk_where" id="obs-bulk-where" placeholder="Where used (for mark used)" style="display:none;">
                    <button class="button" onclick="return confirm(obsData.confirmBulk);">Apply</button>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td style="width:30px;"><input type="checkbox" id="obs-select-all"></td>
                            <th style="width:50px;">ID</th>
                            <th>Comment</th>
                            <th style="width:130px;">Author</th>
                            <th style="width:160px;">Tags</th>
                            <th style="width:70px;">
                                <a href="<?php echo esc_url( add_query_arg( [ 'orderby' => 'usage_count', 'order' => ( $orderby === 'usage_count' && $order === 'DESC' ) ? 'asc' : 'desc' ] ) ); ?>">Used</a>
                            </th>
                            <th style="width:110px;">Added</th>
                            <th style="width:220px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $rows ) ) : ?>
                            <tr><td colspan="8">No comments found.</td></tr>
                        <?php else : foreach ( $rows as $row ) :
                            $full = $row->comment_text;
                            $trim = wp_trim_words( $full, 30, '…' );
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
                                    <?php
                                    $row_tags = array_filter( array_map( 'trim', explode( ',', $row->tags ) ) );
                                    foreach ( $row_tags as $t ) {
                                        echo '<a href="' . esc_url( add_query_arg( [ 'tag' => $t ] ) ) . '" class="obs-tag">' . esc_html( $t ) . '</a> ';
                                    }
                                    ?>
                                </td>
                                <td><strong><?php echo intval( $row->usage_count ); ?></strong></td>
                                <td><?php echo esc_html( date( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=obs-comments-new&id=' . $row->id ) ); ?>" class="button button-small">Edit</a>
                                    <a href="#" class="button button-small obs-use" data-id="<?php echo intval( $row->id ); ?>">Log Use</a>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=obs_delete_comment&id=' . $row->id ), 'obs_delete_' . $row->id ) ); ?>"
                                       class="button button-small"
                                       onclick="return confirm('Delete this comment?');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </form>

            <?php if ( $pages > 1 ) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo paginate_links( [
                        'base'      => add_query_arg( 'paged', '%#%' ),
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

        <!-- Log Use modal -->
        <div id="obs-use-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;">
            <div style="background:#fff;max-width:500px;margin:100px auto;padding:20px;border-radius:6px;">
                <h2>Log Usage</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="obs_log_usage">
                    <input type="hidden" name="id" id="obs-use-id" value="">
                    <?php wp_nonce_field( 'obs_log_usage' ); ?>
                    <p>
                        <label>Where was this used?</label><br>
                        <input type="text" name="usage_where" class="widefat" placeholder="e.g. March 2025 Newsletter" required>
                    </p>
                    <p>
                        <label>Date used</label><br>
                        <input type="date" name="usage_date" value="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>" required>
                    </p>
                    <p>
                        <button type="submit" class="button button-primary">Save</button>
                        <button type="button" class="button obs-modal-close">Cancel</button>
                    </p>
                </form>
            </div>
        </div>

        <style>
            .obs-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;text-decoration:none;margin:1px;}
            .obs-quote{font-style:italic;}
        </style>
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

        // Duplicate warning
        $duplicate_of = isset( $_GET['duplicate'] ) ? intval( $_GET['duplicate'] ) : 0;
        $dup_url      = $duplicate_of ? admin_url( 'admin.php?page=obs-comments-new&id=' . $duplicate_of ) : '';
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
                    <?php if ( $row ) : ?>
                    <tr>
                        <th>Usage Count</th>
                        <td><strong><?php echo intval( $row->usage_count ); ?></strong> times</td>
                    </tr>
                    <tr>
                        <th>Usage Log</th>
                        <td>
                            <?php if ( $row->usage_log ) : ?>
                                <ul style="margin:0;padding-left:18px;">
                                    <?php foreach ( array_filter( explode( "\n", $row->usage_log ) ) as $line ) : ?>
                                        <li><?php echo esc_html( $line ); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <em>Not used yet.</em>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <p>
                    <?php submit_button( $row ? 'Update Comment' : 'Save Comment', 'primary', 'submit', false ); ?>
                    <?php if ( ! $row ) : ?>
                        <button type="submit" name="save_and_new" value="1" class="button">Save &amp; Add Another</button>
                    <?php endif; ?>
                </p>
            </form>
        </div>
        <style>.obs-add-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;text-decoration:none;margin:2px;}</style>
        <?php
    }

    /* ============================================================
     * TAGS PAGE
     * ============================================================ */

    public function page_tags() {
        $tags = $this->get_all_tags_with_counts();

        // Handle rename/merge result
        if ( isset( $_GET['renamed'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Tag updated.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Manage Tags</h1>

            <div class="card" style="max-width:600px;padding:16px;margin-bottom:20px;">
                <h2>Rename / Merge Tag</h2>
                <p>Rename a tag or merge two tags into one (e.g. <code>student</code> → <code>students</code>).</p>
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
     * HANDLERS
     * ============================================================ */

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_save_comment' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = intval( $_POST['id'] ?? 0 );
        $force = ! empty( $_POST['force'] ) && $_POST['force'] === '1';

        $comment_text = wp_kses_post( wp_unslash( $_POST['comment_text'] ) );

        // Duplicate detection (only on new comments, unless forced)
        if ( ! $id && ! $force ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table WHERE comment_text = %s LIMIT 1",
                $comment_text
            ) );
            if ( $existing ) {
                wp_safe_redirect( admin_url( 'admin.php?page=obs-comments-new&duplicate=' . intval( $existing ) ) );
                exit;
            }
        }

        $data = [
            'comment_text' => $comment_text,
            'author_name'  => sanitize_text_field( $_POST['author_name'] ?? '' ),
            'author_email' => sanitize_email( $_POST['author_email'] ?? '' ),
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
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=deleted' ) );
        exit;
    }

    public function handle_log_usage() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_log_usage' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = intval( $_POST['id'] );
        $where = sanitize_text_field( $_POST['usage_where'] );
        $date  = sanitize_text_field( $_POST['usage_date'] );

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT usage_count, usage_log FROM $table WHERE id=%d", $id ) );
        if ( $row ) {
            $log   = trim( $row->usage_log );
            $entry = $date . ' — ' . $where;
            $log   = $log ? $log . "\n" . $entry : $entry;

            $wpdb->update( $table, [
                'usage_count' => intval( $row->usage_count ) + 1,
                'usage_log'   => $log,
                'updated_at'  => current_time( 'mysql' ),
            ], [ 'id' => $id ] );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=logged' ) );
        exit;
    }

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_export' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC", ARRAY_A );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="obs-comments-' . date( 'Y-m-d' ) . '.csv"' );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'ID', 'Comment', 'Author', 'Email', 'Date Received', 'Tags', 'Usage Count', 'Usage Log', 'Notes', 'Added' ] );
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $r['id'], $r['comment_text'], $r['author_name'], $r['author_email'],
                $r['source_date'], $r['tags'], $r['usage_count'], $r['usage_log'],
                $r['notes'], $r['created_at'],
            ] );
        }
        fclose( $out );
        exit;
    }

    /* -------- BULK ACTIONS -------- */

    public function handle_bulk() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'obs_bulk' );

        $ids    = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : [];
        $action = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        $tag    = sanitize_text_field( $_POST['bulk_tag'] ?? '' );
        $where  = sanitize_text_field( $_POST['bulk_where'] ?? '' );

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

                case 'delete':
                    $wpdb->delete( $table, [ 'id' => $id ] );
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

                case 'mark_used':
                    $log   = trim( $row->usage_log );
                    $entry = date( 'Y-m-d' ) . ' — ' . ( $where ?: 'Bulk marked as used' );
                    $log   = $log ? $log . "\n" . $entry : $entry;
                    $wpdb->update( $table, [
                        'usage_count' => intval( $row->usage_count ) + 1,
                        'usage_log'   => $log,
                        'updated_at'  => current_time( 'mysql' ),
                    ], [ 'id' => $id ] );
                    break;
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=obs-comments&msg=bulk_done' ) );
        exit;
    }

    /* -------- TAG RENAME / MERGE -------- */

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
     * HELPERS
     * ============================================================ */

    private function normalize_tags( $raw ) {
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $parts = array_unique( array_map( 'sanitize_text_field', $parts ) );
        $parts = array_filter( $parts ); // remove empties
        return implode( ',', $parts );
    }

    private function get_all_tags() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_col( "SELECT tags FROM $table WHERE tags != ''" );
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
        $rows   = $wpdb->get_col( "SELECT tags FROM $table WHERE tags != ''" );
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
            'deleted'    => 'Comment deleted.',
            'logged'     => 'Usage logged.',
            'bulk_done'  => 'Bulk action applied.',
            'bulk_none'  => 'No comments selected.',
        ][ $key ] ?? '';
    }
}

new OBS_Comments_Manager();
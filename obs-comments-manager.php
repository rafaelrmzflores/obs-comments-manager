<?php
/**
 * Plugin Name: Bible School Comments Manager
 * Description: Store, tag, search, and track usage of comments received via email for newsletters and fundraising letters.
 * Version: 1.0.0
 * Author: Custom
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OBS_Comments_Manager {

    const DB_VERSION = '1.0';
    const TABLE      = 'obs_comments';

    public function __construct() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'admin_post_bsc_save_comment', [ $this, 'handle_save' ] );
        add_action( 'admin_post_bsc_delete_comment', [ $this, 'handle_delete' ] );
        add_action( 'admin_post_bsc_log_usage', [ $this, 'handle_log_usage' ] );
        add_action( 'admin_post_bsc_export_csv', [ $this, 'handle_export' ] );
    }

    /* ---------- Database ---------- */

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
            KEY usage_count (usage_count)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        update_option( 'obs_db_version', self::DB_VERSION );
    }

    /* ---------- Admin Menu ---------- */

    public function admin_menu() {
        add_menu_page(
            'Bible School Comments',
            'OBS Comments',
            'manage_options',
            'bsc-comments',
            [ $this, 'page_list' ],
            'dashicons-format-quote',
            25
        );
        add_submenu_page(
            'bsc-comments',
            'Add New Comment',
            'Add New',
            'manage_options',
            'bsc-comments-new',
            [ $this, 'page_edit' ]
        );
        add_submenu_page(
            'bsc-comments',
            'Manage Tags',
            'Tags',
            'manage_options',
            'bsc-comments-tags',
            [ $this, 'page_tags' ]
        );
    }

    public function admin_assets( $hook ) {
        if ( strpos( $hook, 'bsc-comments' ) === false ) return;
        wp_enqueue_script(
            'bsc-admin',
            plugin_dir_url( __FILE__ ) . 'obs-admin.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
        wp_localize_script( 'bsc-admin', 'bscData', [
            'copiedMsg' => __( 'Copied!', 'bsc' ),
        ]);
    }

    /* ---------- List Page ---------- */

    public function page_list() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $tag      = isset( $_GET['tag'] ) ? sanitize_text_field( $_GET['tag'] ) : '';
        $orderby  = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'created_at';
        $order    = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $offset   = ( $paged - 1 ) * $per_page;

        $allowed_orderby = [ 'created_at', 'usage_count', 'author_name', 'id' ];
        if ( ! in_array( $orderby, $allowed_orderby, true ) ) $orderby = 'created_at';

        $where  = 'WHERE 1=1';
        $params = [];

        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (comment_text LIKE %s OR author_name LIKE %s OR notes LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ( $tag ) {
            $where .= ' AND FIND_IN_SET(%s, tags)';
            $params[] = $tag;
        }

        $sql = "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $count_sql = "SELECT COUNT(*) FROM $table $where";
        $total = $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, array_slice( $params, 0, -2 ) ) ) : $wpdb->get_var( $count_sql );
        $pages = ceil( $total / $per_page );

        $all_tags = $this->get_all_tags();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Bible School Comments</h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=bsc-comments-new' ) ); ?>" class="page-title-action">Add New</a>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bsc_export_csv' ), 'bsc_export' ) ); ?>" class="page-title-action">Export CSV</a>
            <hr class="wp-header-end">

            <?php if ( isset( $_GET['msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $this->msg_text( $_GET['msg'] ) ); ?></p></div>
            <?php endif; ?>

            <form method="get" style="margin:15px 0;">
                <input type="hidden" name="page" value="bsc-comments">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search comments...">
                <select name="tag">
                    <option value="">All tags</option>
                    <?php foreach ( $all_tags as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tag, $t ); ?>><?php echo esc_html( $t ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Filter</button>
                <?php if ( $search || $tag ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=bsc-comments' ) ); ?>" class="button">Reset</a>
                <?php endif; ?>
            </form>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:50px;">ID</th>
                        <th>Comment</th>
                        <th style="width:140px;">Author</th>
                        <th style="width:180px;">Tags</th>
                        <th style="width:80px;">
                            <a href="<?php echo esc_url( add_query_arg( [ 'orderby' => 'usage_count', 'order' => ( $orderby === 'usage_count' && $order === 'DESC' ) ? 'asc' : 'desc' ] ) ); ?>">Used</a>
                        </th>
                        <th style="width:150px;">Date Added</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="7">No comments found.</td></tr>
                    <?php else : foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo intval( $row->id ); ?></td>
                            <td>
                                <div class="bsc-quote" id="bsc-quote-<?php echo intval( $row->id ); ?>"><?php echo esc_html( wp_trim_words( $row->comment_text, 30 ) ); ?></div>
                                <div class="row-actions">
                                    <a href="#" class="bsc-copy" data-target="bsc-quote-<?php echo intval( $row->id ); ?>" data-full="<?php echo esc_attr( $row->comment_text ); ?>">Copy Full Text</a>
                                </div>
                            </td>
                            <td><?php echo esc_html( $row->author_name ); ?></td>
                            <td>
                                <?php
                                $tags = array_filter( array_map( 'trim', explode( ',', $row->tags ) ) );
                                foreach ( $tags as $t ) {
                                    echo '<a href="' . esc_url( add_query_arg( [ 'tag' => $t ] ) ) . '" class="bsc-tag">' . esc_html( $t ) . '</a> ';
                                }
                                ?>
                            </td>
                            <td><strong><?php echo intval( $row->usage_count ); ?></strong></td>
                            <td><?php echo esc_html( date( 'M j, Y', strtotime( $row->created_at ) ) ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bsc-comments-new&id=' . $row->id ) ); ?>" class="button button-small">Edit</a>
                                <a href="#" class="button button-small bsc-use" data-id="<?php echo intval( $row->id ); ?>">Log Use</a>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bsc_delete_comment&id=' . $row->id ), 'bsc_delete_' . $row->id ) ); ?>" class="button button-small" onclick="return confirm('Delete this comment?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

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

        <!-- Log Use Modal -->
        <div id="bsc-use-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9999;">
            <div style="background:#fff;max-width:500px;margin:100px auto;padding:20px;border-radius:6px;">
                <h2>Log Usage</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="bsc_log_usage">
                    <input type="hidden" name="id" id="bsc-use-id" value="">
                    <?php wp_nonce_field( 'bsc_log_usage' ); ?>
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
                        <button type="button" class="button" onclick="document.getElementById('bsc-use-modal').style.display='none';">Cancel</button>
                    </p>
                </form>
            </div>
        </div>

        <style>
            .bsc-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;text-decoration:none;margin:1px;}
            .bsc-quote{font-style:italic;}
        </style>
        <?php
    }

    /* ---------- Add/Edit Page ---------- */

    public function page_edit() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        $row   = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ) ) : null;
        ?>
        <div class="wrap">
            <h1><?php echo $row ? 'Edit Comment' : 'Add New Comment'; ?></h1>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="bsc_save_comment">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <?php wp_nonce_field( 'bsc_save_comment' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="comment_text">Comment *</label></th>
                        <td><textarea name="comment_text" id="comment_text" rows="8" class="large-text" required><?php echo esc_textarea( $row->comment_text ?? '' ); ?></textarea></td>
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
                            <input type="text" name="tags" id="tags" class="regular-text" value="<?php echo esc_attr( $row->tags ?? '' ); ?>" placeholder="Comma-separated, e.g. fundraising, student, answered prayer">
                            <p class="description">Separate with commas. Reuse existing tags for consistent filtering.</p>
                            <?php $all = $this->get_all_tags(); if ( $all ) : ?>
                                <p>Existing: <?php foreach ( $all as $t ) : ?><a href="#" class="bsc-add-tag" data-tag="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></a> <?php endforeach; ?></p>
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
                <?php submit_button( $row ? 'Update Comment' : 'Save Comment' ); ?>
            </form>
        </div>
        <style>.bsc-add-tag{display:inline-block;background:#eef;padding:2px 8px;border-radius:10px;font-size:11px;text-decoration:none;margin:2px;}</style>
        <?php
    }

    /* ---------- Tags Page ---------- */

    public function page_tags() {
        $tags = $this->get_all_tags_with_counts();
        ?>
        <div class="wrap">
            <h1>Manage Tags</h1>
            <p>Tags are created automatically when you enter them on a comment. Below is a summary of all tags and how many comments use them.</p>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Tag</th><th style="width:120px;">Comments</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody>
                <?php if ( empty( $tags ) ) : ?>
                    <tr><td colspan="3">No tags yet.</td></tr>
                <?php else : foreach ( $tags as $tag => $count ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $tag ); ?></strong></td>
                        <td><?php echo intval( $count ); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=bsc-comments&tag=' . urlencode( $tag ) ) ); ?>">View</a>
                            <?php if ( $count == 0 ) : ?>
                                <a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bsc_delete_tag&tag=' . urlencode( $tag ) ), 'bsc_delete_tag' ) ); ?>">Remove (unused)</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ---------- Handlers ---------- */

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'bsc_save_comment' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $id    = intval( $_POST['id'] ?? 0 );

        $data = [
            'comment_text' => wp_kses_post( wp_unslash( $_POST['comment_text'] ) ),
            'author_name'  => sanitize_text_field( $_POST['author_name'] ?? '' ),
            'author_email' => sanitize_email( $_POST['author_email'] ?? '' ),
            'source_date'  => ! empty( $_POST['source_date'] ) ? sanitize_text_field( $_POST['source_date'] ) : null,
            'tags'         => $this->normalize_tags( $_POST['tags'] ?? '' ),
            'notes'        => sanitize_textarea_field( $_POST['notes'] ?? '' ),
            'updated_at'   => current_time( 'mysql' ),
        ];

        if ( $id ) {
            $wpdb->update( $table, $data, [ 'id' => $id ] );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=bsc-comments&msg=saved' ) );
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        $id = intval( $_GET['id'] ?? 0 );
        check_admin_referer( 'bsc_delete_' . $id );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ] );

        wp_safe_redirect( admin_url( 'admin.php?page=bsc-comments&msg=deleted' ) );
        exit;
    }

    public function handle_log_usage() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'bsc_log_usage' );

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

        wp_safe_redirect( admin_url( 'admin.php?page=bsc-comments&msg=logged' ) );
        exit;
    }

    public function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );
        check_admin_referer( 'bsc_export' );

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY id DESC", ARRAY_A );

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="bsc-comments-' . date( 'Y-m-d' ) . '.csv"' );
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

    /* ---------- Helpers ---------- */

    private function normalize_tags( $raw ) {
        $parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
        $parts = array_unique( array_map( 'sanitize_text_field', $parts ) );
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
        $table = $wpdb->prefix . self::TABLE;
        $rows  = $wpdb->get_col( "SELECT tags FROM $table WHERE tags != ''" );
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
            'saved'   => 'Comment saved.',
            'deleted' => 'Comment deleted.',
            'logged'  => 'Usage logged.',
        ][ $key ] ?? '';
    }
}

new OBS_Comments_Manager();
<?php
/**
 * Plugin Name: OBS Legacy Usage Migration
 * Description: One-time importer for the obs-usage-import.csv produced by build_csv.py.
 *              Adds Tools → Import Legacy Usage. Delete this file when done.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// add_action( 'admin_menu', function () {
//     add_management_page(
//         'Import Legacy Usage',
//         'Import Legacy Usage',
//         'manage_options',
//         'obs-legacy-usage',
//         'obs_legacy_usage_page'
//     );
// } );

function obs_legacy_usage_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $upload_dir = wp_upload_dir();
    $csv_path   = $upload_dir['basedir'] . '/obs-usage-import.csv';

    $running = isset( $_POST['obs_run'] );

    echo '<div class="wrap"><h1>Import Legacy Usage</h1>';

    if ( ! $running ) {
        echo '<p>Place <code>obs-usage-import.csv</code> in:</p>';
        echo '<p><code>' . esc_html( $csv_path ) . '</code></p>';
        echo '<p>Status: ' . ( file_exists( $csv_path ) ? '<strong style="color:green">FOUND</strong>' : '<strong style="color:red">NOT FOUND</strong>' ) . '</p>';

        if ( file_exists( $csv_path ) ) {
            echo '<form method="post">';
            wp_nonce_field( 'obs_legacy_usage' );
            echo '<p><label><input type="checkbox" name="mark_used" value="1" checked> Also update each comment\'s usage_count and usage_log field</label></p>';
            echo '<p><button class="button button-primary" name="obs_run" value="1">Run Import</button></p>';
            echo '</form>';
        }
        echo '</div>';
        return;
    }

    check_admin_referer( 'obs_legacy_usage' );

    global $wpdb;
    $comments_table = $wpdb->prefix . 'obs_comments';
    $usage_table    = $wpdb->prefix . 'obs_usage';
    $mark_used      = ! empty( $_POST['mark_used'] );

    if ( ! file_exists( $csv_path ) ) {
        echo '<div class="notice notice-error"><p>CSV not found.</p></div></div>';
        return;
    }

    $fh = fopen( $csv_path, 'r' );
    if ( ! $fh ) {
        echo '<div class="notice notice-error"><p>Could not open CSV.</p></div></div>';
        return;
    }

    $header = fgetcsv( $fh );
    $inserted      = 0;
    $skipped_no_c  = 0;
    $skipped_dupe  = 0;
    $errors        = 0;
    $per_comment   = [];   // comment_id => count of new rows

    while ( ( $row = fgetcsv( $fh ) ) !== false ) {
        $data = array_combine( $header, $row );
        if ( empty( $data['comment_text'] ) ) continue;

        // Find matching comment (exact text, not trashed)
        $comment_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $comments_table WHERE comment_text = %s AND deleted_at IS NULL LIMIT 1",
            $data['comment_text']
        ) );
        if ( ! $comment_id ) { $skipped_no_c++; continue; }

        $month = intval( $data['month'] ?? 0 );
        $year  = intval( $data['year']  ?? 0 );

        // Skip if identical row already exists (idempotent re-runs)
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $usage_table
             WHERE comment_id = %d AND place_name = %s AND month = %d AND year = %d AND recipients = %s
             LIMIT 1",
            $comment_id,
            $data['place_name'],
            $month,
            $year,
            $data['recipients'] ?? ''
        ) );
        if ( $exists ) { $skipped_dupe++; continue; }

        $ok = $wpdb->insert( $usage_table, [
            'comment_id' => $comment_id,
            'place_name' => $data['place_name'],
            'month'      => $month ?: null,
            'year'       => $year  ?: null,
            'recipients' => $data['recipients'] ?? '',
            'note'       => $data['note'] ?? '',
            'created_at' => current_time( 'mysql' ),
        ] );
        if ( $ok ) {
            $inserted++;
            $per_comment[ $comment_id ] = ( $per_comment[ $comment_id ] ?? 0 ) + 1;
        } else {
            $errors++;
        }
    }
    fclose( $fh );

    // Optionally update usage_count on each comment
    if ( $mark_used && $per_comment ) {
        foreach ( $per_comment as $cid => $added ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $comments_table
                 SET usage_count = usage_count + %d, updated_at = %s
                 WHERE id = %d",
                $added,
                current_time( 'mysql' ),
                $cid
            ) );
        }
    }

    echo '<div class="notice notice-success"><p><strong>Done.</strong></p><ul>';
    echo '<li>Usage rows inserted: <strong>' . intval( $inserted )     . '</strong></li>';
    echo '<li>Skipped (no matching comment): <strong>' . intval( $skipped_no_c ) . '</strong></li>';
    echo '<li>Skipped (already existed): <strong>'    . intval( $skipped_dupe ) . '</strong></li>';
    echo '<li>Errors: <strong>'                       . intval( $errors )      . '</strong></li>';
    echo '</ul></div>';
    echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=obs-comments' ) ) . '">View Comments</a></p>';
    echo '</div>';
}
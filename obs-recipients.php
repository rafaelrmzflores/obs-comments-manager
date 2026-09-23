<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Recipient groups — stored in WP options so users can add/remove them.
 * Defaults are seeded on first activation.
 */
function obs_get_recipients() {
    $defaults = [ 'students', 'supporters', 'general', 'staff', 'donors', 'alumni' ];
    $stored   = get_option( 'obs_recipients', null );

    if ( $stored === null ) {
        update_option( 'obs_recipients', $defaults );
        return $defaults;
    }

    return is_array( $stored ) ? $stored : $defaults;
}

function obs_save_recipients( $list ) {
    $list = array_filter( array_map( 'sanitize_text_field', (array) $list ) );
    $list = array_values( array_unique( $list ) );
    sort( $list );
    update_option( 'obs_recipients', $list );
    return $list;
}

/**
 * Render checkboxes for all recipient groups.
 *
 * @param array $selected  Recipient slugs currently checked.
 */
function obs_render_recipient_checkboxes( $selected = [] ) {
    $all = obs_get_recipients();
    if ( empty( $all ) ) {
        echo '<em>No recipient groups defined yet. Add some on the Recipients page.</em>';
        return;
    }
    $selected = array_map( 'strval', (array) $selected );
    foreach ( $all as $r ) {
        printf(
            '<label style="display:inline-block;margin:0 12px 6px 0;"><input type="checkbox" name="recipients[]" value="%s" %s> %s</label>',
            esc_attr( $r ),
            in_array( $r, $selected, true ) ? 'checked' : '',
            esc_html( ucfirst( $r ) )
        );
    }
}
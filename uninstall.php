<?php
/**
 * Removes the settings. Submissions are kept unless the
 * SUBMITDOCK_DELETE_DATA constant is true, so uninstalling never loses messages by accident.
 *
 * @package SubmitDock
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'submitdock_settings' );
wp_clear_scheduled_hook( 'submitdock_purge_spam' );

if ( defined( 'SUBMITDOCK_DELETE_DATA' ) && SUBMITDOCK_DELETE_DATA ) {
	$submitdock_ids = get_posts(
		array(
			'post_type'      => 'submitdock_entry',
			'post_status'    => array( 'private', 'publish', 'draft', 'trash', 'submitdock_spam' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $submitdock_ids as $submitdock_id ) {
		wp_delete_post( $submitdock_id, true );
	}
}

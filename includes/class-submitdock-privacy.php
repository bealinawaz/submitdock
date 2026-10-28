<?php
/**
 * Privacy policy text and personal data export and erasure.
 *
 * @package SubmitDock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connects submissions to the WordPress privacy tools.
 */
final class SubmitDock_Privacy {

	const PAGE_SIZE = 50;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Suggests privacy policy text.
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text = '<p>' . esc_html__( 'When you submit a form on this website, the information you enter is stored on this site and emailed to the site owner. Depending on the settings, the submission also stores the page you submitted it from, your browser and device type, your IP address, and an approximate location based on that IP address, looked up through the ipwho.is service.', 'submitdock' ) . '</p>';
		wp_add_privacy_policy_content( __( 'SubmitDock', 'submitdock' ), $text );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['submitdock'] = array(
			'exporter_friendly_name' => __( 'Form submissions', 'submitdock' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['submitdock'] = array(
			'eraser_friendly_name' => __( 'Form submissions', 'submitdock' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Submission IDs sent from an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number, starting at 1.
	 * @return int[]
	 */
	private static function find( $email, $page ) {
		return get_posts(
			array(
				'post_type'      => SubmitDock_Submissions::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => self::PAGE_SIZE,
				'paged'          => $page,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'meta_key'       => SubmitDock_Submissions::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => strtolower( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/**
	 * Exports submissions for an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		$ids   = self::find( $email, $page );
		$items = array();
		foreach ( $ids as $id ) {
			$data = array(
				array(
					'name'  => __( 'Received', 'submitdock' ),
					'value' => get_post_time( 'Y-m-d H:i:s', true, $id ) . ' UTC',
				),
			);
			foreach ( (array) get_post_meta( $id, SubmitDock_Submissions::META_FIELDS, true ) as $key => $value ) {
				$data[] = array(
					'name'  => SubmitDock_Submissions::label_for( $key ),
					'value' => SubmitDock_Submissions::format_value( $value ),
				);
			}
			$labels = SubmitDock_Submissions::visitor_labels();
			foreach ( (array) get_post_meta( $id, SubmitDock_Submissions::META_VISITOR, true ) as $key => $value ) {
				$data[] = array(
					'name'  => $labels[ $key ] ?? SubmitDock_Submissions::label_for( $key ),
					'value' => SubmitDock_Submissions::format_value( $value ),
				);
			}
			$items[] = array(
				'group_id'    => 'submitdock',
				'group_label' => __( 'Form submissions', 'submitdock' ),
				'item_id'     => 'submitdock-submission-' . $id,
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => count( $ids ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Deletes submissions for an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page number. Always 1, because deleted posts drop out of the results.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );
		$ids     = self::find( $email, 1 );
		$removed = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( $id, true ) ) {
				++$removed;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PAGE_SIZE,
		);
	}
}

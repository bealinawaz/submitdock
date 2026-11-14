<?php
/**
 * Spam protection: CAPTCHA checks, Akismet, and the spam folder.
 *
 * @package SubmitDock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks submissions for spam and keeps suspected spam in its own folder.
 */
final class SubmitDock_Spam {

	const STATUS      = 'submitdock_spam';
	const META_REASON = '_submitdock_spam_reason';
	const META_CHECK  = '_submitdock_akismet';
	const CRON        = 'submitdock_purge_spam';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		if ( is_admin() ) {
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
			add_action( 'admin_post_submitdock_mark', array( __CLASS__, 'handle_mark' ) );
			add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
			add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );
		}
	}

	/**
	 * Registers the Spam status, listed as its own view on the Form Submissions screen.
	 */
	public static function register_status() {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Spam', 'submission status', 'submitdock' ),
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of spam submissions. */
				'label_count'               => _n_noop( 'Spam <span class="count">(%s)</span>', 'Spam <span class="count">(%s)</span>', 'submitdock' ),
			)
		);
	}

	/**
	 * Schedules the daily removal of old spam.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Permanently deletes spam older than the number of days set under Settings (0 keeps it).
	 */
	public static function purge() {
		$days = (int) SubmitDock_Settings::get()['spam_retention_days'];
		if ( $days <= 0 ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => SubmitDock_Submissions::POST_TYPE,
				'post_status'    => self::STATUS,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'date_query'     => array(
					array(
						'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
						'column' => 'post_date_gmt',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * CAPTCHA
	 * ------------------------------------------------------------------
	 */

	/**
	 * Field names that can hold a CAPTCHA token: each service's own field and the generic __captcha.
	 *
	 * @return string[]
	 */
	public static function token_fields() {
		return array_merge( array( '__captcha' ), wp_list_pluck( SubmitDock_Settings::captcha_providers(), 'field' ) );
	}

	/**
	 * Takes the CAPTCHA token out of the payload so it is never saved with the submission.
	 *
	 * @param array $payload Request payload, changed in place.
	 * @return string Token, or an empty string.
	 */
	public static function extract_token( array &$payload ) {
		$token = '';
		foreach ( self::token_fields() as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				if ( '' === $token && is_string( $payload[ $field ] ) ) {
					$token = trim( $payload[ $field ] );
				}
				unset( $payload[ $field ] );
			}
		}
		return strlen( $token ) <= 4096 ? $token : '';
	}

	/**
	 * Checks a CAPTCHA token with the chosen service.
	 *
	 * @param string $token Token from the form.
	 * @param string $ip    Visitor IP address, or an empty string.
	 * @return string "pass", "fail", or "error" when the service could not be reached.
	 */
	public static function verify_captcha( $token, $ip ) {
		$settings  = SubmitDock_Settings::get();
		$providers = SubmitDock_Settings::captcha_providers();
		$provider  = $settings['captcha_provider'];
		if ( ! isset( $providers[ $provider ] ) || '' === $settings['captcha_secret'] ) {
			return 'pass';
		}
		if ( '' === $token ) {
			return 'fail';
		}

		$body = array(
			'secret'   => $settings['captcha_secret'],
			'response' => $token,
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}
		if ( 'hcaptcha' === $provider && '' !== $settings['captcha_site_key'] ) {
			$body['sitekey'] = $settings['captcha_site_key'];
		}

		$response = wp_remote_post(
			$providers[ $provider ]['verify_url'],
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'body'        => $body,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return 'error';
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['success'] ) ) {
			return 'error';
		}
		if ( true !== $data['success'] ) {
			return 'fail';
		}
		// reCAPTCHA v3 adds a score from 0.0 (likely a bot) to 1.0; v2 has none.
		if ( 'recaptcha' === $provider && isset( $data['score'] ) && (float) $data['score'] < (float) $settings['recaptcha_min_score'] ) {
			return 'fail';
		}
		return 'pass';
	}

	/*
	 * ------------------------------------------------------------------
	 * Akismet
	 * ------------------------------------------------------------------
	 */

	/**
	 * The data Akismet checks: sender, message text, and visitor details.
	 *
	 * @param array  $fields  Sanitized fields.
	 * @param string $email   Sender email.
	 * @param array  $visitor Visitor details.
	 * @param string $ip      Visitor IP address (sent even when IP saving is off).
	 * @param string $agent   Visitor user agent.
	 * @return array
	 */
	public static function akismet_data( array $fields, $email, array $visitor, $ip, $agent ) {
		$text = array();
		foreach ( $fields as $key => $value ) {
			$text[] = SubmitDock_Submissions::label_for( $key ) . ': ' . SubmitDock_Submissions::format_value( $value );
		}
		return array(
			'blog'                 => home_url( '/' ),
			'blog_lang'            => get_locale(),
			'blog_charset'         => get_option( 'blog_charset' ),
			'user_ip'              => $ip,
			'user_agent'           => $agent,
			'referrer'             => isset( $visitor['referrer'] ) ? $visitor['referrer'] : '',
			'permalink'            => isset( $visitor['page_url'] ) ? $visitor['page_url'] : '',
			'comment_type'         => 'contact-form',
			'comment_author'       => SubmitDock_Submissions::sender_name( $fields ),
			'comment_author_email' => $email,
			'comment_content'      => implode( "\n", $text ),
		);
	}

	/**
	 * Asks Akismet whether a submission is spam.
	 *
	 * @param array $data Data from akismet_data().
	 * @return bool
	 */
	public static function akismet_is_spam( array $data ) {
		if ( ! SubmitDock_Settings::akismet_enabled() ) {
			return false;
		}
		$response = Akismet::http_post( build_query( $data ), 'comment-check' );
		return is_array( $response ) && isset( $response[1] ) && 'true' === trim( (string) $response[1] );
	}

	/**
	 * Tells Akismet it made a mistake, so it learns from your corrections.
	 *
	 * @param int  $post_id Submission ID.
	 * @param bool $is_spam True to report spam, false to report a good submission.
	 */
	private static function akismet_report( $post_id, $is_spam ) {
		$data = get_post_meta( $post_id, self::META_CHECK, true );
		if ( ! is_array( $data ) || ! $data || ! SubmitDock_Settings::akismet_enabled() ) {
			return;
		}
		Akismet::http_post( build_query( $data ), $is_spam ? 'submit-spam' : 'submit-ham' );
	}

	/*
	 * ------------------------------------------------------------------
	 * Spam folder
	 * ------------------------------------------------------------------
	 */

	/**
	 * Readable reason a submission was marked as spam.
	 *
	 * @param string $reason Stored reason.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$labels = array(
			'akismet'       => __( 'Akismet flagged it as spam', 'submitdock' ),
			'captcha_error' => __( 'The spam check service could not be reached', 'submitdock' ),
			'manual'        => __( 'Marked as spam by an admin', 'submitdock' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : '';
	}

	/**
	 * Shows "Spam" next to the title in the All view of search results.
	 *
	 * @param array   $states Post states.
	 * @param WP_Post $post   Post.
	 * @return array
	 */
	public static function post_states( $states, $post ) {
		if ( SubmitDock_Submissions::POST_TYPE === $post->post_type && self::STATUS === $post->post_status ) {
			$states = array( 'submitdock_spam' => _x( 'Spam', 'submission status', 'submitdock' ) );
		}
		return $states;
	}

	/**
	 * Adds "Spam" and "Not spam" row actions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Submission.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( SubmitDock_Submissions::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) || 'trash' === $post->post_status ) {
			return $actions;
		}
		$spam                       = self::STATUS === $post->post_status;
		$url                        = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'submitdock_mark',
					'post_id' => $post->ID,
					'as'      => $spam ? 'ham' : 'spam',
				),
				admin_url( 'admin-post.php' )
			),
			'submitdock_mark_' . $post->ID
		);
		$label                      = $spam ? __( 'Not spam', 'submitdock' ) : __( 'Spam', 'submitdock' );
		$actions['submitdock_spam'] = '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		return $actions;
	}

	/**
	 * Moves a submission to or from the spam folder.
	 */
	public static function handle_mark() {
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0;
		check_admin_referer( 'submitdock_mark_' . $post_id );
		$post = get_post( $post_id );
		if ( ! $post || SubmitDock_Submissions::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to change this submission.', 'submitdock' ), 403 );
		}
		$as_spam = isset( $_GET['as'] ) && 'spam' === sanitize_key( wp_unslash( $_GET['as'] ) );
		self::move( $post_id, $as_spam );

		$back = add_query_arg(
			array(
				'post_type'        => SubmitDock_Submissions::POST_TYPE,
				'submitdock_moved' => $as_spam ? 'spam' : 'ham',
				'post_status'      => $as_spam ? false : self::STATUS,
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Moves a submission to or from the spam folder and reports the correction to Akismet.
	 *
	 * @param int  $post_id Submission ID.
	 * @param bool $as_spam True for spam, false for the inbox.
	 */
	public static function move( $post_id, $as_spam ) {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $as_spam ? self::STATUS : 'private',
			)
		);
		if ( $as_spam ) {
			update_post_meta( $post_id, self::META_REASON, 'manual' );
		} else {
			delete_post_meta( $post_id, self::META_REASON );
		}
		self::akismet_report( $post_id, $as_spam );
	}

	/**
	 * Confirms a move to or from the spam folder.
	 */
	public static function admin_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads a flag to show a message.
		$moved = isset( $_GET['submitdock_moved'] ) ? sanitize_key( wp_unslash( $_GET['submitdock_moved'] ) ) : '';
		if ( 'spam' === $moved ) {
			$message = __( 'Submission moved to Spam.', 'submitdock' );
		} elseif ( 'ham' === $moved ) {
			$message = __( 'Submission moved back to the inbox.', 'submitdock' );
		} else {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}
}

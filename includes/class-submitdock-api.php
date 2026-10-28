<?php
/**
 * REST API endpoint that receives submissions.
 *
 * @package SubmitDock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the submit endpoint, validates requests, and sends notifications.
 */
final class SubmitDock_API {

	const NAMESPACE  = 'submitdock/v1';
	const MAX_FIELDS = 50;
	const MAX_BYTES  = 20000;
	const MAX_TEXT   = 5000;
	const MAX_DEPTH  = 5;
	const MAX_ITEMS  = 100;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'allowed_http_origins', array( __CLASS__, 'allowed_origins' ) );
	}

	/**
	 * The endpoint URL, optionally for a named form.
	 *
	 * @param string $form Form identifier.
	 * @return string
	 */
	public static function endpoint( $form = '' ) {
		return rest_url( self::NAMESPACE . '/submit' . ( '' !== $form ? '/' . $form : '' ) );
	}

	/**
	 * Registers /submit and /submit/{form}.
	 */
	public static function register_routes() {
		$args = array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'submit' ),
			'permission_callback' => array( __CLASS__, 'check_origin' ),
		);
		register_rest_route( self::NAMESPACE, '/submit', $args );
		register_rest_route( self::NAMESPACE, '/submit/(?P<form>[a-z0-9_-]{1,64})', $args );
	}

	/**
	 * Whether the current request targets this plugin's endpoint.
	 *
	 * @return bool
	 */
	private static function is_own_request() {
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		return 0 === strpos( $route, '/' . self::NAMESPACE . '/submit' );
	}

	/**
	 * Adds the configured origins to WordPress's CORS allow-list for this endpoint.
	 *
	 * @param string[] $origins Allowed origins.
	 * @return string[]
	 */
	public static function allowed_origins( $origins ) {
		if ( ! self::is_own_request() ) {
			return $origins;
		}
		$allowed = SubmitDock_Settings::get()['allowed_origins'];
		if ( ! $allowed ) {
			$request_origin = get_http_origin();
			$allowed        = $request_origin ? array( $request_origin ) : array();
		}
		return array_values( array_unique( array_merge( $origins, $allowed ) ) );
	}

	/**
	 * Rejects requests from websites that are not on the allow-list.
	 *
	 * @return true|WP_Error
	 */
	public static function check_origin() {
		$settings = SubmitDock_Settings::get();
		if ( ! $settings['allowed_origins'] ) {
			return true;
		}
		$origin = get_http_origin();
		if ( $origin && in_array( SubmitDock_Settings::normalize_origin( $origin ), $settings['allowed_origins'], true ) ) {
			return true;
		}
		return new WP_Error( 'submitdock_origin', $settings['origin_message'], array( 'status' => 403 ) );
	}

	/**
	 * Handles a submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function submit( WP_REST_Request $request ) {
		$settings = SubmitDock_Settings::get();
		$is_json  = null !== $request->get_json_params();
		$payload  = $is_json ? $request->get_json_params() : $request->get_body_params();

		$limited = self::check_rate_limit( $settings );
		if ( is_wp_error( $limited ) ) {
			return self::respond( $limited, $is_json, $payload );
		}

		if ( ! is_array( $payload ) || ! $payload || wp_is_numeric_array( $payload ) ) {
			return self::respond( self::error( 'invalid', $settings['invalid_message'], 400 ), $is_json, $payload );
		}

		$context = isset( $payload['__context'] ) && is_array( $payload['__context'] ) ? $payload['__context'] : array();
		// Keys starting with __ are reserved for instructions such as __context and __redirect.
		$fields = array_filter(
			$payload,
			static function ( $key ) {
				return 0 !== strpos( (string) $key, '__' );
			},
			ARRAY_FILTER_USE_KEY
		);

		$honeypot = $settings['honeypot_field'];
		if ( '' !== $honeypot && array_key_exists( $honeypot, $fields ) ) {
			if ( '' !== $fields[ $honeypot ] && null !== $fields[ $honeypot ] && false !== $fields[ $honeypot ] ) {
				return self::respond( self::error( 'spam', $settings['spam_message'], 400 ), $is_json, $payload );
			}
			unset( $fields[ $honeypot ] );
		}

		$fields = self::sanitize_fields( $fields );
		if ( is_wp_error( $fields ) ) {
			return self::respond( self::error( 'invalid', $settings['invalid_message'], 400 ), $is_json, $payload );
		}

		/**
		 * Filters the sanitized fields before a submission is saved. Return a WP_Error to reject it.
		 *
		 * @param array|WP_Error  $fields  Sanitized fields.
		 * @param WP_REST_Request $request Request.
		 */
		$fields = apply_filters( 'submitdock_fields', $fields, $request );
		if ( is_wp_error( $fields ) ) {
			return self::respond( $fields, $is_json, $payload );
		}

		$form    = (string) $request->get_param( 'form' );
		$visitor = self::visitor_details( $request, $context, $settings );
		$email   = '';
		$reply   = $settings['reply_to_field'];
		if ( '' !== $reply && isset( $fields[ $reply ] ) && is_string( $fields[ $reply ] ) && is_email( $fields[ $reply ] ) ) {
			$email = sanitize_email( $fields[ $reply ] );
		}

		$post_id = SubmitDock_Submissions::create( $fields, $visitor, $form, $email );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return self::respond( self::error( 'storage', $settings['storage_failed_message'], 500 ), $is_json, $payload );
		}

		$status = 'disabled';
		if ( $settings['email_notifications'] ) {
			$sent_to = self::notify( $settings, $fields, $form, $email, $visitor );
			$status  = $sent_to['sent'] ? 'sent' : 'failed';
			update_post_meta( $post_id, SubmitDock_Submissions::META_SENT_TO, $sent_to['to'] );
		}
		update_post_meta( $post_id, SubmitDock_Submissions::META_STATUS, $status );

		/**
		 * Fires after a submission is saved and the notification is handled.
		 *
		 * @param int    $post_id Submission ID.
		 * @param array  $fields  Sanitized fields.
		 * @param string $form    Form identifier, or an empty string.
		 * @param array  $visitor Visitor details.
		 */
		do_action( 'submitdock_submission_saved', $post_id, $fields, $form, $visitor );

		$failed   = 'failed' === $status;
		$response = new WP_REST_Response(
			array(
				'success'      => true,
				'message'      => $failed ? $settings['mail_failed_message'] : $settings['success_message'],
				'email_status' => $status,
			),
			$failed ? 202 : 200
		);
		return self::respond( $response, $is_json, $payload );
	}

	/**
	 * Returns JSON, or redirects plain HTML form posts that ask for it.
	 *
	 * HTML forms can send a __redirect field. The visitor is sent back to that URL
	 * with ?form_status=success or ?form_status=error, but only to allowed websites.
	 *
	 * @param WP_REST_Response|WP_Error $result  Result.
	 * @param bool                      $is_json Whether the request body was JSON.
	 * @param mixed                     $payload Request payload.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $result, $is_json, $payload ) {
		$target = ! $is_json && is_array( $payload ) && isset( $payload['__redirect'] ) && is_string( $payload['__redirect'] ) ? self::safe_redirect_url( $payload['__redirect'] ) : '';
		if ( '' === $target ) {
			return $result;
		}
		$response = new WP_REST_Response( null, 303 );
		$response->header( 'Location', add_query_arg( 'form_status', is_wp_error( $result ) ? 'error' : 'success', $target ) );
		return $response;
	}

	/**
	 * Returns the URL if it belongs to an allowed website or this site, otherwise an empty string.
	 *
	 * @param string $url Requested redirect.
	 * @return string
	 */
	private static function safe_redirect_url( $url ) {
		$url    = esc_url_raw( $url, array( 'http', 'https' ) );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! $scheme || ! $host ) {
			return '';
		}
		$origin  = strtolower( $scheme . '://' . $host ) . ( $port ? ':' . $port : '' );
		$allowed = SubmitDock_Settings::get()['allowed_origins'];
		$allowed = array_merge( $allowed, array( SubmitDock_Settings::normalize_origin( home_url( '/' ) ) ) );
		return in_array( $origin, $allowed, true ) ? $url : '';
	}

	/**
	 * Counts submissions per IP address and rejects them over the limit.
	 *
	 * @param array $settings Settings.
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( array $settings ) {
		$key   = 'submitdock_rate_' . hash_hmac( 'sha256', self::client_ip(), wp_salt( 'nonce' ) );
		$count = (int) get_transient( $key );
		if ( $count >= $settings['rate_limit'] ) {
			return self::error( 'rate_limited', $settings['rate_limit_message'], 429 );
		}
		set_transient( $key, $count + 1, $settings['rate_window_minutes'] * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * The visitor's IP address.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the visitor IP address, for sites behind a proxy or CDN that passes the real address in a header.
		 *
		 * @param string $ip IP address from REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'submitdock_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Validates and sanitizes the top-level fields.
	 *
	 * @param array $fields Raw fields.
	 * @return array|WP_Error
	 */
	private static function sanitize_fields( array $fields ) {
		if ( ! $fields || count( $fields ) > self::MAX_FIELDS ) {
			return new WP_Error( 'count' );
		}
		$json = wp_json_encode( $fields );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'size' );
		}
		$clean = self::sanitize_value( $fields, 0 );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		foreach ( array_keys( $clean ) as $key ) {
			if ( is_int( $key ) ) {
				return new WP_Error( 'key' );
			}
		}
		return $clean;
	}

	/**
	 * Recursively sanitizes a value.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Nesting depth.
	 * @return mixed|WP_Error
	 */
	private static function sanitize_value( $value, $depth ) {
		if ( is_string( $value ) ) {
			return mb_strlen( $value ) > self::MAX_TEXT ? new WP_Error( 'length' ) : sanitize_textarea_field( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( ! is_array( $value ) || $depth > self::MAX_DEPTH || count( $value ) > self::MAX_ITEMS ) {
			return new WP_Error( 'value' );
		}

		$clean = array();
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) ) {
				$key = sanitize_text_field( $key );
				if ( '' === $key || mb_strlen( $key ) > 100 ) {
					return new WP_Error( 'key' );
				}
			}
			if ( array_key_exists( $key, $clean ) ) {
				return new WP_Error( 'duplicate' );
			}
			$child = self::sanitize_value( $child, $depth + 1 );
			if ( is_wp_error( $child ) ) {
				return $child;
			}
			$clean[ $key ] = $child;
		}
		return $clean;
	}

	/**
	 * Collects page, browser, and optional IP and location details.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param array           $context  Client-supplied __context.
	 * @param array           $settings Settings.
	 * @return array
	 */
	private static function visitor_details( WP_REST_Request $request, array $context, array $settings ) {
		$agent    = substr( sanitize_text_field( (string) $request->get_header( 'user_agent' ) ), 0, 500 );
		$referer  = (string) $request->get_header( 'referer' );
		$page_url = self::context_url( $context['page_url'] ?? $context['submission_url'] ?? '' );
		$details  = array(
			'page_url'   => $page_url ? $page_url : self::context_url( $referer ),
			'referrer'   => self::context_url( $context['referrer'] ?? '' ),
			'origin'     => (string) get_http_origin(),
			'ip_address' => '',
			'browser'    => self::browser( $agent ),
			'os'         => self::operating_system( $agent ),
			'device'     => self::device( $agent ),
			'user_agent' => $agent,
		);

		if ( $settings['store_ip'] ) {
			$details['ip_address'] = self::client_ip();
			if ( $settings['location_lookup'] ) {
				$details = array_merge( $details, self::location( $details['ip_address'] ) );
			}
		}
		return array_filter(
			$details,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * Returns a safe http(s) URL or an empty string.
	 *
	 * @param mixed $value URL.
	 * @return string
	 */
	private static function context_url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		return wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
	}

	/**
	 * Browser name and version from a user agent.
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	private static function browser( $agent ) {
		$patterns = array(
			'/(?:EdgA|EdgiOS|Edg)\/([\d.]+)/i' => 'Edge',
			'/(?:OPR|Opera)\/([\d.]+)/i'       => 'Opera',
			'/(?:Firefox|FxiOS)\/([\d.]+)/i'   => 'Firefox',
			'/(?:CriOS|Chrome)\/([\d.]+)/i'    => 'Chrome',
			'/Version\/([\d.]+).*Safari\//i'   => 'Safari',
		);
		foreach ( $patterns as $pattern => $name ) {
			if ( preg_match( $pattern, $agent, $matches ) ) {
				return $name . ' ' . $matches[1];
			}
		}
		return '';
	}

	/**
	 * Operating system from a user agent.
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	private static function operating_system( $agent ) {
		$patterns = array(
			'/Windows NT/i'                    => 'Windows',
			'/Android ([\d.]+)/i'              => 'Android',
			'/(?:iPhone OS|CPU OS) ([\d_]+)/i' => 'iOS',
			'/Mac OS X ([\d_]+)/i'             => 'macOS',
			'/CrOS/i'                          => 'ChromeOS',
			'/Linux/i'                         => 'Linux',
		);
		foreach ( $patterns as $pattern => $name ) {
			if ( preg_match( $pattern, $agent, $matches ) ) {
				return isset( $matches[1] ) ? $name . ' ' . str_replace( '_', '.', $matches[1] ) : $name;
			}
		}
		return '';
	}

	/**
	 * Device type from a user agent.
	 *
	 * @param string $agent User agent.
	 * @return string
	 */
	private static function device( $agent ) {
		if ( '' === $agent ) {
			return '';
		}
		if ( preg_match( '/iPad|Tablet|PlayBook|Silk/i', $agent ) || ( preg_match( '/Android/i', $agent ) && ! preg_match( '/Mobile/i', $agent ) ) ) {
			return __( 'Tablet', 'submitdock' );
		}
		return preg_match( '/Mobile|iPhone|iPod|Android/i', $agent ) ? __( 'Mobile', 'submitdock' ) : __( 'Desktop', 'submitdock' );
	}

	/**
	 * Approximate location from ipwho.is. Only runs when enabled in the settings.
	 *
	 * @param string $ip IP address.
	 * @return array
	 */
	private static function location( $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return array();
		}
		$response = wp_remote_get(
			'https://ipwho.is/' . rawurlencode( $ip ) . '?fields=success,city,region,country',
			array(
				'timeout'     => 3,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			return array();
		}
		return array(
			'city'    => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'region'  => sanitize_text_field( (string) ( $data['region'] ?? '' ) ),
			'country' => sanitize_text_field( (string) ( $data['country'] ?? '' ) ),
		);
	}

	/**
	 * Emails the submission to the site owner.
	 *
	 * @param array  $settings Settings.
	 * @param array  $fields   Fields.
	 * @param string $form     Form identifier.
	 * @param string $email    Sender email for Reply-To.
	 * @param array  $visitor  Visitor details.
	 * @return array Whether WordPress accepted the email (sent) and the recipients (to).
	 */
	private static function notify( array $settings, array $fields, $form, $email, array $visitor ) {
		$lines = array();
		foreach ( $fields as $key => $value ) {
			$lines[] = SubmitDock_Submissions::label_for( $key ) . ': ' . SubmitDock_Submissions::format_value( $value );
			$lines[] = '';
		}
		if ( ! empty( $visitor['page_url'] ) ) {
			/* translators: %s: page URL. */
			$lines[] = sprintf( __( 'Sent from: %s', 'submitdock' ), $visitor['page_url'] );
		}

		$subject = '' !== $form ? '[' . $form . '] ' . $settings['email_subject'] : $settings['email_subject'];
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( '' !== $email ) {
			$name      = SubmitDock_Submissions::sender_name( $fields );
			$name      = str_replace( array( '"', "\r", "\n" ), '', $name );
			$headers[] = 'Reply-To: ' . ( '' !== $name ? '"' . $name . '" <' . $email . '>' : $email );
		}

		/**
		 * Filters the notification email before it is sent.
		 *
		 * @param array $mail   Array with to, subject, message, and headers.
		 * @param array $fields Sanitized fields.
		 * @param string $form  Form identifier.
		 */
		$mail = apply_filters(
			'submitdock_email',
			array(
				'to'      => $settings['recipient'],
				'subject' => $subject,
				'message' => implode( "\n", $lines ),
				'headers' => $headers,
			),
			$fields,
			$form
		);
		return array(
			'sent' => wp_mail( $mail['to'], $mail['subject'], $mail['message'], $mail['headers'] ),
			'to'   => implode( ', ', array_map( 'trim', is_array( $mail['to'] ) ? $mail['to'] : explode( ',', (string) $mail['to'] ) ) ),
		);
	}

	/**
	 * Builds an error response.
	 *
	 * @param string $code    Error code suffix.
	 * @param string $message Message for the visitor.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $message, $status ) {
		return new WP_Error( 'submitdock_' . $code, $message, array( 'status' => $status ) );
	}
}

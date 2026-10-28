<?php
/**
 * Plugin settings.
 *
 * @package SubmitDock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates the plugin settings.
 */
final class SubmitDock_Settings {

	const OPTION = 'submitdock_settings';
	const GROUP  = 'submitdock';

	/**
	 * Response messages shown to visitors, keyed by setting name.
	 *
	 * @return array
	 */
	public static function message_labels() {
		return array(
			'success_message'        => __( 'Success', 'submitdock' ),
			'mail_failed_message'    => __( 'Saved, but the email failed', 'submitdock' ),
			'invalid_message'        => __( 'Invalid submission', 'submitdock' ),
			'origin_message'         => __( 'Website not allowed', 'submitdock' ),
			'rate_limit_message'     => __( 'Too many submissions', 'submitdock' ),
			'storage_failed_message' => __( 'Could not save', 'submitdock' ),
			'spam_message'           => __( 'Spam rejected', 'submitdock' ),
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'recipient'              => (string) get_option( 'admin_email' ),
			'email_notifications'    => true,
			/* translators: %s: site name. */
			'email_subject'          => sprintf( __( 'New form submission on %s', 'submitdock' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'reply_to_field'         => 'email',
			'honeypot_field'         => '_gotcha',
			'allowed_origins'        => array(),
			'rate_limit'             => 5,
			'rate_window_minutes'    => 15,
			'store_ip'               => true,
			'location_lookup'        => false,
			'success_message'        => __( 'Thank you. Your message has been received.', 'submitdock' ),
			'mail_failed_message'    => __( 'Your message was saved, but the email notification could not be sent.', 'submitdock' ),
			'invalid_message'        => __( 'Your submission contains invalid or unsupported data.', 'submitdock' ),
			'origin_message'         => __( 'This website is not allowed to submit this form.', 'submitdock' ),
			'rate_limit_message'     => __( 'Too many submissions from this connection. Please wait and try again.', 'submitdock' ),
			'storage_failed_message' => __( 'Your message could not be saved. Please try again later.', 'submitdock' ),
			'spam_message'           => __( 'Your submission could not be accepted.', 'submitdock' ),
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_replace( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Sanitizes the settings form.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::get();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$output = $current;

		$recipient = isset( $input['recipient'] ) ? sanitize_email( (string) $input['recipient'] ) : '';
		if ( is_email( $recipient ) ) {
			$output['recipient'] = $recipient;
		} else {
			self::error( 'recipient', __( 'Enter a valid notification email address.', 'submitdock' ) );
		}

		$output['email_notifications'] = ! empty( $input['email_notifications'] );
		$output['store_ip']            = ! empty( $input['store_ip'] );
		$output['location_lookup']     = ! empty( $input['location_lookup'] ) && $output['store_ip'];

		$subject = isset( $input['email_subject'] ) ? sanitize_text_field( (string) $input['email_subject'] ) : '';
		if ( '' !== $subject ) {
			$output['email_subject'] = $subject;
		} else {
			self::error( 'subject', __( 'The email subject cannot be empty.', 'submitdock' ) );
		}

		$reply_to = isset( $input['reply_to_field'] ) ? trim( (string) $input['reply_to_field'] ) : '';
		if ( '' === $reply_to || self::is_field_key( $reply_to ) ) {
			$output['reply_to_field'] = $reply_to;
		} else {
			self::error( 'reply_to', __( 'Field names can contain only letters, numbers, underscores, periods, and hyphens.', 'submitdock' ) );
		}

		$honeypot = isset( $input['honeypot_field'] ) ? trim( (string) $input['honeypot_field'] ) : '';
		if ( '' === $honeypot || self::is_field_key( $honeypot ) ) {
			$output['honeypot_field'] = $honeypot;
		} else {
			self::error( 'honeypot', __( 'Field names can contain only letters, numbers, underscores, periods, and hyphens.', 'submitdock' ) );
		}

		$lines   = isset( $input['allowed_origins'] ) ? preg_split( '/\r\n|\r|\n/', (string) $input['allowed_origins'] ) : array();
		$origins = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$origin = self::normalize_origin( $line );
			if ( false === $origin ) {
				/* translators: %s: the rejected origin. */
				self::error( 'origin_' . md5( $line ), sprintf( __( '"%s" is not a valid origin and was ignored. Enter only a scheme and host, for example https://example.com.', 'submitdock' ), $line ) );
				continue;
			}
			$origins[] = $origin;
		}
		$output['allowed_origins'] = array_values( array_unique( $origins ) );

		$output['rate_limit']          = self::bounded_int( $input['rate_limit'] ?? null, $current['rate_limit'], 1, 100 );
		$output['rate_window_minutes'] = self::bounded_int( $input['rate_window_minutes'] ?? null, $current['rate_window_minutes'], 1, 1440 );

		foreach ( array_keys( self::message_labels() ) as $key ) {
			$message = isset( $input[ $key ] ) ? sanitize_textarea_field( (string) $input[ $key ] ) : '';
			if ( '' !== $message && mb_strlen( $message ) <= 500 ) {
				$output[ $key ] = $message;
			} else {
				self::error( $key, __( 'Response messages must be between 1 and 500 characters.', 'submitdock' ) );
			}
		}

		return $output;
	}

	/**
	 * Normalizes an origin such as https://example.com, or returns false.
	 *
	 * @param string $origin Origin to check.
	 * @return string|false
	 */
	public static function normalize_origin( $origin ) {
		$parts = wp_parse_url( $origin );
		if (
			! is_array( $parts ) ||
			empty( $parts['scheme'] ) ||
			empty( $parts['host'] ) ||
			! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ||
			isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
			( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] )
		) {
			return false;
		}

		$normalized = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}
		return $normalized;
	}

	/**
	 * Whether a string is a valid field name for the reply-to and honeypot settings.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private static function is_field_key( $key ) {
		return (bool) preg_match( '/^[a-zA-Z0-9_.-]{1,100}$/D', $key );
	}

	/**
	 * Returns an integer within bounds, or the fallback.
	 *
	 * @param mixed $value    Submitted value.
	 * @param int   $fallback Value to keep when invalid.
	 * @param int   $min      Minimum.
	 * @param int   $max      Maximum.
	 * @return int
	 */
	private static function bounded_int( $value, $fallback, $min, $max ) {
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $integer || $integer < $min || $integer > $max ) {
			/* translators: 1: minimum, 2: maximum. */
			self::error( 'range_' . $min . '_' . $max, sprintf( __( 'Rate limit values must be between %1$d and %2$d.', 'submitdock' ), $min, $max ) );
			return (int) $fallback;
		}
		return $integer;
	}

	/**
	 * Adds a settings error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	private static function error( $code, $message ) {
		add_settings_error( self::OPTION, $code, $message );
	}
}

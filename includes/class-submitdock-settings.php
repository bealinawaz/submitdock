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
	 * CAPTCHA services the endpoint can check, with the field name each widget adds to the form.
	 *
	 * @return array Keys: label, field, verify_url, docs.
	 */
	public static function captcha_providers() {
		return array(
			'turnstile' => array(
				'label'      => __( 'Cloudflare Turnstile', 'submitdock' ),
				'field'      => 'cf-turnstile-response',
				'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-side token check called with wp_remote_post(), nothing is loaded in the browser.
				'docs'       => 'https://developers.cloudflare.com/turnstile/', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documentation link, not a loaded resource.
			),
			'hcaptcha'  => array(
				'label'      => __( 'hCaptcha', 'submitdock' ),
				'field'      => 'h-captcha-response',
				'verify_url' => 'https://api.hcaptcha.com/siteverify',
				'docs'       => 'https://docs.hcaptcha.com/',
			),
			'recaptcha' => array(
				'label'      => __( 'Google reCAPTCHA (v2 or v3)', 'submitdock' ),
				'field'      => 'g-recaptcha-response',
				'verify_url' => 'https://www.google.com/recaptcha/api/siteverify',
				'docs'       => 'https://developers.google.com/recaptcha',
			),
		);
	}

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
			'captcha_message'        => __( 'Spam check failed', 'submitdock' ),
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
			'captcha_message'        => __( 'Please complete the spam check and try again.', 'submitdock' ),
			'captcha_provider'       => '',
			'captcha_site_key'       => '',
			'captcha_secret'         => '',
			'recaptcha_min_score'    => 0.5,
			'akismet'                => false,
			'api_key_hash'           => '',
			'spam_retention_days'    => 30,
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_replace( self::defaults(), self::without_defaults( is_array( $saved ) ? $saved : array() ) );
	}

	/**
	 * Visitor-facing texts and their original English defaults.
	 *
	 * @return array
	 */
	private static function source_texts() {
		return array(
			'email_subject'          => sprintf( 'New form submission on %s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'success_message'        => 'Thank you. Your message has been received.',
			'mail_failed_message'    => 'Your message was saved, but the email notification could not be sent.',
			'invalid_message'        => 'Your submission contains invalid or unsupported data.',
			'origin_message'         => 'This website is not allowed to submit this form.',
			'rate_limit_message'     => 'Too many submissions from this connection. Please wait and try again.',
			'storage_failed_message' => 'Your message could not be saved. Please try again later.',
			'spam_message'           => 'Your submission could not be accepted.',
			'captcha_message'        => 'Please complete the spam check and try again.',
		);
	}

	/**
	 * Removes texts that match the default (in English or in the current language), so visitors
	 * get the translated default in their own language.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private static function without_defaults( array $settings ) {
		$defaults = self::defaults();
		foreach ( self::source_texts() as $key => $source ) {
			if ( isset( $settings[ $key ] ) && ( $source === $settings[ $key ] || $defaults[ $key ] === $settings[ $key ] ) ) {
				unset( $settings[ $key ] );
			}
		}
		return $settings;
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

		$providers                  = self::captcha_providers();
		$provider                   = isset( $input['captcha_provider'] ) ? sanitize_key( (string) $input['captcha_provider'] ) : '';
		$output['captcha_provider'] = isset( $providers[ $provider ] ) ? $provider : '';
		$output['captcha_site_key'] = isset( $input['captcha_site_key'] ) ? self::key_text( $input['captcha_site_key'] ) : '';
		// The secret field is left empty in the form; an empty value keeps the saved secret.
		$secret = isset( $input['captcha_secret'] ) ? self::key_text( $input['captcha_secret'] ) : '';
		if ( '' !== $secret ) {
			$output['captcha_secret'] = $secret;
		}
		if ( ! empty( $input['captcha_secret_clear'] ) ) {
			$output['captcha_secret'] = '';
		}
		if ( '' !== $output['captcha_provider'] && '' === $output['captcha_secret'] ) {
			self::error( 'captcha_secret', __( 'Enter the secret key for your spam check service. Until you do, submissions are not checked.', 'submitdock' ) );
		}
		$score                         = isset( $input['recaptcha_min_score'] ) ? (float) $input['recaptcha_min_score'] : 0.5;
		$output['recaptcha_min_score'] = min( 1.0, max( 0.0, round( $score, 1 ) ) );
		$output['akismet']             = ! empty( $input['akismet'] );
		/* translators: 1: minimum days, 2: maximum days. */
		$days_message                  = __( 'Days to keep spam must be between %1$d and %2$d.', 'submitdock' );
		$output['spam_retention_days'] = self::bounded_int( $input['spam_retention_days'] ?? null, $current['spam_retention_days'], 0, 365, $days_message );

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

		// Texts left at their default are not saved, so visitors see them in their own language.
		$output = self::without_defaults( $output );

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
	 * @param mixed  $value    Submitted value.
	 * @param int    $fallback Value to keep when invalid.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param string $message  Error message with %1$d and %2$d for the bounds.
	 * @return int
	 */
	private static function bounded_int( $value, $fallback, $min, $max, $message = '' ) {
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		if ( false === $integer || $integer < $min || $integer > $max ) {
			/* translators: 1: minimum, 2: maximum. */
			$message = '' !== $message ? $message : __( 'Rate limit values must be between %1$d and %2$d.', 'submitdock' );
			self::error( 'range_' . $min . '_' . $max, sprintf( $message, $min, $max ) );
			return (int) $fallback;
		}
		return $integer;
	}

	/**
	 * A CAPTCHA key: letters, numbers, hyphens, underscores and periods only.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private static function key_text( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return preg_match( '/^[A-Za-z0-9_.-]{1,200}$/D', $value ) ? $value : '';
	}

	/**
	 * Whether a CAPTCHA check is set up (service chosen and secret saved).
	 *
	 * @return bool
	 */
	public static function captcha_enabled() {
		$s = self::get();
		return '' !== $s['captcha_provider'] && '' !== $s['captcha_secret'];
	}

	/**
	 * Whether the Akismet check is on and Akismet is active with a key.
	 *
	 * @return bool
	 */
	public static function akismet_enabled() {
		return self::get()['akismet'] && self::akismet_available();
	}

	/**
	 * Whether the Akismet plugin is active and connected.
	 *
	 * @return bool
	 */
	public static function akismet_available() {
		return class_exists( 'Akismet' ) && is_callable( array( 'Akismet', 'get_api_key' ) ) && '' !== (string) Akismet::get_api_key();
	}

	/**
	 * Creates a new API key, saves its hash, and returns the key. Replaces any earlier key.
	 *
	 * @return string
	 */
	public static function regenerate_api_key() {
		$key                   = 'sdk_' . wp_generate_password( 40, false, false );
		$saved                 = get_option( self::OPTION, array() );
		$saved                 = is_array( $saved ) ? $saved : array();
		$saved['api_key_hash'] = hash( 'sha256', $key );
		self::update_raw( $saved );
		return $key;
	}

	/**
	 * Removes the API key.
	 */
	public static function revoke_api_key() {
		$saved                 = get_option( self::OPTION, array() );
		$saved                 = is_array( $saved ) ? $saved : array();
		$saved['api_key_hash'] = '';
		self::update_raw( $saved );
	}

	/**
	 * Saves the option without running the settings form sanitizer.
	 *
	 * @param array $value Option value.
	 */
	private static function update_raw( array $value ) {
		remove_filter( 'sanitize_option_' . self::OPTION, array( __CLASS__, 'sanitize' ) );
		update_option( self::OPTION, $value );
	}

	/**
	 * Whether a key matches the saved API key.
	 *
	 * @param string $key Key from the request.
	 * @return bool
	 */
	public static function api_key_matches( $key ) {
		$hash = (string) self::get()['api_key_hash'];
		return '' !== $hash && '' !== $key && hash_equals( $hash, hash( 'sha256', $key ) );
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

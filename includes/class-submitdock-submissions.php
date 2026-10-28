<?php
/**
 * Submission storage and the submissions screens.
 *
 * @package SubmitDock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores submissions as a private post type and renders them in the admin.
 */
final class SubmitDock_Submissions {

	const POST_TYPE    = 'submitdock_entry';
	const META_FIELDS  = '_submitdock_fields';
	const META_VISITOR = '_submitdock_visitor';
	const META_STATUS  = '_submitdock_email_status';
	const META_FORM    = '_submitdock_form';
	const META_EMAIL   = '_submitdock_email';
	const META_SENT_TO = '_submitdock_email_to';
	const EXPORT_BATCH = 500;

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_list_controls' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_by_form' ) );
		add_action( 'admin_post_submitdock_export', array( __CLASS__, 'export_csv' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
	}

	/**
	 * Registers the private submission post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Form Submissions', 'submitdock' ),
					'singular_name'      => __( 'Submission', 'submitdock' ),
					'menu_name'          => __( 'Form Submissions', 'submitdock' ),
					'all_items'          => __( 'Submissions', 'submitdock' ),
					'edit_item'          => __( 'Submission', 'submitdock' ),
					'view_item'          => __( 'View submission', 'submitdock' ),
					'search_items'       => __( 'Search submissions', 'submitdock' ),
					'not_found'          => __( 'No submissions yet.', 'submitdock' ),
					'not_found_in_trash' => __( 'No submissions in the Trash.', 'submitdock' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-email-alt2',
				'capability_type'     => 'post',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * Saves a submission and returns its ID.
	 *
	 * @param array  $fields  Sanitized form fields.
	 * @param array  $visitor Visitor details.
	 * @param string $form    Form identifier, or an empty string.
	 * @param string $email   Sender email address, or an empty string.
	 * @return int|WP_Error
	 */
	public static function create( array $fields, array $visitor, $form, $email ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => self::title_for( $fields, $form ),
				'meta_input'  => array(
					self::META_FIELDS  => $fields,
					self::META_VISITOR => $visitor,
					self::META_STATUS  => 'pending',
					self::META_FORM    => $form,
					self::META_EMAIL   => strtolower( $email ),
				),
			),
			true
		);
		return $post_id;
	}

	/**
	 * Builds a readable title from the sender's name or email.
	 *
	 * @param array  $fields Form fields.
	 * @param string $form   Form identifier.
	 * @return string
	 */
	private static function title_for( array $fields, $form ) {
		$sender = self::sender_name( $fields );
		if ( '' === $sender && isset( $fields['email'] ) && is_string( $fields['email'] ) ) {
			$sender = $fields['email'];
		}
		$parts = array_filter( array( $form, $sender ) );
		return $parts ? implode( ' — ', $parts ) : __( 'Form submission', 'submitdock' );
	}

	/**
	 * Finds the sender's name in common field names.
	 *
	 * @param array $fields Form fields.
	 * @return string
	 */
	public static function sender_name( array $fields ) {
		foreach ( array( 'name', 'full_name', 'fullname', 'your_name', 'your-name' ) as $key ) {
			if ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) && '' !== trim( $fields[ $key ] ) ) {
				return trim( $fields[ $key ] );
			}
		}
		$first = isset( $fields['first_name'] ) && is_string( $fields['first_name'] ) ? $fields['first_name'] : '';
		$last  = isset( $fields['last_name'] ) && is_string( $fields['last_name'] ) ? $fields['last_name'] : '';
		return trim( $first . ' ' . $last );
	}

	/**
	 * Formats a stored value as text.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	public static function format_value( $value ) {
		if ( is_array( $value ) ) {
			$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			return is_string( $json ) ? $json : '';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'submitdock' ) : __( 'No', 'submitdock' );
		}
		if ( null === $value ) {
			return '—';
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Turns a field key such as project_type into a label.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	public static function label_for( $key ) {
		return ucfirst( str_replace( array( '_', '-' ), ' ', (string) $key ) );
	}

	/**
	 * Human-readable email status.
	 *
	 * @param string $status Stored status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'pending'  => __( 'Pending', 'submitdock' ),
			'sent'     => __( 'Sent', 'submitdock' ),
			'failed'   => __( 'Failed, submission saved', 'submitdock' ),
			'disabled' => __( 'Notifications off', 'submitdock' ),
		);
		return $labels[ $status ] ?? __( 'Unknown', 'submitdock' );
	}

	/**
	 * Notification status with the recipient, such as "Sent to owner@example.com".
	 *
	 * @param int $post_id Submission ID.
	 * @return string
	 */
	public static function delivery_label( $post_id ) {
		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		$to     = (string) get_post_meta( $post_id, self::META_SENT_TO, true );
		if ( '' === $to ) {
			return self::status_label( $status );
		}
		if ( 'sent' === $status ) {
			/* translators: %s: recipient email addresses. */
			return sprintf( __( 'Sent to %s', 'submitdock' ), $to );
		}
		if ( 'failed' === $status ) {
			/* translators: %s: recipient email addresses. */
			return sprintf( __( 'Failed to send to %s, submission saved', 'submitdock' ), $to );
		}
		return self::status_label( $status );
	}

	/**
	 * Adds a View link and removes Quick Edit, which does not apply to submissions.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Submission.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['edit'] );
		$link = get_edit_post_link( $post->ID );
		if ( $link ) {
			$actions = array_merge(
				array(
					'view' => sprintf(
						'<a href="%1$s" aria-label="%2$s">%3$s</a>',
						esc_url( $link ),
						/* translators: %s: submission title. */
						esc_attr( sprintf( __( 'View “%s”', 'submitdock' ), get_the_title( $post ) ) ),
						esc_html__( 'View', 'submitdock' )
					),
				),
				$actions
			);
		}
		return $actions;
	}

	/**
	 * List table columns.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'title'        => __( 'Submission', 'submitdock' ),
			'submitdock_form'     => __( 'Form', 'submitdock' ),
			'submitdock_email'    => __( 'Email', 'submitdock' ),
			'submitdock_origin'   => __( 'Website', 'submitdock' ),
			'submitdock_delivery' => __( 'Notification', 'submitdock' ),
			'date'         => __( 'Received', 'submitdock' ),
		);
	}

	/**
	 * Renders a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Submission ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'submitdock_form' === $column ) {
			$form = (string) get_post_meta( $post_id, self::META_FORM, true );
			echo esc_html( '' !== $form ? $form : '—' );
		} elseif ( 'submitdock_email' === $column ) {
			$email = (string) get_post_meta( $post_id, self::META_EMAIL, true );
			if ( is_email( $email ) ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email ), esc_html( $email ) );
			} else {
				echo '—';
			}
		} elseif ( 'submitdock_origin' === $column ) {
			$visitor = get_post_meta( $post_id, self::META_VISITOR, true );
			$origin  = is_array( $visitor ) && ! empty( $visitor['origin'] ) ? (string) $visitor['origin'] : '';
			echo esc_html( '' !== $origin ? wp_parse_url( $origin, PHP_URL_HOST ) : '—' );
		} elseif ( 'submitdock_delivery' === $column ) {
			echo esc_html( self::delivery_label( $post_id ) );
		}
	}

	/**
	 * Adds the form filter and export button above the list.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Top or bottom navigation.
	 */
	public static function render_list_controls( $post_type, $which = 'top' ) {
		if ( self::POST_TYPE !== $post_type || 'top' !== $which ) {
			return;
		}

		$forms = self::form_names();
		if ( $forms ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
			$current = isset( $_GET['submitdock_form'] ) ? sanitize_text_field( wp_unslash( $_GET['submitdock_form'] ) ) : '';
			echo '<label class="screen-reader-text" for="submitdock-form-filter">' . esc_html__( 'Filter by form', 'submitdock' ) . '</label>';
			echo '<select name="submitdock_form" id="submitdock-form-filter"><option value="">' . esc_html__( 'All forms', 'submitdock' ) . '</option>';
			foreach ( $forms as $form ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $form ), selected( $current, $form, false ), esc_html( $form ) );
			}
			echo '</select>';
		}

		if ( current_user_can( 'read_private_posts' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=submitdock_export' ), 'submitdock_export' );
			printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Export CSV', 'submitdock' ) );
		}
	}

	/**
	 * Distinct form identifiers in use.
	 *
	 * @return string[]
	 */
	private static function form_names() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Distinct meta values have no core API.
		$forms = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' ORDER BY meta_value LIMIT 100", self::META_FORM ) );
		return array_map( 'strval', $forms );
	}

	/**
	 * Applies the form filter to the submissions list.
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function filter_by_form( $query ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) || empty( $_GET['submitdock_form'] ) ) {
			return;
		}
		$query->set(
			'meta_query',
			array(
				array(
					'key'   => self::META_FORM,
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
					'value' => sanitize_text_field( wp_unslash( $_GET['submitdock_form'] ) ),
				),
			)
		);
	}

	/**
	 * Adds the submission and visitor boxes.
	 */
	public static function add_meta_boxes() {
		add_meta_box( 'submitdock-fields', __( 'Submitted fields', 'submitdock' ), array( __CLASS__, 'render_fields_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'submitdock-visitor', __( 'Visitor details', 'submitdock' ), array( __CLASS__, 'render_visitor_box' ), self::POST_TYPE, 'normal' );
	}

	/**
	 * Renders the submitted fields.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function render_fields_box( $post ) {
		$fields = get_post_meta( $post->ID, self::META_FIELDS, true );
		$form   = (string) get_post_meta( $post->ID, self::META_FORM, true );
		if ( ! is_array( $fields ) ) {
			echo '<p>' . esc_html__( 'No fields were saved.', 'submitdock' ) . '</p>';
			return;
		}
		if ( '' !== $form ) {
			/* translators: %s: form identifier. */
			echo '<p>' . esc_html( sprintf( __( 'Form: %s', 'submitdock' ), $form ) ) . '</p>';
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $fields as $key => $value ) {
			echo '<tr><th scope="row">' . esc_html( self::label_for( $key ) ) . '</th><td>';
			if ( is_string( $value ) && is_email( $value ) ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $value ), esc_html( $value ) );
			} else {
				echo nl2br( esc_html( self::format_value( $value ) ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders the visitor details.
	 *
	 * @param WP_Post $post Submission.
	 */
	public static function render_visitor_box( $post ) {
		$visitor = get_post_meta( $post->ID, self::META_VISITOR, true );
		$visitor = is_array( $visitor ) ? $visitor : array();
		$labels  = self::visitor_labels();
		echo '<table class="widefat striped"><tbody>';
		foreach ( $visitor as $key => $value ) {
			$label = $labels[ $key ] ?? self::label_for( $key );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( self::format_value( $value ) ) . '</td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Email notification', 'submitdock' ) . '</th><td>' . esc_html( self::delivery_label( $post->ID ) ) . '</td></tr>';
		echo '</tbody></table>';
	}

	/**
	 * Labels for visitor detail keys.
	 *
	 * @return array
	 */
	public static function visitor_labels() {
		return array(
			'page_url'   => __( 'Page URL', 'submitdock' ),
			'referrer'   => __( 'Referrer', 'submitdock' ),
			'origin'     => __( 'Website', 'submitdock' ),
			'ip_address' => __( 'IP address', 'submitdock' ),
			'browser'    => __( 'Browser', 'submitdock' ),
			'os'         => __( 'Operating system', 'submitdock' ),
			'device'     => __( 'Device', 'submitdock' ),
			'user_agent' => __( 'User agent', 'submitdock' ),
			'city'       => __( 'City', 'submitdock' ),
			'region'     => __( 'Region', 'submitdock' ),
			'country'    => __( 'Country', 'submitdock' ),
		);
	}

	/**
	 * Streams every submission as a CSV file.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'read_private_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to export submissions.', 'submitdock' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'submitdock_export' );

		// First pass: collect every field and visitor key so each gets a column.
		$field_keys   = array();
		$visitor_keys = array();
		for ( $offset = 0; $ids = self::export_batch( $offset ); $offset += self::EXPORT_BATCH ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $id ) {
				$field_keys   = array_merge( $field_keys, array_keys( (array) get_post_meta( $id, self::META_FIELDS, true ) ) );
				$visitor_keys = array_merge( $visitor_keys, array_keys( (array) get_post_meta( $id, self::META_VISITOR, true ) ) );
			}
			$field_keys   = array_values( array_unique( array_map( 'strval', $field_keys ) ) );
			$visitor_keys = array_values( array_unique( array_map( 'strval', $visitor_keys ) ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="form-submissions-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the response.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheet apps.
		$labels = self::visitor_labels();
		$header = array_merge(
			array( 'ID', __( 'Received (UTC)', 'submitdock' ), __( 'Form', 'submitdock' ), __( 'Notification', 'submitdock' ) ),
			$field_keys,
			array_map(
				static function ( $key ) use ( $labels ) {
					return $labels[ $key ] ?? $key;
				},
				$visitor_keys
			)
		);
		fputcsv( $output, array_map( array( __CLASS__, 'csv_cell' ), $header ), ',', '"', '\\' );

		for ( $offset = 0; $ids = self::export_batch( $offset ); $offset += self::EXPORT_BATCH ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			update_meta_cache( 'post', $ids );
			foreach ( $ids as $id ) {
				$fields  = (array) get_post_meta( $id, self::META_FIELDS, true );
				$visitor = (array) get_post_meta( $id, self::META_VISITOR, true );
				$row     = array( $id, get_post_time( 'Y-m-d H:i:s', true, $id ), get_post_meta( $id, self::META_FORM, true ), self::delivery_label( $id ) );
				foreach ( $field_keys as $key ) {
					$row[] = $fields[ $key ] ?? '';
				}
				foreach ( $visitor_keys as $key ) {
					$row[] = $visitor[ $key ] ?? '';
				}
				fputcsv( $output, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '\\' );
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * One batch of submission IDs, oldest first.
	 *
	 * @param int $offset Offset.
	 * @return int[]
	 */
	private static function export_batch( $offset ) {
		return get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => self::EXPORT_BATCH,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
	}

	/**
	 * Converts a value to a CSV cell and neutralizes spreadsheet formulas.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		if ( is_array( $value ) ) {
			$value = (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} elseif ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		} else {
			$value = is_scalar( $value ) ? (string) $value : '';
		}
		return preg_match( '/^\s*[=+\-@]/', $value ) ? "'" . $value : $value;
	}
}

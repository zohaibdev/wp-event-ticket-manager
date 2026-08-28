<?php
/**
 * Attendees list table.
 *
 * One row is one attendee - their own security code, QR and check-in state -
 * alongside the booking number, payment status and date they share with
 * everyone else on the same booking.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin table of individual attendees with filters.
 */
class ETM_Attendees_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'attendee',
				'plural'   => 'attendees',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'attendee'        => __( 'Attendee', 'event-ticket-manager' ),
			'event'           => __( 'Event', 'event-ticket-manager' ),
			'ticket_type'     => __( 'Ticket Type', 'event-ticket-manager' ),
			'booking_number'  => __( 'Booking Number', 'event-ticket-manager' ),
			'security_code'   => __( 'Security Code', 'event-ticket-manager' ),
			'qr'              => __( 'QR', 'event-ticket-manager' ),
			'payment_status'  => __( 'Payment Status', 'event-ticket-manager' ),
			'coming_status'   => __( 'Coming', 'event-ticket-manager' ),
			'check_in_status' => __( 'Check In', 'event-ticket-manager' ),
			'booking_date'    => __( 'Date', 'event-ticket-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'attendee'        => array( 'attendee', false ),
			'event'           => array( 'event', false ),
			'booking_number'  => array( 'booking_number', false ),
			'payment_status'  => array( 'payment_status', false ),
			'check_in_status' => array( 'check_in_status', false ),
			'booking_date'    => array( 'booking_date', true ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items(): void {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status    = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '';
		$event_id  = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
		$check_in  = isset( $_GET['check_in_status'] ) && '' !== $_GET['check_in_status']
			? sanitize_key( wp_unslash( $_GET['check_in_status'] ) )
			: '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'booking_date';
		$order     = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$results = ETM_Attendees::query(
			array(
				'search'          => $search,
				'payment_status'  => $status,
				'event_id'        => $event_id,
				'check_in_status' => $check_in,
				'date_from'       => $date_from,
				'date_to'         => $date_to,
				'orderby'         => $orderby,
				'order'           => $order,
				'per_page'        => $per_page,
				'page'            => $paged,
			)
		);

		$this->items = $results['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $results['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $results['total'] / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'attendee' );
	}

	/**
	 * Event, check-in and date filters above the table.
	 *
	 * @param string $which Table position.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$event_id  = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
		$check_in  = isset( $_GET['check_in_status'] ) ? sanitize_key( wp_unslash( $_GET['check_in_status'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="etm-filter-event"><?php esc_html_e( 'Filter by event', 'event-ticket-manager' ); ?></label>
			<select name="event_id" id="etm-filter-event">
				<option value="0"><?php esc_html_e( 'All events', 'event-ticket-manager' ); ?></option>
				<?php foreach ( ETM_Events::get_all_for_select() as $event ) : ?>
					<option value="<?php echo esc_attr( (string) $event->id ); ?>" <?php selected( $event_id, (int) $event->id ); ?>>
						<?php echo esc_html( $event->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="etm-filter-checkin"><?php esc_html_e( 'Filter by check-in', 'event-ticket-manager' ); ?></label>
			<select name="check_in_status" id="etm-filter-checkin">
				<option value=""><?php esc_html_e( 'Any check-in status', 'event-ticket-manager' ); ?></option>
				<option value="1" <?php selected( $check_in, '1' ); ?>><?php esc_html_e( 'Checked In', 'event-ticket-manager' ); ?></option>
				<option value="0" <?php selected( $check_in, '0' ); ?>><?php esc_html_e( 'Not Checked In', 'event-ticket-manager' ); ?></option>
			</select>

			<label class="screen-reader-text" for="etm-filter-from"><?php esc_html_e( 'From date', 'event-ticket-manager' ); ?></label>
			<input type="date" id="etm-filter-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />

			<label class="screen-reader-text" for="etm-filter-to"><?php esc_html_e( 'To date', 'event-ticket-manager' ); ?></label>
			<input type="date" id="etm-filter-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />

			<?php submit_button( __( 'Filter', 'event-ticket-manager' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Payment status filter links with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '';
		$counts  = ETM_Bookings::status_counts();
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( admin_url( 'admin.php?page=etm-bookings' ) ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'event-ticket-manager' )
		);

		foreach ( ETM_Bookings::statuses() as $status => $label ) {
			if ( empty( $counts[ $status ] ) ) {
				continue;
			}

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( admin_url( 'admin.php?page=etm-bookings&payment_status=' . $status ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label )
			);
		}

		return $views;
	}

	/**
	 * Message for an empty table.
	 */
	public function no_items(): void {
		esc_html_e( 'No attendees found.', 'event-ticket-manager' );
	}

	/**
	 * Attendee cell: name, email and the per-attendee row actions.
	 *
	 * @param object $item Attendee row.
	 * @return string
	 */
	public function column_attendee( $item ): string {
		$booking_url = admin_url( 'admin.php?page=etm-bookings&action=view&booking=' . (int) $item->booking_id );

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $booking_url ),
				esc_html__( 'View Booking', 'event-ticket-manager' )
			),
		);

		if ( ! empty( $item->security_code ) ) {
			$actions['ticket'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( ETM_Attendees::verification_url( (string) $item->security_code ) ),
				esc_html__( 'View Ticket', 'event-ticket-manager' )
			);
		}

		if ( ! empty( $item->check_in_status ) ) {
			$actions['undo_check_in'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'undo_check_in', (int) $item->id ) ),
				esc_html__( 'Undo Check In', 'event-ticket-manager' )
			);
		} else {
			$actions['check_in'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'check_in', (int) $item->id ) ),
				esc_html__( 'Check In', 'event-ticket-manager' )
			);
		}

		$name = '' !== trim( (string) $item->name )
			? (string) $item->name
			: __( '(no name recorded)', 'event-ticket-manager' );

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong><br /><span class="etm-muted">%s</span>%s',
			esc_url( $booking_url ),
			esc_html( $name ),
			esc_html( (string) $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Default cell renderer.
	 *
	 * @param object $item        Attendee row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'event':
				return '' !== (string) $item->event_title
					? esc_html( (string) $item->event_title )
					: '<span class="etm-muted">' . esc_html__( '(deleted event)', 'event-ticket-manager' ) . '</span>';

			case 'ticket_type':
				return '' !== (string) $item->ticket_title_snapshot
					? esc_html( (string) $item->ticket_title_snapshot )
					: '<span class="etm-muted">&mdash;</span>';

			case 'booking_number':
				return sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=etm-bookings&action=view&booking=' . (int) $item->booking_id ) ),
					esc_html( (string) $item->booking_number )
				);

			case 'security_code':
				return '' !== (string) $item->security_code
					? '<code class="etm-code">' . esc_html( (string) $item->security_code ) . '</code>'
					: '<span class="etm-muted">&mdash;</span>';

			case 'qr':
				if ( '' === (string) $item->security_code ) {
					return '<span class="etm-muted">&mdash;</span>';
				}

				return sprintf(
					'<a href="%s" target="_blank" rel="noopener noreferrer" class="etm-attendee-qr" title="%s">%s</a>',
					esc_url( ETM_Attendees::verification_url( (string) $item->security_code ) ),
					esc_attr__( 'Open this ticket', 'event-ticket-manager' ),
					ETM_Attendees::qr_svg( (string) $item->security_code )
				);

			case 'payment_status':
				return etm_status_badge( (string) $item->payment_status, etm_booking_status_label( (string) $item->payment_status ) );

			case 'coming_status':
				return esc_html( ETM_Attendees::coming_status_label( (string) $item->coming_status ) );

			case 'check_in_status':
				$checked_in = ! empty( $item->check_in_status );

				$badge = etm_status_badge(
					$checked_in ? 'paid' : 'pending',
					ETM_Attendees::check_in_label( $checked_in )
				);

				if ( $checked_in && ! empty( $item->check_in_at ) ) {
					$badge .= '<br /><span class="etm-muted">' . esc_html( etm_format_datetime( $item->check_in_at ) ) . '</span>';
				}

				return $badge;

			case 'booking_date':
				return esc_html( etm_format_datetime( $item->booking_date ) );
		}

		return '';
	}

	/**
	 * Build a nonced attendee action URL.
	 *
	 * @param string $action      Action key.
	 * @param int    $attendee_id Attendee ID.
	 * @return string
	 */
	private function action_url( string $action, int $attendee_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'etm_attendee_action',
					'etm_action' => $action,
					'attendee'   => $attendee_id,
				),
				admin_url( 'admin-post.php' )
			),
			'etm_attendee_action_' . $action . '_' . $attendee_id
		);
	}
}

<?php
/**
 * Bookings list table.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin table of bookings with filters.
 */
class ETM_Bookings_List_Table extends WP_List_Table {

	/**
	 * Cached event titles keyed by ID.
	 *
	 * @var array<int, string>
	 */
	private array $event_titles = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'booking',
				'plural'   => 'bookings',
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
			'booking_number'  => __( 'Booking Number', 'event-ticket-manager' ),
			'event'           => __( 'Event', 'event-ticket-manager' ),
			'customer'        => __( 'Customer', 'event-ticket-manager' ),
			'ticket_quantity' => __( 'Tickets', 'event-ticket-manager' ),
			'roommate'        => __( 'Roommate', 'event-ticket-manager' ),
			'total'           => __( 'Total', 'event-ticket-manager' ),
			'payment_status'  => __( 'Payment Status', 'event-ticket-manager' ),
			'created_at'      => __( 'Booking Date', 'event-ticket-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'booking_number' => array( 'booking_number', false ),
			'total'          => array( 'total', false ),
			'payment_status' => array( 'payment_status', false ),
			'created_at'     => array( 'created_at', true ),
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
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$orderby   = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order     = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$results = ETM_Bookings::query(
			array(
				'search'         => $search,
				'payment_status' => $status,
				'event_id'       => $event_id,
				'date_from'      => $date_from,
				'date_to'        => $date_to,
				'orderby'        => $orderby,
				'order'          => $order,
				'per_page'       => $per_page,
				'page'           => $paged,
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

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'booking_number' );
	}

	/**
	 * Status filter links with counts.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET['payment_status'] ) ? sanitize_key( wp_unslash( $_GET['payment_status'] ) ) : '';
		$counts  = ETM_Bookings::status_counts();
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( admin_url( 'admin.php?page=etm-bookings&action=bookings' ) ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'event-ticket-manager' ),
			array_sum( $counts )
		);

		foreach ( ETM_Bookings::statuses() as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( admin_url( 'admin.php?page=etm-bookings&action=bookings&payment_status=' . $status ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label ),
				(int) ( $counts[ $status ] ?? 0 )
			);
		}

		return $views;
	}

	/**
	 * Event and date filters above the table.
	 *
	 * @param string $which Table position.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$event_id  = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
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

			<label class="screen-reader-text" for="etm-filter-from"><?php esc_html_e( 'From date', 'event-ticket-manager' ); ?></label>
			<input type="date" id="etm-filter-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />

			<label class="screen-reader-text" for="etm-filter-to"><?php esc_html_e( 'To date', 'event-ticket-manager' ); ?></label>
			<input type="date" id="etm-filter-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />

			<?php submit_button( __( 'Filter', 'event-ticket-manager' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Message for an empty table.
	 */
	public function no_items(): void {
		esc_html_e( 'No bookings found.', 'event-ticket-manager' );
	}

	/**
	 * Booking number cell with row actions.
	 *
	 * @param object $item Booking row.
	 * @return string
	 */
	public function column_booking_number( $item ): string {
		$view_url = admin_url( 'admin.php?page=etm-bookings&action=view&booking=' . (int) $item->id );
		$number   = '' !== (string) $item->booking_number
			? (string) $item->booking_number
			: sprintf( '#%d', (int) $item->id );

		$actions = array(
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $view_url ), esc_html__( 'View', 'event-ticket-manager' ) ),
			'delete' => sprintf(
				'<a href="%s" class="etm-delete-booking submitdelete">%s</a>',
				esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'action'     => 'etm_booking_action',
								'etm_action' => 'delete',
								'booking'    => (int) $item->id,
							),
							admin_url( 'admin-post.php' )
						),
						'etm_booking_action_delete_' . (int) $item->id
					)
				),
				esc_html__( 'Delete', 'event-ticket-manager' )
			),
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $view_url ),
			esc_html( $number ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Default cell renderer.
	 *
	 * @param object $item        Booking row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'event':
				return esc_html( $this->get_event_title( (int) $item->event_id ) );

			case 'customer':
				return sprintf(
					'%s<br /><a href="mailto:%s" class="etm-muted">%s</a>',
					esc_html( (string) $item->customer_name ),
					esc_attr( (string) $item->customer_email ),
					esc_html( (string) $item->customer_email )
				);

			case 'ticket_quantity':
				return esc_html( (string) (int) $item->ticket_quantity );

			case 'roommate':
				$roommates = ETM_Bookings::get_roommates( $item );

				if ( empty( $roommates ) ) {
					return '<span class="etm-muted">&mdash;</span>';
				}

				return esc_html( implode( ', ', wp_list_pluck( $roommates, 'name' ) ) );

			case 'total':
				return esc_html( etm_format_price( (float) $item->total, (string) $item->currency ) );

			case 'payment_status':
				return etm_status_badge( (string) $item->payment_status, etm_booking_status_label( (string) $item->payment_status ) );

			case 'created_at':
				return esc_html( etm_format_datetime( $item->created_at ) );
		}

		return '';
	}

	/**
	 * Resolve and cache an event title.
	 *
	 * @param int $event_id Event ID.
	 * @return string
	 */
	private function get_event_title( int $event_id ): string {
		if ( ! isset( $this->event_titles[ $event_id ] ) ) {
			$event = ETM_Events::get( $event_id );

			$this->event_titles[ $event_id ] = $event ? (string) $event->title : __( '(deleted event)', 'event-ticket-manager' );
		}

		return $this->event_titles[ $event_id ];
	}
}

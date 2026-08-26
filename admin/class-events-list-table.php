<?php
/**
 * Events list table.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Admin table of events with capacity figures.
 */
class ETM_Events_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
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
			'title'             => __( 'Event', 'event-ticket-manager' ),
			'start_datetime'    => __( 'Start Date', 'event-ticket-manager' ),
			'end_datetime'      => __( 'End Date', 'event-ticket-manager' ),
			'ticket_price'      => __( 'Ticket Price', 'event-ticket-manager' ),
			'participant_limit' => __( 'Participant Limit', 'event-ticket-manager' ),
			'sold'              => __( 'Tickets Sold', 'event-ticket-manager' ),
			'remaining'         => __( 'Remaining', 'event-ticket-manager' ),
			'status'            => __( 'Status', 'event-ticket-manager' ),
			'shortcode'         => __( 'Shortcode', 'event-ticket-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'title'          => array( 'title', false ),
			'start_datetime' => array( 'start_datetime', true ),
			'end_datetime'   => array( 'end_datetime', false ),
			'status'         => array( 'status', false ),
		);
	}

	/**
	 * Load rows.
	 */
	public function prepare_items(): void {
		$per_page = 20;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'start_datetime';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		$results = ETM_Events::query(
			array(
				'search'   => $search,
				'status'   => $status,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => $paged,
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

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
	}

	/**
	 * Status filter links.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$views   = array();

		$views['all'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( admin_url( 'admin.php?page=etm-events' ) ),
			'' === $current ? ' class="current"' : '',
			esc_html__( 'All', 'event-ticket-manager' )
		);

		foreach ( ETM_Events::statuses() as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( admin_url( 'admin.php?page=etm-events&status=' . $status ) ),
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
		esc_html_e( 'No events yet. Create your first event to start selling tickets.', 'event-ticket-manager' );
	}

	/**
	 * Event title cell with row actions.
	 *
	 * @param object $item Event row.
	 * @return string
	 */
	public function column_title( $item ): string {
		$edit_url = admin_url( 'admin.php?page=etm-events&action=edit&event=' . (int) $item->id );

		$actions = array(
			'edit'      => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'event-ticket-manager' ) ),
			'duplicate' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'duplicate', (int) $item->id ) ),
				esc_html__( 'Duplicate', 'event-ticket-manager' )
			),
			'bookings'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=etm-bookings&event_id=' . (int) $item->id ) ),
				esc_html__( 'Bookings', 'event-ticket-manager' )
			),
		);

		if ( ETM_Events::STATUS_PUBLISHED !== $item->status ) {
			$actions['publish'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'publish', (int) $item->id ) ),
				esc_html__( 'Publish', 'event-ticket-manager' )
			);
		} else {
			$actions['close'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( 'close', (int) $item->id ) ),
				esc_html__( 'Close', 'event-ticket-manager' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="etm-delete submitdelete">%s</a>',
			esc_url( $this->action_url( 'delete', (int) $item->id ) ),
			esc_html__( 'Delete', 'event-ticket-manager' )
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item->title ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Default cell renderer.
	 *
	 * @param object $item        Event row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'start_datetime':
				return esc_html( etm_format_datetime( $item->start_datetime ) );

			case 'end_datetime':
				return esc_html( etm_format_datetime( $item->end_datetime ) );

			case 'ticket_price':
				// Prices live on the event's ticket types; the event's own column
				// is only a fallback for events that have none yet.
				$types = ETM_Event_Tickets::get_for_event( (int) $item->id, true );

				if ( empty( $types ) ) {
					$price = '<span class="etm-muted">' . esc_html__( 'No ticket types', 'event-ticket-manager' ) . '</span>';
				} else {
					$parts = array();

					foreach ( array_slice( $types, 0, 3 ) as $type ) {
						$parts[] = esc_html( $type->title ) . ' ' . esc_html( etm_format_price( (float) $type->price ) );
					}

					if ( count( $types ) > 3 ) {
						$parts[] = '<span class="etm-muted">' . sprintf(
							/* translators: %d: number of further ticket types. */
							esc_html__( '+%d more', 'event-ticket-manager' ),
							count( $types ) - 3
						) . '</span>';
					}

					$price = implode( '<br />', $parts );
				}

				if ( ! empty( $item->roommate_enabled ) ) {
					$price .= '<br /><span class="etm-muted">' . sprintf(
						/* translators: %s: roommate add-on price. */
						esc_html__( '+ %s roommate', 'event-ticket-manager' ),
						esc_html( etm_format_price( (float) $item->roommate_price ) )
					) . '</span>';
				}

				return $price;

			case 'participant_limit':
				return null === $item->participant_limit
					? '<span class="etm-muted">' . esc_html__( 'Unlimited', 'event-ticket-manager' ) . '</span>'
					: esc_html( (string) (int) $item->participant_limit );

			case 'sold':
				return esc_html( (string) ETM_Capacity::get_sold_tickets( (int) $item->id ) );

			case 'remaining':
				$remaining = ETM_Capacity::get_remaining( $item );

				if ( ETM_Capacity::UNLIMITED === $remaining ) {
					return '<span class="etm-muted">&infin;</span>';
				}

				return sprintf(
					'<span class="%s">%s</span>',
					$remaining < 1 ? 'etm-danger' : '',
					esc_html( (string) $remaining )
				);

			case 'status':
				return etm_status_badge( (string) $item->status, etm_event_status_label( (string) $item->status ) );

			case 'shortcode':
				return sprintf(
					'<code class="etm-shortcode" data-etm-copy>[event_ticket event_id="%d"]</code>',
					(int) $item->id
				);
		}

		return '';
	}

	/**
	 * Build a nonced action URL.
	 *
	 * @param string $action   Action key.
	 * @param int    $event_id Event ID.
	 * @return string
	 */
	private function action_url( string $action, int $event_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'etm_event_action',
					'etm_action' => $action,
					'event'      => $event_id,
				),
				admin_url( 'admin-post.php' )
			),
			'etm_event_action_' . $action . '_' . $event_id
		);
	}
}

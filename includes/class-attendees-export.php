<?php
/**
 * Builds the CSV rows for the Attendees export.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * One booking in, one CSV row out.
 *
 * Additional attendees live under the same booking in this plugin's data
 * model - the attendees table keys every row to a booking_id, and a Sharing
 * a Room roommate is stored on the booking itself - so one row per booking
 * with a primary pair of columns and an additional pair keeps every person's
 * name and email together with the booking number, total and transaction
 * they belong to.
 *
 * Names and emails are separate columns. The two additional columns are
 * built in lockstep, so the nth name and the nth email are always the same
 * person even when someone has only one of the two recorded.
 */
class ETM_Attendees_Export {

	/**
	 * How several people are joined inside one cell.
	 */
	private const SEPARATOR = '; ';

	/**
	 * The fixed header row, in the required order.
	 *
	 * @return string[]
	 */
	public static function headers(): array {
		return array(
			'Date',
			'Booking number',
			'Event Name',
			'Attendees Name',
			'Attendees Email',
			'Additional Attendees Name',
			'Additional Attendees Email Address',
			'Ticket Type',
			'Payment Status',
			'Total Amount',
			'Stripe Transaction ID',
		);
	}

	/**
	 * One CSV row for one booking, in the same order as headers().
	 *
	 * @param object $booking Booking row.
	 * @return string[]
	 */
	public static function booking_row( object $booking ): array {
		$event     = ETM_Events::get( (int) $booking->event_id );
		$lines     = ETM_Bookings::get_ticket_lines( (int) $booking->id );
		$attendees = ETM_Attendees::get_for_booking( (int) $booking->id );
		$roommates = ETM_Bookings::get_roommates( $booking );

		$people = self::split_people( $booking, $attendees, $roommates );

		return array(
			etm_format_datetime( $booking->created_at, true ),
			(string) $booking->booking_number,
			// Blank rather than a placeholder when the event has since been
			// deleted - the booking itself is still a complete record.
			$event ? (string) $event->title : '',
			$people['primary_name'],
			$people['primary_email'],
			implode( self::SEPARATOR, $people['additional_names'] ),
			implode( self::SEPARATOR, $people['additional_emails'] ),
			self::ticket_type_summary( $lines, $roommates ),
			(string) $booking->payment_status,
			number_format( (float) $booking->total, 2, '.', '' ),
			! empty( $booking->stripe_payment_intent_id ) ? (string) $booking->stripe_payment_intent_id : '',
		);
	}

	/**
	 * Separate the booking's people into the primary attendee and everyone
	 * else, losing nobody and keeping each name beside its own email.
	 *
	 * Attendee 1 is the purchaser (see ETM_Checkout::validate_submission(),
	 * where slot 0 takes the customer's own name and email), so that row is
	 * the primary. Any further attendee, plus a Sharing a Room roommate -
	 * who is recorded on the booking rather than as an attendee row - is an
	 * additional attendee. If a booking somehow has no attendee rows at all,
	 * the booking's own customer details stand in so the primary columns are
	 * never empty for a real order.
	 *
	 * @param object                                         $booking   Booking row.
	 * @param object[]                                       $attendees Attendee rows, ordered.
	 * @param array<int, array{name: string, email: string}> $roommates Roommates on the booking.
	 * @return array{primary_name: string, primary_email: string, additional_names: string[], additional_emails: string[]}
	 */
	private static function split_people( object $booking, array $attendees, array $roommates ): array {
		$primary_name  = '';
		$primary_email = '';
		$names         = array();
		$emails        = array();
		$have_primary  = false;

		foreach ( array_values( $attendees ) as $index => $attendee ) {
			$name  = trim( (string) $attendee->name );
			$email = trim( (string) $attendee->email );

			if ( '' === $name && '' === $email ) {
				continue;
			}

			if ( ! $have_primary && 0 === $index ) {
				$primary_name  = $name;
				$primary_email = $email;
				$have_primary  = true;
				continue;
			}

			// Pushed as a pair, always, so the two columns stay aligned.
			$names[]  = $name;
			$emails[] = $email;
		}

		if ( ! $have_primary ) {
			$primary_name  = trim( (string) $booking->customer_name );
			$primary_email = trim( (string) $booking->customer_email );
		}

		foreach ( $roommates as $roommate ) {
			$name  = trim( (string) $roommate['name'] );
			$email = trim( (string) $roommate['email'] );

			if ( '' === $name && '' === $email ) {
				continue;
			}

			// Labelled on the name so a roommate is never mistaken for a
			// ticket holder, while the email column stays clean for a mail
			// merge.
			$names[]  = '' !== $name
				? $name . ' (' . __( 'Sharing a Room', 'event-ticket-manager' ) . ')'
				: '(' . __( 'Sharing a Room', 'event-ticket-manager' ) . ')';
			$emails[] = $email;
		}

		return array(
			'primary_name'      => $primary_name,
			'primary_email'     => $primary_email,
			'additional_names'  => $names,
			'additional_emails' => $emails,
		);
	}

	/**
	 * The ticket-type column: every line's title and quantity, with a
	 * Sharing a Room line relabelled and given its roommate detail.
	 *
	 * @param array<int, array<string, mixed>>               $lines     Ticket lines.
	 * @param array<int, array{name: string, email: string}> $roommates Roommates on the booking.
	 * @return string
	 */
	private static function ticket_type_summary( array $lines, array $roommates ): string {
		if ( empty( $lines ) ) {
			return '';
		}

		$roommate_names = array_filter( array_map( static fn( $r ) => trim( (string) $r['name'] ), $roommates ) );
		$summary        = array();

		foreach ( $lines as $line ) {
			$type    = (int) ( $line['ticket_type_id'] ?? 0 ) > 0 ? ETM_Event_Tickets::get( (int) $line['ticket_type_id'] ) : null;
			$is_room = $type && ETM_Event_Tickets::is_sharing_room( $type );

			$label = $is_room ? __( 'Sharing a Room', 'event-ticket-manager' ) : (string) ( $line['title'] ?? '' );

			if ( $is_room && ! empty( $roommate_names ) ) {
				$label .= ' (' . implode( ', ', $roommate_names ) . ')';
			}

			$summary[] = $label . ' x' . (int) ( $line['quantity'] ?? 0 );
		}

		return implode( self::SEPARATOR, $summary );
	}
}

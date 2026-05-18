<?php
/**
 * Cross-request event buffer.
 *
 * WordPress has no long-lived process, so events accumulate in an option and
 * are flushed once the buffer is large enough or old enough. Most requests
 * just append (a single option write) and never make an outbound call.
 *
 * @package Temso
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option-backed buffer that accumulates events across requests.
 */
class Temso_Buffer {

	const EVENTS_OPTION  = 'temso_buffer';
	const STARTED_OPTION = 'temso_buffer_started_at';

	/**
	 * Append one event. Returns true when the buffer is now due for a flush.
	 *
	 * Concurrent requests can race on the option read/write; a lost append is
	 * acceptable (delivery is best-effort by design).
	 *
	 * @param array $event Normalized event payload.
	 * @return bool
	 */
	public function add( array $event ) {
		$events = get_option( self::EVENTS_OPTION, array() );
		if ( ! is_array( $events ) ) {
			$events = array();
		}

		if ( empty( $events ) ) {
			update_option( self::STARTED_OPTION, time(), false );
		}

		$events[] = $event;

		// Hard ceiling so a misconfigured/unreachable endpoint (flush never
		// succeeds) can't grow this option without bound. Oldest events go
		// first — delivery is best-effort by design.
		$max = TEMSO_BATCH_MAX_SIZE * 5;
		if ( count( $events ) > $max ) {
			$events = array_slice( $events, -$max );
		}

		update_option( self::EVENTS_OPTION, $events, false );

		return $this->is_due( count( $events ) );
	}

	/**
	 * Whether the buffer should be flushed now.
	 *
	 * @param int $size Current buffer size.
	 * @return bool
	 */
	private function is_due( $size ) {
		if ( $size >= TEMSO_BATCH_MAX_SIZE ) {
			return true;
		}

		$started = (int) get_option( self::STARTED_OPTION, 0 );

		return $started > 0 && ( time() - $started ) >= TEMSO_BATCH_MAX_AGE;
	}

	/**
	 * Drain the buffer, returning the events and clearing storage.
	 *
	 * @return array
	 */
	public function drain() {
		$events = get_option( self::EVENTS_OPTION, array() );
		if ( ! is_array( $events ) ) {
			$events = array();
		}

		delete_option( self::EVENTS_OPTION );
		delete_option( self::STARTED_OPTION );

		return $events;
	}

	/**
	 * Ship whatever is buffered immediately (used on deactivation).
	 */
	public function flush_now() {
		$events = $this->drain();
		if ( ! empty( $events ) ) {
			( new Temso_Dispatcher() )->send( $events );
		}
	}
}

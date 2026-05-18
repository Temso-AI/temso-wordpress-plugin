<?php
/**
 * Unit tests for Temso_Buffer.
 *
 * @package Temso
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-temso-buffer.php';

final class BufferTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_add_appends_event_and_is_not_due_below_threshold(): void {
		$stored = array();

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$stored ) {
				return $stored[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored[ $name ] = $value;
				return true;
			}
		);

		$buffer = new Temso_Buffer();
		$due    = $buffer->add( array( 'url' => '/' ) );

		$this->assertFalse( $due, 'A single event must not trigger a flush.' );
		$this->assertCount( 1, $stored[ Temso_Buffer::EVENTS_OPTION ] );
		$this->assertArrayHasKey( Temso_Buffer::STARTED_OPTION, $stored );
	}

	public function test_add_is_due_when_size_reaches_max(): void {
		$events = array_fill( 0, TEMSO_BATCH_MAX_SIZE - 1, array( 'url' => '/' ) );
		$stored = array(
			Temso_Buffer::EVENTS_OPTION  => $events,
			Temso_Buffer::STARTED_OPTION => time(),
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$stored ) {
				return $stored[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored[ $name ] = $value;
				return true;
			}
		);

		$buffer = new Temso_Buffer();

		$this->assertTrue(
			$buffer->add( array( 'url' => '/' ) ),
			'Reaching TEMSO_BATCH_MAX_SIZE must mark the buffer due for a flush.'
		);
	}

	public function test_add_enforces_hard_ceiling(): void {
		$max    = TEMSO_BATCH_MAX_SIZE * 5;
		$stored = array(
			Temso_Buffer::EVENTS_OPTION  => array_fill( 0, $max, array( 'url' => '/old' ) ),
			Temso_Buffer::STARTED_OPTION => time(),
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$stored ) {
				return $stored[ $name ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored[ $name ] = $value;
				return true;
			}
		);

		( new Temso_Buffer() )->add( array( 'url' => '/new' ) );

		$this->assertCount( $max, $stored[ Temso_Buffer::EVENTS_OPTION ], 'Buffer must not grow past its hard ceiling.' );
		$this->assertSame( '/new', end( $stored[ Temso_Buffer::EVENTS_OPTION ] )['url'], 'Newest event must be kept; oldest dropped.' );
	}

	public function test_drain_returns_and_clears_events(): void {
		$stored = array(
			Temso_Buffer::EVENTS_OPTION  => array( array( 'url' => '/a' ), array( 'url' => '/b' ) ),
			Temso_Buffer::STARTED_OPTION => 123,
		);

		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$stored ) {
				return $stored[ $name ] ?? $default;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) use ( &$stored ) {
				unset( $stored[ $name ] );
				return true;
			}
		);

		$drained = ( new Temso_Buffer() )->drain();

		$this->assertCount( 2, $drained );
		$this->assertArrayNotHasKey( Temso_Buffer::EVENTS_OPTION, $stored );
		$this->assertArrayNotHasKey( Temso_Buffer::STARTED_OPTION, $stored );
	}
}

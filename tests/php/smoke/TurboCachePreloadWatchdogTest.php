<?php
/**
 * Smoke tests for Turbo Cache Safe Preload Watchdog (Phase 14).
 *
 * Verifies recovery of genuinely stalled preload workers, legacy counter independence,
 * prevention of duplicate cron events, stop flag respect, and malformed state tolerance.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning

require_once dirname( __DIR__, 3 ) . '/inc/preload-engine.php';
require_once dirname( __DIR__, 3 ) . '/inc/preload.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCachePreloadWatchdogTest
 *
 * @covers ::check_up_on_preloading
 */
class TurboCachePreloadWatchdogTest extends TestCase {

	/**
	 * Temporary directory.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Saved global cache path.
	 *
	 * @var string|null
	 */
	private $saved_cache_path;

	/**
	 * Set up test environment before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . '/tc_watchdog_test_' . uniqid() . '/';
		mkdir( $this->temp_dir, 0777, true );

		$this->saved_cache_path = isset( $GLOBALS['cache_path'] ) ? $GLOBALS['cache_path'] : null;
		$GLOBALS['cache_path']  = $this->temp_dir;

		$GLOBALS['wpsc_test_scheduled_events'] = array();
		$GLOBALS['wpsc_test_options']          = array();

		if ( file_exists( $this->temp_dir . 'stop_preload.txt' ) ) {
			@unlink( $this->temp_dir . 'stop_preload.txt' );
		}
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'turbo_cache_use_safe_preload' );

		if ( is_dir( $this->temp_dir ) ) {
			$files = glob( $this->temp_dir . '*' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					@unlink( $file );
				}
			}
			@rmdir( $this->temp_dir );
		}

		if ( null !== $this->saved_cache_path ) {
			$GLOBALS['cache_path'] = $this->saved_cache_path;
		} else {
			unset( $GLOBALS['cache_path'] );
		}

		unset( $GLOBALS['wpsc_test_scheduled_events'], $GLOBALS['wpsc_test_options'] );

		parent::tearDown();
	}

	/**
	 * 1. Watchdog kickstarts a stalled Safe Preload job whose legacy counter is zero.
	 */
	public function test_watchdog_restarts_stalled_safe_preload_job_with_zero_counter() {
		// Set legacy counter to 0 (modern safe preload does not use this counter).
		update_option(
			'preload_cache_counter',
			array(
				'c' => 0,
				't' => time() - 5000,
			)
		);

		// Set Safe Preload state to running with last update older than 3600 seconds.
		$state = array(
			'status'       => 'running',
			'generation'   => 'gen_watchdog_1',
			'started_at'   => time() - 4000,
			'updated_at'   => time() - 3700,
			'queue'        => array( 'https://example.com/item1', 'https://example.com/item2' ),
			'queue_cursor' => 0,
		);
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );

		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		check_up_on_preloading();

		$scheduled = wp_next_scheduled( 'wp_cache_preload_hook' );
		$this->assertNotFalse( $scheduled, 'Watchdog must schedule preload hook for stalled job' );
		$this->assertGreaterThanOrEqual( time(), $scheduled );
		$this->assertLessThanOrEqual( time() + 10, $scheduled );
	}

	/**
	 * 2. Watchdog does not restart a recently progressing job.
	 */
	public function test_watchdog_does_not_restart_recently_progressing_job() {
		$state = array(
			'status'       => 'running',
			'generation'   => 'gen_watchdog_2',
			'started_at'   => time() - 500,
			'updated_at'   => time() - 120, // 2 minutes ago
			'queue'        => array( 'https://example.com/item1' ),
			'queue_cursor' => 0,
		);
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );

		check_up_on_preloading();

		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ), 'Watchdog must not schedule event for recently updated job' );
	}

	/**
	 * 3. Watchdog does not restart when a live worker lock is fresh (< 600s).
	 */
	public function test_watchdog_does_not_restart_when_worker_lock_is_fresh() {
		$state = array(
			'status'       => 'running',
			'generation'   => 'gen_watchdog_3',
			'started_at'   => time() - 4000,
			'updated_at'   => time() - 3800,
			'queue'        => array( 'https://example.com/item1' ),
			'queue_cursor' => 0,
		);
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );

		// Active worker lock file modified 60 seconds ago.
		$lock_file = $this->temp_dir . 'preload_batch.lock';
		file_put_contents(
			$lock_file,
			json_encode(
				array(
					'token' => 'active_worker_token',
					'time'  => time() - 60,
				)
			)
		);
		touch( $lock_file, time() - 60 );

		check_up_on_preloading();

		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ), 'Watchdog must not kickstart when worker lock is fresh' );
	}

	/**
	 * 4. Watchdog does not restart completed, stopped, or inactive states.
	 */
	public function test_watchdog_does_not_restart_inactive_or_stopped_states() {
		// A: Completed state.
		$state = array(
			'status'       => 'completed',
			'generation'   => 'gen_watchdog_4',
			'started_at'   => time() - 5000,
			'updated_at'   => time() - 4000,
			'queue'        => array(),
			'queue_cursor' => 0,
		);
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );

		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		// B: Idle state.
		$state['status'] = 'idle';
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );
		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		// C: Cancelled state.
		$state['status'] = 'cancelled';
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );
		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		// D: Running state, but stop flag exists.
		$state['status'] = 'running';
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );
		file_put_contents( $this->temp_dir . 'stop_preload.txt', 'stop' );
		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ), 'Stop flag must prevent restart' );
	}

	/**
	 * 5. Watchdog does not schedule duplicate events when cron event is already queued.
	 */
	public function test_watchdog_avoids_duplicate_events() {
		$state = array(
			'status'       => 'running',
			'generation'   => 'gen_watchdog_5',
			'started_at'   => time() - 4000,
			'updated_at'   => time() - 3800,
			'queue'        => array( 'https://example.com/item' ),
			'queue_cursor' => 0,
		);
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );

		// Existing event queued at time() + 30.
		wp_schedule_single_event( time() + 30, 'wp_cache_preload_hook' );
		$this->assertCount( 1, $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] );

		check_up_on_preloading();

		// Count must remain 1; no duplicate event.
		$this->assertCount( 1, $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] );
	}

	/**
	 * 6. Watchdog does not repeatedly schedule work on every page load.
	 */
	public function test_watchdog_does_not_reschedule_on_subsequent_page_loads() {
		$state = array(
			'status'       => 'running',
			'generation'   => 'gen_watchdog_6',
			'started_at'   => time() - 4000,
			'updated_at'   => time() - 3800,
			'queue'        => array( 'https://example.com/item' ),
			'queue_cursor' => 0,
		);
		file_put_contents( $this->temp_dir . 'preload_state.json', json_encode( $state ) );

		// First page load: schedules restart.
		check_up_on_preloading();
		$this->assertCount( 1, $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] );

		// Second page load: detects existing scheduled event and does not reschedule.
		check_up_on_preloading();
		$this->assertCount( 1, $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] );

		// Third page load: still exactly 1 event.
		check_up_on_preloading();
		$this->assertCount( 1, $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] );
	}

	/**
	 * 7. Watchdog legacy-mode fallback when safe preload is disabled.
	 */
	public function test_watchdog_legacy_mode_behavior() {
		add_filter(
			'turbo_cache_use_safe_preload',
			function () {
				return false;
			}
		);

		// A: Counter > 0 and elapsed > 3600 schedules restart.
		update_option(
			'preload_cache_counter',
			array(
				'c' => 5,
				't' => time() - 4000,
			)
		);
		check_up_on_preloading();
		$this->assertNotFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		// Reset scheduled events.
		$GLOBALS['wpsc_test_scheduled_events'] = array();

		// B: Counter is 0 does not schedule restart.
		update_option(
			'preload_cache_counter',
			array(
				'c' => 0,
				't' => time() - 4000,
			)
		);
		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );
	}

	/**
	 * 8. Watchdog tolerates missing or malformed state data without throwing or scheduling.
	 */
	public function test_watchdog_handles_missing_or_malformed_state() {
		// A: Empty / corrupt state file.
		file_put_contents( $this->temp_dir . 'preload_state.json', '{corrupt json...' );
		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		// B: Valid JSON but unexpected schema (missing timestamps, null values).
		file_put_contents(
			$this->temp_dir . 'preload_state.json',
			json_encode(
				array(
					'status'     => 'running',
					'updated_at' => null,
					'started_at' => 0,
				)
			)
		);
		check_up_on_preloading();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );
	}
}

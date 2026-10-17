<?php
/**
 * Regression and concurrency tests for Turbo Cache Safe Preload engine.
 *
 * Verifies reset safety, worker lock ownership, stale-lock recovery,
 * in-loop cancellation responsiveness, and cron lock-collision retry recovery.
 *
 * @package Turbo_Cache
 */

// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
// phpcs:disable Generic.Formatting.MultipleStatementAlignment.NotSameWarning
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

require_once dirname( __DIR__, 3 ) . '/inc/preload-engine.php';
require_once dirname( __DIR__, 3 ) . '/inc/preload.php';

use PHPUnit\Framework\TestCase;

/**
 * Class TurboCachePreloadConcurrencyTest
 *
 * @covers ::turbo_cache_preload_acquire_lock
 * @covers ::turbo_cache_preload_release_lock
 * @covers ::turbo_cache_preload_get_lock_token
 * @covers ::turbo_cache_preload_save_state
 * @covers ::turbo_cache_preload_reset_state
 * @covers ::turbo_cache_preload_process_batch
 * @covers ::wp_cron_preload_cache
 */
class TurboCachePreloadConcurrencyTest extends TestCase {

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
		$this->temp_dir = sys_get_temp_dir() . '/tc_concurrency_test_' . uniqid() . '/';
		mkdir( $this->temp_dir, 0777, true );

		$this->saved_cache_path = isset( $GLOBALS['cache_path'] ) ? $GLOBALS['cache_path'] : null;
		$GLOBALS['cache_path']  = $this->temp_dir;

		$GLOBALS['wpsc_test_scheduled_events'] = array();
		$GLOBALS['wpsc_test_options']          = array();

		// Ensure stop flag does not exist.
		if ( file_exists( $this->temp_dir . 'stop_preload.txt' ) ) {
			@unlink( $this->temp_dir . 'stop_preload.txt' );
		}
	}

	/**
	 * Tear down test environment after each test.
	 */
	protected function tearDown(): void {
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
	 * 1. Reset while a worker owns the lock.
	 *
	 * When reset is executed while an active worker owns the lock, the state is
	 * reset to idle with a new generation ID, superseding the active worker.
	 */
	public function test_reset_while_worker_owns_lock() {
		turbo_cache_preload_reset_state();

		$worker1_token = null;
		$acquired      = turbo_cache_preload_acquire_lock( $worker1_token );
		$this->assertTrue( $acquired );
		$this->assertNotEmpty( $worker1_token );

		// Set running state under Worker 1.
		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array( 'https://example.com/item-1', 'https://example.com/item-2' );
		$state['queue_cursor'] = 1;
		$w1_gen                = $state['generation'];
		$this->assertTrue( turbo_cache_preload_save_state( $state, $w1_gen, $worker1_token ) );

		// Explicit admin/user reset is triggered.
		turbo_cache_preload_reset_state();

		$state_after = turbo_cache_preload_get_state();
		$this->assertSame( 'idle', $state_after['status'] );
		$this->assertEmpty( $state_after['queue'] );
		$this->assertSame( 0, $state_after['queue_cursor'] );
		$this->assertNotSame( $w1_gen, $state_after['generation'] );
	}

	/**
	 * 2. An old worker attempting to save state after a reset.
	 *
	 * An in-flight worker holding stale in-memory state cannot overwrite the reset
	 * state or resurrect the old queue.
	 */
	public function test_old_worker_cannot_save_state_after_reset() {
		turbo_cache_preload_reset_state();

		$worker1_token = null;
		turbo_cache_preload_acquire_lock( $worker1_token );

		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array( 'https://example.com/old-1', 'https://example.com/old-2' );
		$state['queue_cursor'] = 1;
		$w1_gen                = $state['generation'];
		$this->assertTrue( turbo_cache_preload_save_state( $state, $w1_gen, $worker1_token ) );

		// Reset happens while worker 1 is busy.
		turbo_cache_preload_reset_state();

		// Worker 1 finishes its URL and attempts to commit stale in-memory state.
		$stale_worker_state                 = $state;
		$stale_worker_state['queue_cursor'] = 2;
		$stale_worker_state['status']       = 'completed';

		$save_result = turbo_cache_preload_save_state( $stale_worker_state, $w1_gen, $worker1_token );
		$this->assertFalse( $save_result, 'Stale worker must not be allowed to commit state after reset' );

		// On-disk state must remain reset/idle.
		$current_disk_state = turbo_cache_preload_get_state();
		$this->assertSame( 'idle', $current_disk_state['status'] );
		$this->assertEmpty( $current_disk_state['queue'] );
		$this->assertSame( 0, $current_disk_state['queue_cursor'] );
	}

	/**
	 * 3. An old worker attempting to release a newer worker's lock.
	 *
	 * A worker must only release the lock file if its own ownership token matches.
	 */
	public function test_old_worker_cannot_release_newer_worker_lock() {
		// Worker 1 acquires lock.
		$token_w1 = null;
		$this->assertTrue( turbo_cache_preload_acquire_lock( $token_w1 ) );

		// Force simulate lock takeover by Worker 2 (e.g. after stale recovery).
		$lock_file = $this->temp_dir . 'preload_batch.lock';
		$token_w2  = 'worker_newer_token_9999';
		file_put_contents(
			$lock_file,
			json_encode(
				array(
					'token' => $token_w2,
					'time'  => time(),
				)
			)
		);

		// Worker 1 completes and attempts to release lock with its old token.
		$released = turbo_cache_preload_release_lock( $token_w1 );
		$this->assertFalse( $released, 'Release by old worker must fail' );

		// Lock file must still exist and retain Worker 2's token.
		$this->assertFileExists( $lock_file );
		$this->assertSame( $token_w2, turbo_cache_preload_get_lock_token() );

		// Worker 2 releases lock with its valid token.
		$released_w2 = turbo_cache_preload_release_lock( $token_w2 );
		$this->assertTrue( $released_w2 );
		$this->assertFileDoesNotExist( $lock_file );
	}

	/**
	 * 4. A lock collision between two worker attempts.
	 *
	 * Second worker receives 'locked' status and existing queue state is preserved.
	 */
	public function test_lock_collision_between_workers() {
		turbo_cache_preload_reset_state();

		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array( 'https://example.com/p1', 'https://example.com/p2' );
		$state['queue_cursor'] = 0;
		turbo_cache_preload_save_state( $state );

		// Worker 1 acquires lock.
		$token1 = null;
		$this->assertTrue( turbo_cache_preload_acquire_lock( $token1 ) );

		// Worker 2 attempts batch while locked.
		$result = turbo_cache_preload_process_batch( 1 );
		$this->assertSame( 'locked', $result['status'] );
		$this->assertSame( 'Another preload batch is in progress.', $result['message'] );

		// State must remain uncorrupted.
		$disk_state = turbo_cache_preload_get_state();
		$this->assertSame( 0, $disk_state['queue_cursor'] );
		$this->assertSame( 'running', $disk_state['status'] );

		turbo_cache_preload_release_lock( $token1 );
	}

	/**
	 * 5. Stale-lock recovery without deleting a newly acquired lock.
	 *
	 * Stale lock (> 600s) is safely recycled by Worker 1; once acquired,
	 * another concurrent attempt sees the lock as fresh and does not delete it.
	 */
	public function test_stale_lock_recovery_protects_new_lock() {
		$lock_file = $this->temp_dir . 'preload_batch.lock';

		// Create a stale lock from 700 seconds ago.
		file_put_contents(
			$lock_file,
			json_encode(
				array(
					'token' => 'worker_ancient_111',
					'time'  => time() - 700,
				)
			)
		);
		touch( $lock_file, time() - 700 );

		// Worker 1 acquires lock (recovering stale lock).
		$token_w1 = null;
		$this->assertTrue( turbo_cache_preload_acquire_lock( $token_w1 ) );
		$this->assertNotEmpty( $token_w1 );
		$this->assertNotSame( 'worker_ancient_111', $token_w1 );

		// A concurrent Worker 2 tries to acquire immediately.
		// Since Worker 1's lock is fresh (0s old), Worker 2 must be rejected.
		$token_w2 = null;
		$this->assertFalse( turbo_cache_preload_acquire_lock( $token_w2 ) );
		$this->assertNull( $token_w2 );

		// Worker 1's lock file must remain intact and belong to Worker 1.
		$this->assertFileExists( $lock_file );
		$this->assertSame( $token_w1, turbo_cache_preload_get_lock_token() );

		turbo_cache_preload_release_lock( $token_w1 );
	}

	/**
	 * 6. Cancellation during a multi-URL batch prevents processing subsequent URLs.
	 *
	 * A stop flag created mid-batch halts the loop before processing the next URL.
	 */
	public function test_cancellation_during_batch_stops_subsequent_urls() {
		turbo_cache_preload_reset_state();

		$state                 = turbo_cache_preload_get_state();
		$state['status']       = 'running';
		$state['queue']        = array(
			'https://example.com/page-1',
			'https://example.com/page-2',
			'https://example.com/page-3',
			'https://example.com/page-4',
		);
		$state['queue_cursor'] = 0;
		$state['stats']['total_discovered'] = 4;
		turbo_cache_preload_save_state( $state );

		// Simulate HTTP request creating the stop flag during the first URL request.
		add_filter(
			'pre_http_request',
			function ( $response, $parsed_args, $url ) {
				unset( $response, $parsed_args, $url );
				// Create stop_preload.txt flag during URL processing.
				global $cache_path;
				file_put_contents( $cache_path . 'stop_preload.txt', '' );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '<html><body>Test Content</body></html>',
				);
			},
			10,
			3
		);

		// Process batch with requested size 4.
		$batch_res = turbo_cache_preload_process_batch( 4 );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'cancelled', $batch_res['status'] );
		$this->assertSame( 1, $batch_res['processed_in_batch'], 'Only the first in-flight URL should be processed' );
		$this->assertSame( 1, $batch_res['queue_cursor'] );

		// Preload lock must be cleanly released.
		$this->assertNull( turbo_cache_preload_get_lock_token() );
	}

	/**
	 * 7. Completed URLs remain correctly represented after cancellation.
	 *
	 * When cancelled, previously completed URLs and stats are preserved.
	 */
	public function test_completed_urls_represented_after_cancellation() {
		turbo_cache_preload_reset_state();

		$state                              = turbo_cache_preload_get_state();
		$state['status']                    = 'running';
		$state['queue']                     = array(
			'https://example.com/done-1',
			'https://example.com/done-2',
			'https://example.com/pending-3',
			'https://example.com/pending-4',
		);
		$state['queue_cursor']              = 2;
		$state['stats']['total_discovered'] = 4;
		$state['stats']['processed']        = 2;
		$state['stats']['cached']           = 2;
		turbo_cache_preload_save_state( $state );

		// Create stop flag before batch runs.
		file_put_contents( $this->temp_dir . 'stop_preload.txt', '' );

		$res = turbo_cache_preload_process_batch( 2 );

		$this->assertSame( 'cancelled', $res['status'] );

		$disk_state = turbo_cache_preload_get_state();
		$this->assertSame( 'cancelled', $disk_state['status'] );
		$this->assertSame( 2, $disk_state['queue_cursor'] );
		$this->assertSame( 2, $disk_state['stats']['processed'] );
		$this->assertSame( 2, $disk_state['stats']['cached'] );
	}

	/**
	 * 8. Cron receiving 'locked' schedules a delayed retry.
	 *
	 * When wp_cron_preload_cache encounters a locked batch for an active queue,
	 * it schedules a delayed retry at time() + 15 instead of terminating permanently.
	 */
	public function test_cron_locked_batch_schedules_delayed_retry() {
		turbo_cache_preload_reset_state();

		// Set queue to running.
		$state                              = turbo_cache_preload_get_state();
		$state['status']                    = 'running';
		$state['queue']                     = array( 'https://example.com/1', 'https://example.com/2' );
		$state['queue_cursor']              = 0;
		$state['stats']['total_discovered'] = 2;
		turbo_cache_preload_save_state( $state );

		// Worker 1 holds the lock.
		$token_w1 = null;
		$this->assertTrue( turbo_cache_preload_acquire_lock( $token_w1 ) );

		// wp_cron_preload_cache runs.
		$cron_result = wp_cron_preload_cache();
		$this->assertTrue( $cron_result );

		// A delayed retry must be scheduled for wp_cache_preload_hook.
		$scheduled_timestamp = wp_next_scheduled( 'wp_cache_preload_hook' );
		$this->assertNotFalse( $scheduled_timestamp );
		$this->assertGreaterThanOrEqual( time() + 10, $scheduled_timestamp );
		$this->assertLessThanOrEqual( time() + 20, $scheduled_timestamp );

		turbo_cache_preload_release_lock( $token_w1 );
	}

	/**
	 * 9. Repeated 'locked' results do not create duplicate scheduled events.
	 */
	public function test_repeated_locked_results_do_not_duplicate_scheduled_events() {
		turbo_cache_preload_reset_state();

		$state                              = turbo_cache_preload_get_state();
		$state['status']                    = 'running';
		$state['queue']                     = array( 'https://example.com/1' );
		$state['queue_cursor']              = 0;
		$state['stats']['total_discovered'] = 1;
		turbo_cache_preload_save_state( $state );

		$token = null;
		turbo_cache_preload_acquire_lock( $token );

		// Run cron 3 times while locked.
		wp_cron_preload_cache();
		wp_cron_preload_cache();
		wp_cron_preload_cache();

		// Check the events registry: exactly 1 event must be scheduled for wp_cache_preload_hook.
		$hook_events = isset( $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] ) ? $GLOBALS['wpsc_test_scheduled_events']['wp_cache_preload_hook'] : array();
		$this->assertCount( 1, $hook_events );

		turbo_cache_preload_release_lock( $token );
	}

	/**
	 * 10. A completed queue is not unnecessarily rescheduled.
	 */
	public function test_completed_queue_is_not_rescheduled() {
		turbo_cache_preload_reset_state();

		$state                              = turbo_cache_preload_get_state();
		$state['status']                    = 'completed';
		$state['queue']                     = array( 'https://example.com/1' );
		$state['queue_cursor']              = 1;
		$state['stats']['total_discovered'] = 1;
		$state['stats']['processed']        = 1;
		turbo_cache_preload_save_state( $state );

		$cron_result = wp_cron_preload_cache();
		$this->assertTrue( $cron_result );

		$scheduled = wp_next_scheduled( 'wp_cache_preload_hook' );
		$this->assertFalse( $scheduled );
	}

	/**
	 * 11. Reset and cancellation do not revive an obsolete cron chain.
	 */
	public function test_reset_and_cancellation_do_not_revive_cron_chain() {
		turbo_cache_preload_reset_state();

		// State is idle after reset.
		$token = null;
		turbo_cache_preload_acquire_lock( $token );

		// Running cron when state is idle should NOT schedule a retry, even if locked.
		wp_cron_preload_cache();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		// If stop_preload.txt exists, even if state is 'running', do NOT schedule retry.
		file_put_contents( $this->temp_dir . 'stop_preload.txt', '' );
		$state           = turbo_cache_preload_get_state();
		$state['status'] = 'running';
		turbo_cache_preload_save_state( $state, null, null, true );

		wp_cron_preload_cache();
		$this->assertFalse( wp_next_scheduled( 'wp_cache_preload_hook' ) );

		turbo_cache_preload_release_lock( $token );
	}

	/**
	 * 12. Existing normal preload, success, failure, and batch-limit behavior remains intact.
	 */
	public function test_normal_preload_lifecycle_intact() {
		turbo_cache_preload_reset_state();

		$state                              = turbo_cache_preload_get_state();
		$state['status']                    = 'running';
		$state['queue']                     = array(
			'https://example.com/ok-1',
			'https://example.com/ok-2',
			'https://example.com/ok-3',
		);
		$state['queue_cursor']              = 0;
		$state['stats']['total_discovered'] = 3;
		turbo_cache_preload_save_state( $state );

		// Mock HTTP request to return 200 and create static cache file.
		add_filter(
			'pre_http_request',
			function ( $response, $parsed_args, $url ) {
				unset( $response, $parsed_args );
				// Create simulated cache entry on disk.
				$parts = parse_url( $url );
				$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
				$dir   = $GLOBALS['cache_path'] . 'supercache/' . $parts['host'] . $path . '/';
				if ( ! is_dir( $dir ) ) {
					mkdir( $dir, 0777, true );
				}
				file_put_contents( $dir . 'index-https.html', '<!-- turbo-cache test warmed -->' );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '<html><body>Warmed</body></html>',
				);
			},
			10,
			3
		);

		// Batch 1: process 2 URLs.
		$b1 = turbo_cache_preload_process_batch( 2 );
		$this->assertSame( 'running', $b1['status'] );
		$this->assertSame( 2, $b1['processed_in_batch'] );
		$this->assertSame( 2, $b1['queue_cursor'] );

		// Batch 2: process remaining 1 URL.
		$b2 = turbo_cache_preload_process_batch( 2 );
		$this->assertSame( 'completed', $b2['status'] );
		$this->assertSame( 1, $b2['processed_in_batch'] );
		$this->assertSame( 3, $b2['queue_cursor'] );
		$this->assertSame( 3, $b2['stats']['cached'] );

		remove_all_filters( 'pre_http_request' );

		// Verify final state.
		$final_state = turbo_cache_preload_get_state();
		$this->assertSame( 'completed', $final_state['status'] );
		$this->assertSame( 3, $final_state['queue_cursor'] );
		$this->assertGreaterThan( 0, $final_state['finished_at'] );
	}
}

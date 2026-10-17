<?php
/**
 * Turbo Cache Diagnostics Admin UI partial.
 *
 * Renders the cache diagnostics and observability dashboard tab within
 * the WP Super Cache administration interface.
 *
 * @package Turbo_Cache
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-super-cache' ) );
}

$logging_enabled = turbo_cache_diagnostics_is_enabled();
$summary         = turbo_cache_diagnostics_get_summary();
$recent_events   = turbo_cache_diagnostics_get_recent_log( 50 );
?>

<div class="wpsc-settings-inner" id="turbo-cache-diagnostics-app">
	<a name="diagnostics"></a>

	<!-- Status & Operations Card -->
	<div class="wpsc-card">
		<h3><?php esc_html_e( 'Cache Diagnostics & Observability', 'wp-super-cache' ); ?></h3>
		<p>
			<?php esc_html_e( 'Inspect real-time cache delivery decisions, rule bypasses, output buffer generation, and preload warming events.', 'wp-super-cache' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Note: Persistent disk logging is disabled by default to preserve zero disk I/O on production traffic. In-memory diagnostics for the active request execute with sub-microsecond overhead. Enable persistent logging temporarily when troubleshooting cache behavior.', 'wp-super-cache' ); ?>
		</p>

		<div class="turbo-diag-status-row" style="margin: 1.5em 0 1em; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
			<strong><?php esc_html_e( 'Persistent Logging Status:', 'wp-super-cache' ); ?></strong>
			<span id="turbo-diag-status-badge" class="turbo-badge <?php echo $logging_enabled ? 'turbo-badge-enabled' : 'turbo-badge-disabled'; ?>">
				<?php echo $logging_enabled ? esc_html__( 'Enabled', 'wp-super-cache' ) : esc_html__( 'Disabled', 'wp-super-cache' ); ?>
			</span>

			<button type="button" id="turbo-diag-toggle-btn" class="button <?php echo $logging_enabled ? 'button-secondary' : 'button-primary'; ?>" data-enabled="<?php echo $logging_enabled ? '1' : '0'; ?>">
				<?php echo $logging_enabled ? esc_html__( 'Disable Logging', 'wp-super-cache' ) : esc_html__( 'Enable Logging', 'wp-super-cache' ); ?>
			</button>

			<button type="button" id="turbo-diag-refresh-btn" class="button button-secondary">
				<span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
				<?php esc_html_e( 'Refresh', 'wp-super-cache' ); ?>
			</button>

			<button type="button" id="turbo-diag-clear-btn" class="button button-link-delete" style="margin-left: auto;">
				<?php esc_html_e( 'Clear Diagnostic Log', 'wp-super-cache' ); ?>
			</button>
		</div>

		<div id="turbo-diag-feedback" class="notice notice-info inline" style="display:none; margin-top: 1em;">
			<p></p>
		</div>
	</div>

	<!-- Aggregate Metrics Card -->
	<div class="wpsc-card">
		<h3><?php esc_html_e( 'Diagnostic Metrics Summary', 'wp-super-cache' ); ?></h3>

		<div class="turbo-metrics-grid" style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 1.5em;">
			<div class="turbo-stat-box">
				<div class="turbo-stat-label"><?php esc_html_e( 'Total Logged', 'wp-super-cache' ); ?></div>
				<div class="turbo-stat-number" id="stat-total-recorded"><?php echo (int) $summary['total_recorded']; ?></div>
			</div>
			<div class="turbo-stat-box" style="border-top: 3px solid #22863a;">
				<div class="turbo-stat-label"><?php esc_html_e( 'Cache HIT', 'wp-super-cache' ); ?></div>
				<div class="turbo-stat-number" id="stat-cat-hit" style="color: #22863a;"><?php echo (int) $summary['by_category']['hit']; ?></div>
			</div>
			<div class="turbo-stat-box" style="border-top: 3px solid #d97706;">
				<div class="turbo-stat-label"><?php esc_html_e( 'Cache MISS', 'wp-super-cache' ); ?></div>
				<div class="turbo-stat-number" id="stat-cat-miss" style="color: #d97706;"><?php echo (int) $summary['by_category']['miss']; ?></div>
			</div>
			<div class="turbo-stat-box" style="border-top: 3px solid #6b7280;">
				<div class="turbo-stat-label"><?php esc_html_e( 'Cache BYPASS', 'wp-super-cache' ); ?></div>
				<div class="turbo-stat-number" id="stat-cat-bypass" style="color: #4b5563;"><?php echo (int) $summary['by_category']['bypass']; ?></div>
			</div>
			<div class="turbo-stat-box" style="border-top: 3px solid #7c3aed;">
				<div class="turbo-stat-label"><?php esc_html_e( 'GENERATION', 'wp-super-cache' ); ?></div>
				<div class="turbo-stat-number" id="stat-cat-generation" style="color: #7c3aed;"><?php echo (int) $summary['by_category']['generation']; ?></div>
			</div>
			<div class="turbo-stat-box" style="border-top: 3px solid #0284c7;">
				<div class="turbo-stat-label"><?php esc_html_e( 'PRELOAD', 'wp-super-cache' ); ?></div>
				<div class="turbo-stat-number" id="stat-cat-preload" style="color: #0284c7;"><?php echo (int) $summary['by_category']['preload']; ?></div>
			</div>
		</div>

		<h4><?php esc_html_e( 'Outcome Code Breakdown', 'wp-super-cache' ); ?></h4>
		<div id="turbo-outcome-pills" style="display: flex; gap: 8px; flex-wrap: wrap;">
			<?php
			if ( empty( $summary['by_outcome'] ) ) {
				echo '<p class="description" id="turbo-no-outcomes">' . esc_html__( 'No outcome codes recorded yet.', 'wp-super-cache' ) . '</p>';
			} else {
				foreach ( $summary['by_outcome'] as $code => $count ) {
					echo '<span class="turbo-pill"><code>' . esc_html( $code ) . '</code>: <strong>' . (int) $count . '</strong></span>';
				}
			}
			?>
		</div>

		<?php
		$has_preload_stats = ! empty( $summary['preload'] ) && ! empty( $summary['preload']['total_discovered'] );
		?>
		<div id="turbo-preload-accounting" style="<?php echo $has_preload_stats ? '' : 'display: none;'; ?>">
			<h4 style="margin-top: 1.5em;"><?php esc_html_e( 'Preload Accounting (Phase 8/9 Engine)', 'wp-super-cache' ); ?></h4>
			<p class="description">
				<?php esc_html_e( 'Discovered:', 'wp-super-cache' ); ?> <strong id="stat-preload-discovered"><?php echo (int) ( $has_preload_stats ? $summary['preload']['total_discovered'] : 0 ); ?></strong> |
				<?php esc_html_e( 'Processed:', 'wp-super-cache' ); ?> <strong id="stat-preload-processed"><?php echo (int) ( $has_preload_stats ? $summary['preload']['processed'] : 0 ); ?></strong> |
				<?php esc_html_e( 'Verified Cached on Disk:', 'wp-super-cache' ); ?> <strong id="stat-preload-cached"><?php echo (int) ( $has_preload_stats ? $summary['preload']['cached'] : 0 ); ?></strong> |
				<?php esc_html_e( 'Skipped:', 'wp-super-cache' ); ?> <strong id="stat-preload-skipped"><?php echo (int) ( $has_preload_stats ? $summary['preload']['skipped'] : 0 ); ?></strong> |
				<?php esc_html_e( 'Failed:', 'wp-super-cache' ); ?> <strong id="stat-preload-failed"><?php echo (int) ( $has_preload_stats ? $summary['preload']['failed'] : 0 ); ?></strong>
			</p>
		</div>
	</div>

	<!-- Recent Events Log Card -->
	<div class="wpsc-card">
		<h3><?php esc_html_e( 'Recent Diagnostic Events (Bounded Ring Buffer)', 'wp-super-cache' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Shows the most recent entries in chronological FIFO order (most recent first, capped at 100 entries / 256 KB). Sensitive credentials and cookie values are automatically redacted.', 'wp-super-cache' ); ?>
		</p>

		<table class="widefat striped fixed" id="turbo-diag-table" style="margin-top: 1em;">
			<thead>
				<tr>
					<th style="width: 130px;"><?php esc_html_e( 'Timestamp', 'wp-super-cache' ); ?></th>
					<th style="width: 70px;"><?php esc_html_e( 'Method', 'wp-super-cache' ); ?></th>
					<th style="width: 22%;"><?php esc_html_e( 'URI', 'wp-super-cache' ); ?></th>
					<th style="width: 170px;"><?php esc_html_e( 'Outcome', 'wp-super-cache' ); ?></th>
					<th style="width: 90px;"><?php esc_html_e( 'Category', 'wp-super-cache' ); ?></th>
					<th><?php esc_html_e( 'Reason / Explanation', 'wp-super-cache' ); ?></th>
					<th style="width: 15%;"><?php esc_html_e( 'Details', 'wp-super-cache' ); ?></th>
				</tr>
			</thead>
			<tbody id="turbo-diag-table-body">
				<?php if ( empty( $recent_events ) ) : ?>
					<tr id="turbo-diag-empty-row">
						<td colspan="7" style="text-align: center; padding: 2em; color: #646970;">
							<?php esc_html_e( 'No diagnostic events recorded. Enable persistent logging above to capture real-time cache decisions.', 'wp-super-cache' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $recent_events as $event ) : ?>
						<?php
						$cat_slug = isset( $event['category'] ) ? sanitize_html_class( $event['category'] ) : 'other';
						$time_str = isset( $event['timestamp'] ) ? gmdate( 'Y-m-d H:i:s', (int) $event['timestamp'] ) : '-';
						$method   = isset( $event['method'] ) ? $event['method'] : 'GET';
						$uri      = isset( $event['uri'] ) ? $event['uri'] : '/';
						$outcome  = isset( $event['outcome'] ) ? $event['outcome'] : 'UNKNOWN';
						$reason   = isset( $event['reason'] ) ? $event['reason'] : '';
						$details  = ( isset( $event['details'] ) && is_array( $event['details'] ) && ! empty( $event['details'] ) )
							? wp_json_encode( $event['details'], JSON_UNESCAPED_SLASHES )
							: '-';
						?>
						<tr>
							<td><code><?php echo esc_html( $time_str ); ?></code></td>
							<td><strong><?php echo esc_html( $method ); ?></strong></td>
							<td><code style="word-break: break-all;"><?php echo esc_html( $uri ); ?></code></td>
							<td><code><?php echo esc_html( $outcome ); ?></code></td>
							<td><span class="turbo-badge turbo-cat-<?php echo esc_attr( $cat_slug ); ?>"><?php echo esc_html( strtoupper( $cat_slug ) ); ?></span></td>
							<td><?php echo esc_html( $reason ); ?></td>
							<td><small><code><?php echo esc_html( $details ); ?></code></small></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>

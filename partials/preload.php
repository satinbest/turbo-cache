<div class="wpsc-settings-inner" id="turbo-cache-preload-app">
<?php
global $wp_cache_preload_posts;

echo '<a name="preload"></a>';
if ( ! $cache_enabled || ! $super_cache_enabled || true === defined( 'DISABLESUPERCACHEPRELOADING' ) ) {
	wp_admin_notice(
		esc_html__( 'Preloading of cache disabled. Please make sure simple or expert mode is enabled or talk to your host administrator.', 'wp-super-cache' ),
		array(
			'type' => 'warning',
		)
	);
	return;
}

$count = wpsc_post_count();

$min_refresh_interval = wpsc_get_minimum_preload_interval();

$turbo_state      = turbo_cache_preload_get_state();
$turbo_status     = isset( $turbo_state['status'] ) ? sanitize_html_class( $turbo_state['status'] ) : 'idle';
$turbo_stats      = ( isset( $turbo_state['stats'] ) && is_array( $turbo_state['stats'] ) ) ? $turbo_state['stats'] : array();
$turbo_discovered = isset( $turbo_stats['total_discovered'] ) ? (int) $turbo_stats['total_discovered'] : 0;
$turbo_processed  = isset( $turbo_stats['processed'] ) ? (int) $turbo_stats['processed'] : 0;
$turbo_cached     = isset( $turbo_stats['cached'] ) ? (int) $turbo_stats['cached'] : 0;
$turbo_skipped    = isset( $turbo_stats['skipped'] ) ? (int) $turbo_stats['skipped'] : 0;
$turbo_failed     = isset( $turbo_stats['failed'] ) ? (int) $turbo_stats['failed'] : 0;
$turbo_started    = ! empty( $turbo_state['started_at'] ) ? (int) $turbo_state['started_at'] : 0;
$turbo_updated    = ! empty( $turbo_state['updated_at'] ) ? (int) $turbo_state['updated_at'] : 0;
$turbo_finished   = ! empty( $turbo_state['finished_at'] ) ? (int) $turbo_state['finished_at'] : 0;
$turbo_last_error = ! empty( $turbo_state['last_error'] ) ? sanitize_text_field( $turbo_state['last_error'] ) : '';
?>

<!-- Turbo Cache Safe Preload Status Card -->
<div class="wpsc-card">
	<h3><?php esc_html_e( 'Turbo Cache Safe Preload Status & Batch Controls', 'wp-super-cache' ); ?></h3>
	<p>
		<?php esc_html_e( 'The safe preload engine warms pages incrementally using bounded batches, strict URL host/path verification, WooCommerce cart/checkout protection, circuit-breaker safety, and verified cache entry validation on disk.', 'wp-super-cache' ); ?>
	</p>

	<div class="turbo-preload-status-row" style="margin: 1.5em 0 1em; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
		<strong><?php esc_html_e( 'Engine Status:', 'wp-super-cache' ); ?></strong>
		<span id="turbo-preload-status-badge" class="turbo-badge turbo-status-<?php echo esc_attr( $turbo_status ); ?>">
			<?php echo esc_html( strtoupper( $turbo_status ) ); ?>
		</span>

		<button type="button" id="turbo-preload-batch-btn" class="button button-primary">
			<span class="dashicons dashicons-controls-play" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
			<span class="turbo-btn-text"><?php esc_html_e( 'Process Next Batch', 'wp-super-cache' ); ?></span>
		</button>

		<button type="button" id="turbo-preload-refresh-btn" class="button button-secondary">
			<span class="dashicons dashicons-update" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
			<span class="turbo-btn-text"><?php esc_html_e( 'Refresh Status', 'wp-super-cache' ); ?></span>
		</button>

		<button type="button" id="turbo-preload-reset-btn" class="button button-link-delete" style="margin-left: auto;">
			<span class="turbo-btn-text"><?php esc_html_e( 'Reset Preload State', 'wp-super-cache' ); ?></span>
		</button>
	</div>

	<p class="description" style="margin-top: 0.5em; margin-bottom: 1.5em;">
		<?php esc_html_e( 'Each manual batch warms up to 10 URLs within a bounded 20-second execution window. You can trigger batches interactively here, or let scheduled cron process batches automatically in the background using the settings below.', 'wp-super-cache' ); ?>
	</p>

	<div class="turbo-metrics-grid" style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 1.5em;">
		<div class="turbo-stat-box">
			<div class="turbo-stat-label"><?php esc_html_e( 'Total Discovered', 'wp-super-cache' ); ?></div>
			<div class="turbo-stat-number" id="turbo-preload-stat-discovered"><?php echo (int) $turbo_discovered; ?></div>
		</div>
		<div class="turbo-stat-box" style="border-top: 3px solid #0284c7;">
			<div class="turbo-stat-label"><?php esc_html_e( 'Processed', 'wp-super-cache' ); ?></div>
			<div class="turbo-stat-number" id="turbo-preload-stat-processed" style="color: #0284c7;"><?php echo (int) $turbo_processed; ?></div>
		</div>
		<div class="turbo-stat-box" style="border-top: 3px solid #22863a;">
			<div class="turbo-stat-label"><?php esc_html_e( 'Verified Cached', 'wp-super-cache' ); ?></div>
			<div class="turbo-stat-number" id="turbo-preload-stat-cached" style="color: #22863a;"><?php echo (int) $turbo_cached; ?></div>
		</div>
		<div class="turbo-stat-box" style="border-top: 3px solid #d97706;">
			<div class="turbo-stat-label"><?php esc_html_e( 'Skipped / Redirects', 'wp-super-cache' ); ?></div>
			<div class="turbo-stat-number" id="turbo-preload-stat-skipped" style="color: #d97706;"><?php echo (int) $turbo_skipped; ?></div>
		</div>
		<div class="turbo-stat-box" style="border-top: 3px solid #d63638;">
			<div class="turbo-stat-label"><?php esc_html_e( 'Failed Requests', 'wp-super-cache' ); ?></div>
			<div class="turbo-stat-number" id="turbo-preload-stat-failed" style="color: #d63638;"><?php echo (int) $turbo_failed; ?></div>
		</div>
	</div>

	<div class="turbo-preload-timing-row description" style="display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 0.5em;">
		<span><strong><?php esc_html_e( 'Started:', 'wp-super-cache' ); ?></strong> <span id="turbo-preload-started-at"><?php echo $turbo_started > 0 ? esc_html( gmdate( 'Y-m-d H:i:s T', $turbo_started ) ) : '-'; ?></span></span>
		<span><strong><?php esc_html_e( 'Last Updated:', 'wp-super-cache' ); ?></strong> <span id="turbo-preload-updated-at"><?php echo $turbo_updated > 0 ? esc_html( gmdate( 'Y-m-d H:i:s T', $turbo_updated ) ) : '-'; ?></span></span>
		<span><strong><?php esc_html_e( 'Finished:', 'wp-super-cache' ); ?></strong> <span id="turbo-preload-finished-at"><?php echo $turbo_finished > 0 ? esc_html( gmdate( 'Y-m-d H:i:s T', $turbo_finished ) ) : '-'; ?></span></span>
	</div>

	<div id="turbo-preload-last-error-row" style="<?php echo ! empty( $turbo_last_error ) ? '' : 'display: none;'; ?> margin-top: 0.5em; color: #d63638;">
		<strong><?php esc_html_e( 'Notice:', 'wp-super-cache' ); ?></strong>
		<span id="turbo-preload-last-error-text"><?php echo esc_html( $turbo_last_error ); ?></span>
	</div>

	<div id="turbo-preload-feedback" class="notice notice-info inline" style="display: none; margin-top: 1em;">
		<p></p>
	</div>
</div>

<?php
echo '<div class="wpsc-card">';
echo '<p>' . __( 'This will cache every published post and page on your site. It will create supercache static files so unknown visitors (including bots) will hit a cached page. This will probably help your Google ranking as they are using speed as a metric when judging websites now.', 'wp-super-cache' ) . '</p>';
echo '<p>' . __( 'Preloading creates lots of files however. Caching is done from the newest post to the oldest so please consider only caching the newest if you have lots (10,000+) of posts. This is especially important on shared hosting.', 'wp-super-cache' ) . '</p>';
echo '<p>' . __( 'In &#8217;Preload Mode&#8217; regular garbage collection will be disabled so that old cache files are not deleted. This is a recommended setting when the cache is preloaded.', 'wp-super-cache' ) . '</p>';
echo '<form name="cache_filler" action="' . esc_url_raw( add_query_arg( 'tab', 'preload', $admin_url ) ) . '" method="POST">';
echo '<input type="hidden" name="action" value="preload" />';
echo '<input type="hidden" name="page" value="wpsupercache" />';
echo '</div>';
echo '<div class="wpsc-card">';
echo '<p>' . sprintf( __( 'Refresh preloaded cache files every %s minutes. (0 to disable, minimum %d minutes.)', 'wp-super-cache' ), "<input type='text' size=4 name='wp_cache_preload_interval' value='" . (int) $wp_cache_preload_interval . "' />", $min_refresh_interval ) . '</p>';
if ( $count > 100 ) {
	$step = (int)( $count / 10 );

	$select = "<select name='wp_cache_preload_posts' size=1>";
	$select .= "<option value='all' ";
	if ( ! isset( $wp_cache_preload_posts ) || $wp_cache_preload_posts == 'all' ) {
		$checked = 'selectect=1 ';
		$best = 'all';
	} else {
		$checked = ' ';
		$best = $wp_cache_preload_posts;
	}
	$select .= "{$checked}>" . __( 'all', 'wp-super-cache' ) . "</option>";

	$options = array();
	for( $c = $step; $c < $count; $c += $step ) {
		$checked = ' ';
		if ( $best == $c )
			$checked = 'selected=1 ';

		$options[ $c ] = "<option value='$c'{$checked}>$c</option>";
	}

	if ( ! isset( $options[ $wp_cache_preload_posts ] ) ) {
		$options[ $wp_cache_preload_posts ] = "<option value='$wp_cache_preload_posts' selected=1>$wp_cache_preload_posts</option>";
	}
	ksort( $options );
	$select .= implode( "\n", $options );

	$checked = ' ';
	if ( $best == $count )
		$checked = 'selected=1 ';
	$select .= "<option value='$count'{$checked}>$count</option>";
	$select .= "</select>";
	echo '<p>' . sprintf( __( 'Preload %s posts.', 'wp-super-cache' ), $select ) . '</p>';
} else {
	echo '<input type="hidden" name="wp_cache_preload_posts" value="' . $count . '" />';
}

echo '<input type="checkbox" name="wp_cache_preload_on" value="1" ';
echo $wp_cache_preload_on == 1 ? 'checked=1' : '';
echo ' /> ' . __( 'Preload mode (garbage collection disabled. Recommended.)', 'wp-super-cache' ) . '<br />';
echo '<input type="checkbox" name="wp_cache_preload_taxonomies" value="1" ';
echo $wp_cache_preload_taxonomies == 1 ? 'checked=1' : '';
echo ' /> ' . __( 'Preload tags, categories and other taxonomies.', 'wp-super-cache' ) . '<br />';
echo __( 'Send me status emails when files are refreshed.', 'wp-super-cache' ) . '<br />';
if ( !isset( $wp_cache_preload_email_volume ) )
	$wp_cache_preload_email_volume = 'none';
echo '<select type="select" name="wp_cache_preload_email_volume">';
echo '<option value="none" ' . selected( 'none', $wp_cache_preload_email_volume ) . '>' . esc_html__( 'No Emails', 'wp-super-cache' ) . '</option>';
// translators: %d is the number of posts
echo '<option value="many" ' . selected( 'many', $wp_cache_preload_email_volume ) . '>' . esc_html( sprintf( __( 'Many emails, 2 emails per %d posts.', 'wp-super-cache' ), WPSC_PRELOAD_POST_COUNT ) ) . '</option>';
// translators: %d is the number of posts
echo '<option value="medium" ' . selected( 'medium', $wp_cache_preload_email_volume ) . '>' . esc_html( sprintf( __( 'Medium, 1 email per %d posts.', 'wp-super-cache' ), WPSC_PRELOAD_POST_COUNT ) ) . '</option>';
echo '<option value="less" ' . selected( 'less', $wp_cache_preload_email_volume ) . '>' . esc_html__( 'Less emails, 1 at the start and 1 at the end of preloading all posts.', 'wp-super-cache' ) . '</option>';
echo "</select>";

$currently_preloading = false;
if (
	wp_next_scheduled( 'wp_cache_preload_hook' )
	|| wp_next_scheduled( 'wp_cache_full_preload_hook' )
	|| wpsc_is_preload_active()
) {
	$currently_preloading = true;
}
echo '<div class="submit"><input class="button-primary" type="submit" name="preload" value="' . __( 'Save Settings', 'wp-super-cache' ) . '" />';
echo '</div>';
wp_nonce_field( 'wp-cache' );
echo '</form>';
echo '<form name="do_preload" action="' . esc_url_raw( add_query_arg( 'tab', 'preload', $admin_url ) ) . '" method="POST">';
echo '<input type="hidden" name="action" value="preload" />';
echo '<input type="hidden" name="page" value="wpsupercache" />';
echo '<div class="submit">';
if ( false == $currently_preloading ) {
	echo '<input class="button-primary" type="submit" name="preload_now" value="' . __( 'Preload Cache Now', 'wp-super-cache' ) . '" />';
} else {
	echo '<input class="button-primary" type="submit" name="preload_off" value="' . __( 'Cancel Cache Preload', 'wp-super-cache' ) . '" />';
}
echo '</div>';
wp_nonce_field( 'wp-cache' );
echo '</form>';
echo '</div>';
echo '</div>';

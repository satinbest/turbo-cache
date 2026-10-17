/**
 * Turbo Cache Safe Preload Admin UI JavaScript.
 *
 * Handles client-side AJAX interactions for the Safe Preload Admin controls:
 * triggering bounded batches, refreshing status and metrics, and resetting state.
 *
 * All DOM updates are executed safely using native DOM methods (textContent, createElement)
 * to prevent XSS and preserve production reliability.
 *
 * @package Turbo_Cache
 */
(function() {
	'use strict';

	function initPreload() {
		var app = document.getElementById('turbo-cache-preload-app');
		if (!app) {
			return;
		}

		var config = window.turboCachePreload || {};
		var ajaxUrl = config.ajaxUrl || window.ajaxurl || '';
		var nonce = config.nonce || '';
		var strings = config.strings || {};

		var batchBtn = document.getElementById('turbo-preload-batch-btn');
		var refreshBtn = document.getElementById('turbo-preload-refresh-btn');
		var resetBtn = document.getElementById('turbo-preload-reset-btn');
		var statusBadge = document.getElementById('turbo-preload-status-badge');
		var feedback = document.getElementById('turbo-preload-feedback');

		var statDiscovered = document.getElementById('turbo-preload-stat-discovered');
		var statProcessed = document.getElementById('turbo-preload-stat-processed');
		var statCached = document.getElementById('turbo-preload-stat-cached');
		var statSkipped = document.getElementById('turbo-preload-stat-skipped');
		var statFailed = document.getElementById('turbo-preload-stat-failed');

		var startedAt = document.getElementById('turbo-preload-started-at');
		var updatedAt = document.getElementById('turbo-preload-updated-at');
		var finishedAt = document.getElementById('turbo-preload-finished-at');

		var lastErrorRow = document.getElementById('turbo-preload-last-error-row');
		var lastErrorText = document.getElementById('turbo-preload-last-error-text');

		var feedbackTimer = null;

		function showFeedback(message, type) {
			if (!feedback) {
				return;
			}
			var p = feedback.querySelector('p');
			if (p) {
				p.textContent = message;
			}
			feedback.className = 'notice inline notice-' + (type || 'info');
			feedback.style.display = 'block';

			if (feedbackTimer) {
				clearTimeout(feedbackTimer);
			}
			feedbackTimer = setTimeout(function() {
				feedback.style.display = 'none';
			}, 6000);
		}

		function getErrorMessage(err) {
			if (err && err.message === 'Request timed out') {
				return strings.timeoutError || 'The request timed out. Please try again.';
			}
			if (err && err.message === 'Network error') {
				return strings.networkError || 'A network error occurred while communicating with the server.';
			}
			return strings.errorGeneric || 'An error occurred while communicating with the server.';
		}

		function sendAjax(action, callback) {
			try {
				var xhr = new XMLHttpRequest();
				xhr.open('POST', ajaxUrl, true);
				xhr.timeout = 25000;
				xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

				var params = ['action=' + encodeURIComponent(action), '_ajax_nonce=' + encodeURIComponent(nonce)];

				xhr.onreadystatechange = function() {
					if (xhr.readyState === 4) {
						if (xhr.status >= 200 && xhr.status < 300) {
							try {
								var res = JSON.parse(xhr.responseText);
								callback(null, res);
							} catch (e) {
								callback(new Error('Invalid JSON response'));
							}
						} else {
							callback(new Error('HTTP ' + xhr.status));
						}
					}
				};

				xhr.ontimeout = function() {
					callback(new Error('Request timed out'));
				};

				xhr.onerror = function() {
					callback(new Error('Network error'));
				};

				xhr.send(params.join('&'));
			} catch (e) {
				callback(e);
			}
		}

		function setButtonsBusy(isBusy, activeBtn, busyText) {
			var buttons = [batchBtn, refreshBtn, resetBtn];
			for (var i = 0; i < buttons.length; i++) {
				if (buttons[i]) {
					buttons[i].disabled = isBusy;
				}
			}
			if (activeBtn) {
				var labelSpan = activeBtn.querySelector('.turbo-btn-text');
				var target = labelSpan || activeBtn;

				if (isBusy && busyText) {
					if (!target.getAttribute('data-orig-text')) {
						target.setAttribute('data-orig-text', target.textContent);
					}
					target.textContent = busyText;
				} else if (!isBusy) {
					var orig = target.getAttribute('data-orig-text');
					if (orig !== null && orig !== undefined) {
						target.textContent = orig;
						target.removeAttribute('data-orig-text');
					}
				}
			}
		}

		function pad(n) {
			return (n < 10 ? '0' : '') + n;
		}

		function formatDate(timestamp) {
			if (!timestamp || timestamp <= 0) {
				return '-';
			}
			var d = new Date(timestamp * 1000);
			return d.getUTCFullYear() + '-' +
				pad(d.getUTCMonth() + 1) + '-' +
				pad(d.getUTCDate()) + ' ' +
				pad(d.getUTCHours()) + ':' +
				pad(d.getUTCMinutes()) + ':' +
				pad(d.getUTCSeconds()) + ' UTC';
		}

		function updateUI(state) {
			if (!state) {
				return;
			}

			var status = (state.status || 'idle').toLowerCase();
			if (statusBadge) {
				statusBadge.className = 'turbo-badge turbo-status-' + status;
				statusBadge.textContent = status.toUpperCase();
			}

			if (statDiscovered) {
				statDiscovered.textContent = String(state.total_discovered || 0);
			}
			if (statProcessed) {
				statProcessed.textContent = String(state.processed || 0);
			}
			if (statCached) {
				statCached.textContent = String(state.cached || 0);
			}
			if (statSkipped) {
				statSkipped.textContent = String(state.skipped || 0);
			}
			if (statFailed) {
				statFailed.textContent = String(state.failed || 0);
			}

			if (startedAt) {
				startedAt.textContent = formatDate(state.started_at);
			}
			if (updatedAt) {
				updatedAt.textContent = formatDate(state.updated_at);
			}
			if (finishedAt) {
				finishedAt.textContent = formatDate(state.finished_at);
			}

			if (lastErrorRow && lastErrorText) {
				if (state.last_error && String(state.last_error).trim() !== '') {
					lastErrorText.textContent = String(state.last_error);
					lastErrorRow.style.display = 'block';
				} else {
					lastErrorRow.style.display = 'none';
					lastErrorText.textContent = '';
				}
			}
		}

		if (batchBtn) {
			batchBtn.addEventListener('click', function(e) {
				e.preventDefault();
				setButtonsBusy(true, batchBtn, strings.processing || 'Processing batch...');

				sendAjax('wpsc_preload_process_batch', function(err, res) {
					setButtonsBusy(false, batchBtn);

					if (err) {
						showFeedback(getErrorMessage(err), 'error');
						return;
					}

					if (!res || !res.success || !res.data) {
						var msg = (res && res.data && res.data.message) ? res.data.message : (strings.errorGeneric || 'Operation failed');
						showFeedback(msg, 'error');
						return;
					}

					var state = res.data.state || {};
					var batch = res.data.batch || {};

					updateUI(state);

					if (batch.status === 'locked') {
						showFeedback(batch.message || 'Another preload batch is currently in progress.', 'warning');
					} else if (state.status === 'completed') {
						showFeedback('Preload completed! All discovered URLs have been processed.', 'success');
					} else if (state.status === 'paused') {
						showFeedback('Preload paused: ' + (state.last_error || 'circuit breaker active.'), 'warning');
					} else {
						var processedInBatch = batch.processed_in_batch !== undefined ? batch.processed_in_batch : 0;
						showFeedback((strings.batchSuccess || 'Preload batch processed successfully.') + ' (' + processedInBatch + ' URLs evaluated in this batch)', 'success');
					}
				});
			});
		}

		if (refreshBtn) {
			refreshBtn.addEventListener('click', function(e) {
				e.preventDefault();
				setButtonsBusy(true, refreshBtn, strings.refreshing || 'Refreshing...');

				sendAjax('wpsc_preload_get_state', function(err, res) {
					setButtonsBusy(false, refreshBtn);

					if (err) {
						showFeedback(getErrorMessage(err), 'error');
						return;
					}

					if (!res || !res.success || !res.data) {
						showFeedback(strings.errorGeneric || 'Failed to refresh preload state.', 'error');
						return;
					}

					updateUI(res.data);
					showFeedback(strings.refreshSuccess || 'Preload state refreshed.', 'success');
				});
			});
		}

		if (resetBtn) {
			resetBtn.addEventListener('click', function(e) {
				e.preventDefault();

				var confirmMsg = strings.confirmReset || 'Are you sure you want to cancel and reset the preload state and queue?';
				if (!window.confirm(confirmMsg)) {
					return;
				}

				setButtonsBusy(true, resetBtn, strings.resetting || 'Resetting...');

				sendAjax('wpsc_preload_reset_state', function(err, res) {
					setButtonsBusy(false, resetBtn);

					if (err) {
						showFeedback(getErrorMessage(err), 'error');
						return;
					}

					if (!res || !res.success || !res.data) {
						var msg = (res && res.data && res.data.message) ? res.data.message : (strings.errorGeneric || 'Failed to reset state.');
						showFeedback(msg, 'error');
						return;
					}

					updateUI(res.data.state);
					showFeedback(res.data.message || (strings.resetSuccess || 'Preload state and queue reset successfully.'), 'success');
				});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initPreload);
	} else {
		initPreload();
	}
})();

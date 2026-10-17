/**
 * Turbo Cache Diagnostics Admin UI JavaScript.
 *
 * Handles client-side AJAX interactions for the Diagnostics tab:
 * toggling persistent logging, refreshing metrics/events, and clearing log records.
 *
 * All DOM updates are executed safely using native DOM methods (textContent, createElement)
 * to prevent XSS and preserve production reliability.
 *
 * @package Turbo_Cache
 */
(function() {
	'use strict';

	function initDiagnostics() {
		var app = document.getElementById('turbo-cache-diagnostics-app');
		if (!app) {
			return;
		}

		var config = window.turboCacheDiag || {};
		var ajaxUrl = config.ajaxUrl || window.ajaxurl || '';
		var nonce = config.nonce || '';
		var strings = config.strings || {};

		var toggleBtn = document.getElementById('turbo-diag-toggle-btn');
		var refreshBtn = document.getElementById('turbo-diag-refresh-btn');
		var clearBtn = document.getElementById('turbo-diag-clear-btn');
		var statusBadge = document.getElementById('turbo-diag-status-badge');
		var feedback = document.getElementById('turbo-diag-feedback');
		var tableBody = document.getElementById('turbo-diag-table-body');
		var outcomePills = document.getElementById('turbo-outcome-pills');

		var statTotal = document.getElementById('stat-total-recorded');
		var statHit = document.getElementById('stat-cat-hit');
		var statMiss = document.getElementById('stat-cat-miss');
		var statBypass = document.getElementById('stat-cat-bypass');
		var statGeneration = document.getElementById('stat-cat-generation');
		var statPreload = document.getElementById('stat-cat-preload');

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

		function sendAjax(action, extraData, callback) {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', ajaxUrl, true);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

			var params = ['action=' + encodeURIComponent(action), '_ajax_nonce=' + encodeURIComponent(nonce)];
			if (extraData) {
				for (var key in extraData) {
					if (Object.prototype.hasOwnProperty.call(extraData, key)) {
						params.push(encodeURIComponent(key) + '=' + encodeURIComponent(extraData[key]));
					}
				}
			}

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
						callback(new Error('HTTP Error: ' + xhr.status));
					}
				}
			};

			xhr.send(params.join('&'));
		}

		function formatDate(timestamp) {
			if (!timestamp) {
				return '-';
			}
			try {
				var d = new Date(timestamp * 1000);
				if (isNaN(d.getTime())) {
					return String(timestamp);
				}
				var pad = function(n) { return (n < 10 ? '0' : '') + n; };
				return d.getUTCFullYear() + '-' +
					pad(d.getUTCMonth() + 1) + '-' +
					pad(d.getUTCDate()) + ' ' +
					pad(d.getUTCHours()) + ':' +
					pad(d.getUTCMinutes()) + ':' +
					pad(d.getUTCSeconds());
			} catch (e) {
				return String(timestamp);
			}
		}

		function sanitizeClass(str) {
			return String(str || 'other').toLowerCase().replace(/[^a-z0-9_-]/g, '');
		}

		// ---------------------------------------------------------------------
		// 1. Toggle Persistent Logging
		// ---------------------------------------------------------------------
		if (toggleBtn) {
			toggleBtn.addEventListener('click', function() {
				var currentEnabled = toggleBtn.getAttribute('data-enabled') === '1';
				var nextEnabled = !currentEnabled;

				toggleBtn.disabled = true;
				var origText = toggleBtn.textContent;
				toggleBtn.textContent = strings.updating || 'Updating...';

				sendAjax('wpsc_toggle_diagnostics', { enable: nextEnabled ? '1' : '0' }, function(err, res) {
					toggleBtn.disabled = false;
					if (err || !res || !res.success) {
						toggleBtn.textContent = origText;
						showFeedback(strings.errorGeneric || 'Operation failed.', 'error');
						return;
					}

					var isNowEnabled = res.data && res.data.enabled;
					toggleBtn.setAttribute('data-enabled', isNowEnabled ? '1' : '0');
					toggleBtn.textContent = isNowEnabled ? (strings.disable || 'Disable Logging') : (strings.enable || 'Enable Logging');
					toggleBtn.className = 'button ' + (isNowEnabled ? 'button-secondary' : 'button-primary');

					if (statusBadge) {
						statusBadge.textContent = isNowEnabled ? (strings.enabled || 'Enabled') : (strings.disabled || 'Disabled');
						statusBadge.className = 'turbo-badge ' + (isNowEnabled ? 'turbo-badge-enabled' : 'turbo-badge-disabled');
					}

					showFeedback(strings.toggleSuccess || 'Diagnostic logging setting updated.', 'success');
				});
			});
		}

		// ---------------------------------------------------------------------
		// 2. Clear Diagnostic Log
		// ---------------------------------------------------------------------
		if (clearBtn) {
			clearBtn.addEventListener('click', function() {
				var confirmMsg = strings.confirmClear || 'Are you sure you want to clear all recorded cache diagnostic events?';
				if (!window.confirm(confirmMsg)) {
					return;
				}

				clearBtn.disabled = true;
				var origText = clearBtn.textContent;
				clearBtn.textContent = strings.clearing || 'Clearing...';

				sendAjax('wpsc_clear_diagnostics', {}, function(err, res) {
					clearBtn.disabled = false;
					clearBtn.textContent = origText;

					if (err || !res || !res.success) {
						showFeedback(strings.errorGeneric || 'Failed to clear logs.', 'error');
						return;
					}

					// Reset counters
					if (statTotal) statTotal.textContent = '0';
					if (statHit) statHit.textContent = '0';
					if (statMiss) statMiss.textContent = '0';
					if (statBypass) statBypass.textContent = '0';
					if (statGeneration) statGeneration.textContent = '0';
					if (statPreload) statPreload.textContent = '0';

					// Reset outcomes
					if (outcomePills) {
						outcomePills.textContent = '';
						var p = document.createElement('p');
						p.className = 'description';
						p.id = 'turbo-no-outcomes';
						p.textContent = strings.noEvents || 'No outcome codes recorded yet.';
						outcomePills.appendChild(p);
					}

					// Reset table rows
					if (tableBody) {
						tableBody.textContent = '';
						var tr = document.createElement('tr');
						tr.id = 'turbo-diag-empty-row';
						var td = document.createElement('td');
						td.colSpan = 7;
						td.style.textAlign = 'center';
						td.style.padding = '2em';
						td.style.color = '#646970';
						td.textContent = strings.noEvents || 'No diagnostic events recorded.';
						tr.appendChild(td);
						tableBody.appendChild(tr);
					}

					showFeedback(strings.clearSuccess || 'Diagnostic log cleared successfully.', 'success');
				});
			});
		}

		// ---------------------------------------------------------------------
		// 3. Refresh Diagnostics Data
		// ---------------------------------------------------------------------
		if (refreshBtn) {
			refreshBtn.addEventListener('click', function() {
				refreshBtn.disabled = true;
				var origHtml = refreshBtn.innerHTML;
				refreshBtn.textContent = strings.refreshing || 'Refreshing...';

				sendAjax('wpsc_get_diagnostics', {}, function(err, res) {
					refreshBtn.disabled = false;
					refreshBtn.innerHTML = origHtml;

					if (err || !res || !res.success || !res.data) {
						showFeedback(strings.errorGeneric || 'Failed to refresh diagnostic data.', 'error');
						return;
					}

					var summary = res.data.summary || {};
					var recent = res.data.recent || [];

					// Update counters
					if (statTotal) statTotal.textContent = String(summary.total_recorded || 0);
					var byCat = summary.by_category || {};
					if (statHit) statHit.textContent = String(byCat.hit || 0);
					if (statMiss) statMiss.textContent = String(byCat.miss || 0);
					if (statBypass) statBypass.textContent = String(byCat.bypass || 0);
					if (statGeneration) statGeneration.textContent = String(byCat.generation || 0);
					if (statPreload) statPreload.textContent = String(byCat.preload || 0);

					// Update outcome breakdown
					if (outcomePills) {
						outcomePills.textContent = '';
						var byOutcome = summary.by_outcome || {};
						var outcomeKeys = Object.keys(byOutcome);
						if (outcomeKeys.length === 0) {
							var desc = document.createElement('p');
							desc.className = 'description';
							desc.id = 'turbo-no-outcomes';
							desc.textContent = strings.noEvents || 'No outcome codes recorded yet.';
							outcomePills.appendChild(desc);
						} else {
							outcomeKeys.forEach(function(code) {
								var pill = document.createElement('span');
								pill.className = 'turbo-pill';

								var codeEl = document.createElement('code');
								codeEl.textContent = code;
								pill.appendChild(codeEl);

								pill.appendChild(document.createTextNode(': '));

								var strong = document.createElement('strong');
								strong.textContent = String(byOutcome[code]);
								pill.appendChild(strong);

								outcomePills.appendChild(pill);
							});
						}
					}

					// Update recent events table
					if (tableBody) {
						tableBody.textContent = '';
						if (!recent || recent.length === 0) {
							var emptyTr = document.createElement('tr');
							emptyTr.id = 'turbo-diag-empty-row';
							var emptyTd = document.createElement('td');
							emptyTd.colSpan = 7;
							emptyTd.style.textAlign = 'center';
							emptyTd.style.padding = '2em';
							emptyTd.style.color = '#646970';
							emptyTd.textContent = strings.noEvents || 'No diagnostic events recorded.';
							emptyTr.appendChild(emptyTd);
							tableBody.appendChild(emptyTr);
						} else {
							recent.forEach(function(ev) {
								var tr = document.createElement('tr');

								// Col 1: Timestamp
								var tdTime = document.createElement('td');
								var codeTime = document.createElement('code');
								codeTime.textContent = formatDate(ev.timestamp);
								tdTime.appendChild(codeTime);
								tr.appendChild(tdTime);

								// Col 2: Method
								var tdMethod = document.createElement('td');
								var strongMethod = document.createElement('strong');
								strongMethod.textContent = String(ev.method || 'GET');
								tdMethod.appendChild(strongMethod);
								tr.appendChild(tdMethod);

								// Col 3: URI
								var tdUri = document.createElement('td');
								var codeUri = document.createElement('code');
								codeUri.style.wordBreak = 'break-all';
								codeUri.textContent = String(ev.uri || '/');
								tdUri.appendChild(codeUri);
								tr.appendChild(tdUri);

								// Col 4: Outcome
								var tdOutcome = document.createElement('td');
								var codeOutcome = document.createElement('code');
								codeOutcome.textContent = String(ev.outcome || 'UNKNOWN');
								tdOutcome.appendChild(codeOutcome);
								tr.appendChild(tdOutcome);

								// Col 5: Category badge
								var tdCat = document.createElement('td');
								var catClean = sanitizeClass(ev.category);
								var badge = document.createElement('span');
								badge.className = 'turbo-badge turbo-cat-' + catClean;
								badge.textContent = (ev.category || 'other').toUpperCase();
								tdCat.appendChild(badge);
								tr.appendChild(tdCat);

								// Col 6: Reason
								var tdReason = document.createElement('td');
								tdReason.textContent = String(ev.reason || '');
								tr.appendChild(tdReason);

								// Col 7: Details
								var tdDetails = document.createElement('td');
								var small = document.createElement('small');
								var codeDetails = document.createElement('code');
								var detailsStr = '-';
								if (ev.details && typeof ev.details === 'object' && Object.keys(ev.details).length > 0) {
									try {
										detailsStr = JSON.stringify(ev.details);
									} catch (e) {
										detailsStr = '[Details]';
									}
								}
								codeDetails.textContent = detailsStr;
								small.appendChild(codeDetails);
								tdDetails.appendChild(small);
								tr.appendChild(tdDetails);

								tableBody.appendChild(tr);
							});
						}
					}

					showFeedback('Diagnostic data refreshed.', 'success');
				});
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initDiagnostics);
	} else {
		initDiagnostics();
	}
})();

(function ($) {
	'use strict';

	$(function () {
		$('.chathearth-color-field').wpColorPicker();

		var $wrap = $('.chathearth-settings');
		if (!$wrap.length) {
			return;
		}

		var $tabs = $wrap.find('.chathearth-tabs .nav-tab');
		var $panels = $wrap.find('.chathearth-tab-panel');
		var $activeTabInput = $('#chathearth_active_tab');
		var $referer = $('input[name="_wp_http_referer"]');

		function tabUrl(tabId) {
			var url = new URL(window.location.href);
			url.searchParams.set('page', 'chathearth');
			url.searchParams.set('tab', tabId);
			url.hash = '';
			return url.toString();
		}

		function activateTab(tabId, pushUrl) {
			if (!$panels.filter('[data-tab-panel="' + tabId + '"]').length) {
				tabId = 'welcome';
			}

			$tabs.removeClass('nav-tab-active').attr('aria-selected', 'false');
			$tabs.filter('[data-tab="' + tabId + '"]').addClass('nav-tab-active').attr('aria-selected', 'true');

			$panels.attr('hidden', true);
			$panels.filter('[data-tab-panel="' + tabId + '"]').removeAttr('hidden');

			$activeTabInput.val(tabId);

			var nextUrl = tabUrl(tabId);
			if ($referer.length) {
				// Preserve settings-updated and other query args from current page when possible.
				var refererUrl = new URL(nextUrl, window.location.origin);
				$referer.val(refererUrl.pathname + refererUrl.search);
			}

			if (pushUrl !== false && window.history && window.history.replaceState) {
				window.history.replaceState(null, '', nextUrl);
			}
		}

		$tabs.on('click', function (e) {
			e.preventDefault();
			activateTab($(this).data('tab'));
		});

		var params = new URLSearchParams(window.location.search);
		var initialTab = params.get('tab') || $wrap.data('current-tab') || 'welcome';
		activateTab(initialTab, false);

		initKnowledgeBase();
	});

	function initKnowledgeBase() {
		var cfg = window.chatHearthAdmin;
		if (!cfg || !cfg.syncUrl) {
			return;
		}

		var $status = $('#chathearth-kb-status');
		var $counts = $('#chathearth-kb-counts');
		var $tbody = $('#chathearth-kb-table tbody');
		var $pager = $('#chathearth-kb-pager');
		var $search = $('#chathearth-kb-search');
		var page = 1;
		var searchTimer = null;

		function headers() {
			return {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			};
		}

		function setStatus(text) {
			$status.text(text || '');
		}

		function loadStatus() {
			if (!$counts.length) {
				return;
			}
			fetch(cfg.statusUrl, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (!data || !data.counts) {
						return;
					}
					$counts.text(
						'Indexed: ' + (data.counts.indexed || 0) +
						' · Pending: ' + (data.counts.pending || 0) +
						' · Excluded: ' + (data.counts.excluded || 0) +
						' · Errors: ' + (data.counts.error || 0)
					);
				})
				.catch(function () { /* ignore */ });
		}

		function loadEntries() {
			if (!$tbody.length) {
				return;
			}
			var url = cfg.entriesUrl + '?page=' + encodeURIComponent(page);
			var q = ($search.val() || '').trim();
			if (q) {
				url += '&search=' + encodeURIComponent(q);
			}
			fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var items = (data && data.items) || [];
					$tbody.empty();
					if (!items.length) {
						$tbody.append('<tr><td colspan="4">' + escapeHtml(cfg.i18n.empty) + '</td></tr>');
					} else {
						items.forEach(function (item) {
							var source = item.post_type || item.taxonomy || item.object_type || '';
							var included = String(item.included) === '1' || item.included === true || item.included === 1;
							var title = item.title || item.source_id;
							if (item.url) {
								title = '<a href="' + escapeHtml(item.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(title) + '</a>';
							} else {
								title = escapeHtml(title);
							}
							var $row = $('<tr/>');
							$row.append('<td>' + title + '<div class="description"><code>' + escapeHtml(item.source_id) + '</code></div></td>');
							$row.append('<td>' + escapeHtml(source) + '</td>');
							$row.append('<td>' + escapeHtml(item.status || '') + '</td>');
							var $cell = $('<td/>');
							var $btn = $('<button type="button" class="button button-small chathearth-kb-toggle"/>');
							$btn.text(included ? cfg.i18n.excluded : cfg.i18n.include);
							$btn.on('click', function () {
								toggleEntry(item.source_id, !included);
							});
							$cell.append($btn);
							$row.append($cell);
							$tbody.append($row);
						});
					}

					var total = (data && data.total) ? parseInt(data.total, 10) : 0;
					var pages = Math.max(1, Math.ceil(total / 20));
					$pager.empty();
					if (pages > 1) {
						var $prev = $('<button type="button" class="button"/>').text('«').prop('disabled', page <= 1);
						var $next = $('<button type="button" class="button"/>').text('»').prop('disabled', page >= pages);
						$prev.on('click', function () { page -= 1; loadEntries(); });
						$next.on('click', function () { page += 1; loadEntries(); });
						$pager.append($prev).append(' ' + page + ' / ' + pages + ' ').append($next);
					}
				})
				.catch(function () {
					$tbody.html('<tr><td colspan="4">' + escapeHtml(cfg.i18n.syncFailed) + '</td></tr>');
				});
		}

		function toggleEntry(sourceId, included) {
			fetch(cfg.entriesUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: JSON.stringify({ source_id: sourceId, included: included })
			}).then(function () {
				loadEntries();
				loadStatus();
			});
		}

		$('#chathearth-kb-sync').on('click', function () {
			setStatus(cfg.i18n.syncing);
			fetch(cfg.syncUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: '{}'
			})
				.then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
				.then(function (result) {
					setStatus(result.ok ? cfg.i18n.synced : cfg.i18n.syncFailed);
					loadStatus();
					loadEntries();
				})
				.catch(function () {
					setStatus(cfg.i18n.syncFailed);
				});
		});

		$('#chathearth-kb-ping').on('click', function () {
			setStatus(cfg.i18n.syncing);
			fetch(cfg.pingUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers(),
				body: '{}'
			})
				.then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
				.then(function (result) {
					var msg = result.data && result.data.message ? result.data.message : '';
					if (result.ok && result.data && result.data.ok) {
						setStatus(msg || cfg.i18n.pingOk);
						return;
					}
					setStatus(msg || cfg.i18n.pingFail);
				})
				.catch(function () {
					setStatus(cfg.i18n.pingFail);
				});
		});

		$search.on('input', function () {
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				page = 1;
				loadEntries();
			}, 300);
		});

		if ($tbody.length) {
			loadStatus();
			loadEntries();
		}
	}

	function escapeHtml(str) {
		return String(str || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}
})(jQuery);

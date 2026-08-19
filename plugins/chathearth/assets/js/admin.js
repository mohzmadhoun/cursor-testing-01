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
	});
})(jQuery);

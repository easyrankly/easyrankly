(function () {
	'use strict';

	var config = window.eranklyRedirects || {};

	function canUseAjax() {
		return !!(config.restUrlToggle && config.restUrlDelete && config.nonce && window.fetch);
	}

	function postToRest(url, id) {
		return window
			.fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify({ id: id }),
			})
			.then(function (response) {
				return response.json().then(function (data) {
					if (!response.ok) {
						throw new Error((data && data.message) || 'Request failed');
					}

					return data;
				});
			});
	}

	function showRowError(row, message) {
		if (!row) {
			window.alert(message);
			return;
		}

		var existing = row.querySelector('.erankly-redirects-row-error');
		if (existing) {
			existing.remove();
		}

		var cell = row.querySelector('.erankly-redirects-actions');
		if (!cell) {
			window.alert(message);
			return;
		}

		var notice = document.createElement('span');
		notice.className = 'erankly-redirects-row-error description';
		notice.textContent = ' ' + message;
		cell.appendChild(notice);
	}

	// Toggle active/inactive via REST, updating the row in place so the current
	// search term and pagination position are preserved (no page reload).
	document.addEventListener('click', function (event) {
		var link = event.target.closest('.erankly-redirects-toggle');

		if (!link || !canUseAjax()) {
			return;
		}

		event.preventDefault();

		if (link.classList.contains('erankly-redirects-busy')) {
			return;
		}

		var id = parseInt(link.getAttribute('data-id'), 10);
		var row = link.closest('tr');

		if (!id) {
			return;
		}

		link.classList.add('erankly-redirects-busy');

		postToRest(config.restUrlToggle, id)
			.then(function (data) {
				var isActive = !!data.is_active;
				var activeCell = row ? row.querySelector('.erankly-redirects-active-cell') : null;

				if (activeCell) {
					activeCell.textContent = isActive ? config.activeYes || 'Yes' : config.activeNo || 'No';
				}

				link.setAttribute('data-active', isActive ? '1' : '0');
				link.textContent = isActive ? config.disableLabel || 'Disable' : config.enableLabel || 'Enable';
			})
			.catch(function () {
				showRowError(row, config.toggleError || 'The redirect status could not be changed.');
			})
			.finally(function () {
				link.classList.remove('erankly-redirects-busy');
			});
	});

	// Delete via REST, removing the row in place so the current search term and
	// pagination position are preserved (no page reload).
	document.addEventListener('click', function (event) {
		var link = event.target.closest('.erankly-redirects-delete');

		if (!link) {
			return;
		}

		var message = config.deleteConfirm || 'Delete this redirect?';

		if (!window.confirm(message)) {
			event.preventDefault();
			return;
		}

		if (!canUseAjax()) {
			return;
		}

		event.preventDefault();

		if (link.classList.contains('erankly-redirects-busy')) {
			return;
		}

		var id = parseInt(link.getAttribute('data-id'), 10);
		var row = link.closest('tr');

		if (!id) {
			return;
		}

		link.classList.add('erankly-redirects-busy');

		postToRest(config.restUrlDelete, id)
			.then(function () {
				if (row) {
					row.remove();
				}
			})
			.catch(function () {
				link.classList.remove('erankly-redirects-busy');
				showRowError(row, config.deleteError || 'The redirect could not be deleted.');
			});
	});

	// Hide the "Target URL" field for status-only codes (410/451) that carry no Location.
	var STATUS_ONLY_CODES = ['410', '451'];

	function syncTargetField() {
		var statusSelect = document.getElementById('erankly-redirects-status-code');
		var targetField = document.getElementById('erankly-redirects-target-field');

		if (!statusSelect || !targetField) {
			return;
		}

		targetField.style.display = STATUS_ONLY_CODES.indexOf(statusSelect.value) !== -1 ? 'none' : '';
	}

	syncTargetField();

	var statusSel = document.getElementById('erankly-redirects-status-code');
	if (statusSel) {
		statusSel.addEventListener('change', syncTargetField);
	}

	// Expand/collapse is handled by the shared bindExpandablePanel() in admin.js
	// (data-erankly-* attributes on the section wrapper and toggle button).
}());

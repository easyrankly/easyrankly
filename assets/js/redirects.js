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
				var source = link.getAttribute('data-source') || '';
				var ariaTemplate = isActive
					? config.disableAria || 'Disable redirect from %s'
					: config.enableAria || 'Enable redirect from %s';
				link.setAttribute('aria-label', ariaTemplate.replace('%s', source));
			})
			.catch(function () {
				showRowError(row, config.toggleError || 'The redirect status could not be changed.');
			})
			.finally(function () {
				link.classList.remove('erankly-redirects-busy');
			});
	});

	// The delete link remains a nonce-protected no-JavaScript fallback. With
	// JavaScript available, an accessible alert dialog confirms the same URL or
	// performs the REST deletion and reloads the current filtered/sorted view.
	var pendingDeleteLink = null;
	var deleteLastFocused = null;

	function deleteModal() {
		return document.querySelector('[data-erankly-redirect-delete-modal]');
	}

	function closeDeleteModal() {
		var modal = deleteModal();
		if (modal) {
			modal.hidden = true;
		}
		document.body.classList.remove('erankly-redirects-dialog-open');
		pendingDeleteLink = null;
		if (deleteLastFocused && typeof deleteLastFocused.focus === 'function') {
			deleteLastFocused.focus();
		}
	}

	function openDeleteModal(link) {
		var modal = deleteModal();
		if (!modal) {
			return false;
		}

		pendingDeleteLink = link;
		deleteLastFocused = document.activeElement;
		var source = link.getAttribute('data-source') || '';
		var description = modal.querySelector('[data-erankly-redirect-delete-description]');
		var cancel = modal.querySelector('[data-erankly-redirect-delete-cancel]');
		if (description) {
			description.textContent = (config.deleteConfirm || 'The redirect from %s will be permanently deleted.').replace('%s', source);
		}
		modal.hidden = false;
		document.body.classList.add('erankly-redirects-dialog-open');
		if (cancel) {
			cancel.focus();
		}

		return true;
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest('.erankly-redirects-delete');

		if (!link) {
			return;
		}

		if (openDeleteModal(link)) {
			event.preventDefault();
		}
	});

	var modal = deleteModal();
	if (modal) {
		var cancelDelete = modal.querySelector('[data-erankly-redirect-delete-cancel]');
		var confirmDelete = modal.querySelector('[data-erankly-redirect-delete-confirm]');

		if (cancelDelete) {
			cancelDelete.addEventListener('click', closeDeleteModal);
		}
		modal.addEventListener('click', function (event) {
			if (event.target === modal) {
				closeDeleteModal();
			}
		});

		if (confirmDelete) {
			confirmDelete.addEventListener('click', function () {
				if (!pendingDeleteLink) {
					return;
				}

				var link = pendingDeleteLink;
				var id = parseInt(link.getAttribute('data-id'), 10);
				var row = link.closest('tr');

				if (!canUseAjax()) {
					window.location.assign(link.href);
					return;
				}

				if (!id || link.classList.contains('erankly-redirects-busy')) {
					return;
				}

				link.classList.add('erankly-redirects-busy');
				confirmDelete.disabled = true;

				postToRest(config.restUrlDelete, id)
					.then(function () {
						window.location.reload();
					})
					.catch(function () {
						link.classList.remove('erankly-redirects-busy');
						confirmDelete.disabled = false;
						closeDeleteModal();
						showRowError(row, config.deleteError || 'The redirect could not be deleted.');
					});
			});
		}
	}

	document.addEventListener('keydown', function (event) {
		var current = deleteModal();
		if (!current || current.hidden) {
			return;
		}
		if (event.key === 'Escape') {
			event.preventDefault();
			closeDeleteModal();
			return;
		}
		if (event.key !== 'Tab') {
			return;
		}

		var focusable = Array.prototype.filter.call(
			current.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'),
			function (node) { return !node.disabled && node.offsetParent !== null; },
		);
		if (!focusable.length) {
			return;
		}
		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

	// Hide the "Target URL" field for status-only codes (410/451) that carry no Location.
	var STATUS_ONLY_CODES = Array.isArray(config.statusOnlyCodes) ? config.statusOnlyCodes : ['410', '451'];

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

	var matchType = document.getElementById('erankly-redirects-match-type');
	var queryMode = document.getElementById('erankly-redirects-query-mode');
	var queryField = document.getElementById('erankly-redirects-source-query-field');
	var matchHelp = document.getElementById('erankly-redirects-match-help');

	function syncAdvancedFields() {
		if (queryMode && queryField) {
			queryField.hidden = queryMode.value !== 'exact';
		}
		if (matchType && matchHelp) {
			var help = {
				exact: config.exactHelp || 'Matches one path.',
				wildcard: config.wildcardHelp || 'Use * to capture variable path segments.',
				regex: config.regexHelp || 'Use a PCRE path expression.',
			};
			matchHelp.textContent = help[matchType.value] || '';
		}
	}

	syncAdvancedFields();
	if (matchType) {
		matchType.addEventListener('change', syncAdvancedFields);
	}
	if (queryMode) {
		queryMode.addEventListener('change', syncAdvancedFields);
	}

	var testButton = document.getElementById('erankly-redirects-test-button');
	if (testButton && config.restUrlTest && config.nonce && window.fetch) {
		testButton.addEventListener('click', function () {
			var form = testButton.closest('form');
			var result = document.getElementById('erankly-redirects-test-result');
			var testUrl = document.getElementById('erankly-redirects-test-url');
			if (!form || !result || !testUrl) {
				return;
			}

			var field = function (name) {
				return form.querySelector('[name="' + name + '"]');
			};
			var caseField = field('case_sensitive');
			var slashField = field('trailing_slash');
			var payload = {
				source_path: field('source_path') ? field('source_path').value : '',
				target_url: field('target_url') ? field('target_url').value : '',
				match_type: field('match_type') ? field('match_type').value : 'exact',
				query_mode: field('query_mode') ? field('query_mode').value : 'ignore',
				status_code: field('status_code') ? field('status_code').value : '301',
				source_query: field('source_query') ? field('source_query').value : '',
				case_sensitive: !!(caseField && caseField.checked),
				trailing_slash: slashField && slashField.checked ? 'exact' : 'ignore',
				test_url: testUrl.value,
			};

			testButton.disabled = true;
			result.textContent = '';
			window.fetch(config.restUrlTest, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify(payload),
			})
				.then(function (response) {
					return response.json().then(function (data) {
						if (!response.ok) {
							throw new Error((data && data.message) || config.testError);
						}
						return data;
					});
				})
				.then(function (data) {
					if (!data.matches) {
						result.textContent = config.testNoMatch || 'This URL does not match the rule.';
						return;
					}
					if (data.status_only) {
						result.textContent = config.testMatchedStatus || 'Matches. This response has no destination.';
						return;
					}
					result.textContent = (config.testMatched || 'Matches. Destination: %s').replace('%s', data.target_url || '—');
				})
				.catch(function (error) {
					result.textContent = error.message || config.testError || 'The rule could not be tested.';
				})
				.finally(function () {
					testButton.disabled = false;
				});
		});
	}

	// Expand/collapse is handled by the shared bindExpandablePanel() in admin.js
	// (data-erankly-* attributes on the section wrapper and toggle button).
}());

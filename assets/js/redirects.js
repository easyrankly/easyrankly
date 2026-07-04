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

	// Show/hide the "Required role" field based on the "Apply to" select.
	function syncRoleField() {
		var visibilitySelect = document.getElementById('erankly-redirects-visibility');
		var roleField = document.getElementById('erankly-redirects-role-field');

		if (!visibilitySelect || !roleField) {
			return;
		}

		roleField.style.display = visibilitySelect.value === 'logged_in' ? '' : 'none';
	}

	syncRoleField();

	var sel = document.getElementById('erankly-redirects-visibility');
	if (sel) {
		sel.addEventListener('change', syncRoleField);
	}

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

	// Search filter suggestions: clicking/focusing the search field opens a menu of
	// filter keys (status, code, type, visibility...). Picking one inserts "key:"
	// and narrows the menu to that key's allowed values. A standalone "&" between
	// tokens is purely a readability separator (same effect as a space) so multiple
	// filters can be chained, e.g. "status:on & code:301".
	(function initSearchSuggestions() {
		var input = document.getElementById('erankly-redirects-search-source');
		var menu = document.getElementById('erankly-redirects-search-suggest');
		var filters = config.searchFilters;

		if (!input || !menu || !filters || !filters.length) {
			return;
		}

		var activeIndex = -1;
		var currentItems = [];
		var currentRange = null;
		var suppressNextOutsideClick = false;

		function getActiveTokenRange() {
			var value = input.value;
			var caret = input.selectionStart || 0;
			var start = caret;

			while (start > 0 && !/\s/.test(value.charAt(start - 1))) {
				start--;
			}

			return { start: start, end: caret, text: value.slice(start, caret) };
		}

		function buildItems(range) {
			var token = range.text;
			var colonIndex = token.indexOf(':');

			if (colonIndex === -1) {
				var keyQuery = token.toLowerCase();
				// When there's already a completed filter/term before this token, prefix
				// the insertion with "and " so chained filters read as
				// "code:301 and status:off" instead of just butting up against each other.
				var hasPrecedingContent = input.value.slice(0, range.start).replace(/\s+$/, '').length > 0;
				var connector = hasPrecedingContent ? 'and ' : '';

				return filters
					.filter(function (filter) {
						return filter.key.toLowerCase().indexOf(keyQuery) === 0;
					})
					.map(function (filter) {
						return {
							insertText: connector + filter.key + ':',
							primary: filter.key + ':',
							secondary: filter.label,
						};
					});
			}

			var key = token.slice(0, colonIndex).toLowerCase();
			var valueQuery = token.slice(colonIndex + 1).toLowerCase();
			var filter = null;

			for (var i = 0; i < filters.length; i++) {
				if (filters[i].key.toLowerCase() === key) {
					filter = filters[i];
					break;
				}
			}

			if (!filter) {
				return [];
			}

			return filter.values
				.filter(function (value) {
					return value.value.toLowerCase().indexOf(valueQuery) === 0;
				})
				.map(function (value) {
					return {
						insertText: filter.key + ':' + value.value + ' ',
						primary: filter.key + ':' + value.value,
						secondary: value.label,
					};
				});
		}

		function closeMenu() {
			menu.hidden = true;
			menu.innerHTML = '';
			activeIndex = -1;
			currentItems = [];
			currentRange = null;
			input.removeAttribute('aria-expanded');
			input.removeAttribute('aria-activedescendant');
		}

		function highlight(index) {
			var options = menu.querySelectorAll('.erankly-redirects-suggest-option');

			for (var i = 0; i < options.length; i++) {
				options[i].classList.toggle('is-active', i === index);
			}

			activeIndex = index;

			if (index >= 0 && options[index]) {
				input.setAttribute('aria-activedescendant', options[index].id);
			} else {
				input.removeAttribute('aria-activedescendant');
			}
		}

		function applyItem(item) {
			if (!currentRange) {
				return;
			}

			var value = input.value;
			var before = value.slice(0, currentRange.start);
			var after = value.slice(currentRange.end);

			input.value = before + item.insertText + after;

			var caret = (before + item.insertText).length;
			input.setSelectionRange(caret, caret);
			input.focus();

			openMenu();
		}

		function openMenu() {
			var range = getActiveTokenRange();
			var items = buildItems(range);

			if (!items.length) {
				closeMenu();
				return;
			}

			currentRange = range;
			currentItems = items;
			menu.innerHTML = '';

			items.forEach(function (item, index) {
				var option = document.createElement('button');
				option.type = 'button';
				option.id = 'erankly-redirects-suggest-option-' + index;
				option.className = 'erankly-redirects-suggest-option';
				option.setAttribute('role', 'option');

				var primary = document.createElement('span');
				primary.className = 'erankly-redirects-suggest-primary';
				primary.textContent = item.primary;
				option.appendChild(primary);

				if (item.secondary) {
					var secondary = document.createElement('span');
					secondary.className = 'erankly-redirects-suggest-secondary';
					secondary.textContent = item.secondary;
					option.appendChild(secondary);
				}

				option.addEventListener('mousedown', function (event) {
					// Prevent the input from losing focus/blurring before the click registers.
					event.preventDefault();
				});

				option.addEventListener('click', function () {
					// This click is still bubbling toward the document listener when
					// applyItem() rebuilds the menu below, so the (now-detached) option
					// element would otherwise look like an "outside" click and close the
					// menu we just reopened for the next filter step.
					suppressNextOutsideClick = true;
					applyItem(item);
				});

				menu.appendChild(option);
			});

			menu.hidden = false;
			input.setAttribute('aria-expanded', 'true');
			highlight(-1);
		}

		input.addEventListener('focus', openMenu);
		input.addEventListener('click', openMenu);
		input.addEventListener('input', openMenu);

		input.addEventListener('keydown', function (event) {
			if (menu.hidden) {
				return;
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault();
				highlight(Math.min(activeIndex + 1, currentItems.length - 1));
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				highlight(Math.max(activeIndex - 1, 0));
			} else if (event.key === 'Enter') {
				if (activeIndex >= 0 && currentItems[activeIndex]) {
					event.preventDefault();
					applyItem(currentItems[activeIndex]);
				}
			} else if (event.key === 'Escape') {
				closeMenu();
			}
		});

		document.addEventListener('click', function (event) {
			if (suppressNextOutsideClick) {
				suppressNextOutsideClick = false;
				return;
			}

			if (event.target !== input && !menu.contains(event.target)) {
				closeMenu();
			}
		});
	}());
}());

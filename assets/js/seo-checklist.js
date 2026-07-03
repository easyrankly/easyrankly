(function () {
	'use strict';

	var STATUS_CLASSES = ['is-incomplete', 'is-partial', 'is-complete'];

	function setup() {
		var root = document.querySelector('[data-erankly-seo-checklist]');

		if (!root) {
			return;
		}

		var toggle = root.querySelector('[data-erankly-seo-checklist-toggle]');
		var panel = root.querySelector('[data-erankly-seo-checklist-panel]');
		var count = root.querySelector('[data-erankly-seo-checklist-count]');
		var minWidth = parseInt(root.getAttribute('data-erankly-min-width'), 10) || 1200;
		var minHeight = parseInt(root.getAttribute('data-erankly-min-height'), 10) || 630;
		var state = {};

		root.querySelectorAll('[data-erankly-seo-checklist-item]').forEach(function (item) {
			state[item.getAttribute('data-erankly-seo-checklist-item')] = item.classList.contains('is-done');
		});

		function apply() {
			var keys = Object.keys(state);
			var done = keys.filter(function (key) {
				return state[key];
			}).length;
			var status = 'is-partial';

			if (done === 0) {
				status = 'is-incomplete';
			} else if (done === keys.length) {
				status = 'is-complete';
			}

			keys.forEach(function (key) {
				var item = root.querySelector('[data-erankly-seo-checklist-item="' + key + '"]');

				if (item) {
					item.classList.toggle('is-done', state[key]);
				}
			});

			STATUS_CLASSES.forEach(function (statusClass) {
				root.classList.toggle(statusClass, statusClass === status);
			});

			if (count) {
				count.textContent = done + '/' + keys.length;
			}
		}

		function update(key, done) {
			if (!(key in state) || state[key] === done) {
				return;
			}

			state[key] = done;
			apply();
		}

		bindToggle(root, toggle, panel);
		bindSnooze(root, toggle, panel);
		bindClassicFields(update);
		bindClassicFeaturedImage(update, minWidth, minHeight);
		bindBlockEditor(update, minWidth, minHeight);
	}

	function bindToggle(root, toggle, panel) {
		if (!toggle || !panel) {
			return;
		}

		function close() {
			root.classList.remove('is-open');
			panel.hidden = true;
			toggle.setAttribute('aria-expanded', 'false');
		}

		toggle.addEventListener('click', function () {
			var open = root.classList.toggle('is-open');

			panel.hidden = !open;
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		});

		document.addEventListener('click', function (event) {
			if (root.classList.contains('is-open') && !root.contains(event.target)) {
				close();
			}
		});

		document.addEventListener('keydown', function (event) {
			if ('Escape' === event.key && root.classList.contains('is-open')) {
				close();
			}
		});
	}

	// Hides the whole checklist for 10 seconds so the content underneath
	// becomes visible, then brings it back.
	function bindSnooze(root, toggle, panel) {
		var hide = root.querySelector('[data-erankly-seo-checklist-hide]');

		if (!hide) {
			return;
		}

		hide.addEventListener('click', function () {
			root.classList.remove('is-open');

			if (panel) {
				panel.hidden = true;
			}

			if (toggle) {
				toggle.setAttribute('aria-expanded', 'false');
			}

			root.classList.add('is-snoozed');

			window.setTimeout(function () {
				root.classList.remove('is-snoozed');
			}, 10000);
		});
	}

	// Classic editor: the meta box title and description fields.
	function bindClassicFields(update) {
		var fields = {
			title: document.getElementById('erankly-title'),
			description: document.getElementById('erankly-description')
		};

		Object.keys(fields).forEach(function (key) {
			var field = fields[key];

			if (!field) {
				return;
			}

			field.addEventListener('input', function () {
				update(key, '' !== field.value.trim());
			});
		});
	}

	// Classic editor: the featured image box is re-rendered via AJAX, so watch
	// it and fetch the new attachment's dimensions through the REST API.
	function bindClassicFeaturedImage(update, minWidth, minHeight) {
		var box = document.getElementById('postimagediv');

		if (!box || !window.wp || !window.wp.apiFetch || !window.MutationObserver) {
			return;
		}

		var lastId = null;

		function check() {
			var input = document.getElementById('_thumbnail_id');
			var id = input ? parseInt(input.value, 10) : 0;

			if (id === lastId) {
				return;
			}

			lastId = id;

			if (!id || id < 1) {
				update('featured_image', false);
				return;
			}

			window.wp.apiFetch({ path: '/wp/v2/media/' + id })
				.then(function (media) {
					var details = media && media.media_details ? media.media_details : {};

					update('featured_image', (details.width || 0) >= minWidth && (details.height || 0) >= minHeight);
				})
				.catch(function () {});
		}

		new MutationObserver(check).observe(box, { childList: true, subtree: true });
	}

	// Block editor: follow the edited meta and featured image in the data stores.
	function bindBlockEditor(update, minWidth, minHeight) {
		if (!window.wp || !window.wp.data || !window.wp.data.subscribe || !window.wp.data.select('core/editor')) {
			return;
		}

		var select = window.wp.data.select;

		window.wp.data.subscribe(function () {
			var editor = select('core/editor');
			var meta = editor.getEditedPostAttribute('meta') || {};

			update('title', '' !== String(meta._erankly_title || '').trim());
			update('description', '' !== String(meta._erankly_description || '').trim());

			var mediaId = editor.getEditedPostAttribute('featured_media') || 0;

			if (!mediaId) {
				update('featured_image', false);
				return;
			}

			var media = select('core').getMedia(mediaId);

			// Keep the last known state while the attachment record resolves.
			if (!media) {
				return;
			}

			var details = media.media_details || {};

			update('featured_image', (details.width || 0) >= minWidth && (details.height || 0) >= minHeight);
		});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', setup);
	} else {
		setup();
	}
})();

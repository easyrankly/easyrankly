(function () {
	'use strict';

	// Delete confirmation.
	document.addEventListener('click', function (event) {
		var link = event.target.closest('.erankly-redirects-delete');

		if (!link) {
			return;
		}

		var message = window.eranklyRedirects && window.eranklyRedirects.deleteConfirm ? window.eranklyRedirects.deleteConfirm : 'Delete this redirect?';

		if (!window.confirm(message)) {
			event.preventDefault();
		}
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
}());

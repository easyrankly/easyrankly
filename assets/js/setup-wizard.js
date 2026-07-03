(function () {
	'use strict';

	// Show the "reference user" (Person) field only when the Person identity is selected.
	var radios = document.querySelectorAll('[data-erankly-setup-identity]');
	var field = document.querySelector('[data-erankly-setup-person]');

	if (!field || !radios.length) {
		return;
	}

	function sync() {
		var selected = document.querySelector('[data-erankly-setup-identity]:checked');
		field.hidden = !selected || 'person' !== selected.value;
	}

	radios.forEach(function (radio) {
		radio.addEventListener('change', sync);
	});

	sync();
})();

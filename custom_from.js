/*
** Plugin custom_from for RoundcubeMail
**  - Plugin script
*/

if (window.rcmail) {
	var customFromToggle = (function () {
		// UI elements
		var iconDisable = 'plugins/custom_from/images/custom_from_off.png';
		var iconEnable = 'plugins/custom_from/images/custom_from_on.png';
		var textDisable = rcmail.gettext('custom_from_button_off', 'custom_from');
		var textEnable = rcmail.gettext('custom_from_button_on', 'custom_from');

		var button = $('<a class="input-group-text" href="#">')
			.attr('title', textEnable)
			.html($('<img alt="Custom From Toggle">').attr('src', iconEnable));

		// Plugin state
		var disabled = true;
		var senderSelect = [];
		var senderTextbox;

		// Feature toggle handler
		var toggle = function (event, value) {
			if (senderSelect.length < 1) {
				return;
			}

			if (disabled) {
				button
					.attr('title', textDisable)
					.find('img').attr('src', iconDisable);

				senderTextbox = $('<input class="custom_from form-control" name="_from" type="text">')
					.attr('onchange', senderSelect.attr('onchange'))
					.attr('value', value || senderSelect.find('option:selected')[0].text);

				senderSelect
					.before(senderTextbox)
					.removeAttr('name')
					.css('display', 'none');

				// Fix for Classic skin only
				// See: https://github.com/r3c/custom_from/issues/18
				$('#compose-div')
					.css('top', '+=18');
			}
			else {
				button
					.attr('title', textEnable)
					.find('img').attr('src', iconEnable);

				senderTextbox.remove();

				senderSelect
					.attr('name', '_from')
					.css('display', 'inline');

				// Cancel fix for Classic skin only (see above)
				$('#compose-div')
					.css('top', '-=18');
			}

			disabled = !disabled;
		};

		// Toggle plugin on button click
		button.bind('click', toggle);

		// Enable plugin on Roundcube initialization
		rcmail.addEventListener('init', function (event) {
			senderSelect = $('select#_from');
			senderSelect.after($('<span class="input-group-append">').html(button))
		});

		// Make toggle function visible from global scope
		return toggle;
	})();
}

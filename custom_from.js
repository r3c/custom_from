/*
** Plugin custom_from for RoundcubeMail
**  - Plugin script
*/

if (window.rcmail) {
	var customFromToggle = (function () {
		// UI elements
		var textDisable = rcmail.gettext('custom_from_off', 'custom_from');
		var textDisableHint = rcmail.gettext('custom_from_off_hint', 'custom_from');
		var textEnable = rcmail.gettext('custom_from_on', 'custom_from');
		var textEnableHint = rcmail.gettext('custom_from_on_hint', 'custom_from');

		var button = $('<a class="custom-from-on iconlink input-group-text" href="#">')
			.attr('title', textEnableHint)
			.text(textEnable);

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
					.addClass('custom-from-off')
					.removeClass('custom-from-on')
					.attr('title', textDisableHint)
					.text(textDisable);

				senderTextbox = $('<input class="custom_from form-control" name="_from" type="text">')
					.attr('onchange', senderSelect.attr('onchange'))
					.attr('value', value || senderSelect.find('option:selected')[0].text);

				senderSelect
					.before(senderTextbox)
					.removeAttr('name')
					.css('display', 'none');
			} else {
				button
					.addClass('custom-from-on')
					.removeClass('custom-from-off')
					.attr('title', textEnableHint)
					.text(textEnable);

				senderTextbox.remove();

				senderSelect
					.attr('name', '_from')
					.css('display', 'inline');
			}

			/** Update hash to trigger Roundcube's form validation */
			rcmail.cmp_hash += ' ';

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

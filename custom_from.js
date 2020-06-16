/*
** Plugin custom_from for RoundcubeMail
**  - Plugin script
*/

if (window.rcmail) {
	var custom_from_off = function (event) {
		$('a#rcmbtn_custom_from_off')
			.addClass('custom_from_hide')

		$('a#rcmbtn_custom_from_on')
			.removeClass('custom_from_hide')

		$('select#_from')
			.attr('name', '_from')
			.css('display', 'inline');

		$('input#custom_from_text')
			.remove();

		$('#compose-div')
			.css('top', '-=18');
	};

	var custom_from_on = function (event, value) {
		var drop = $('select#_from');

		if (drop.length > 0) {
			$('a#rcmbtn_custom_from_off')
				.removeClass('custom_from_hide')

			$('a#rcmbtn_custom_from_on')
				.addClass('custom_from_hide')

			drop.after
				(
					$('<input>')
						.addClass('custom_from')
						.addClass('form-control')
						.attr('id', 'custom_from_text')
						.attr('name', '_from')
						.attr('onchange', drop.attr('onchange'))
						.attr('type', 'text')
						.attr('value', value || drop.find('option:selected')[0].text)
				);

			drop
				.removeAttr('name', '')
				.css('display', 'none');

			$('#compose-div')
				.css('top', '+=18');
		}
	};

	rcmail.addEventListener('init', function (event) {
		$('#_from')
			.after
			(
				$('<span>')
					.addClass('input-group-append')
					.html
					(
						$('<a>')
							.addClass('custom_from_hide')
							.addClass('custom_from_off')
							.addClass('input-group-text')
							.attr('id', 'rcmbtn_custom_from_off')
							.attr('href', '#')
							.attr('title', rcmail.gettext('custom_from_button_off', 'custom_from'))
							.html
							(
								$('<img>')
									.attr('alt', 'custom_from_off')
									.attr('src', 'plugins/custom_from/images/custom_from_off.png')
							)
							.bind('click', custom_from_off)
					)
			)
			.after
			(
				$('<span>')
					.addClass('input-group-append')
					.html
					(
						$('<a>')
							.addClass('custom_from_on')
							.addClass('input-group-text')
							.attr('id', 'rcmbtn_custom_from_on')
							.attr('href', '#')
							.attr('title', rcmail.gettext('custom_from_button_on', 'custom_from'))
							.html
							(
								$('<img>')
									.attr('alt', 'custom_from_on')
									.attr('src', 'plugins/custom_from/images/custom_from_on.png')
							)
							.bind('click', custom_from_on)
					)
			)
	});
}

<?php

/*
** Plugin custom_from for RoundcubeMail
**  - Description: replace dropdown by textbox to allow "From:" header input
**  - Author: Remi Caput - roundcube.net@mirari.fr
*/

class	custom_from extends rcube_plugin
{
	private	$from = null;

	/*
	** Initialize plugin.
	*/
	public function	init ()
	{
	    $app = rcmail::get_instance ();

		$this->add_texts ('localization', true);
		$this->add_hook ('message_compose', array ($this, 'message_compose'));
		$this->add_hook ('render_page', array ($this, 'render_page'));
	}

	/*
	** Enable custom "From:" field if mail being composed has been sent to an
	** address that looks like virtual (i.e. not in user identities list).
	*/
	public function	message_compose ($params)
	{
		global	$IMAP;
		global	$USER;

		$address = null;
		$rcmail = rcmail::get_instance ();
		$this->load_config ();

		if ($rcmail->config->get ('custom_from_compose_auto', true) && isset ($params['param']['reply_uid']))
		{
			$IMAP->get_all_headers = true;

			$headers = $IMAP->get_message ($params['param']['reply_uid']);

			if ($headers !== null && isset ($headers->to))
			{
				$identities = array ();
				$recipients = array ();

				// Decode recipients from "to" header field
				foreach ($IMAP->decode_address_list ($headers->to) as $recipient)
				{
					if (isset ($recipient['mailto']))
					{
						$recipients[$recipient['mailto']] = array
						(
							'domain'	=> preg_replace ('/^[^@]*@(.*)$/', '$1', $recipient['mailto']),
							'name'		=> $recipient['name']
						);
					}
				}

				// Get user identities list
				foreach ($USER->list_identities () as $identity)
				{
					$identities[$identity['email']] = array
					(
						'domain'	=> preg_replace ('/^[^@]*@(.*)$/', '$1', $identity['email']),
						'id'		=> $identity['id']
					);
				}

				// Find best possible match from recipients and identities
				$level = 0;

				foreach ($recipients as $email => $recipient)
				{
					// Relevance level 3: exact match found in identities
					if ($level < 3)
					{
						if (isset ($identities[$email]))
						{
							$address = null;
							$level = 3;
						}
					}

					// Relevance level 2: domain match found in identities
					if ($level < 2)
					{
						foreach ($identities as $identity)
						{
							if ($identity['domain'] == $recipient['domain'])
							{
								$address = $recipient['name'] ? ($recipient['name'] . ' <' . $email . '>') : $email;
								$level = 2;
							}
						}
					}

					// Relevance level 1: no match found
					if ($level < 1)
					{
						$address = $recipient['name'] ? ($recipient['name'] . ' <' . $email . '>') : $email;
						$level = 1;
					}
				}
			}
		}

		$_SESSION['custom_from'] = $address;
	}

	public function	render_page ($params)
	{
		if ($params['template'] == 'compose')
		{
			if (isset ($_SESSION['custom_from']))
			{
				$value = str_replace (array ('\\', '\''), array ('\\\\', '\\\''), $_SESSION['custom_from']);

				$rcmail = rcmail::get_instance ();
				$rcmail->output->add_footer ('<script type="text/javascript">rcmail.addEventListener(\'init\', function (event) { custom_from_on(event, \'' . $value . '\'); });</script>');
			}

			$this->include_stylesheet ('custom_from.css');
			$this->include_script ('custom_from.js');
		}

		return $params;
	}
}

?>

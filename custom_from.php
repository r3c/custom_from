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
		$this->add_hook('storage_init', array($this, 'storage_init'));
	}

	/**
	** Adds additional headers to supported headers list
	*/
	function storage_init($p)
	{
		$rcmail = rcmail::get_instance();
		$this->load_config ();
		$definitive = $rcmail->config->get('custom_from_definitive_to', null);

		if ($definitive !== null)
		{
			$p['fetch_headers'] = trim($p['fetch_headers']) . ' ' . trim($definitive);
		}

		return $p;
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
		$definitive = $rcmail->config->get('custom_from_definitive_to', 'Bogus-NonExistent-Header-' . sha1(time()));

		if (isset ($params['param']['reply_uid']))
			$message = $params['param']['reply_uid'];
		else if (isset ($params['param']['forward_uid']))
			$message = $params['param']['forward_uid'];
		else if (isset ($params['param']['uid']))
			$message = $params['param']['uid'];
		else
			$message = null;

		if ($rcmail->config->get ('custom_from_compose_auto', true) && $message !== null)
		{
			// Newer versions of roundcube don't provide a global $IMAP or $USER variable
			if (!isset ($IMAP) && isset ($rcmail->storage))
				$IMAP = $rcmail->storage;

			if (!isset ($USER) && isset ($rcmail->user))
				$USER = $rcmail->user;

			$IMAP->get_all_headers = true;

			$headers = $IMAP->get_message ($message);

			if ($headers !== null)
			{
				$identities = array ();
				$recipients = array ();

				// Decode recipients from e-mail headers
				$targets = array_merge
				(
					isset ($headers->to) ? $IMAP->decode_address_list ($headers->to) : array (),
					isset ($headers->cc) ? $IMAP->decode_address_list ($headers->cc) : array (),
					isset ($headers->cci) ? $IMAP->decode_address_list ($headers->cci) : array (),
					isset ($headers->others[strtolower($definitive)]) ? $IMAP->decode_address_list ('<' . $headers->others[strtolower($definitive)] . '>') : array ()
				);

				foreach ($targets as $target)
				{
					if (isset ($target['mailto']))
					{
						$recipients[$target['mailto']] = array
						(
							'domain'	=> preg_replace ('/^[^@]*@(.*)$/', '$1', $target['mailto']),
							'name'		=> $target['name']
						);
					}
				}

				// Get user identities list
				foreach ($USER->list_identities () as $identity)
				{
					$identities[$identity['email']] = array
					(
						'domain'	=> preg_replace ('/^[^@]*@(.*)$/', '$1', $identity['email']),
						'name'		=> $identity['name']
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
								$address = $identity['name'] ? ($identity['name'] . ' <' . $email . '>') : $email;
								$level = 2;
							}
						}
					}

					// Relevance level 1: no match found
					if ($level < 1)
					{
						$address = $identity['name'] ? ($identity['name'] . ' <' . $email . '>') : $email;
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

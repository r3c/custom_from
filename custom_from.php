<?php

/*
** Plugin custom_from for RoundcubeMail
**
** Description: replace dropdown by textbox to allow "From:" header input
**
** @version 1.6
** @license MIT
** @author Remi Caput
** @url https://github.com/r3c/Custom-From
*/

class	custom_from extends rcube_plugin
{
	const HEADER_RULES = 'X-Original-To=deo;to=deo;cc=deo;cci=deo;from=de';

	/*
	** Initialize plugin.
	*/
	public function	init ()
	{
		$this->add_texts ('localization', true);
		$this->add_hook ('message_compose', array ($this, 'message_compose'));
		$this->add_hook ('render_page', array ($this, 'render_page'));
		$this->add_hook ('storage_init', array ($this, 'storage_init'));
	}

	/**
	** Adds additional headers to supported headers list.
	*/
	public function storage_init ($params)
	{
		$this->load_config ();

		$excludes = array_flip (array ('cc', 'cci', 'from', 'to'));
		$rcmail = rcmail::get_instance ();
		$rules = $this->parse_headers ($rcmail->config->get ('custom_from_header_rules', self::HEADER_RULES));

		foreach ($rules as $header => $value)
		{
			if (!isset ($excludes[$header]))
				$params['fetch_headers'] = trim ($params['fetch_headers'] . ' ') . $header;
		}

		return $params;
	}

	/*
	** Enable custom "From:" field if mail being composed has been sent to an
	** address that looks like virtual (i.e. not in user identities list).
	*/
	public function	message_compose ($params)
	{
		global	$IMAP;
		global	$USER;

		$this->load_config ();

		$address = null;
		$rcmail = rcmail::get_instance ();

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
				// Browse headers where addresses will be fetched from
				$recipients = array ();
				$rules = $this->parse_headers ($rcmail->config->get ('custom_from_header_rules', self::HEADER_RULES));

				foreach ($rules as $header => $rule)
				{
					switch ($header)
					{
						case 'cc':
							$addresses = isset ($headers->cc) ? $IMAP->decode_address_list ($headers->cc) : array ();

							break;

						case 'cci':
							$addresses = isset ($headers->cci) ? $IMAP->decode_address_list ($headers->cci) : array ();

							break;

						case 'from':
							$addresses = isset ($headers->from) ? $IMAP->decode_address_list ($headers->from) : array ();

							break;

						case 'to':
							$addresses = isset ($headers->to) ? $IMAP->decode_address_list ($headers->to) : array ();

							break;

						default:
							$addresses = isset ($headers->others[$header]) ? $IMAP->decode_address_list ($headers->others[$header]) : array ();

							break;
					}

					// Decode recipients and matching rules from retrieved addresses
					foreach ($addresses as $address)
					{
						if (isset ($address['mailto']))
						{
							$email = $address['mailto'];

							$recipients[] = array
							(
								'domain'		=> preg_replace ('/^[^@]*@(.*)$/', '$1', $email),
								'email'			=> $email,
								'match_domain'	=> strpos ($rule, 'd') !== false,
								'match_exact'	=> strpos ($rule, 'e') !== false,
								'match_other'	=> strpos ($rule, 'o') !== false,
								'name'			=> $address['name']
							);
						}
					}
				}

				// Get user identities list
				$identities = array ();

				foreach ($USER->list_identities () as $identity)
				{
					$identities[$identity['email']] = array
					(
						'domain'	=> preg_replace ('/^[^@]*@(.*)$/', '$1', $identity['email']),
						'name'		=> $identity['name']
					);
				}

				// Find best possible match from recipients and identities
				$address = null;
				$score = 0;

				foreach ($recipients as $recipient)
				{
					$email = $recipient['email'];

					// Relevance score 3: exact match found in identities
					if ($score < 3 && $recipient['match_exact'] && isset ($identities[$email]))
					{
						$address = null;
						$score = 3;
					}

					// Relevance score 2: domain match found in identities
					if ($score < 2 && $recipient['match_domain'])
					{
						foreach ($identities as $identity)
						{
							if (strcasecmp($identity['domain'], $recipient['domain']) == 0)
							{
								$address = $identity['name'] ? ($identity['name'] . ' <' . $email . '>') : $email;
								$score = 2;
							}
						}
					}

					// Relevance score 1: no match found
					if ($score < 1 && $recipient['match_other'])
					{
						$address = $recipient['name'] ? ($recipient['name'] . ' <' . $email . '>') : $email;
						$score = 1;
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

	private function parse_headers ($input)
	{
		$headers = array ();

		foreach (explode (';', $input) as $header)
		{
			$fields = explode ('=', $header, 2);

			if (count ($fields) === 2)
				$headers[strtolower (trim ($fields[0]))] = strtolower (trim ($fields[1]));
		}

		return $headers;
	}
}

?>

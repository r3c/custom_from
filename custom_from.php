<?php

/*
** Plugin custom_from for RoundcubeMail
**
** Description: replace dropdown by textbox to allow "From:" header input
**
** @version 1.8.0
** @license MIT
** @author Remi Caput
** @url https://github.com/r3c/custom_from
*/

class custom_from extends rcube_plugin
{
    const PREFERENCE_COMPOSE_CONTAINS = 'custom_from_compose_contains';
    const PREFERENCE_COMPOSE_IDENTITY = 'custom_from_compose_identity';
    const PREFERENCE_COMPOSE_IDENTITY_EXACT = 'exact';
    const PREFERENCE_COMPOSE_IDENTITY_LOOSE = 'loose';
    const PREFERENCE_COMPOSE_SUBJECT = 'custom_from_compose_subject';
    const PREFERENCE_COMPOSE_SUBJECT_ALWAYS = 'always';
    const PREFERENCE_COMPOSE_SUBJECT_DOMAIN = 'domain';
    const PREFERENCE_COMPOSE_SUBJECT_EXACT = 'exact';
    const PREFERENCE_COMPOSE_SUBJECT_NEVER = 'never';
    const PREFERENCE_COMPOSE_SUBJECT_PREFIX = 'prefix';
    const PREFERENCE_SECTION = 'custom_from';

    private string $contains;
    private string $identity;
    private array $rules;

    /**
     ** Initialize plugin.
     */
    public function init()
    {
        $this->add_texts('localization', true);
        $this->add_hook('identity_select', array($this, 'identity_select'));
        $this->add_hook('message_compose', array($this, 'message_compose'));
        $this->add_hook('message_compose_body', array($this, 'message_compose_body'));
        $this->add_hook('preferences_list', array($this, 'preferences_list'));
        $this->add_hook('preferences_save', array($this, 'preferences_save'));
        $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
        $this->add_hook('render_page', array($this, 'render_page'));
        $this->add_hook('storage_init', array($this, 'storage_init'));

        $this->load_config();

        $rcmail = rcmail::get_instance();

        list($contains, $identity, $rules) = self::get_configuration($rcmail);

        $this->contains = $contains;
        $this->identity = $identity;
        $this->rules = $rules;
    }

    /**
     * Override selected identity according to configuration.
     */
    public function identity_select($params)
    {
        $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);

        list($identity) = self::get_state($compose_id);

        // Set selected identity if one was matched
        if ($identity !== null) {
            foreach ($params['identities'] as $index => $candidate) {
                if ($candidate['identity_id'] === $identity) {
                    $params['selected'] = $index;

                    break;
                }
            }
        }

        return $params;
    }

    /**
     ** Enable custom "From:" field if mail being composed has been sent to an
     ** address that looks like a virtual one (i.e. not in user identities list).
     */
    public function message_compose($params)
    {
        // Search for message ID in known parameters
        $message_uid_keys = array('draft_uid', 'forward_ui', 'reply_uid', 'uid');
        $message_uid = null;

        foreach ($message_uid_keys as $key) {
            if (isset($params['param'][$key])) {
                $message_uid = $params['param'][$key];

                break;
            }
        }

        // Early return if current message is unknown
        $rcmail = rcmail::get_instance();
        $storage = $rcmail->get_storage();
        $headers = $message_uid !== null ? $storage->get_message($message_uid) : null;

        if ($headers === null) {
            return;
        }

        // Browse headers where addresses will be fetched from
        $recipients = array();

        foreach ($this->rules as $header => $rule) {
            $header_value = $headers->get($header);
            $addresses = $header_value !== null ? rcube_mime::decode_address_list($header_value, null, false) : array();

            // Decode recipients and matching rules from retrieved addresses
            foreach ($addresses as $address) {
                if (!isset($address['mailto'])) {
                    continue;
                }

                $email = $address['mailto'];

                if (strpos($email, $this->contains) === false) {
                    continue;
                }

                $recipients[] = array(
                    'domain' => preg_replace('/^[^@]*@(.*)$/', '$1', $email),
                    'email' => $email,
                    'email_prefix' => preg_replace('/^([^@+]*)\\+[^@]+@(.*)$/', '$1@$2', $email),
                    'match_always' => strpos($rule, 'o') !== false,
                    'match_domain' => strpos($rule, 'd') !== false,
                    'match_exact' => strpos($rule, 'e') !== false,
                    'match_prefix' => strpos($rule, 'p') !== false,
                    'name' => $address['name'],
                );
            }
        }

        // Build lookup maps from domain name and full address
        $identity_by_domain = array();
        $identity_by_email = array();
        $identity_default = null;

        foreach ($rcmail->user->list_identities() as $identity) {
            $domain = strtolower(preg_replace('/^[^@]*@(.*)$/', '$1', $identity['email']));
            $email = strtolower($identity['email']);
            $match = array(
                'id' => $identity['identity_id'],
                'name' => $identity['name'],
                'rank' => $identity['standard'] === '1' ? 1 : 0
            );

            if (!isset($identity_by_domain[$domain]) || $identity_by_domain[$domain]['rank'] < $match['rank'])
                $identity_by_domain[$domain] = $match;

            if (!isset($identity_by_email[$email]) || $identity_by_email[$email]['rank'] < $match['rank'])
                $identity_by_email[$email] = $match;

            if ($identity_default === null || $identity_default['rank'] < $match['rank'])
                $identity_default = $match;
        }

        // Find best possible match from recipients and identities
        $best_match = null;
        $best_score = 4;

        foreach ($recipients as $recipient) {
            $domain = strtolower($recipient['domain']);
            $email = strtolower($recipient['email']);
            $email_prefix = strtolower($recipient['email_prefix']);

            // Relevance score 0: match by e-mail found in identities
            if ($recipient['match_exact'] && isset($identity_by_email[$email])) {
                $identity = $identity_by_email[$email];
                $score = 0;
            }

            // Relevance score 1: match by e-mail found in identities after removing "+suffix"
            else if ($recipient['match_prefix'] && isset($identity_by_email[$email_prefix])) {
                $identity = $identity_by_email[$email_prefix];
                $score = 1;
            }

            // Relevance score 2: match by domain found in identities
            else if ($recipient['match_domain'] && isset($identity_by_domain[$domain])) {
                $identity = $identity_by_domain[$domain];
                $score = 2;
            }

            // Relevance score 3: any match found
            else if ($recipient['match_always'] && $identity_default !== null) {
                $identity = $identity_default;
                $score = 3;
            }

            // No match
            else
                continue;

            // Overwrite best match if score is better (lower)
            if ($score < $best_score) {
                $best_match = array(
                    'email' => $recipient['email'],
                    'identity' => $identity,
                    'name' => $recipient['name']
                );

                $best_score = $score;
            }
        }

        // Define name and identity to be used for composing
        if ($best_match === null) {
            // No match, preserve default behavior
            $identity = null;
            $sender = null;
        } else if ($best_score === 0) {
            // Exact match, select it and preserve identity selector
            $identity = $best_match['identity']['id'];
            $sender = null;
        } else if ($this->identity !== self::PREFERENCE_COMPOSE_IDENTITY_EXACT) {
            // Approximate match + use identity, select it and set custom sender with identity name
            $identity = $best_match['identity']['id'];
            $sender = format_email_recipient($best_match['email'], $best_match['identity']['name']);
        } else {
            // Approximate match + identity shouldn't be used, set custom sender with matched name
            $identity = null;
            $sender = format_email_recipient($best_match['email'], $best_match['name']);
        }

        // Store matched address
        $compose_id = $params['id'];

        self::set_state($compose_id, $identity, $sender);
    }

    public function message_compose_body($params)
    {
        global $MESSAGE;

        $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);
        $message = isset($params['message']) ? $params['message'] : (isset($MESSAGE) ? $MESSAGE : null);

        // Log error and exit in case required state variables are undefined to avoid unwanted behavior
        if ($compose_id === null) {
            self::emit_error('missing \'_id\' GET parameter, custom_from won\'t work properly');

            return;
        } else if ($message === null) {
            self::emit_error('missing $message hook parameter and global variable, custom_from won\'t work properly');

            return;
        }

        list($identity, $sender) = self::get_state($compose_id);

        if ($sender !== null) {
            // Disable signature when a sender override was defined but no
            // identity should be reused
            if ($identity === null) {
                $rcmail = rcmail::get_instance();
                $rcmail->output->set_env('show_sig', false);
            }

            // Remove selected virtual e-mail from message headers so it doesn't
            // get copied to "cc" field, see details at
            // https://github.com/r3c/custom_from/issues/19. This implementation
            // relies on how method `compose_header_value` from
            // `rcmail_sendmail.php` is currently reading headers to build "cc"
            // field value and is most probably not a good use of
            // `message_compose_body` hook but there is currently no better
            // place to introduce a dedicated hook, see follow-up at
            // https://github.com/roundcube/roundcubemail/issues/7590.
            foreach (array_keys($this->rules) as $header) {
                $header_value = $message->headers->get($header);

                if ($header_value !== null) {
                    $addresses_header = rcube_mime::decode_address_list($header_value, null, false);

                    $addresses_filtered = array_filter($addresses_header, function ($candidate) use ($sender) {
                        return $candidate['mailto'] !== $sender;
                    });

                    $addresses_string = array_map(function ($address) {
                        return $address['string'];
                    }, $addresses_filtered);

                    $message->headers->set($header, implode(', ', $addresses_string));
                }
            }
        }
    }

    public function preferences_list($params)
    {
        if ($params['section'] !== self::PREFERENCE_SECTION) {
            return $params;
        }

        // Read configuration in case it was just changed
        $rcmail = rcmail::get_instance();

        list($contains, $identity, $rules) = self::get_configuration($rcmail);

        // Contains preference
        $compose_contains = new html_inputfield(array('id' => self::PREFERENCE_COMPOSE_CONTAINS, 'name' => self::PREFERENCE_COMPOSE_CONTAINS));

        // Identity preference
        $compose_identity = new html_select(array('id' => self::PREFERENCE_COMPOSE_IDENTITY, 'name' => self::PREFERENCE_COMPOSE_IDENTITY));
        $compose_identity->add(self::get_text($rcmail, 'preference_compose_identity_loose'), self::PREFERENCE_COMPOSE_IDENTITY_LOOSE);
        $compose_identity->add(self::get_text($rcmail, 'preference_compose_identity_exact'), self::PREFERENCE_COMPOSE_IDENTITY_EXACT);

        // Subject preference, using global configuration as fallback value
        $rule = isset($rules['to']) ? $rules['to'] : '';

        if (strpos($rule, 'o') !== false)
            $compose_subject_value = self::PREFERENCE_COMPOSE_SUBJECT_ALWAYS;
        else if (strpos($rule, 'd') !== false)
            $compose_subject_value = self::PREFERENCE_COMPOSE_SUBJECT_DOMAIN;
        else if (strpos($rule, 'p') !== false)
            $compose_subject_value = self::PREFERENCE_COMPOSE_SUBJECT_PREFIX;
        else if (strpos($rule, 'e') !== false)
            $compose_subject_value = self::PREFERENCE_COMPOSE_SUBJECT_EXACT;
        else
            $compose_subject_value = self::PREFERENCE_COMPOSE_SUBJECT_NEVER;

        $compose_subject = new html_select(array('id' => self::PREFERENCE_COMPOSE_SUBJECT, 'name' => self::PREFERENCE_COMPOSE_SUBJECT));
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_never'), self::PREFERENCE_COMPOSE_SUBJECT_NEVER);
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_exact'), self::PREFERENCE_COMPOSE_SUBJECT_EXACT);
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_prefix'), self::PREFERENCE_COMPOSE_SUBJECT_PREFIX);
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_domain'), self::PREFERENCE_COMPOSE_SUBJECT_DOMAIN);
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_always'), self::PREFERENCE_COMPOSE_SUBJECT_ALWAYS);

        $params['blocks'] = array(
            'compose' => array(
                'name' => self::get_text_quoted($rcmail, 'preference_compose'),
                'options' => array(
                    array(
                        'title' => html::label(self::PREFERENCE_COMPOSE_SUBJECT, self::get_text_quoted($rcmail, 'preference_compose_subject')),
                        'content' => $compose_subject->show(array($compose_subject_value))
                    ),
                    array(
                        'title' => html::label(self::PREFERENCE_COMPOSE_CONTAINS, self::get_text_quoted($rcmail, 'preference_compose_contains')),
                        'content' => $compose_contains->show($contains)
                    ),
                    array(
                        'title' => html::label(self::PREFERENCE_COMPOSE_IDENTITY, self::get_text_quoted($rcmail, 'preference_compose_identity')),
                        'content' => $compose_identity->show(array($identity))
                    )
                )
            )
        );

        return $params;
    }

    public function preferences_save($params)
    {
        if ($params['section'] === self::PREFERENCE_SECTION) {
            $keys = array(
                self::PREFERENCE_COMPOSE_CONTAINS,
                self::PREFERENCE_COMPOSE_IDENTITY,
                self::PREFERENCE_COMPOSE_SUBJECT
            );

            foreach ($keys as $key) {
                $params['prefs'][$key] = rcube_utils::get_input_value($key, rcube_utils::INPUT_POST);
            }
        }

        return $params;
    }

    public function preferences_sections_list($params)
    {
        $rcmail = rcmail::get_instance();

        if (!$rcmail->config->get('custom_from_preference_disable', false)) {
            $params['list'][self::PREFERENCE_SECTION] = array(
                'id' => self::PREFERENCE_SECTION,
                'section' => self::get_text($rcmail, 'preference')
            );
        }

        return $params;
    }

    public function render_page($params)
    {
        $template = $params['template'];

        if ($template === 'compose') {
            $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);

            list(, $sender) = self::get_state($compose_id);

            if ($sender !== null) {
                $rcmail = rcmail::get_instance();
                $rcmail->output->add_footer('<script type="text/javascript">rcmail.addEventListener(\'init\', function (event) { customFromToggle(event, ' . json_encode($sender) . '); });</script>');
            }

            $this->include_script('custom_from.js');
        }

        if ($template === 'compose' || $template === 'settings') {
            $this->include_stylesheet($this->local_skin_path() . '/custom_from.css');
        }

        return $params;
    }

    /**
     ** Adds additional headers to supported headers list.
     */
    public function storage_init($params)
    {
        $fetch_headers = isset($params['fetch_headers']) ? $params['fetch_headers'] : '';
        $separator = $fetch_headers !== '' ? ' ' : '';

        foreach (array_keys($this->rules) as $header) {
            $fetch_headers .= $separator . $header;
            $separator = ' ';
        }

        $params['fetch_headers'] = $fetch_headers;

        return $params;
    }

    private static function emit_error($message)
    {
        rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => $message), true, false);
    }

    private static function get_configuration(rcmail $rcmail)
    {
        // Early return with no rule if plugin "auto enable" mode is disabled
        if (!$rcmail->config->get('custom_from_compose_auto', true)) {
            return array('', self::PREFERENCE_COMPOSE_IDENTITY_EXACT, array());
        }

        $use_preference = !$rcmail->config->get('custom_from_preference_disable', false);

        // Read "contains" parameter from global configuration & preferences if allowed
        $contains = $rcmail->config->get(self::PREFERENCE_COMPOSE_CONTAINS, '');

        if ($use_preference) {
            $contains = self::get_preference($rcmail, self::PREFERENCE_COMPOSE_CONTAINS, $contains);
        }

        // Read "identity" parameter from global configuration & preferences if allowed
        $identity = $rcmail->config->get('custom_from_compose_identity', self::PREFERENCE_COMPOSE_IDENTITY_LOOSE);

        if ($use_preference) {
            $identity = self::get_preference($rcmail, self::PREFERENCE_COMPOSE_IDENTITY, $identity);
        }

        // Read "rules" parameter from global configuration & preferences if allowed
        $rules_config = $rcmail->config->get('custom_from_header_rules', 'bcc=ep;cc=ep;from=ep;to=ep;x-original-to=ep');
        $rules = array();

        foreach (explode(';', $rules_config) as $pair) {
            $fields = explode('=', $pair, 2);

            if (count($fields) === 2) {
                $rules[strtolower(trim($fields[0]))] = strtolower(trim($fields[1]));
            }
        }

        if ($use_preference) {
            $subject = self::get_preference($rcmail, self::PREFERENCE_COMPOSE_SUBJECT, '');
            $subject_rules = array(
                self::PREFERENCE_COMPOSE_SUBJECT_ALWAYS => 'deop',
                self::PREFERENCE_COMPOSE_SUBJECT_DOMAIN => 'dep',
                self::PREFERENCE_COMPOSE_SUBJECT_EXACT => 'e',
                self::PREFERENCE_COMPOSE_SUBJECT_NEVER => '',
                self::PREFERENCE_COMPOSE_SUBJECT_PREFIX => 'ep'
            );

            if (isset($subject_rules[$subject])) {
                $rule = $subject_rules[$subject];

                foreach (array('bcc', 'cc', 'from', 'to', 'x-original-to') as $header) {
                    if ($rule !== '')
                        $rules[$header] = $rule;
                    else
                        unset($rules[$header]);
                }
            }
        }

        return array($contains, $identity, $rules);
    }

    private static function get_preference(rcmail $rcmail, string $key, string $default)
    {
        return isset($rcmail->user->prefs[$key]) ? $rcmail->user->prefs[$key] : $default;
    }

    private static function get_state($compose_id)
    {
        return $compose_id !== null && isset($_SESSION['custom_from_' . $compose_id])
            ? $_SESSION['custom_from_' . $compose_id]
            : array(null, null);
    }

    private static function get_text(rcmail $rcmail, string $key)
    {
        return $rcmail->gettext($key, 'custom_from');
    }

    private static function get_text_quoted(rcmail $rcmail, string $key)
    {
        return rcmail::Q(self::get_text($rcmail, $key));
    }

    private static function set_state($compose_id, $identity, $sender)
    {
        $_SESSION['custom_from_' . $compose_id] = array($identity, $sender);
    }
}

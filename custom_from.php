<?php

/*
** Plugin custom_from for RoundcubeMail
**
** Description: replace dropdown by textbox to allow "From:" header input
**
** @version 1.6.7
** @license MIT
** @author Remi Caput
** @url https://github.com/r3c/custom_from
*/

class custom_from extends rcube_plugin
{
    const PREFERENCE_COMPOSE_CONTAINS = 'custom_from_compose_contains';
    const PREFERENCE_COMPOSE_SUBJECT = 'custom_from_compose_subject';
    const PREFERENCE_SECTION = 'custom_from';

    private string $contains;
    private array $rules;

    /**
     ** Initialize plugin.
     */
    public function init()
    {
        $this->add_texts('localization', true);
        $this->add_hook('message_compose', array($this, 'message_compose'));
        $this->add_hook('message_compose_body', array($this, 'message_compose_body'));
        $this->add_hook('preferences_list', array($this, 'preferences_list'));
        $this->add_hook('preferences_save', array($this, 'preferences_save'));
        $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
        $this->add_hook('render_page', array($this, 'render_page'));
        $this->add_hook('storage_init', array($this, 'storage_init'));

        $this->load_config();

        $rcmail = rcmail::get_instance();

        list($contains, $rules) = self::get_configuration($rcmail);

        $this->contains = $contains;
        $this->rules = $rules;
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

    /**
     ** Enable custom "From:" field if mail being composed has been sent to an
     ** address that looks like virtual (i.e. not in user identities list).
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
        $message = $message_uid !== null ? $storage->get_message($message_uid) : null;

        if ($message_uid === null) {
            return;
        }

        // Browse headers where addresses will be fetched from
        $recipients = array();

        foreach ($this->rules as $header => $rule) {
            $addresses = isset($message->{$header}) ? rcube_mime::decode_address_list($message->{$header}, null, false) : array();

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
                    'match_always' => strpos($rule, 'o') !== false,
                    'match_domain' => strpos($rule, 'd') !== false,
                    'match_exact' => strpos($rule, 'e') !== false,
                    'name' => $address['name']
                );
            }
        }

        // Build lookup maps from domain name and full address
        $identity_by_domain = array();
        $identity_by_email = array();

        foreach ($rcmail->user->list_identities() as $identity) {
            $domain = strtolower(preg_replace('/^[^@]*@(.*)$/', '$1', $identity['email']));
            $email = strtolower($identity['email']);
            $rank = $identity['standard'] === '1' ? 1 : 0;

            if (!isset($identity_by_domain[$domain]) || $identity_by_domain[$domain]['rank'] < $rank) {
                $identity_by_domain[$domain] = array('name' => $identity['name'], 'rank' => $rank);
            }

            if (!isset($identity_by_email[$email]) || $identity_by_email[$email]['rank'] < $rank) {
                $identity_by_email[$email] = array('rank' => $rank);
            }
        }

        // Find best possible match from recipients and identities
        $best_email = null;
        $best_score = 0;

        foreach ($recipients as $recipient) {
            $domain = strtolower($recipient['domain']);
            $email = strtolower($recipient['email']);

            // Relevance score 3: match by e-mail found in identities
            if ($recipient['match_exact'] && isset($identity_by_email[$email])) {
                $current_email = null;
                $current_score = 3;
            }

            // Relevance score 2: match by domain found in identities
            else if ($recipient['match_domain'] && isset($identity_by_domain[$domain])) {
                $current_email = format_email_recipient($recipient['email'], $identity_by_domain[$domain]['name']);
                $current_score = 2;
            }

            // Relevance score 1: any match found
            else if ($recipient['match_always']) {
                $current_email = format_email_recipient($recipient['email'], $recipient['name']);
                $current_score = 1;
            }

            // No match
            else {
                $current_email = null;
                $current_score = 0;
            }

            // Overwrite best match if score is higher
            if ($current_score > $best_score) {
                $best_email = $current_email;
                $best_score = $current_score;
            }
        }

        // Store matched address
        $compose_id = $params['id'];

        self::set_state($compose_id, $best_email);
    }

    /**
     * Remove selected virtual e-mail from message headers so it doesn't get
     * copied to "cc" field (https://github.com/r3c/custom_from/issues/19).
     * This implementation relies on how method "compose_header_value" from
     * "rcmail_sendmail.php" is currently reading headers to build "cc" field
     * value and is most probably not a good use of "message_compose_body" hook
     * but there is currently no better place to introduce a dedicated hook
     * (https://github.com/roundcube/roundcubemail/issues/7590).
     */
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

        $address = self::get_state($compose_id);

        foreach (array_keys($this->rules) as $header) {
            if (isset($message->headers->{$header})) {
                $addresses_header = rcube_mime::decode_address_list($message->headers->{$header}, null, false);

                $addresses_filtered = array_filter($addresses_header, function ($test) use ($address) {
                    return $test['mailto'] !== $address;
                });

                $addresses_string = array_map(function ($address) {
                    return $address['string'];
                }, $addresses_filtered);

                $message->headers->{$header} = implode(', ', $addresses_string);
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

        list($contains, $rules) = self::get_configuration($rcmail);

        // Contains preference
        $compose_contains = new html_inputfield(array('id' => self::PREFERENCE_COMPOSE_CONTAINS, 'name' => self::PREFERENCE_COMPOSE_CONTAINS));

        // Subject preference, using global configuration as fallback value
        $rule = isset($rules['to']) ? $rules['to'] : '';

        if (strpos($rule, 'o') !== false)
            $compose_subject_value = 'always';
        else if (strpos($rule, 'd') !== false)
            $compose_subject_value = 'domain';
        else if (strpos($rule, 'e') !== false)
            $compose_subject_value = 'exact';
        else
            $compose_subject_value = 'never';

        $compose_subject = new html_select(array('id' => self::PREFERENCE_COMPOSE_SUBJECT, 'name' => self::PREFERENCE_COMPOSE_SUBJECT));
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_never'), 'never');
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_exact'), 'exact');
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_domain'), 'domain');
        $compose_subject->add(self::get_text($rcmail, 'preference_compose_subject_always'), 'always');

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
            $address = self::get_state($compose_id);

            if ($address !== null) {
                $rcmail = rcmail::get_instance();
                $rcmail->output->add_footer('<script type="text/javascript">rcmail.addEventListener(\'init\', function (event) { customFromToggle(event, ' . json_encode($address) . '); });</script>');
            }

            $this->include_script('custom_from.js');
        }

        if ($template === 'compose' || $template === 'settings') {
            $this->include_stylesheet($this->local_skin_path() . '/custom_from.css');
        }

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
            return array('', array());
        }

        $use_preference = !$rcmail->config->get('custom_from_preference_disable', false);

        // Read "contains" parameter from global configuration & preferences if allowed
        $contains = $rcmail->config->get('custom_from_compose_contains', '');

        if ($use_preference) {
            $contains = self::get_preference($rcmail, self::PREFERENCE_COMPOSE_CONTAINS, $contains);
        }

        // Read "rules" parameter from global configuration & preferences if allowed
        $rules_config = $rcmail->config->get('custom_from_header_rules');
        $rules = array();

        if ($rules_config !== null) {
            foreach (explode(';', $rules_config) as $pair) {
                $fields = explode('=', $pair, 2);

                if (count($fields) === 2) {
                    $rules[strtolower(trim($fields[0]))] = strtolower(trim($fields[1]));
                }
            }
        }

        if ($use_preference) {
            $subject = self::get_preference($rcmail, self::PREFERENCE_COMPOSE_SUBJECT, '');
            $subject_rules = array('always' => 'deo', 'domain' => 'de', 'exact' => 'e', 'never' => '');

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

        return array($contains, $rules);
    }

    private static function get_preference(rcmail $rcmail, string $key, string $default)
    {
        return isset($rcmail->user->prefs[$key]) ? $rcmail->user->prefs[$key] : $default;
    }

    private static function get_state($compose_id)
    {
        return $compose_id !== null && isset($_SESSION['custom_from_' . $compose_id]) ? $_SESSION['custom_from_' . $compose_id] : null;
    }

    private static function get_text(rcmail $rcmail, string $key)
    {
        return $rcmail->gettext($key, 'custom_from');
    }

    private static function get_text_quoted(rcmail $rcmail, string $key)
    {
        return rcmail::Q(self::get_text($rcmail, $key));
    }

    private static function set_state($compose_id, $value)
    {
        $_SESSION['custom_from_' . $compose_id] = $value;
    }
}

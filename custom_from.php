<?php

/*
** Plugin custom_from for RoundcubeMail
**
** Description: replace dropdown by textbox to allow "From:" header input
**
** @version 1.6.7
** @license MIT
** @author Remi Caput
** @url https://github.com/r3c/Custom-From
*/

class custom_from extends rcube_plugin
{
    const DEFAULT_HEADER_RULES = 'X-Original-To=deo;To=de;Cc=de;Cci=de;From=de';
    const REPLY_FROM_SETTING = '_custom_from_reply_from';

    /** Plugin options */
    const RECEIVING_EMAIL_OPTION = 'reply_from_receiving_email';
    const RECEIVING_EMAIL_WITH_IDENTITY_OPTION = 'reply_from_receiving_email_with_identity';
    const RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION = 'reply_from_receiving_email_with_default_identity';
    const DEFAULT_IDENTITY_OPTION = 'reply_from_default_identity';

    /** User preferences */
    const PREFERENCE_SECTION = 'custom_from';

    /** Plugin states */
    private static $is_reply = false;
    private static $is_draft = false;
    private static $is_identity = false;

    /**
     ** Initialize plugin.
     */
    public function init()
    {
        $this->add_texts('localization', true);
        $this->add_hook('message_compose_body', array($this, 'message_compose_body'));
        $this->add_hook('render_page', array($this, 'render_page'));
        $this->add_hook('storage_init', array($this, 'storage_init'));
        $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
        $this->add_hook('preferences_list', array($this, 'preferences_list'));
        $this->add_hook('preferences_save', array($this, 'preferences_save'));
        $this->add_hook('template_object_composeheaders', array($this, 'template_object_composeheaders'));
        $this->add_hook('template_object_composebody', array($this, 'template_object_composebody'));
    }

    /**
     ** Override form fields
     */
    public function template_object_composeheaders($attrib)
    {
        $this->load_config();

        $rcmail = rcmail::get_instance();
        $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);
        $compose_data = $_SESSION['compose_data_' . $compose_id] ?? array();
        $msg_uid = '';

        if (isset($compose_data['param']['reply_uid'])) {
            $msg_uid = $compose_data['param']['reply_uid'];
            self::$is_reply = true;
        } elseif (isset($compose_data['param']['draft_uid'])) {
            $msg_uid = $compose_data['param']['draft_uid'];
            self::$is_draft = true;
        } elseif (isset($compose_data['param']['forward_uid'])) {
            $msg_uid = $compose_data['param']['forward_uid'];
        } elseif (isset($compose_data['param']['uid'])) {
            $msg_uid = $compose_data['param']['uid'];
        }

        if (!$rcmail->config->get('custom_from_compose_auto', true) || $msg_uid === '') {
            return $attrib;
        }

        $reply_from = rcmail::get_instance()->user->prefs[self::REPLY_FROM_SETTING] ?? self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION;

        switch ($attrib['part']) {
            case 'from':
                if (self::$is_draft) {
                    $this->compose_draft_from_headers($compose_id, $msg_uid, $reply_from);
                } else {
                    $attrib = $this->compose_from_headers($compose_id, $msg_uid, $reply_from, $attrib);
                }

                break;
            case 'replyto':
            case 'bcc':
                if (self::$is_draft) {
                    break;
                }

                if (!self::get_state($compose_id) && $reply_from !== self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION) {
                    break;
                }

                if ($field_content = $this->override_bcc_or_reply_to_fields($reply_from, $attrib)) {
                    $attrib['content'] = $field_content;
                }

                break;
        }

        return $attrib;
    }

    /**
     * Remove signature from email body when replying to a receiving email.
     */
    public function template_object_composebody($attrib)
    {
        if (!self::$is_reply) {
            return $attrib;
        }

        $reply_from = rcmail::get_instance()->user->prefs[self::REPLY_FROM_SETTING] ?? self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION;
        $rcmail = rcmail::get_instance();

        switch ($reply_from) {
            case self::RECEIVING_EMAIL_OPTION:
                $rcmail->output->set_env('signatures', array());

                break;

            case self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION:
                if (!self::$is_identity) {
                    $rcmail->output->set_env('signatures', array());
                }

                break;

            case self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION:
                $default_identity = $rcmail->user->get_identity();
                $default_identity_id = $default_identity['identity_id'];
                $signatures = $rcmail->output->get_env('signatures');
                $default_signature = $signatures[$default_identity_id] ?? array();
                $replaced = array_fill_keys(array_keys($signatures), $default_signature);

                if (count($default_signature) > 0) {
                    foreach ($rcmail->user->list_identities() as $identity) {
                        $replaced[$identity['identity_id']] = $default_signature;
                    }
                }

                $rcmail->output->set_env('identity', $default_identity);
                $rcmail->output->set_env('signatures', $replaced);

                break;
        }

        return $attrib;
    }


    /**
     ** Enable custom "From:" field for drafts.
     */
    public function compose_draft_from_headers($compose_id, $msg_uid, $reply_from)
    {
        $rcmail = rcmail::get_instance();
        $message = $rcmail->get_storage()->get_message($msg_uid);
        $from = '';
        $identities_emails = array();

        foreach ($rcmail->user->list_identities() as $identity) {
            $identities_emails[] = $identity['email'];
        }

        if ($message->from) {
            $mail_from_address = array_first(rcube_mime::decode_address_list($message->from, null, true, $message->charset));
            $from_email = $mail_from_address['mailto'];
            $from_name = !empty($mail_from_address['name']) ? $mail_from_address['name'] : '';

            if (!in_array($from_email, $identities_emails) || $reply_from === self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION) {
                $from = format_email_recipient($from_email, $from_name);
            }
        }

        if ($from) {
            self::set_state($compose_id, $from);
        }
    }

    /**
     ** Enable custom "From:" field if mail being composed has been sent to an
     ** address that looks like virtual (i.e. not in user identities list).
     */
    public function compose_from_headers($compose_id, $msg_uid, $reply_from, $attrib)
    {
        $rcmail = rcmail::get_instance();
        $message = $rcmail->get_storage()->get_message_headers($msg_uid, null, true);
        $address = null;

        switch ($reply_from) {
            case self::RECEIVING_EMAIL_OPTION:
            case self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION:
            case self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION:
                // Browse headers where addresses will be fetched from
                $recipients = array();
                $rules = self::get_rules($rcmail->config);

                foreach ($rules as $header => $rule) {
                    $addresses = isset($message->{$header}) ? rcube_mime::decode_address_list($message->{$header}, null, false) : array();

                    // Decode recipients and matching rules from retrieved addresses
                    foreach ($addresses as $address) {
                        if (isset($address['mailto'])) {
                            $email = $address['mailto'];

                            $recipients[] = array(
                                'domain' => preg_replace('/^[^@]*@(.*)$/', '$1', $email),
                                'email' => $email,
                                'match_domain' => strpos($rule, 'd') !== false,
                                'match_exact' => strpos($rule, 'e') !== false,
                                'match_other' => strpos($rule, 'o') !== false,
                                'name' => $address['name']
                            );
                        }
                    }
                }

                // Get user identities list
                $identities = array();

                foreach ($rcmail->user->list_identities() as $identity) {
                    $identities[$identity['email']] = array(
                        'domain' => preg_replace('/^[^@]*@(.*)$/', '$1', $identity['email']),
                        'name' => $identity['name']
                    );
                }

                // Find best possible match from recipients and identities
                $address = null;
                $score = 0;

                $should_override_identity = in_array($reply_from, array(
                    self::RECEIVING_EMAIL_OPTION,
                    self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION
                ));

                foreach ($recipients as $recipient) {
                    $email = $recipient['email'];

                    // Relevance score 3: exact match found in identities
                    if ($score < 3 && $recipient['match_exact'] && isset($identities[$email])) {
                        $score = 3;

                        self::$is_identity = true;

                        if ($should_override_identity && $reply_from == self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION) {
                            $address = format_email_recipient($email, rcmail::get_instance()->user->get_identity()['name']);
                        } else if ($should_override_identity) {
                            $address = format_email_recipient($email);
                        }
                    }

                    // Relevance score 2: domain match found in identities
                    if ($score < 2 && $recipient['match_domain']) {
                        foreach ($identities as $identity) {
                            if (strcasecmp($identity['domain'], $recipient['domain']) == 0) {
                                $address = format_email_recipient($email, $identity['name']);
                                $score = 2;
                            }
                        }
                    }

                    // Relevance score 1: no match found
                    if ($score < 1 && $recipient['match_other']) {
                        $address = format_email_recipient($email, $recipient['name']);
                        $score = 1;
                    }
                }

                if ($score > 2 && !$should_override_identity) {
                    break;
                }

                /** Remove autocomplete by JS frontend after page loaded */
                $rcmail->output->set_env('identities', array());

                break;

            case self::DEFAULT_IDENTITY_OPTION:
                $default_identity = $rcmail->user->get_identity();
                $select_from_attrib = array_intersect_key($attrib, array_flip(array('id', 'class', 'style', 'size', 'tabindex'))) + array(
                    'name' => '_from',
                    'onchange' => rcmail_output::JS_OBJECT_NAME . ".change_identity(this)"
                );
                $select_from = new html_select($select_from_attrib);
                $select_from->add(format_email_recipient($default_identity['email'], $default_identity['name']), $default_identity['identity_id']);

                $attrib['content'] = $select_from->show($default_identity['identity_id']);

                break;
        }

        self::set_state($compose_id, $address);

        return $attrib;
    }

    /**
     * Override headers fields from identity (bcc, reply-to), by plugin settings.
     * $attrib needs all because we reading all attributes for HTML field.
     * @param string $reply_from
     * @param array $attrib
     * @return string
     */
    public function override_bcc_or_reply_to_fields($reply_from, $attrib)
    {
        $part = strtolower($attrib['part']);
        $value = '';
        $field_content = '';

        switch ($reply_from) {
            case self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION:
                $default_identity = rcmail::get_instance()->user->get_identity();
                $value = $default_identity[$part] ?? '';

                if ($value === '' && $part == 'replyto') {
                    $value = $default_identity['reply-to'] ?? '';
                }

                /**
                 * The break statement is unnecessary because we are changing the value
                 * of the overridden field to avoid duplication.
                 */
            case self::RECEIVING_EMAIL_OPTION:
                $field_attrib = array_intersect_key($attrib, array_flip(array('id', 'class', 'style', 'cols', 'rows', 'tabindex', 'data-recipient-input'))) + array(
                    'name' => '_' . $part
                );

                // Create a textarea field for overriding Roundcube input fields
                $field = new html_textarea();

                $field_content = $field->show($value, $field_attrib);

                break;
        }

        return $field_content;
    }


    /**
     ** Adds additional headers to supported headers list.
     */
    public function storage_init($params)
    {
        $this->load_config();

        $rcmail = rcmail::get_instance();
        $rules = self::get_rules($rcmail->config);

        foreach (array_keys($rules) as $header) {
            $params['fetch_headers'] = (isset($params['fetch_headers']) && $params['fetch_headers'] !== '' ? $params['fetch_headers'] . ' ' : '') . $header;
        }

        return $params;
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

        $rcmail = rcmail::get_instance();
        $rules = self::get_rules($rcmail->config);
        $is_draft = isset($compose_data['param']['draft_uid']);

        foreach (array_keys($rules) as $header) {
            // Skip "To:" header for drafts
            if ($is_draft && $header === 'to') {
                continue;
            }

            if (!isset($message->headers->{$header})) {
                continue;
            }

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

    public function render_page($params)
    {
        if ($params['template'] === 'compose') {
            $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);
            $address = self::get_state($compose_id);

            if ($address !== null) {
                $rcmail = rcmail::get_instance();
                $rcmail->output->add_footer('<script type="text/javascript">rcmail.addEventListener(\'init\', function (event) { customFromToggle(event, ' . json_encode($address) . '); });</script>');
            }

            $this->include_script('custom_from.js');
            $this->include_stylesheet($this->local_skin_path() . '/custom_from.css');
        }

        return $params;
    }

    /**
     ** Add a plugin section to a settings list
     */
    public function preferences_sections_list($params)
    {
        $section_name = rcmail::get_instance()->gettext('reply_settings_title', 'custom_from');
        $params['list'][self::PREFERENCE_SECTION] = array(
            'id' => self::PREFERENCE_SECTION,
            'section' => $section_name
        );

        $this->include_stylesheet($this->local_skin_path() . '/custom_from.css');

        return $params;
    }

    /**
     ** Add a plugin options to a settings list
     */
    public function preferences_list($params)
    {
        if ($params['section'] !== self::PREFERENCE_SECTION) {
            return $params;
        }

        $rcmail = rcmail::get_instance();

        $options = array(
            self::RECEIVING_EMAIL_OPTION,
            self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION,
            self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION,
            self::DEFAULT_IDENTITY_OPTION
        );

        $reply_from = $rcmail->user->prefs[self::REPLY_FROM_SETTING] ?? self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION;

        if (!in_array($reply_from, $options)) {
            $reply_from = $options[0];
        }

        $select = new html_select(array(
            'name' => self::REPLY_FROM_SETTING,
            'id' => self::REPLY_FROM_SETTING,
            'value' => 1,
        ));

        $select->add(array(
            $rcmail->gettext(self::RECEIVING_EMAIL_OPTION, 'custom_from'),
            $rcmail->gettext(self::RECEIVING_EMAIL_WITH_IDENTITY_OPTION, 'custom_from'),
            $rcmail->gettext(self::RECEIVING_EMAIL_WITH_DEFAULT_IDENTITY_OPTION, 'custom_from'),
            $rcmail->gettext(self::DEFAULT_IDENTITY_OPTION, 'custom_from')
        ), $options);

        $params['blocks'][self::PREFERENCE_SECTION] = array(
            'name' => $rcmail->gettext('custom_from_session_section_title', 'custom_from'),
            'options' => array(
                array(
                    'title' => html::label(self::REPLY_FROM_SETTING, $rcmail->gettext('reply_from_setting_label', 'custom_from')),
                    'content' => $select->show(array($reply_from))
                )
            )
        );

        return $params;
    }

    /**
     ** Save plugin preferences.
     */
    public function preferences_save($args)
    {
        if ($args['section'] === self::PREFERENCE_SECTION) {
            $args['prefs'][self::REPLY_FROM_SETTING] = rcube_utils::get_input_value(self::REPLY_FROM_SETTING, rcube_utils::INPUT_POST);
        }

        return $args;
    }

    private static function emit_error($message)
    {
        rcube::raise_error(array('code' => 500, 'type' => 'php', 'file' => __FILE__, 'line' => __LINE__, 'message' => $message), true, false);
    }

    private static function get_rules($config)
    {
        $headers = array();
        $value = $config->get('custom_from_header_rules', self::DEFAULT_HEADER_RULES);

        foreach (explode(';', $value) as $pair) {
            $fields = explode('=', $pair, 2);

            if (count($fields) === 2) {
                $headers[strtolower(trim($fields[0]))] = strtolower(trim($fields[1]));
            }
        }

        return $headers;
    }

    private static function get_state($compose_id)
    {
        return $compose_id !== null && isset($_SESSION['custom_from_' . $compose_id]) ? $_SESSION['custom_from_' . $compose_id] : null;
    }

    private static function set_state($compose_id, $value)
    {
        $_SESSION['custom_from_' . $compose_id] = $value;
    }
}

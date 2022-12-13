<?php

/*
** Plugin custom_from for RoundcubeMail
**
** Description: replace dropdown by textbox to allow "From:" header input
**
** @version 1.7
** @license MIT
** @author Remi Caput
** @url https://github.com/r3c/Custom-From
*/

class custom_from extends rcube_plugin
{
    const DEFAULT_HEADER_RULES = 'X-Original-To=deo;To=de;Cc=de;Cci=de;From=de';

    /**
     ** Initialize plugin.
     */
    public function init()
    {
        $this->add_texts('localization', true);
        $this->add_hook('message_compose', array($this, 'message_compose'));
        $this->add_hook('message_compose_body', array($this, 'message_compose_body'));
        $this->add_hook('render_page', array($this, 'render_page'));
        $this->add_hook('storage_init', array($this, 'storage_init'));
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
     ** Enable custom "From:" field if mail being composed has been sent to an
     ** address that looks like virtual (i.e. not in user identities list).
     */
    public function message_compose($params)
    {
        $this->load_config();

        $address = null;
        $compose_id = $params['id'];
        $rcmail = rcmail::get_instance();

        if (isset($params['param']['reply_uid'])) {
            $message_uid = $params['param']['reply_uid'];
        } elseif (isset($params['param']['forward_uid'])) {
            $message_uid = $params['param']['forward_uid'];
        } elseif (isset($params['param']['uid'])) {
            $message_uid = $params['param']['uid'];
        } else {
            $message_uid = null;
        }

        if ($rcmail->config->get('custom_from_compose_auto', true) && $message_uid !== null) {
            $storage = $rcmail->get_storage();
            $message = $storage->get_message($message_uid);

            if ($message !== null) {
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

                foreach ($recipients as $recipient) {
                    $email = $recipient['email'];

                    // Relevance score 3: exact match found in identities
                    if ($score < 3 && $recipient['match_exact'] && isset($identities[$email])) {
                        $address = null;
                        $score = 3;
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
            }
        }

        self::set_state($compose_id, $address);
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

        foreach (array_keys($rules) as $header) {
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

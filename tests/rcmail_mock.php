<?php

function format_email_recipient($email)
{
	return $email;
}

class rcmail
{
	private static rcmail $instance;

	public static function get_instance()
	{
		return self::$instance;
	}

	public static function mock()
	{
		self::$instance = new self();

		return self::$instance;
	}

	public $config = null;
	public $messages = array();
	public $user = null;

	public function get_message($id)
	{
		return $this->messages[$id];
	}

	public function get_storage()
	{
		return $this;
	}

	public function mock_config($config_values)
	{
		$this->config = new rcube_config($config_values);
	}

	public function mock_message($id, $message_fields)
	{
		$this->messages[$id] = new rcube_message($message_fields);
	}

	public function mock_user($identities, $prefs)
	{
		$this->user = new rcube_user($identities, $prefs);
	}
}

class rcube_config
{
	private array $values;

	public function __construct($values)
	{
		$this->values = $values;
	}

	public function get($name, $def = null)
	{
		return isset($this->values[$name]) ? $this->values[$name] : $def;
	}
}

class rcube_message
{
	public string $to;

	public function __construct($fields)
	{
		$this->to = $fields['to'];
	}
}

class rcube_mime
{
	public static function decode_address_list($address)
	{
		return array(array('mailto' => $address, 'name' => $address));
	}
}

class rcube_plugin
{
	public function add_texts() {}
	public function add_hook() {}
	public function load_config() {}
}

class rcube_user
{
	public array $prefs;

	private array $identities;

	public function __construct($identities, $prefs)
	{
		$this->identities = $identities;
		$this->prefs = $prefs;
	}

	public function list_identities()
	{
		return $this->identities;
	}
}

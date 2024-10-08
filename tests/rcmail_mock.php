<?php

function format_email_recipient($email, $name)
{
	return $name . ' <' . $email . '>';
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
	public array $fields;

	public function __construct($fields)
	{
		$this->fields = $fields;
	}

	public function get($name)
	{
		return isset($this->fields[$name]) ? $this->fields[$name] : null;
	}
}

class rcube_mime
{
	public static function decode_address_list($input)
	{
		return preg_match('/(.*) <(.*)>/', $input, $match) === 1
			? array(array('mailto' => $match[2], 'name' => $match[1]))
			: array(array('mailto' => $input, 'name' => $input));
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

class rcube_utils
{
	public const INPUT_GET = 1;

	private static $input_values = array();

	public static function get_input_value($name, $mode)
	{
		return $mode === self::INPUT_GET ? self::$input_values[$name] : null;
	}

	public static function mock_input_value($name, $value)
	{
		self::$input_values[$name] = $value;
	}
}

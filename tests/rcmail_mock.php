<?php

class rcmail
{
	private static rcmail | null $instance = null;

	public static function get_instance()
	{
		return self::$instance;
	}

	public static function mock_instance(array $config_values, array $user_prefs)
	{
		$rcmail = new self();
		$rcmail->config = new rcube_config($config_values);
		$rcmail->user = new rcube_user($user_prefs);

		self::$instance = $rcmail;
	}

	public $config;
	public $user;
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

class rcube_user
{
	public array $prefs;

	public function __construct($prefs)
	{
		$this->prefs = $prefs;
	}
}

class rcube_plugin
{
	public function add_texts() {}
	public function add_hook() {}
	public function load_config() {}
}

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require __DIR__ . '/rcmail_mock.php';
require __DIR__ . '/../custom_from.php';

final class CustomFromTest extends TestCase
{
    const CONTAINS = 'custom_from_compose_contains';
    const DISABLE = 'custom_from_preference_disable';
    const RULES = 'custom_from_header_rules';
    const SUBJECT = 'custom_from_compose_subject';

    public static function storage_init_should_fetch_headers_provider(): array
    {
        return array(
            array(
                array('custom_from_compose_auto' => false),
                array(),
                ''
            ),
            array(
                array('custom_from_compose_auto' => true),
                array(),
                'bcc cc from to x-original-to'
            ),
            array(
                array(self::RULES => 'header1=a;header2=b'),
                array(),
                'header1 header2'
            ),
            array(
                array(self::RULES => 'header=a', self::DISABLE => true),
                array(self::SUBJECT => 'always'),
                'header'
            ),
            array(
                array(self::RULES => 'header=a', self::DISABLE => false),
                array(self::SUBJECT => 'always'),
                'header bcc cc from to x-original-to'
            )
        );
    }

    #[DataProvider('storage_init_should_fetch_headers_provider')]
    public function test_storage_init_should_fetch_headers($config_values, $user_prefs, $expected): void
    {
        $rcmail = rcmail::mock();
        $rcmail->mock_config($config_values);
        $rcmail->mock_user(array(), $user_prefs);

        $plugin = self::create_plugin();
        $params = $plugin->storage_init(array());

        $this->assertSame($params['fetch_headers'], $expected);
    }

    public static function message_compose_should_set_state_provider(): array
    {
        return array(
            // Subject rule "exact" should match address exactly
            array(
                array('to' => 'alice@primary.ext'),
                array(self::RULES => 'to=e'),
                array(),
                array('email' => null, 'id' => '1')
            ),
            // Subject rule "exact" shouldn't match suffix
            array(
                array('to' => 'alice+suffix@primary.ext'),
                array(self::RULES => 'to=e'),
                array(),
                null,
            ),
            // Subject rule "prefix" should match address exactly
            array(
                array('to' => 'alice@primary.ext'),
                array(),
                array(),
                array('email' => null, 'id' => '1')
            ),
            // Subject rule "prefix" should match address by prefix
            array(
                array('to' => 'alice+suffix@primary.ext'),
                array(),
                array(),
                array('email' => 'alice+suffix@primary.ext', 'id' => '1')
            ),
            // Subject rule "prefix" should not match different user
            array(
                array('to' => 'carl+suffix@primary.ext'),
                array(),
                array(),
                null,
            ),
            // Subject rule "domain" on custom header should match address by domain
            array(
                array('to' => 'unknown@primary.ext', 'x-custom' => 'unknown@secondary.ext'),
                array(self::RULES => 'x-custom=d'),
                array(),
                array('email' => 'unknown@secondary.ext', 'id' => '3')
            ),
            // Subject rule "domain" should not match different domain
            array(
                array('to' => 'unknown@unknown.ext'),
                array(self::RULES => 'to=d'),
                array(),
                null,
            ),
            // Subject rule "other" should match anything
            array(
                array('to' => 'unknown@unknown.ext'),
                array(self::RULES => 'to=o'),
                array(),
                array('email' => 'unknown@unknown.ext', 'id' => '2')
            ),
            // Subject rule is overridden by user prefrences
            array(
                array('to' => 'unknown@secondary.ext'),
                array(self::RULES => 'to=e'),
                array(self::SUBJECT => 'domain'),
                array('email' => 'unknown@secondary.ext', 'id' => '3')
            ),
            // Contains constraint in configuration options matches address
            array(
                array('to' => 'alice+match@primary.ext'),
                array(self::CONTAINS => 'match'),
                array(self::SUBJECT => 'domain'),
                array('email' => 'alice+match@primary.ext', 'id' => '1')
            ),
            // Contains constraint in configuration options rejects no match
            array(
                array('to' => 'alice+other@primary.ext'),
                array(self::CONTAINS => 'match'),
                array(self::SUBJECT => 'domain'),
                null
            ),
            // Contains constraint in user preferences rejects no match
            array(
                array('to' => 'alice+other@primary.ext'),
                array(self::CONTAINS => 'other'),
                array(self::CONTAINS => 'match', self::SUBJECT => 'always'),
                null
            ),
        );
    }

    #[DataProvider('message_compose_should_set_state_provider')]
    public function test_message_compose_should_set_state($message, $config_values, $user_prefs, $expected): void
    {
        $identity1 = array('identity_id' => '1', 'email' => 'alice@primary.ext', 'name' => 'Alice', 'standard' => '0');
        $identity2 = array('identity_id' => '2', 'email' => 'bob@primary.ext', 'name' => 'Bob', 'standard' => '1');
        $identity3 = array('identity_id' => '3', 'email' => 'carl@secondary.ext', 'name' => 'Carl', 'standard' => '0');

        $compose_id = '17';
        $message_id = '42';
        $rcmail = rcmail::mock();
        $rcmail->mock_config($config_values);
        $rcmail->mock_message($message_id, $message);
        $rcmail->mock_user(array($identity1, $identity2, $identity3), $user_prefs);

        $plugin = self::create_plugin();
        $plugin->message_compose(array('id' => $compose_id, 'param' => array('uid' => $message_id)));

        $this->assertSame($_SESSION["custom_from_$compose_id"], $expected);
    }

    private static function create_plugin()
    {
        $plugin = new custom_from(null);
        $plugin->init();

        return $plugin;
    }
}

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
    const IDENTITY = 'custom_from_compose_identity';
    const RULES = 'custom_from_header_rules';
    const SUBJECT = 'custom_from_compose_subject';

    public static function identity_select_should_select_matched_identity_provider(): array
    {
        return array(
            array('1', 0),
            array('2', 1),
            array('3', null)
        );
    }

    #[DataProvider('identity_select_should_select_matched_identity_provider')]
    public function test_identity_select_should_select_matched_identity($identity, $expected): void
    {
        $rcmail = rcmail::mock();
        $rcmail->mock_config(array());
        $rcmail->mock_user(array(), array());

        $plugin = self::create_plugin();

        rcube_utils::mock_input_value('_id', '42');

        self::set_state($plugin, '42', $identity, null);

        $params = $plugin->identity_select(array('identities' => array(
            array('identity_id' => '1'),
            array('identity_id' => '2')
        )));

        if ($expected !== null) {
            $this->assertSame($params['selected'], $expected);
        } else {
            $this->assertSame(isset($params['selected']), false);
        }
    }

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
                '1',
                null
            ),
            // Subject rule "exact" shouldn't match suffix
            array(
                array('to' => 'alice+suffix@primary.ext'),
                array(self::RULES => 'to=e'),
                array(),
                null,
                null,
            ),
            // Subject rule "prefix" should match address exactly
            array(
                array('to' => 'alice@primary.ext'),
                array(),
                array(),
                '1',
                null
            ),
            // Subject rule "prefix" should match address by prefix
            array(
                array('to' => 'alice+suffix@primary.ext'),
                array(),
                array(),
                '1',
                'Alice <alice+suffix@primary.ext>'
            ),
            // Subject rule "prefix" should not match different user
            array(
                array('to' => 'carl+suffix@primary.ext'),
                array(),
                array(),
                null,
                null,
            ),
            // Subject rule "domain" on custom header should match address by domain
            array(
                array('to' => 'unknown@primary.ext', 'x-custom' => 'unknown@secondary.ext'),
                array(self::RULES => 'x-custom=d'),
                array(),
                '3',
                'Carl <unknown@secondary.ext>'
            ),
            // Subject rule "domain" should not match different domain
            array(
                array('to' => 'unknown@unknown.ext'),
                array(self::RULES => 'to=d'),
                array(),
                null,
                null,
            ),
            // Subject rule "other" should match anything
            array(
                array('to' => 'unknown@unknown.ext'),
                array(self::RULES => 'to=o'),
                array(),
                '2',
                'Bob <unknown@unknown.ext>'
            ),
            // Subject rule is overridden by user prefrences
            array(
                array('to' => 'unknown@secondary.ext'),
                array(self::RULES => 'to=e'),
                array(self::SUBJECT => 'domain'),
                '3',
                'Carl <unknown@secondary.ext>'
            ),
            // Contains constraint in configuration options matches address
            array(
                array('to' => 'alice+match@primary.ext'),
                array(self::CONTAINS => 'match'),
                array(self::SUBJECT => 'domain'),
                '1',
                'Alice <alice+match@primary.ext>'
            ),
            // Contains constraint in configuration options rejects no match
            array(
                array('to' => 'alice+other@primary.ext'),
                array(self::CONTAINS => 'match'),
                array(self::SUBJECT => 'domain'),
                null,
                null
            ),
            // Contains constraint in user preferences rejects no match
            array(
                array('to' => 'alice+other@primary.ext'),
                array(self::CONTAINS => 'other'),
                array(self::CONTAINS => 'match', self::SUBJECT => 'always'),
                null,
                null
            ),
            // Identity behavior "default" returns matched identity with no sender on exact match
            array(
                array('to' => 'carl@secondary.ext'),
                array(),
                array(self::SUBJECT => 'always'),
                '3',
                null
            ),
            // Identity behavior "default" returns matched identity and sender with identity name on domain match
            array(
                array('to' => 'unknown@secondary.ext'),
                array(),
                array(self::SUBJECT => 'domain'),
                '3',
                'Carl <unknown@secondary.ext>'
            ),
            // Identity behavior "loose" returns matched identity and sender with identity name on prefix match
            array(
                array('to' => 'SomeName <carl+suffix@secondary.ext>'),
                array(),
                array(self::IDENTITY => 'loose', self::SUBJECT => 'prefix'),
                '3',
                'Carl <carl+suffix@secondary.ext>'
            ),
            // Identity behavior "exact" returns matched identity with no sender on exact match
            array(
                array('to' => 'carl@secondary.ext'),
                array(self::IDENTITY => 'exact'),
                array(self::SUBJECT => 'always'),
                '3',
                null
            ),
            // Identity behavior "exact" returns no identity and sender with recipient name on prefix match
            array(
                array('to' => 'SomeName <carl+suffix@secondary.ext>'),
                array(),
                array(self::IDENTITY => 'exact', self::SUBJECT => 'prefix'),
                null,
                'SomeName <carl+suffix@secondary.ext>'
            )
        );
    }

    #[DataProvider('message_compose_should_set_state_provider')]
    public function test_message_compose_should_set_state($message, $config_values, $user_prefs, $expected_identity, $expected_sender): void
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

        $state = self::get_state($plugin, $compose_id);

        $this->assertSame($state, array($expected_identity, $expected_sender));
    }

    private static function create_plugin()
    {
        $plugin = new custom_from(null);
        $plugin->init();

        return $plugin;
    }

    private static function get_state($plugin, $compose_id)
    {
        $class = new ReflectionClass($plugin);
        $method = $class->getMethod('get_state');

        return $method->invokeArgs(null, array($compose_id));
    }

    private static function set_state($plugin, $compose_id, $identity, $sender)
    {
        $class = new ReflectionClass($plugin);
        $method = $class->getMethod('set_state');

        return $method->invokeArgs(null, array($compose_id, $identity, $sender));
    }
}

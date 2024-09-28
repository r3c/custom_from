<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require __DIR__ . '/rcmail_mock.php';
require __DIR__ . '/../custom_from.php';

final class CustomFromTest extends TestCase
{
    public static function storage_init_should_fetch_headers_provider(): array
    {
        return array(
            array(
                array(
                    'custom_from_compose_auto' => false
                ),
                array(),
                ''
            ),
            array(
                array(
                    'custom_from_compose_auto' => true
                ),
                array(),
                'bcc cc from to x-original-to'
            ),
            array(
                array(
                    'custom_from_header_rules' => 'header1=a;header2=b'
                ),
                array(),
                'header1 header2'
            ),
            array(
                array(
                    'custom_from_header_rules' => 'header=a',
                    'custom_from_preference_disable' => true
                ),
                array(
                    'custom_from_compose_subject' => 'always'
                ),
                'header'
            ),
            array(
                array(
                    'custom_from_header_rules' => 'header=a',
                    'custom_from_preference_disable' => false
                ),
                array(
                    'custom_from_compose_subject' => 'always'
                ),
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
            array(
                array('to' => 'primary@domain.ext'),
                null,
            ),
            array(
                array('to' => 'primary+suffix@domain.ext'),
                'primary+suffix@domain.ext',
            )
        );
    }

    #[DataProvider('message_compose_should_set_state_provider')]
    public function test_message_compose_should_set_state($message, $expected): void
    {
        $identity1 = array('email' => 'primary@domain.ext', 'name' => 'Primary', 'standard' => '1');
        $identity2 = array('email' => 'secondary@domain.ext', 'name' => 'Secondary', 'standard' => '0');

        $id = 17;
        $uid = '42';
        $rcmail = rcmail::mock();
        $rcmail->mock_config(array());
        $rcmail->mock_message($uid, $message);
        $rcmail->mock_user(array($identity1, $identity2), array());

        $plugin = self::create_plugin();
        $plugin->message_compose(array('id' => $id, 'param' => array('uid' => $uid)));

        $this->assertSame($_SESSION["custom_from_$id"], $expected);
    }

    private static function create_plugin()
    {
        $plugin = new custom_from(null);
        $plugin->init();

        return $plugin;
    }
}

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
        $plugin = self::create_plugin($config_values, $user_prefs);
        $params = $plugin->storage_init(array());

        $this->assertSame($params['fetch_headers'], $expected);
    }

    private static function create_plugin($config_values, $user_prefs)
    {
        rcmail::mock_instance($config_values, $user_prefs);

        $plugin = new custom_from(null);
        $plugin->init();

        return $plugin;
    }
}

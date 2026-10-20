<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Model\Config;
use PHPUnit\Framework\TestCase;

class ConfigInterfaceTest extends TestCase
{
    public function testDeclaresExactlyTheSharedGetters(): void
    {
        $reflection = new \ReflectionClass(ConfigInterface::class);
        $methods = array_map(static fn (\ReflectionMethod $m) => $m->getName(), $reflection->getMethods());
        sort($methods);

        self::assertSame(['getModel', 'isConfigured', 'isEnabled'], $methods);
        self::assertSame('bool', (string)$reflection->getMethod('isEnabled')->getReturnType());
        self::assertSame('bool', (string)$reflection->getMethod('isConfigured')->getReturnType());
        self::assertSame('string', (string)$reflection->getMethod('getModel')->getReturnType());
        self::assertStringContainsString('@api', (string)$reflection->getDocComment());
    }

    public function testNoMethodExposesTheKey(): void
    {
        foreach ((new \ReflectionClass(ConfigInterface::class))->getMethods() as $method) {
            self::assertStringNotContainsStringIgnoringCase('key', $method->getName());
        }
    }

    public function testDiXmlPrefersModelConfig(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        $preferences = [];
        foreach ($xml->preference as $preference) {
            $preferences[(string)$preference['for']] = (string)$preference['type'];
        }

        self::assertSame(Config::class, $preferences[ConfigInterface::class] ?? null);
    }

    public function testDiXmlMarksApiKeySensitive(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        $items = $xml->xpath(
            "//type[@name='Magento\\Config\\Model\\Config\\TypePool']/arguments/argument[@name='sensitive']"
            . "/item[@name='dmlab_typesafe/general/api_key']"
        );

        self::assertCount(1, $items);
        self::assertSame('1', (string)$items[0]);
    }
}

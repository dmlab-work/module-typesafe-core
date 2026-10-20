<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Console;

use DmLab\TypeSafeCore\Console\Command\PingCommand;
use DmLab\TypeSafeCore\Console\Command\UsageCommand;
use PHPUnit\Framework\TestCase;

class CommandRegistrationTest extends TestCase
{
    private function di(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        return $xml;
    }

    /**
     * @param string $type
     * @return array<string, string>
     */
    private function objectArguments(string $type): array
    {
        $arguments = [];
        foreach ($this->di()->xpath(sprintf('/config/type[@name="%s"]/arguments/argument', $type)) as $argument) {
            $arguments[(string)$argument['name']] = trim((string)$argument);
        }

        return $arguments;
    }

    public function testCommandsAreRegistered(): void
    {
        $items = [];
        $path = '/config/type[@name="Magento\Framework\Console\CommandListInterface"]'
            . '/arguments/argument[@name="commands"]/item';
        foreach ($this->di()->xpath($path) as $item) {
            $items[(string)$item['name']] = trim((string)$item);
        }

        $this->assertSame(
            ['dmlab_typesafe_ping' => PingCommand::class, 'dmlab_typesafe_usage' => UsageCommand::class],
            $items
        );
    }

    public function testCommandsGetProxies(): void
    {
        $this->assertSame(
            [
                'config' => 'DmLab\TypeSafeCore\Model\Config\Proxy',
                'modelLister' => 'DmLab\TypeSafeCore\Model\Connection\ModelLister\Proxy',
            ],
            $this->objectArguments(PingCommand::class)
        );
        $this->assertSame(
            [
                'usageLedger' => 'DmLab\TypeSafeCore\Model\Usage\UsageLedger\Proxy',
                'config' => 'DmLab\TypeSafeCore\Model\Config\Proxy',
            ],
            $this->objectArguments(UsageCommand::class)
        );
    }

    public function testProxiesMatchConstructorTypes(): void
    {
        foreach ([PingCommand::class, UsageCommand::class] as $command) {
            $parameters = [];
            foreach ((new \ReflectionClass($command))->getConstructor()->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = (string)$parameter->getType();
            }
            foreach ($this->objectArguments($command) as $name => $proxy) {
                $this->assertArrayHasKey($name, $parameters);
                $this->assertTrue(class_exists($proxy), $proxy);
                $this->assertTrue(is_a($proxy, $parameters[$name], true), $proxy);
            }
        }
    }
}

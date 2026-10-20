<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Console\Command;

use DmLab\TypeSafeCore\Console\Command\PingCommand;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Connection\ModelLister;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PingCommandTest extends TestCase
{
    private function tester(ModelLister $lister, bool $enabled = true): CommandTester
    {
        $config = $this->createStub(Config::class);
        $config->method('getBaseUrl')->willReturn('https://api.example.test');
        $config->method('getModel')->willReturn('jev-1.13.0');
        $config->method('isEnabled')->willReturn($enabled);

        return new CommandTester(new PingCommand($config, $lister));
    }

    public function testNameIsRegistered(): void
    {
        $command = new PingCommand($this->createStub(Config::class), $this->createStub(ModelLister::class));

        $this->assertSame('dmlab:typesafe:ping', $command->getName());
    }

    public function testPrintsSettingsAndModels(): void
    {
        $lister = $this->createStub(ModelLister::class);
        $lister->method('listModels')->willReturn(['jev-latest', 'jev-preview']);
        $tester = $this->tester($lister, false);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(
            "Base URL: https://api.example.test\nConfigured model: jev-1.13.0\nEnabled: No\n"
            . "OK — models: jev-latest, jev-preview\n"
            . "The list shows aliases only; pinned versions such as jev-1.13.0 are accepted too.\n",
            $tester->getDisplay(true)
        );
    }

    public function testListedModelHasNoNote(): void
    {
        $lister = $this->createStub(ModelLister::class);
        $lister->method('listModels')->willReturn(['jev-1.13.0', 'jev-latest']);
        $tester = $this->tester($lister);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringEndsWith("OK — models: jev-1.13.0, jev-latest\n", $tester->getDisplay(true));
    }

    public function testAuthenticationErrorExitsOne(): void
    {
        $lister = $this->createStub(ModelLister::class);
        $lister->method('listModels')
            ->willThrowException(new AuthenticationException(__('TypeSafe rejected the API key (HTTP %1).', 401)));
        $tester = $this->tester($lister);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('Enabled: Yes', $display);
        $this->assertStringContainsString('TypeSafe rejected the API key (HTTP 401).', $display);
        $this->assertStringNotContainsString('OK', $display);
    }

    public function testNotConfiguredExitsOne(): void
    {
        $lister = $this->createStub(ModelLister::class);
        $lister->method('listModels')
            ->willThrowException(new NotConfiguredException(__('TypeSafe API key is not set.')));
        $tester = $this->tester($lister);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('TypeSafe API key is not set.', $tester->getDisplay(true));
    }
}

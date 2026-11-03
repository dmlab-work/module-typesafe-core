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
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PingCommandTest extends TestCase
{
    /** @var array<string, array|\Throwable> code => listModels() result */
    private array $results = [];

    /** @var string[] codes whose models were listed */
    private array $listed = [];

    private function tester(bool $enabled = true, string $active = 'typesafe'): CommandTester
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn (string $path) => [
            'dmlab_typesafe/general/base_url' => 'https://api.example.test',
            'dmlab_typesafe/anthropic/base_url' => 'https://proxy.example.test',
        ][$path] ?? null);
        $settings = new ProviderSettings($scopeConfig);

        $providers = [];
        foreach (['typesafe' => 'TypeSafe', 'anthropic' => 'Anthropic API'] as $code => $label) {
            $provider = $this->createStub(FakeProvider::class);
            $provider->method('getCode')->willReturn($code);
            $provider->method('getLabel')->willReturn($label);
            $provider->method('getModel')->willReturn($code === 'typesafe' ? 'jev-1.13.0' : 'claude-haiku-5-5');
            $provider->method('listModels')->willReturnCallback(function () use ($code): array {
                $this->listed[] = $code;
                $result = $this->results[$code] ?? [];
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });
            $providers[$code] = $provider;
        }

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getProvider')->willReturn($active);

        return new CommandTester(new PingCommand($config, new ProviderPool($settings, $providers), $settings));
    }

    public function testNameAndProviderOption(): void
    {
        $command = new PingCommand(
            $this->createStub(Config::class),
            $this->createStub(ProviderPool::class),
            $this->createStub(ProviderSettings::class)
        );

        $this->assertSame('dmlab:typesafe:ping', $command->getName());
        $this->assertTrue($command->getDefinition()->getOption(PingCommand::OPTION_PROVIDER)->isValueRequired());
    }

    public function testDefaultsToTheActiveProvider(): void
    {
        $this->results['typesafe'] = ['jev-latest', 'jev-preview'];
        $tester = $this->tester(false);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame(['typesafe'], $this->listed);
        $this->assertSame(
            "Provider: TypeSafe (typesafe)\nBase URL: https://api.example.test\nConfigured model: jev-1.13.0\n"
            . "Enabled: No\nOK — models: jev-latest, jev-preview\n"
            . "The list shows aliases only; pinned versions such as jev-1.13.0 are accepted too.\n",
            $tester->getDisplay(true)
        );
    }

    public function testProviderOptionPingsThatProvider(): void
    {
        $this->results['anthropic'] = ['claude-sonnet-5-5'];
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--provider' => 'anthropic']));
        $this->assertSame(['anthropic'], $this->listed);
        $this->assertSame(
            "Provider: Anthropic API (anthropic), not active\nBase URL: https://proxy.example.test\n"
            . "Configured model: claude-haiku-5-5\nEnabled: Yes\nOK — models: claude-sonnet-5-5\n"
            . "The configured model is not in the list; check its id.\n",
            $tester->getDisplay(true)
        );
    }

    public function testActiveNonTypeSafeProviderIsTheDefault(): void
    {
        $this->results['anthropic'] = ['claude-haiku-5-5'];
        $tester = $this->tester(true, 'anthropic');

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay(true);
        $this->assertStringStartsWith("Provider: Anthropic API (anthropic)\n", $display);
        $this->assertStringEndsWith("OK — models: claude-haiku-5-5\n", $display);
    }

    public function testUnknownProviderExitsOne(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--provider' => 'nope']));
        $this->assertSame("Unknown AI provider \"nope\".\n", $tester->getDisplay(true));
        $this->assertSame([], $this->listed);
    }

    public function testAuthenticationErrorExitsOne(): void
    {
        $this->results['typesafe'] = new AuthenticationException(
            __('%1 rejected the API key (HTTP %2).', 'TypeSafe', 401)
        );
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay(true);
        $this->assertStringContainsString('Enabled: Yes', $display);
        $this->assertStringContainsString('TypeSafe rejected the API key (HTTP 401).', $display);
        $this->assertStringNotContainsString('OK', $display);
    }

    public function testNotConfiguredExitsOne(): void
    {
        $this->results['typesafe'] = new NotConfiguredException(__('%1 API key is not set.', 'TypeSafe'));
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('TypeSafe API key is not set.', $tester->getDisplay(true));
    }
}

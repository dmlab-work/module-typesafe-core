<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function config(array $values): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')
            ->willReturnCallback(static fn (string $path) => (bool)($values[$path] ?? false));

        return new Config($scopeConfig);
    }

    public function testImplementsConfigInterface(): void
    {
        self::assertInstanceOf(ConfigInterface::class, $this->config([]));
    }

    public function testDefaultsWhenUnset(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isEnabled());
        self::assertFalse($config->isConfigured());
        self::assertSame('', $config->getApiKey());
        self::assertSame('jev-1.13.0', $config->getModel());
        self::assertSame('https://api.typesafe.ai', $config->getBaseUrl());
        self::assertSame(20, $config->getTimeout());
        self::assertSame(4, $config->getConcurrency());
        self::assertSame(4, $config->getMaxAttempts());
        self::assertSame(0, $config->getMonthlyTokenBudget());
        self::assertSame(30, $config->getCacheTtlDays());
    }

    /**
     * The config.xml backend model decrypts on load, so the value must not be decrypted again.
     */
    public function testReturnsTheLoadedKeyTrimmed(): void
    {
        self::assertSame('ts-key', $this->config([Config::XML_PATH_API_KEY => '  ts-key  '])->getApiKey());
    }

    public function testConfigXmlDecryptsTheKeyOnLoad(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        $node = $xml->xpath('/config/default/dmlab_typesafe/general/api_key')[0] ?? null;

        self::assertNotNull($node);
        self::assertSame('Magento\Config\Model\Config\Backend\Encrypted', (string)$node['backend_model']);
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function clampProvider(): array
    {
        return [
            'timeout low' => [Config::XML_PATH_TIMEOUT, '0', 1],
            'timeout high' => [Config::XML_PATH_TIMEOUT, '500', 120],
            'timeout ok' => [Config::XML_PATH_TIMEOUT, '45', 45],
            'concurrency low' => [Config::XML_PATH_CONCURRENCY, '-3', 1],
            'concurrency high' => [Config::XML_PATH_CONCURRENCY, '99', 16],
            'attempts low' => [Config::XML_PATH_MAX_ATTEMPTS, '0', 1],
            'attempts high' => [Config::XML_PATH_MAX_ATTEMPTS, '11', 10],
            'budget negative' => [Config::XML_PATH_MONTHLY_TOKEN_BUDGET, '-5', 0],
            'budget ok' => [Config::XML_PATH_MONTHLY_TOKEN_BUDGET, '1000000', 1000000],
            'ttl negative' => [Config::XML_PATH_CACHE_TTL_DAYS, '-1', 0],
            'ttl zero' => [Config::XML_PATH_CACHE_TTL_DAYS, '0', 0],
        ];
    }

    #[DataProvider('clampProvider')]
    public function testClamping(string $path, string $stored, int $expected): void
    {
        $config = $this->config([$path => $stored]);
        $getter = match ($path) {
            Config::XML_PATH_TIMEOUT => $config->getTimeout(...),
            Config::XML_PATH_CONCURRENCY => $config->getConcurrency(...),
            Config::XML_PATH_MAX_ATTEMPTS => $config->getMaxAttempts(...),
            Config::XML_PATH_MONTHLY_TOKEN_BUDGET => $config->getMonthlyTokenBudget(...),
            Config::XML_PATH_CACHE_TTL_DAYS => $config->getCacheTtlDays(...),
        };

        self::assertSame($expected, $getter());
    }

    public function testNonNumericLimitFallsBackToDefault(): void
    {
        self::assertSame(20, $this->config([Config::XML_PATH_TIMEOUT => 'abc'])->getTimeout());
    }

    public function testBaseUrlTrailingSlashStripped(): void
    {
        self::assertSame(
            'https://example.test/api',
            $this->config([Config::XML_PATH_BASE_URL => ' https://example.test/api// '])->getBaseUrl()
        );
    }

    public function testModelTrimmedAndEmptyFallsBackToDefault(): void
    {
        self::assertSame('jev-1.14.0', $this->config([Config::XML_PATH_MODEL => ' jev-1.14.0 '])->getModel());
        self::assertSame('jev-1.13.0', $this->config([Config::XML_PATH_MODEL => '   '])->getModel());
    }

    public function testIsConfiguredFalseWhenDisabled(): void
    {
        $config = $this->config(
            [Config::XML_PATH_ENABLED => '0', Config::XML_PATH_API_KEY => 'ts-key']
        );

        self::assertFalse($config->isConfigured());
    }

    public function testIsConfiguredFalseWhenEnabledWithEmptyOrWhitespaceKey(): void
    {
        self::assertFalse($this->config([Config::XML_PATH_ENABLED => '1'])->isConfigured());
        self::assertFalse($this->config([Config::XML_PATH_ENABLED => '1', Config::XML_PATH_API_KEY => ''])
            ->isConfigured());
        self::assertFalse($this->config(
            [Config::XML_PATH_ENABLED => '1', Config::XML_PATH_API_KEY => "  \t "]
        )->isConfigured());
    }

    public function testIsConfiguredTrueWhenEnabledWithKey(): void
    {
        $config = $this->config(
            [Config::XML_PATH_ENABLED => '1', Config::XML_PATH_API_KEY => 'ts-key']
        );

        self::assertTrue($config->isEnabled());
        self::assertTrue($config->isConfigured());
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider;

use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderSettingsTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function settings(array $values = []): ProviderSettings
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->willReturnCallback(static fn (string $path) => $values[$path] ?? null);

        return new ProviderSettings($scopeConfig);
    }

    public function testActiveCodeDefaultsToTypeSafe(): void
    {
        self::assertSame('typesafe', $this->settings()->getActiveCode());
        self::assertSame('typesafe', $this->settings(['dmlab_typesafe/general/provider' => '  '])->getActiveCode());
        self::assertSame('gemini', $this->settings(['dmlab_typesafe/general/provider' => ' gemini '])->getActiveCode());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function defaultsProvider(): array
    {
        return [
            'typesafe' => ['typesafe', 'https://api.typesafe.ai', 'jev-1.13.0'],
            'openai_compatible' => ['openai_compatible', 'https://api.openai.com/v1', 'gpt-6-luna'],
            'anthropic' => ['anthropic', 'https://api.anthropic.com', 'claude-haiku-5-5'],
            'gemini' => ['gemini', 'https://generativelanguage.googleapis.com', 'gemini-3.5-flash-lite'],
            'di-added' => ['custom', '', ''],
        ];
    }

    #[DataProvider('defaultsProvider')]
    public function testDefaultsWhenUnset(string $code, string $baseUrl, string $model): void
    {
        $settings = $this->settings();

        self::assertSame($baseUrl, $settings->getBaseUrl($code));
        self::assertSame($model, $settings->getModel($code));
        self::assertSame('', $settings->getApiKey($code));
    }

    public function testTypeSafeReadsTheLegacyGeneralPaths(): void
    {
        $settings = $this->settings([
            Config::XML_PATH_API_KEY => ' ts-key ',
            Config::XML_PATH_MODEL => ' jev-1.14.0 ',
            Config::XML_PATH_BASE_URL => 'https://ts.example.test//',
            'dmlab_typesafe/typesafe/api_key' => 'ignored',
        ]);

        self::assertSame('ts-key', $settings->getApiKey('typesafe'));
        self::assertSame('jev-1.14.0', $settings->getModel('typesafe'));
        self::assertSame('https://ts.example.test', $settings->getBaseUrl('typesafe'));
        self::assertSame('dmlab_typesafe/general/api_key', $settings->path('typesafe', 'api_key'));
    }

    public function testOtherProvidersReadTheirOwnGroup(): void
    {
        $settings = $this->settings([
            'dmlab_typesafe/anthropic/api_key' => ' sk-ant ',
            'dmlab_typesafe/anthropic/model' => 'claude-sonnet-5-5',
            'dmlab_typesafe/anthropic/base_url' => 'https://proxy.example.test/',
            Config::XML_PATH_API_KEY => 'ts-key',
        ]);

        self::assertSame('sk-ant', $settings->getApiKey('anthropic'));
        self::assertSame('claude-sonnet-5-5', $settings->getModel('anthropic'));
        self::assertSame('https://proxy.example.test', $settings->getBaseUrl('anthropic'));
        self::assertSame('', $settings->getApiKey('gemini'));
        self::assertSame('dmlab_typesafe/gemini/model', $settings->path('gemini', 'model'));
    }

    public function testSendTemperatureOffUnlessSwitchedOn(): void
    {
        $path = 'dmlab_typesafe/openai_compatible/send_temperature';

        self::assertFalse($this->settings()->isSendTemperature());
        self::assertTrue($this->settings([$path => '1'])->isSendTemperature());
        self::assertFalse($this->settings([$path => '0'])->isSendTemperature());
    }

    public function testDefaultBaseUrlAndCodeOfPath(): void
    {
        $settings = $this->settings(['dmlab_typesafe/anthropic/base_url' => 'https://proxy.example.test']);

        self::assertSame('https://api.anthropic.com', $settings->getDefaultBaseUrl('anthropic'));
        self::assertSame('', $settings->getDefaultBaseUrl('custom'));
        self::assertSame('typesafe', $settings->codeOfPath('dmlab_typesafe/general/base_url'));
        self::assertSame('gemini', $settings->codeOfPath('dmlab_typesafe/gemini/base_url'));
        self::assertSame('', $settings->codeOfPath(''));
    }
}

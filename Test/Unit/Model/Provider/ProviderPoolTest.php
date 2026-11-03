<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Provider;

use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ProviderPoolTest extends TestCase
{
    private function settings(?string $active = null): ProviderSettings
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === ProviderSettings::XML_PATH_PROVIDER ? $active : null
        );

        return new ProviderSettings($scopeConfig);
    }

    public function testGetAndGetAllKeepDiOrder(): void
    {
        $settings = $this->settings();
        $typesafe = new FakeProvider('typesafe', $settings);
        $gemini = new FakeProvider('gemini', $settings);
        $pool = new ProviderPool($settings, ['typesafe' => $typesafe, 'gemini' => $gemini]);

        self::assertSame($gemini, $pool->get('gemini'));
        self::assertSame(['typesafe' => $typesafe, 'gemini' => $gemini], $pool->getAll());
    }

    public function testUnknownCodeIsNotConfigured(): void
    {
        $pool = new ProviderPool($this->settings(), []);

        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('Unknown AI provider "openai".');
        $pool->get('openai');
    }

    public function testActiveDefaultsToTypeSafe(): void
    {
        $settings = $this->settings();
        $typesafe = new FakeProvider('typesafe', $settings);
        $gemini = new FakeProvider('gemini', $settings);
        $pool = new ProviderPool($settings, ['typesafe' => $typesafe, 'gemini' => $gemini]);

        self::assertSame($typesafe, $pool->getActive());
    }

    public function testActiveFollowsTheSelectedCode(): void
    {
        $settings = $this->settings('gemini');
        $gemini = new FakeProvider('gemini', $settings);
        $typesafe = new FakeProvider('typesafe', $settings);
        $pool = new ProviderPool($settings, ['typesafe' => $typesafe, 'gemini' => $gemini]);

        self::assertSame($gemini, $pool->getActive());
    }

    public function testActiveUnknownCodeIsNotConfigured(): void
    {
        $settings = $this->settings('removed');

        $this->expectException(NotConfiguredException::class);
        (new ProviderPool($settings, ['typesafe' => new FakeProvider('typesafe', $settings)]))->getActive();
    }

    public function testRejectsANonProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProviderPool($this->settings(), ['typesafe' => new \stdClass()]);
    }

    public function testRejectsAKeyThatDiffersFromTheCode(): void
    {
        $settings = $this->settings();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provider registered as "openai" reports code "gemini".');
        new ProviderPool($settings, ['openai' => new FakeProvider('gemini', $settings)]);
    }
}

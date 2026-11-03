<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Config\Backend;

use DmLab\TypeSafeCore\Model\Config\Backend\BaseUrl;
use DmLab\TypeSafeCore\Model\Config\Backend\OpenAiCompatibleBaseUrl;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Validator\Url as UrlValidator;
use PHPUnit\Framework\TestCase;

class BaseUrlTest extends TestCase
{
    private function backend(
        string $value,
        string $path = 'dmlab_typesafe/general/base_url',
        string $class = BaseUrl::class
    ): BaseUrl {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        $backend = new $class(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            new UrlValidator(),
            new ProviderSettings($this->createStub(ScopeConfigInterface::class))
        );
        $backend->setValue($value);
        $backend->setPath($path);

        return $backend;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'plain' => ['https://api.typesafe.ai', 'https://api.typesafe.ai'],
            'trailing slashes' => ['https://api.typesafe.ai//', 'https://api.typesafe.ai'],
            'path and port' => [
                ' https://proxy.example.test:8443/typesafe/ ',
                'https://proxy.example.test:8443/typesafe',
            ],
            'empty = default' => ['  ', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedProvider')]
    public function testAcceptsHttpsAndNormalises(string $value, string $stored): void
    {
        $backend = $this->backend($value);

        $backend->beforeSave();

        $this->assertSame($stored, $backend->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'http' => ['http://api.typesafe.ai'],
            'ftp' => ['ftp://api.typesafe.ai'],
            'no scheme' => ['api.typesafe.ai'],
            'no host' => ['https:///v1'],
            'garbage' => ['https://'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedProvider')]
    public function testRejectsNonHttpsOrHostless(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Base URL must be an https:// URL with a host, e.g. https://api.typesafe.ai.'
        );

        $this->backend($value)->beforeSave();
    }

    public function testExampleFollowsTheProviderOfThePath(): void
    {
        $this->expectExceptionMessage(
            'Base URL must be an https:// URL with a host, e.g. https://generativelanguage.googleapis.com.'
        );

        $this->backend('http://gemini.example.test', 'dmlab_typesafe/gemini/base_url')->beforeSave();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function openAiAcceptedProvider(): array
    {
        return [
            'https' => ['https://api.openai.com/v1/', 'https://api.openai.com/v1'],
            'http self-hosted' => [' http://localhost:11434/v1 ', 'http://localhost:11434/v1'],
            'empty = default' => ['', ''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('openAiAcceptedProvider')]
    public function testOpenAiCompatibleAllowsHttp(string $value, string $stored): void
    {
        $backend = $this->backend($value, 'dmlab_typesafe/openai_compatible/base_url', OpenAiCompatibleBaseUrl::class);

        $backend->beforeSave();

        $this->assertSame($stored, $backend->getValue());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function openAiRejectedProvider(): array
    {
        return [
            'ftp' => ['ftp://api.openai.com/v1'],
            'no scheme' => ['localhost:11434'],
            'no host' => ['http:///v1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('openAiRejectedProvider')]
    public function testOpenAiCompatibleRejectsOtherSchemes(string $value): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Base URL must be an http:// or https:// URL with a host, e.g. https://api.openai.com/v1.'
        );

        $this->backend($value, 'dmlab_typesafe/openai_compatible/base_url', OpenAiCompatibleBaseUrl::class)
            ->beforeSave();
    }
}

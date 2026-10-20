<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Config\Backend;

use DmLab\TypeSafeCore\Model\Config\Backend\BaseUrl;
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
    private function backend(string $value): BaseUrl
    {
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        $backend = new BaseUrl(
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class),
            new UrlValidator()
        );
        $backend->setValue($value);

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
            'TypeSafe Base URL must be an https:// URL with a host, e.g. https://api.typesafe.ai.'
        );

        $this->backend($value)->beforeSave();
    }

    public function testSystemXmlUsesTheBackendModel(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 5) . '/etc/adminhtml/system.xml');
        $fields = $xml->xpath('//section[@id="dmlab_typesafe"]/group[@id="general"]/field[@id="base_url"]');

        $this->assertCount(1, $fields);
        $this->assertSame(BaseUrl::class, (string)$fields[0]->backend_model);
    }
}

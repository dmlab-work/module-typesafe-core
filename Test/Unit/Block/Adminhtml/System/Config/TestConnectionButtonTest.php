<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Block\Adminhtml\System\Config;

use DmLab\TypeSafeCore\Block\Adminhtml\System\Config\TestConnectionButton;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;

class TestConnectionButtonTest extends TestCase
{
    private const URL = 'https://magento.test/admin/dmlab_typesafe/connection/test/key/a"b<c>/';

    /** @var ObjectManagerInterface|null */
    private ?ObjectManagerInterface $previousObjectManager = null;

    /** @var string[] */
    private array $routes = [];

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();

        // Backend Template pulls its helpers from the ObjectManager.
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn (string $type) => $this->createStub($type));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, $this->previousObjectManager);
    }

    private function button(): TestConnectionButton
    {
        $escape = static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);
        $escaper->method('escapeHtml')->willReturnCallback($escape);

        $urlBuilder = $this->createStub(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(function ($route) {
            $this->routes[] = $route;

            return self::URL;
        });

        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk"123');

        $context = $this->createStub(Context::class);
        $context->method('getEscaper')->willReturn($escaper);
        $context->method('getUrlBuilder')->willReturn($urlBuilder);
        $context->method('getFormKey')->willReturn($formKey);

        return new TestConnectionButton($context, [], $this->createStub(SecureHtmlRenderer::class));
    }

    private function invoke(TestConnectionButton $button, string $method): string
    {
        return (new \ReflectionMethod($button, $method))->invoke($button, $this->createStub(AbstractElement::class));
    }

    public function testHtmlContainsEscapedUrlFormKeyAndMageInit(): void
    {
        $html = $this->invoke($this->button(), '_getElementHtml');

        $this->assertSame([TestConnectionButton::TEST_ROUTE], $this->routes);
        $this->assertStringContainsString(
            'data-test-url="https://magento.test/admin/dmlab_typesafe/connection/test/key/a&quot;b&lt;c&gt;/"',
            $html
        );
        $this->assertStringContainsString('data-form-key="fk&quot;123"', $html);
        $this->assertStringContainsString(
            'data-mage-init="{&quot;DmLab_TypeSafeCore/js/test-connection&quot;: {}}"',
            $html
        );
        $this->assertStringContainsString('<button type="button" class="action-default"', $html);
        $this->assertStringContainsString('>Test Connection</button>', $html);
        $this->assertStringContainsString(
            '<div class="dmlab-typesafe-test-result message" style="display:none"></div>',
            $html
        );
        $this->assertStringNotContainsString(self::URL, $html);
    }

    public function testScopeLabelIsEmpty(): void
    {
        $this->assertSame('', $this->invoke($this->button(), '_renderScopeLabel'));
    }

    public function testSystemXmlDeclaresButtonField(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 6) . '/etc/adminhtml/system.xml');
        $fields = $xml->xpath('//section[@id="dmlab_typesafe"]/group[@id="general"]/field[@id="test_connection"]');

        $this->assertCount(1, $fields);
        $field = $fields[0];
        $this->assertSame('button', (string)$field['type']);
        $this->assertSame(TestConnectionButton::class, (string)$field->frontend_model);
        $this->assertSame('Tests the saved configuration; save changes first.', (string)$field->comment);
    }

    public function testAdminRouteIsDeclared(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 6) . '/etc/adminhtml/routes.xml');
        $routes = $xml->xpath('//router[@id="admin"]/route[@id="dmlab_typesafe"][@frontName="dmlab_typesafe"]/module');

        $this->assertCount(1, $routes);
        $this->assertSame('DmLab_TypeSafeCore', (string)$routes[0]['name']);
    }
}

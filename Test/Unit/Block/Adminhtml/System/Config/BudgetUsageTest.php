<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Block\Adminhtml\System\Config;

use DmLab\TypeSafeCore\Api\UsageInterface;
use DmLab\TypeSafeCore\Block\Adminhtml\System\Config\BudgetUsage;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BudgetUsageTest extends TestCase
{
    /** @var ObjectManagerInterface|null */
    private ?ObjectManagerInterface $previousObjectManager = null;

    /** @var string[] */
    private array $warnings = [];

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

    private function html(int|\Exception $tokens, ?int $remaining = null): string
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn ($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message): void {
            $this->warnings[] = (string)$message;
        });
        $context = $this->createStub(Context::class);
        $context->method('getEscaper')->willReturn($escaper);
        $context->method('getLogger')->willReturn($logger);

        $usage = $this->createStub(UsageInterface::class);
        if ($tokens instanceof \Exception) {
            $usage->method('getMonthTokens')->willThrowException($tokens);
        } else {
            $usage->method('getMonthTokens')->willReturn($tokens);
        }
        $usage->method('getRemainingBudget')->willReturn($remaining);

        $element = $this->createStub(AbstractElement::class);
        $element->method('getElementHtml')->willReturn('<input id="budget"/>');

        $block = new BudgetUsage($context, $usage, [], $this->createStub(SecureHtmlRenderer::class));

        return (new \ReflectionMethod($block, '_getElementHtml'))->invoke($block, $element);
    }

    public function testShowsUsageCostAndRemainingBudget(): void
    {
        $this->assertSame(
            '<input id="budget"/><p class="note dmlab-typesafe-budget-usage"><span>'
            . 'Used this month: 2,500,000 tokens (≈ $0.1050). Remaining: 7,500,000 tokens</span></p>',
            $this->html(2500000, 7500000)
        );
    }

    public function testUnlimitedBudget(): void
    {
        $this->assertStringContainsString(
            '<span>Used this month: 0 tokens (≈ $0.0000). Remaining: unlimited</span>',
            $this->html(0)
        );
    }

    public function testReadFailureIsLoggedAndShownAsUnavailable(): void
    {
        $html = $this->html(new \RuntimeException('db <down>'));

        $this->assertStringContainsString('<span>Usage this month is unavailable.</span>', $html);
        $this->assertSame(['TypeSafe usage read failed: db <down>'], $this->warnings);
    }

    public function testSystemXmlUsesTheBlockForTheBudgetField(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 6) . '/etc/adminhtml/system.xml');
        $fields = $xml->xpath(
            '//section[@id="dmlab_typesafe"]/group[@id="limits"]/field[@id="monthly_token_budget"]'
        );

        $this->assertCount(1, $fields);
        $this->assertSame(BudgetUsage::class, (string)$fields[0]->frontend_model);
    }
}

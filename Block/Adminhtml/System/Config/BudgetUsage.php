<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Block\Adminhtml\System\Config;

use DmLab\TypeSafeCore\Api\UsageInterface;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Monthly Token Budget field with this month's usage, TypeSafe cost and remaining budget below it.
 */
class BudgetUsage extends Field
{
    /**
     * @param Context $context
     * @param UsageInterface $usage
     * @param Config $config
     * @param array $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly UsageInterface $usage,
        private readonly Config $config,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return parent::_getElementHtml($element)
            . '<p class="note dmlab-typesafe-budget-usage"><span>'
            . $this->_escaper->escapeHtml($this->usageLine())
            . '</span></p>';
    }

    /**
     * "Used this month …" line; a read failure is logged and shown as unavailable.
     *
     * @return string
     */
    private function usageLine(): string
    {
        try {
            $used = $this->usage->getMonthTokens();
            $remaining = $this->usage->getRemainingBudget();
            $typeSafe = $this->config->getProvider() === TypeSafeProvider::CODE;
        } catch (\Exception $e) {
            $this->_logger->warning('TypeSafe usage read failed: ' . $e->getMessage());
            return (string)__('Usage this month is unavailable.');
        }

        if (!$typeSafe) {
            return $remaining === null
                ? (string)__('Used this month: %1 tokens. Remaining: unlimited', number_format($used))
                : (string)__(
                    'Used this month: %1 tokens. Remaining: %2 tokens',
                    number_format($used),
                    number_format($remaining)
                );
        }
        $cost = sprintf('%.4f', $used * UsageLedger::USD_PER_MILLION_TOKENS / 1000000);

        return $remaining === null
            ? (string)__('Used this month: %1 tokens (≈ $%2). Remaining: unlimited', number_format($used), $cost)
            : (string)__(
                'Used this month: %1 tokens (≈ $%2). Remaining: %3 tokens',
                number_format($used),
                $cost,
                number_format($remaining)
            );
    }
}

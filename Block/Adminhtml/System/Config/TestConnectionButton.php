<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * "Test Connection" button of a provider group; the group id is the provider code.
 */
class TestConnectionButton extends Field
{
    public const TEST_ROUTE = 'dmlab_typesafe/connection/test';

    /**
     * @inheritDoc
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $init = $this->_escaper->escapeHtmlAttr('{"DmLab_TypeSafeCore/js/test-connection": {}}');

        return '<button type="button" class="action-default" data-mage-init="' . $init . '"'
            . ' data-test-url="' . $this->_escaper->escapeHtmlAttr($this->getUrl(self::TEST_ROUTE)) . '"'
            . ' data-provider="' . $this->_escaper->escapeHtmlAttr($this->getProviderCode($element)) . '"'
            . ' data-form-key="' . $this->_escaper->escapeHtmlAttr((string)$this->getFormKey()) . '">'
            . $this->_escaper->escapeHtml((string)__('Test Connection')) . '</button>'
            . '<div class="dmlab-typesafe-test-result message" style="display:none"></div>';
    }

    /**
     * @inheritDoc
     */
    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }

    /**
     * Group id of the field: its `field_config` path is `<section>/<group>`.
     *
     * @param AbstractElement $element
     * @return string
     */
    private function getProviderCode(AbstractElement $element): string
    {
        $field = $element->getData('field_config');
        $path = is_array($field) && is_string($field['path'] ?? null) ? $field['path'] : '';

        return explode('/', $path)[1] ?? '';
    }
}

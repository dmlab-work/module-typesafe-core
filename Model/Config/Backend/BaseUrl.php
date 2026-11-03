<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Config\Backend;

use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Phrase;
use Magento\Framework\Registry;
use Magento\Framework\Validator\Url as UrlValidator;

/**
 * Provider base URL must be https:// with a host; stored without trailing slashes. Empty falls back to the default.
 */
class BaseUrl extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param UrlValidator $urlValidator
     * @param ProviderSettings $providerSettings
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly UrlValidator $urlValidator,
        private readonly ProviderSettings $providerSettings,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @inheritDoc
     */
    public function beforeSave()
    {
        $value = rtrim(trim((string)$this->getValue()), '/');
        if ($value !== '' && !$this->urlValidator->isValid($value, $this->getAllowedSchemes())) {
            throw new LocalizedException($this->getInvalidMessage(
                $this->providerSettings->getDefaultBaseUrl(
                    $this->providerSettings->codeOfPath((string)$this->getPath())
                )
            ));
        }
        $this->setValue($value);

        return parent::beforeSave();
    }

    /**
     * URL schemes accepted for this field.
     *
     * @return string[]
     */
    protected function getAllowedSchemes(): array
    {
        return ['https'];
    }

    /**
     * Validation error for a rejected value.
     *
     * @param string $example
     * @return Phrase
     */
    protected function getInvalidMessage(string $example): Phrase
    {
        return __('Base URL must be an https:// URL with a host, e.g. %1.', $example);
    }
}

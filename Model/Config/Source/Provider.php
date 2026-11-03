<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Config\Source;

use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Provider select options from the pool, so a di-added provider appears without config changes.
 */
class Provider implements OptionSourceInterface
{
    /**
     * @param ProviderPool $providerPool
     */
    public function __construct(
        private readonly ProviderPool $providerPool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->providerPool->getAll() as $code => $provider) {
            $options[] = ['value' => $code, 'label' => $provider->getLabel()];
        }

        return $options;
    }
}

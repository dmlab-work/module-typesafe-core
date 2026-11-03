<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider;

use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;

/**
 * AI providers registered through di.xml, keyed by code.
 */
class ProviderPool
{
    /**
     * @var array<string, ProviderInterface>
     */
    private readonly array $providers;

    /**
     * @param ProviderSettings $settings
     * @param ProviderInterface[] $providers code => provider
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly ProviderSettings $settings,
        array $providers = []
    ) {
        foreach ($providers as $code => $provider) {
            if (!$provider instanceof ProviderInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Provider "%s" must implement %s.', $code, ProviderInterface::class)
                );
            }
            if ($provider->getCode() !== (string)$code) {
                throw new \InvalidArgumentException(
                    sprintf('Provider registered as "%s" reports code "%s".', $code, $provider->getCode())
                );
            }
        }
        $this->providers = $providers;
    }

    /**
     * Provider by code.
     *
     * @param string $code
     * @return ProviderInterface
     * @throws NotConfiguredException
     */
    public function get(string $code): ProviderInterface
    {
        if (!isset($this->providers[$code])) {
            throw new NotConfiguredException(__('Unknown AI provider "%1".', $code));
        }

        return $this->providers[$code];
    }

    /**
     * All providers, code => provider, in di order.
     *
     * @return array<string, ProviderInterface>
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    /**
     * The provider selected in `dmlab_typesafe/general/provider`.
     *
     * @return ProviderInterface
     * @throws NotConfiguredException
     */
    public function getActive(): ProviderInterface
    {
        return $this->get($this->settings->getActiveCode());
    }
}

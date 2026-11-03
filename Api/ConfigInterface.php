<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api;

/**
 * Shared AI client settings for consumers. Never exposes an API key.
 *
 * @api
 */
interface ConfigInterface
{
    /**
     * Whether the AI client is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Whether the client is enabled and the active provider has its required settings.
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Model id of the active provider (TypeSafe default 'jev-1.13.0').
     *
     * @return string
     */
    public function getModel(): string;
}

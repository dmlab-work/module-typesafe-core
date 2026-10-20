<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api;

/**
 * Shared TypeSafe settings for consumers. Never exposes the API key.
 *
 * @api
 */
interface ConfigInterface
{
    /**
     * Whether TypeSafe is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Whether TypeSafe is enabled and has a non-empty API key.
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Configured pinned model id, default 'jev-1.13.0'.
     *
     * @return string
     */
    public function getModel(): string;
}

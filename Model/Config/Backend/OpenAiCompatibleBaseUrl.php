<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Config\Backend;

use Magento\Framework\Phrase;

/**
 * OpenAI-compatible base URL: http:// is allowed too, for self-hosted endpoints (Ollama, vLLM).
 */
class OpenAiCompatibleBaseUrl extends BaseUrl
{
    /**
     * @inheritDoc
     */
    protected function getAllowedSchemes(): array
    {
        return ['http', 'https'];
    }

    /**
     * @inheritDoc
     */
    protected function getInvalidMessage(string $example): Phrase
    {
        return __('Base URL must be an http:// or https:// URL with a host, e.g. %1.', $example);
    }
}

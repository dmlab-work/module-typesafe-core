<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Fake;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;

/**
 * Provider that reads its key and model from ProviderSettings; HTTP methods are not used.
 */
class FakeProvider implements ProviderInterface
{
    /**
     * @param string $code
     * @param ProviderSettings $settings
     * @param bool $requiresKey false = configured by base URL alone
     */
    public function __construct(
        private readonly string $code,
        private readonly ProviderSettings $settings,
        private readonly bool $requiresKey = true
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Fake ' . $this->code;
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return $this->requiresKey
            ? $this->settings->getApiKey($this->code) !== ''
            : $this->settings->getBaseUrl($this->code) !== '';
    }

    /**
     * @inheritDoc
     */
    public function getModel(): string
    {
        return $this->settings->getModel($this->code);
    }

    /**
     * @inheritDoc
     */
    public function cacheScope(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest
    {
        throw new \LogicException('Not used.');
    }

    /**
     * @inheritDoc
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response
    {
        throw new \LogicException('Not used.');
    }

    /**
     * @inheritDoc
     */
    public function usage(string $body): array
    {
        return ['input' => 0, 'output' => 0];
    }

    /**
     * @inheritDoc
     */
    public function classifyError(HttpResponse $response): ?TypeSafeException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function retryAfter(HttpResponse $response): ?int
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function listModels(): array
    {
        return [];
    }
}

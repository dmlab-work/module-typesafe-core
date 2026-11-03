<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;

/**
 * One AI backend: maps one Request to one HTTP exchange. Cache, budget, retries and rate limiting stay in the client.
 *
 * Register through di.xml in the `providers` argument of `Model\Provider\ProviderPool`, keyed by `getCode()`.
 *
 * @api
 */
interface ProviderInterface
{
    /**
     * Stable code stored in `dmlab_typesafe/general/provider`, e.g. 'typesafe'.
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Human-readable name used in admin and in error messages.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Whether the provider has its required settings (API key, base URL, …).
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Configured model id.
     *
     * @return string
     */
    public function getModel(): string;

    /**
     * Cache key prefix separating this provider's answers; '' keeps the bare, question-order-insensitive key.
     *
     * @return string
     */
    public function cacheScope(): string;

    /**
     * HTTP request for one evaluation.
     *
     * @param Request $request
     * @param string $model resolved model id
     * @param string|null $repairNote why the previous answer was rejected, for the one repair retry
     * @return HttpRequest
     * @throws RequestException
     */
    public function buildRequest(Request $request, string $model, ?string $repairNote): HttpRequest;

    /**
     * Response from a successful (2xx or cached) body.
     *
     * @param string $body
     * @param Request $request
     * @param string $model resolved model id
     * @param bool $cached
     * @return Response
     * @throws TransportException
     * @throws InvalidAnswerException
     */
    public function parseResponse(string $body, Request $request, string $model, bool $cached): Response;

    /**
     * Billed tokens of a body; zeros when unreadable.
     *
     * @param string $body
     * @return array{input: int, output: int}
     */
    public function usage(string $body): array;

    /**
     * Provider-specific final exception for a failed response (never retried); null applies the default mapping.
     *
     * @param HttpResponse $response
     * @return TypeSafeException|null
     */
    public function classifyError(HttpResponse $response): ?TypeSafeException;

    /**
     * Seconds the provider asks to wait before retrying; null when not stated.
     *
     * @param HttpResponse $response
     * @return int|null
     */
    public function retryAfter(HttpResponse $response): ?int;

    /**
     * Model ids available to the saved settings, single attempt.
     *
     * @return string[]
     * @throws TypeSafeException
     */
    public function listModels(): array;
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model;

use DmLab\TypeSafeCore\Api\ClientInterface;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\CircuitBreaker;
use DmLab\TypeSafeCore\Model\Http\Clock;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\RateLimitGuard;
use DmLab\TypeSafeCore\Model\Http\TransportInterface;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * AI client over the active provider: request cache first, then budget- and circuit-guarded parallel waves.
 */
class Client implements ClientInterface
{
    public const MAX_RETRY_AFTER = 60;
    private const FALLBACK_LABEL = 'AI';
    private const ERROR_SNIPPET_LENGTH = 200;

    /**
     * @param Config $config
     * @param RequestEncoder $encoder
     * @param ProviderPool $providerPool
     * @param ResponseCache $cache
     * @param UsageLedger $ledger
     * @param CircuitBreaker $circuitBreaker
     * @param RateLimitGuard $rateLimitGuard
     * @param Backoff $backoff
     * @param Clock $clock
     * @param TransportInterface $transport
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly RequestEncoder $encoder,
        private readonly ProviderPool $providerPool,
        private readonly ResponseCache $cache,
        private readonly UsageLedger $ledger,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly Backoff $backoff,
        private readonly Clock $clock,
        private readonly TransportInterface $transport,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function evaluate(Request $request): Response
    {
        $result = $this->evaluateMany([0 => $request])[0];
        if ($result instanceof TypeSafeException) {
            throw $result;
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function evaluateMany(array $requests): array
    {
        $results = [];
        $label = self::FALLBACK_LABEL;
        try {
            $provider = $requests !== [] ? $this->resolveProvider($requests, $results) : null;
            if ($provider !== null) {
                $label = $provider->getLabel();
                $duplicates = [];
                $pending = $this->serveCached($provider, $requests, $results, $duplicates);
                $stop = null;
                foreach (array_chunk($pending, $this->config->getConcurrency(), true) as $wave) {
                    $this->sendWave($provider, $wave, $results, $stop);
                }
                $this->resolveDuplicates($requests, $duplicates, $results);
            }
        } catch (\Throwable $e) {
            $this->logger->error($label . ' client error: ' . $e->getMessage());
            foreach (array_keys($requests) as $key) {
                $results[$key] ??= new TransportException(
                    __('%1 client error: %2', $label, $e->getMessage()),
                    $e instanceof \Exception ? $e : null
                );
            }
        }

        $ordered = [];
        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * Active provider when the client is usable; otherwise every key gets NotConfiguredException and null is returned.
     *
     * @param array $requests
     * @param array $results
     * @return ProviderInterface|null
     */
    private function resolveProvider(array $requests, array &$results): ?ProviderInterface
    {
        try {
            $provider = $this->providerPool->getActive();
            if (!$this->config->isEnabled()) {
                throw new NotConfiguredException(__('%1 is disabled.', $provider->getLabel()));
            }
            if (!$provider->isConfigured()) {
                throw new NotConfiguredException(__('%1 API key is not set.', $provider->getLabel()));
            }

            return $provider;
        } catch (NotConfiguredException $e) {
            foreach (array_keys($requests) as $key) {
                $results[$key] = $this->replicate($e);
            }
        }

        return null;
    }

    /**
     * Resolve encode errors and cache hits; returns the misses to send, keyed like the input.
     *
     * A miss whose cache key is already pending is not sent again: it goes to $duplicates as index => primary index.
     *
     * @param ProviderInterface $provider
     * @param Request[] $requests
     * @param array $results
     * @param array $duplicates index => primary index
     * @return array[] misses as {request, model, key, http, repaired}
     */
    private function serveCached(
        ProviderInterface $provider,
        array $requests,
        array &$results,
        array &$duplicates
    ): array {
        $pending = [];
        $primaries = [];
        foreach ($requests as $index => $request) {
            $model = $this->encoder->resolveModel($request);
            try {
                $key = $this->encoder->cacheKey($request, $model, $provider);
                if (isset($primaries[$key])) {
                    $duplicates[$index] = $primaries[$key];
                    continue;
                }
                $hit = $this->cachedResponse($provider, $key, $request, $model);
                if ($hit !== null) {
                    $results[$index] = $hit;
                    $this->ledger->record($request->getConsumer(), 0, true);
                    continue;
                }
                $http = $provider->buildRequest($request, $model, null);
            } catch (RequestException $e) {
                $results[$index] = $e;
                continue;
            }

            $pending[$index] = [
                'request' => $request,
                'model' => $model,
                'key' => $key,
                'http' => $http,
                'repaired' => false,
            ];
            $primaries[$key] = $index;
        }

        return $pending;
    }

    /**
     * Give each coalesced duplicate its primary's outcome: a live answer becomes a cache hit, an error is copied.
     *
     * @param Request[] $requests
     * @param array $duplicates index => primary index
     * @param array $results
     * @return void
     */
    private function resolveDuplicates(array $requests, array $duplicates, array &$results): void
    {
        foreach ($duplicates as $index => $primary) {
            $result = $results[$primary];
            if ($result instanceof TypeSafeException) {
                $results[$index] = $this->replicate($result);
                continue;
            }
            $results[$index] = new Response($result->getModel(), 0, true, $result->getAnswers());
            $this->ledger->record($requests[$index]->getConsumer(), 0, true);
        }
    }

    /**
     * Cached response for the key; an unreadable cached body counts as a miss and is overwritten by the live answer.
     *
     * @param ProviderInterface $provider
     * @param string $key
     * @param Request $request
     * @param string $model
     * @return Response|null
     */
    private function cachedResponse(
        ProviderInterface $provider,
        string $key,
        Request $request,
        string $model
    ): ?Response {
        $body = $this->cache->get($key);
        if ($body === null) {
            return null;
        }
        try {
            return $provider->parseResponse($body, $request, $model, true);
        } catch (TransportException $e) {
            return null;
        }
    }

    /**
     * Send one wave of misses, retrying failed keys up to the attempt limit while the circuit and budget allow.
     *
     * An error the provider classifies is final. A final 401/403 or 429 sets $stop; every key still
     * unanswered then gets a copy of it without HTTP. A final rate limit also pauses later calls via RateLimitGuard.
     * An invalid answer is resent once with a repair note in the next round, without sleeping and without counting
     * as a circuit failure.
     *
     * @param ProviderInterface $provider
     * @param array[] $wave misses as {request, model, key, http, repaired}
     * @param array $results
     * @param TypeSafeException|null $stop
     * @return void
     */
    private function sendWave(
        ProviderInterface $provider,
        array $wave,
        array &$results,
        ?TypeSafeException &$stop
    ): void {
        $maxAttempts = $this->config->getMaxAttempts();
        $todo = $wave;
        for ($attempt = 1; $todo !== []; $attempt++) {
            if ($stop !== null) {
                foreach (array_keys($todo) as $index) {
                    $results[$index] = $this->replicate($stop);
                }
                return;
            }
            if ($this->refuseIfBlocked($provider->getLabel(), $todo, $results)) {
                return;
            }

            $httpRequests = [];
            foreach ($todo as $index => $item) {
                $httpRequests[$index] = $item['http'];
            }
            $responses = $this->transport->sendMany($httpRequests);

            $failures = 0;
            $anySuccess = false;
            $retry = [];
            $wait = null;
            foreach ($todo as $index => $item) {
                $response = $responses[$index];
                $status = $response->getStatus();
                if (!$response->isTransportError() && $status >= 200 && $status < 300) {
                    $result = $this->succeed($provider, $item, $response);
                    if ($result instanceof InvalidAnswerException && !$item['repaired']) {
                        try {
                            $retry[$index] = $this->repair($provider, $item, $result);
                        } catch (RequestException $e) {
                            $results[$index] = $e;
                        }
                        continue;
                    }
                    $results[$index] = $result;
                    $anySuccess = $anySuccess || $result instanceof Response;
                    continue;
                }

                $this->logFailure($provider->getLabel(), $item, $response, $attempt, $maxAttempts);
                if ($this->isServerFailure($response)) {
                    $failures++;
                }
                $classified = $response->isTransportError() ? null : $provider->classifyError($response);
                $delay = $classified === null && $attempt < $maxAttempts
                    ? $this->retryDelay($provider, $response, $attempt)
                    : null;
                if ($delay === null) {
                    $results[$index] = $classified ?? $this->fail($provider, $response);
                    if ($stop === null && ($results[$index] instanceof AuthenticationException
                        || $results[$index] instanceof RateLimitException
                        || $results[$index]->getCode() === 429)
                    ) {
                        $stop = $results[$index];
                        if ($stop instanceof RateLimitException) {
                            $this->rateLimitGuard->pause($stop->getRetryAfter());
                        }
                    }
                    continue;
                }
                $retry[$index] = $item;
                $wait = max($wait ?? 0.0, $delay);
            }

            // One failing round counts once, so parallel failures cannot open the circuit before retries happen.
            $this->circuitBreaker->record($failures > 0 ? 1 : 0, $anySuccess);
            if ($wait !== null && $stop === null) {
                $this->clock->sleep($wait);
            }
            $todo = $retry;
        }
    }

    /**
     * The miss rebuilt with the rejection reason as repair note.
     *
     * @param ProviderInterface $provider
     * @param array $item miss as {request, model, key, http, repaired}
     * @param InvalidAnswerException $invalid
     * @return array
     * @throws RequestException
     */
    private function repair(ProviderInterface $provider, array $item, InvalidAnswerException $invalid): array
    {
        $item['http'] = $provider->buildRequest($item['request'], $item['model'], $invalid->getMessage());
        $item['repaired'] = true;

        return $item;
    }

    /**
     * Fail every key with CircuitOpen/RateLimit/BudgetExceeded when sending is not allowed; true when it refused.
     *
     * @param string $label provider label
     * @param array[] $todo
     * @param array $results
     * @return bool
     */
    private function refuseIfBlocked(string $label, array $todo, array &$results): bool
    {
        if ($this->circuitBreaker->isOpen()) {
            foreach (array_keys($todo) as $index) {
                $results[$index] = new CircuitOpenException(__(
                    '%1 calls are paused for up to %2 s after repeated failures.',
                    $label,
                    CircuitBreaker::OPEN_SECONDS
                ));
            }
            return true;
        }
        $pause = $this->rateLimitGuard->remainingSeconds();
        if ($pause > 0) {
            foreach (array_keys($todo) as $index) {
                $results[$index] = new RateLimitException(
                    __('%1 rate limit reached; calls are paused for %2 s.', $label, $pause),
                    null,
                    429,
                    $pause
                );
            }
            return true;
        }
        if ($this->ledger->getRemainingBudget() === 0) {
            foreach (array_keys($todo) as $index) {
                $results[$index] = new BudgetExceededException(
                    __('%1 monthly token budget (%2) is used up.', $label, $this->config->getMonthlyTokenBudget())
                );
            }
            return true;
        }

        return false;
    }

    /**
     * Seconds to wait before retrying a failed attempt, or null when the failure is final.
     *
     * @param ProviderInterface $provider
     * @param HttpResponse $response
     * @param int $attempt
     * @return float|null
     */
    private function retryDelay(ProviderInterface $provider, HttpResponse $response, int $attempt): ?float
    {
        if (!$response->isTransportError() && $response->getStatus() === 429) {
            $retryAfter = $provider->retryAfter($response);
            if ($retryAfter === null) {
                return $this->backoff->delay($attempt);
            }

            return $retryAfter <= self::MAX_RETRY_AFTER ? (float)$retryAfter : null;
        }

        return $this->isServerFailure($response) ? $this->backoff->delay($attempt) : null;
    }

    /**
     * Transport error, 408 or 5xx: retryable and counted by the circuit breaker.
     *
     * @param HttpResponse $response
     * @return bool
     */
    private function isServerFailure(HttpResponse $response): bool
    {
        $status = $response->getStatus();

        return $response->isTransportError() || $status === 408 || $status >= 500;
    }

    /**
     * One warning per failed attempt; never the API key, the body or the state.
     *
     * @param string $label provider label
     * @param array $item miss as {request, model, key, http, repaired}
     * @param HttpResponse $response
     * @param int $attempt
     * @param int $maxAttempts
     * @return void
     */
    private function logFailure(
        string $label,
        array $item,
        HttpResponse $response,
        int $attempt,
        int $maxAttempts
    ): void {
        $reason = $response->isTransportError()
            ? ($response->getError() !== '' ? $response->getError() : 'no response')
            : 'HTTP ' . $response->getStatus();
        $this->logger->warning(
            sprintf('%s request failed (attempt %d/%d): %s', $label, $attempt, $maxAttempts, $reason),
            [
                'consumer' => $item['request']->getConsumer(),
                'model' => $item['model'],
                'status' => $response->getStatus(),
            ]
        );
    }

    /**
     * Parse a 2xx body, then cache it and record its tokens.
     *
     * A malformed body or invalid answer is returned as TransportException; its billed tokens are still recorded.
     *
     * @param ProviderInterface $provider
     * @param array $item miss as {request, model, key, http, repaired}
     * @param HttpResponse $httpResponse
     * @return Response|TransportException
     */
    private function succeed(
        ProviderInterface $provider,
        array $item,
        HttpResponse $httpResponse
    ): Response|TransportException {
        $request = $item['request'];
        $usage = $provider->usage($httpResponse->getBody());
        try {
            $response = $provider->parseResponse($httpResponse->getBody(), $request, $item['model'], false);
        } catch (TransportException $e) {
            if ($usage['input'] + $usage['output'] > 0) {
                $this->ledger->record($request->getConsumer(), $usage['input'], false, $usage['output']);
            }
            return $e;
        }

        $this->cache->save(
            $item['key'],
            $request->getConsumer(),
            $response->getModel(),
            $httpResponse->getBody(),
            $response->getInputTokens()
        );
        $this->ledger->record($request->getConsumer(), $usage['input'], false, $usage['output']);

        return $response;
    }

    /**
     * Map a non-2xx or transport-error response the provider did not classify to its final exception by status.
     *
     * @param ProviderInterface $provider
     * @param HttpResponse $response
     * @return TypeSafeException
     */
    private function fail(ProviderInterface $provider, HttpResponse $response): TypeSafeException
    {
        $label = $provider->getLabel();
        $status = $response->getStatus();
        if ($response->isTransportError()) {
            return new TransportException(__(
                '%1 request failed: %2',
                $label,
                $response->getError() !== '' ? $response->getError() : 'no response'
            ));
        }
        if ($status === 401 || $status === 403) {
            return new AuthenticationException(
                __('%1 rejected the API key (HTTP %2).', $label, $status),
                null,
                $status
            );
        }
        if ($status === 429) {
            $retryAfter = $provider->retryAfter($response);

            return new RateLimitException(
                $retryAfter !== null
                    ? __('%1 rate limit reached; retry after %2 s.', $label, $retryAfter)
                    : __('%1 rate limit reached.', $label),
                null,
                $status,
                $retryAfter
            );
        }
        if ($status === 408 || $status >= 500) {
            return new TransportException(__('%1 answered HTTP %2.', $label, $status), null, $status);
        }

        return new RequestException(
            __('%1 rejected the request (HTTP %2): %3', $label, $status, $this->errorDetail($response->getBody())),
            null,
            $status
        );
    }

    /**
     * Fresh exception of the same class, message, code and retry-after.
     *
     * @param TypeSafeException $e
     * @return TypeSafeException
     */
    private function replicate(TypeSafeException $e): TypeSafeException
    {
        $phrase = new Phrase($e->getRawMessage(), $e->getParameters());
        $cause = $e->getPrevious() instanceof \Exception ? $e->getPrevious() : null;
        if ($e instanceof RateLimitException) {
            return new RateLimitException($phrase, $cause, (int)$e->getCode(), $e->getRetryAfter());
        }
        $class = $e::class;

        return new $class($phrase, $cause, (int)$e->getCode());
    }

    /**
     * Server `message`/`detail`/`error` string, else the start of the body.
     *
     * @param string $body
     * @return string
     */
    private function errorDetail(string $body): string
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $data = null;
        }
        if (is_array($data)) {
            foreach (['message', 'detail', 'error'] as $field) {
                $value = $data[$field] ?? null;
                if (is_array($value)) {
                    $value = $value['message'] ?? null;
                }
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return mb_substr(trim($body), 0, self::ERROR_SNIPPET_LENGTH);
    }
}

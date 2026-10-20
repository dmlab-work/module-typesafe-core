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
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Exception\BudgetExceededException;
use DmLab\TypeSafeCore\Exception\CircuitOpenException;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Exception\RateLimitException;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Http\CircuitBreaker;
use DmLab\TypeSafeCore\Model\Http\Clock;
use DmLab\TypeSafeCore\Model\Http\HttpRequest;
use DmLab\TypeSafeCore\Model\Http\HttpResponse;
use DmLab\TypeSafeCore\Model\Http\RateLimitGuard;
use DmLab\TypeSafeCore\Model\Http\TransportInterface;
use DmLab\TypeSafeCore\Model\Usage\UsageLedger;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * TypeSafe client: request cache first, then budget- and circuit-guarded parallel waves.
 */
class Client implements ClientInterface
{
    public const ENDPOINT = '/v1/systemone';
    public const MAX_RETRY_AFTER = 60;
    private const ERROR_SNIPPET_LENGTH = 200;

    /**
     * @param Config $config
     * @param RequestEncoder $encoder
     * @param ResponseParser $parser
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
        private readonly ResponseParser $parser,
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
        try {
            $apiKey = $this->resolveApiKey($requests, $results);
            if ($apiKey !== null) {
                $duplicates = [];
                $pending = $this->serveCached($requests, $results, $duplicates);
                $stop = null;
                foreach (array_chunk($pending, $this->config->getConcurrency(), true) as $wave) {
                    $this->sendWave($wave, $apiKey, $results, $stop);
                }
                $this->resolveDuplicates($requests, $duplicates, $results);
            }
        } catch (\Throwable $e) {
            $this->logger->error('TypeSafe client error: ' . $e->getMessage());
            foreach (array_keys($requests) as $key) {
                $results[$key] ??= new TransportException(
                    __('TypeSafe client error: %1', $e->getMessage()),
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
     * API key when the client is usable; otherwise every key gets NotConfiguredException and null is returned.
     *
     * @param array $requests
     * @param array $results
     * @return string|null
     */
    private function resolveApiKey(array $requests, array &$results): ?string
    {
        if (!$this->config->isEnabled()) {
            $phrase = 'TypeSafe is disabled.';
        } else {
            $apiKey = $this->config->getApiKey();
            if ($apiKey !== '') {
                return $apiKey;
            }
            $phrase = 'TypeSafe API key is not set.';
        }

        foreach (array_keys($requests) as $key) {
            $results[$key] = new NotConfiguredException(__($phrase));
        }

        return null;
    }

    /**
     * Resolve encode errors and cache hits; returns the misses to send, keyed like the input.
     *
     * A miss whose cache key is already pending is not sent again: it goes to $duplicates as index => primary index.
     *
     * @param Request[] $requests
     * @param array $results
     * @param array $duplicates index => primary index
     * @return array[] misses as {request, model, key, body}
     */
    private function serveCached(array $requests, array &$results, array &$duplicates): array
    {
        $pending = [];
        $primaries = [];
        foreach ($requests as $index => $request) {
            $model = $this->encoder->resolveModel($request);
            try {
                $key = $this->encoder->cacheKey($request, $model);
                if (isset($primaries[$key])) {
                    $duplicates[$index] = $primaries[$key];
                    continue;
                }
                $body = $this->encoder->body($request, $model);
            } catch (RequestException $e) {
                $results[$index] = $e;
                continue;
            }

            $hit = $this->cachedResponse($key, $request);
            if ($hit !== null) {
                $results[$index] = $hit;
                $this->ledger->record($request->getConsumer(), 0, true);
                continue;
            }

            $pending[$index] = ['request' => $request, 'model' => $model, 'key' => $key, 'body' => $body];
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
     * @param string $key
     * @param Request $request
     * @return Response|null
     */
    private function cachedResponse(string $key, Request $request): ?Response
    {
        $body = $this->cache->get($key);
        if ($body === null) {
            return null;
        }
        try {
            return $this->parser->parse($body, $request, true);
        } catch (TransportException $e) {
            return null;
        }
    }

    /**
     * Send one wave of misses, retrying failed keys up to the attempt limit while the circuit and budget allow.
     *
     * A final 401/403 or rate limit sets $stop; every key still unanswered then gets a copy of it without HTTP.
     * A final rate limit also pauses later calls via RateLimitGuard.
     *
     * @param array[] $wave misses as {request, model, key, body}
     * @param string $apiKey
     * @param array $results
     * @param TypeSafeException|null $stop
     * @return void
     */
    private function sendWave(
        array $wave,
        #[\SensitiveParameter] string $apiKey,
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
            if ($this->refuseIfBlocked($todo, $results)) {
                return;
            }

            $httpRequests = [];
            foreach ($todo as $index => $item) {
                $httpRequests[$index] = $this->httpRequest($item['body'], $apiKey);
            }
            $responses = $this->transport->sendMany($httpRequests);

            $failures = 0;
            $anySuccess = false;
            $retry = [];
            $wait = 0.0;
            foreach ($todo as $index => $item) {
                $response = $responses[$index];
                $status = $response->getStatus();
                if (!$response->isTransportError() && $status >= 200 && $status < 300) {
                    $results[$index] = $this->succeed($item, $response);
                    $anySuccess = $anySuccess || $results[$index] instanceof Response;
                    continue;
                }

                $this->logFailure($item, $response, $attempt, $maxAttempts);
                if ($this->isServerFailure($response)) {
                    $failures++;
                }
                $delay = $attempt < $maxAttempts ? $this->retryDelay($response, $attempt) : null;
                if ($delay === null) {
                    $results[$index] = $this->fail($response);
                    if ($stop === null && ($results[$index] instanceof AuthenticationException
                        || $results[$index] instanceof RateLimitException)
                    ) {
                        $stop = $results[$index];
                        if ($stop instanceof RateLimitException) {
                            $this->rateLimitGuard->pause($stop->getRetryAfter());
                        }
                    }
                    continue;
                }
                $retry[$index] = $item;
                $wait = max($wait, $delay);
            }

            // One failing round counts once, so parallel failures cannot open the circuit before retries happen.
            $this->circuitBreaker->record($failures > 0 ? 1 : 0, $anySuccess);
            if ($retry !== [] && $stop === null) {
                $this->clock->sleep($wait);
            }
            $todo = $retry;
        }
    }

    /**
     * Fail every key with CircuitOpen/RateLimit/BudgetExceeded when sending is not allowed; true when it refused.
     *
     * @param array[] $todo
     * @param array $results
     * @return bool
     */
    private function refuseIfBlocked(array $todo, array &$results): bool
    {
        if ($this->circuitBreaker->isOpen()) {
            foreach (array_keys($todo) as $index) {
                $results[$index] = new CircuitOpenException(__(
                    'TypeSafe calls are paused for up to %1 s after repeated failures.',
                    CircuitBreaker::OPEN_SECONDS
                ));
            }
            return true;
        }
        $pause = $this->rateLimitGuard->remainingSeconds();
        if ($pause > 0) {
            foreach (array_keys($todo) as $index) {
                $results[$index] = new RateLimitException(
                    __('TypeSafe rate limit reached; calls are paused for %1 s.', $pause),
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
                    __('TypeSafe monthly token budget (%1) is used up.', $this->config->getMonthlyTokenBudget())
                );
            }
            return true;
        }

        return false;
    }

    /**
     * Seconds to wait before retrying a failed attempt, or null when the failure is final.
     *
     * @param HttpResponse $response
     * @param int $attempt
     * @return float|null
     */
    private function retryDelay(HttpResponse $response, int $attempt): ?float
    {
        if (!$response->isTransportError() && $response->getStatus() === 429) {
            $retryAfter = $this->backoff->parseRetryAfter($response->getHeader('retry-after'));
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
     * @param array $item miss as {request, model, key, body}
     * @param HttpResponse $response
     * @param int $attempt
     * @param int $maxAttempts
     * @return void
     */
    private function logFailure(array $item, HttpResponse $response, int $attempt, int $maxAttempts): void
    {
        $reason = $response->isTransportError()
            ? ($response->getError() !== '' ? $response->getError() : 'no response')
            : 'HTTP ' . $response->getStatus();
        $this->logger->warning(
            sprintf('TypeSafe request failed (attempt %d/%d): %s', $attempt, $maxAttempts, $reason),
            [
                'consumer' => $item['request']->getConsumer(),
                'model' => $item['model'],
                'status' => $response->getStatus(),
            ]
        );
    }

    /**
     * Build the `POST /v1/systemone` request.
     *
     * @param string $body
     * @param string $apiKey
     * @return HttpRequest
     */
    private function httpRequest(string $body, #[\SensitiveParameter] string $apiKey): HttpRequest
    {
        return new HttpRequest(
            'POST',
            $this->config->getBaseUrl() . self::ENDPOINT,
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Expect' => '',
            ],
            $body,
            $this->config->getTimeout()
        );
    }

    /**
     * Parse a 2xx body, then cache it and record its tokens.
     *
     * A malformed body is a final TransportException; its billed tokens are still recorded.
     *
     * @param array $item miss as {request, model, key, body}
     * @param HttpResponse $httpResponse
     * @return Response|TransportException
     */
    private function succeed(array $item, HttpResponse $httpResponse): Response|TransportException
    {
        $request = $item['request'];
        try {
            $response = $this->parser->parse($httpResponse->getBody(), $request, false);
        } catch (TransportException $e) {
            $tokens = $this->parser->billedTokens($httpResponse->getBody());
            if ($tokens > 0) {
                $this->ledger->record($request->getConsumer(), $tokens, false);
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
        $this->ledger->record($request->getConsumer(), $response->getInputTokens(), false);

        return $response;
    }

    /**
     * Map a non-2xx or transport-error response to its final exception.
     *
     * @param HttpResponse $response
     * @return TypeSafeException
     */
    private function fail(HttpResponse $response): TypeSafeException
    {
        $status = $response->getStatus();
        if ($response->isTransportError()) {
            return new TransportException(
                __('TypeSafe request failed: %1', $response->getError() !== '' ? $response->getError() : 'no response')
            );
        }
        if ($status === 401 || $status === 403) {
            return new AuthenticationException(__('TypeSafe rejected the API key (HTTP %1).', $status), null, $status);
        }
        if ($status === 429) {
            $retryAfter = $this->backoff->parseRetryAfter($response->getHeader('retry-after'));

            return new RateLimitException(
                $retryAfter !== null
                    ? __('TypeSafe rate limit reached; retry after %1 s.', $retryAfter)
                    : __('TypeSafe rate limit reached.'),
                null,
                $status,
                $retryAfter
            );
        }
        if ($status === 408 || $status >= 500) {
            return new TransportException(__('TypeSafe answered HTTP %1.', $status), null, $status);
        }

        return new RequestException(
            __('TypeSafe rejected the request (HTTP %1): %2', $status, $this->errorDetail($response->getBody())),
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

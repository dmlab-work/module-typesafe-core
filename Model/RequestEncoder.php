<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;

/**
 * Resolves the model and builds the cache key and HTTP body of a Request.
 */
class RequestEncoder
{
    public const MAX_BODY_BYTES = 32 * 1024 * 1024;

    /**
     * @param ConfigInterface $config
     * @param CanonicalJson $canonicalJson
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CanonicalJson $canonicalJson
    ) {
    }

    /**
     * Request model override, else the configured model.
     *
     * @param Request $request
     * @return string
     */
    public function resolveModel(Request $request): string
    {
        return $request->getModel() ?? $this->config->getModel();
    }

    /**
     * Sha256 of the canonical {model, state, questions}; the consumer is not part of it.
     *
     * @param Request $request
     * @param string $model
     * @return string
     * @throws RequestException
     */
    public function cacheKey(Request $request, string $model): string
    {
        // The body always sends questions as an object, so only the id set matters, not whether ids form a list.
        $questions = $this->questions($request);
        ksort($questions, SORT_STRING);
        try {
            $json = $this->canonicalJson->encode([
                'model' => $model,
                'state' => $request->getState(),
                'questions' => $questions,
            ]);
        } catch (\JsonException $e) {
            throw $this->encodeFailed($e);
        }

        return hash('sha256', $json);
    }

    /**
     * JSON body for `POST /v1/systemone`.
     *
     * @param Request $request
     * @param string $model
     * @return string
     * @throws RequestException
     */
    public function body(Request $request, string $model): string
    {
        try {
            $body = json_encode([
                'model' => $model,
                'state' => $request->getState(),
                'questions' => (object)$this->questions($request),
            ], CanonicalJson::FLAGS);
        } catch (\JsonException $e) {
            throw $this->encodeFailed($e);
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RequestException(__('TypeSafe request body exceeds 32 MiB.'));
        }

        return $body;
    }

    /**
     * Questions as arrays, keyed by id.
     *
     * @param Request $request
     * @return array
     */
    private function questions(Request $request): array
    {
        return array_map(static fn ($question) => $question->toArray(), $request->getQuestions());
    }

    /**
     * Build the encode-failure exception.
     *
     * @param \JsonException $e
     * @return RequestException
     */
    private function encodeFailed(\JsonException $e): RequestException
    {
        return new RequestException(__('TypeSafe request could not be encoded: %1', $e->getMessage()), $e);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;

/**
 * Resolves the model and builds the provider-scoped cache key of a Request.
 */
class RequestEncoder
{
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
     * Sha256 of the provider's cache scope + canonical {model, state, questions}; the consumer is not part of it.
     *
     * @param Request $request
     * @param string $model
     * @param ProviderInterface $provider
     * @return string
     * @throws RequestException
     */
    public function cacheKey(Request $request, string $model, ProviderInterface $provider): string
    {
        $questions = array_map(static fn ($question) => $question->toArray(), $request->getQuestions());
        $scope = $provider->cacheScope();
        if ($scope === '') {
            // Bare-key answers are keyed by id, so only the id set matters.
            ksort($questions, SORT_STRING);
        } else {
            // Scoped (LLM) answers are positional q1…qN; an object keeps question order in the key.
            $questions = (object)$questions;
        }
        try {
            $json = $this->canonicalJson->encode([
                'model' => $model,
                'state' => $request->getState(),
                'questions' => $questions,
            ]);
        } catch (\JsonException $e) {
            throw new RequestException(
                __('%1 request could not be encoded: %2', $provider->getLabel(), $e->getMessage()),
                $e
            );
        }

        return hash('sha256', $scope . $json);
    }
}

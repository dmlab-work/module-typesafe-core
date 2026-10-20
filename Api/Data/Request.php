<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data;

use DmLab\TypeSafeCore\Api\Data\Question\QuestionInterface;

/**
 * Immutable TypeSafe evaluation request: state + questions, attributed to a consumer.
 *
 * @api
 */
class Request
{
    use ContentValidationTrait;

    public const CONSUMER_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param string|array $state
     * @param QuestionInterface[] $questions keyed by question id
     * @param string $consumer
     * @param string|null $model overrides the configured model
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string|array $state,
        private readonly array $questions,
        private readonly string $consumer,
        private readonly ?string $model = null
    ) {
        $this->assertContent($state, 'Request state');
        if ($questions === []) {
            throw new \InvalidArgumentException('Request needs at least one question.');
        }
        foreach ($questions as $id => $question) {
            if (trim((string)$id) === '') {
                throw new \InvalidArgumentException('Question id must be a non-empty string.');
            }
            if (!$question instanceof QuestionInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Question "%s" must implement %s.', $id, QuestionInterface::class)
                );
            }
        }
        if (!preg_match(self::CONSUMER_PATTERN, $consumer)) {
            throw new \InvalidArgumentException(
                sprintf('Consumer code "%s" must match %s.', $consumer, self::CONSUMER_PATTERN)
            );
        }
        if ($model !== null && trim($model) === '') {
            throw new \InvalidArgumentException('Model must be null or a non-empty string.');
        }
    }

    /**
     * Context the questions are asked about.
     *
     * @return string|array
     */
    public function getState(): string|array
    {
        return $this->state;
    }

    /**
     * Questions keyed by id (numeric ids become int keys; cast with (string)).
     *
     * @return array<string, QuestionInterface>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * Consumer code used for usage accounting.
     *
     * @return string
     */
    public function getConsumer(): string
    {
        return $this->consumer;
    }

    /**
     * Model override; null = configured model.
     *
     * @return string|null
     */
    public function getModel(): ?string
    {
        return $this->model;
    }
}

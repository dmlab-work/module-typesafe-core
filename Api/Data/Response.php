<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Api\Data;

use DmLab\TypeSafeCore\Api\Data\Answer\AnswerInterface;

/**
 * Immutable TypeSafe evaluation result.
 *
 * @api
 */
class Response
{
    /**
     * @param string $model versioned model id that answered
     * @param int $inputTokens tokens this call cost (0 when cached)
     * @param bool $cached
     * @param AnswerInterface[] $answers keyed by question id
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string $model,
        private readonly int $inputTokens,
        private readonly bool $cached,
        private readonly array $answers
    ) {
        foreach ($answers as $id => $answer) {
            if (!$answer instanceof AnswerInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Answer "%s" must implement %s.', $id, AnswerInterface::class)
                );
            }
        }
    }

    /**
     * Versioned model id that answered.
     *
     * @return string
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Input tokens this call cost; 0 for a cache hit.
     *
     * @return int
     */
    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    /**
     * Whether the response came from the request cache.
     *
     * @return bool
     */
    public function isCached(): bool
    {
        return $this->cached;
    }

    /**
     * Answer for one question id.
     *
     * @param string $id
     * @return AnswerInterface
     * @throws \OutOfBoundsException
     */
    public function getAnswer(string $id): AnswerInterface
    {
        if (!isset($this->answers[$id])) {
            throw new \OutOfBoundsException(sprintf('No answer for question "%s".', $id));
        }

        return $this->answers[$id];
    }

    /**
     * Answers keyed by question id (numeric ids become int keys).
     *
     * @return array<string, AnswerInterface>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }
}

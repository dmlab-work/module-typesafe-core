<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Llm;

use DmLab\TypeSafeCore\Api\Data\Question\QuestionInterface;
use DmLab\TypeSafeCore\Api\Data\Request;

/**
 * Positional answer keys `q1…qN` for a request's questions; ids like `color:rev` are unsafe schema property names.
 */
class QuestionKeys
{
    /**
     * @var array<string, int|string> key => original id
     */
    private array $ids = [];

    /**
     * @var array<string, string> (string)id => key
     */
    private array $keys = [];

    /**
     * @var array<string, QuestionInterface> key => question
     */
    private array $questions = [];

    /**
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $position = 0;
        foreach ($request->getQuestions() as $id => $question) {
            $position++;
            $key = 'q' . $position;
            $this->ids[$key] = $id;
            $this->keys[(string)$id] = $key;
            $this->questions[$key] = $question;
        }
    }

    /**
     * Answer key of a question id.
     *
     * @param string $id
     * @return string
     * @throws \OutOfBoundsException
     */
    public function keyOf(string $id): string
    {
        if (!isset($this->keys[$id])) {
            throw new \OutOfBoundsException(sprintf('No question "%s".', $id));
        }

        return $this->keys[$id];
    }

    /**
     * Original question id of an answer key (numeric ids as int, like Request::getQuestions()).
     *
     * @param string $key
     * @return int|string
     * @throws \OutOfBoundsException
     */
    public function idOf(string $key): int|string
    {
        if (!isset($this->ids[$key])) {
            throw new \OutOfBoundsException(sprintf('No answer key "%s".', $key));
        }

        return $this->ids[$key];
    }

    /**
     * Questions keyed by answer key, in request order.
     *
     * @return array<string, QuestionInterface>
     */
    public function questions(): array
    {
        return $this->questions;
    }

    /**
     * Question type of an answer key: noul, choice or score.
     *
     * @param string $key
     * @return string
     * @throws \OutOfBoundsException
     */
    public function type(string $key): string
    {
        return (string)($this->payload($key)['type'] ?? '');
    }

    /**
     * Choice options as strings, in display order; [] for other types.
     *
     * @param string $key
     * @return string[]
     * @throws \OutOfBoundsException
     */
    public function options(string $key): array
    {
        $payload = $this->payload($key);
        if (($payload['type'] ?? '') !== 'choice') {
            return [];
        }

        return array_map('strval', array_keys((array)$payload['criteria']));
    }

    /**
     * Number of score levels; 0 for other types.
     *
     * @param string $key
     * @return int
     * @throws \OutOfBoundsException
     */
    public function levelCount(string $key): int
    {
        $payload = $this->payload($key);

        return ($payload['type'] ?? '') === 'score' ? count($payload['criteria']) : 0;
    }

    /**
     * Serialized question of an answer key.
     *
     * @param string $key
     * @return array
     * @throws \OutOfBoundsException
     */
    private function payload(string $key): array
    {
        if (!isset($this->questions[$key])) {
            throw new \OutOfBoundsException(sprintf('No answer key "%s".', $key));
        }

        return $this->questions[$key]->toArray();
    }
}

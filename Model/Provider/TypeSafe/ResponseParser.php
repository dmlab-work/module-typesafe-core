<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider\TypeSafe;

use DmLab\TypeSafeCore\Api\Data\Answer\AnswerInterface;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Api\Data\Response;
use DmLab\TypeSafeCore\Exception\TransportException;

/**
 * Turns a raw `/v1/systemone` body into a Response checked against the request's questions.
 */
class ResponseParser
{
    /**
     * Parse a response body; extra answers are ignored, a cached parse costs 0 tokens.
     *
     * @param string $body
     * @param Request $request
     * @param string $model resolved model, used when the body names none
     * @param bool $cached
     * @return Response
     * @throws TransportException
     */
    public function parse(string $body, Request $request, string $model, bool $cached): Response
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw $this->malformed('invalid JSON (' . $e->getMessage() . ')', $e);
        }
        if (!is_array($data) || !isset($data['answers']) || !is_array($data['answers'])) {
            throw $this->malformed('no "answers" object');
        }

        if (is_string($data['model'] ?? null) && $data['model'] !== '') {
            $model = $data['model'];
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        $tokens = $cached ? 0 : max(0, (int)($usage['input_tokens'] ?? 0));

        $answers = [];
        foreach ($request->getQuestions() as $id => $question) {
            $answers[$id] = $this->answer((string)$id, $question->toArray()['type'], $data['answers'][$id] ?? null);
        }

        return new Response($model, $tokens, $cached, $answers);
    }

    /**
     * Billed `usage.input_tokens` of a body that may fail full parsing; 0 when absent or unreadable.
     *
     * @param string $body
     * @return int
     */
    public function billedTokens(string $body): int
    {
        $data = json_decode($body, true);
        $tokens = is_array($data) && is_array($data['usage'] ?? null) ? ($data['usage']['input_tokens'] ?? 0) : 0;

        return is_numeric($tokens) ? max(0, (int)$tokens) : 0;
    }

    /**
     * Build one typed answer.
     *
     * @param string $id
     * @param string $type expected question type
     * @param mixed $raw
     * @return AnswerInterface
     * @throws TransportException
     */
    private function answer(string $id, string $type, mixed $raw): AnswerInterface
    {
        if (!is_array($raw)) {
            throw $this->malformed(sprintf('missing answer "%s"', $id));
        }
        if (($raw['type'] ?? null) !== $type) {
            throw $this->malformed(sprintf('answer "%s" is not of type "%s"', $id, $type));
        }

        return match ($type) {
            'noul' => new NoulAnswer($this->number($raw, 'noul', $id)),
            'choice' => new ChoiceAnswer(
                $this->choice($raw, $id),
                $this->probabilities($raw, $id),
                $this->number($raw, 'confidence', $id)
            ),
            'score' => new ScoreAnswer(
                $this->number($raw, 'score', $id),
                $this->probabilities($raw, $id),
                $this->number($raw, 'confidence', $id)
            ),
            default => throw $this->malformed(sprintf('unsupported answer type "%s"', $type)),
        };
    }

    /**
     * Required numeric field.
     *
     * @param array $raw
     * @param string $field
     * @param string $id
     * @return float
     * @throws TransportException
     */
    private function number(array $raw, string $field, string $id): float
    {
        if (!isset($raw[$field]) || !is_numeric($raw[$field])) {
            throw $this->malformed(sprintf('answer "%s" has no numeric "%s"', $id, $field));
        }

        return (float)$raw[$field];
    }

    /**
     * Required string-castable `choice`.
     *
     * @param array $raw
     * @param string $id
     * @return string
     * @throws TransportException
     */
    private function choice(array $raw, string $id): string
    {
        $choice = $raw['choice'] ?? null;
        if (!is_string($choice) && !is_int($choice)) {
            throw $this->malformed(sprintf('answer "%s" has no "choice"', $id));
        }

        return (string)$choice;
    }

    /**
     * Required `probabilities` map, values cast to float.
     *
     * @param array $raw
     * @param string $id
     * @return array
     * @throws TransportException
     */
    private function probabilities(array $raw, string $id): array
    {
        $probabilities = $raw['probabilities'] ?? null;
        if (!is_array($probabilities)) {
            throw $this->malformed(sprintf('answer "%s" has no "probabilities"', $id));
        }
        foreach ($probabilities as $key => $value) {
            if (!is_numeric($value)) {
                throw $this->malformed(sprintf('answer "%s" has a non-numeric probability for "%s"', $id, $key));
            }
            $probabilities[$key] = (float)$value;
        }

        return $probabilities;
    }

    /**
     * Build the malformed-response exception.
     *
     * @param string $detail
     * @param \Exception|null $cause
     * @return TransportException
     */
    private function malformed(string $detail, ?\Exception $cause = null): TransportException
    {
        return new TransportException(__('TypeSafe returned a malformed response: %1', $detail), $cause);
    }
}

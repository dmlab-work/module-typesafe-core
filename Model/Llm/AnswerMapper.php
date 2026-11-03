<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Llm;

use DmLab\TypeSafeCore\Api\Data\Answer\AnswerInterface;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;

/**
 * Validates an LLM's decoded answer object and maps it to typed answers keyed by the original question ids.
 *
 * Self-reported confidence c becomes the probability of the given answer; the rest is spread evenly.
 */
class AnswerMapper
{
    /**
     * Answers keyed by question id; extra keys and fields are ignored.
     *
     * @param mixed $decoded `{qN: {...}}` as decoded by json_decode(…, true)
     * @param QuestionKeys $keys
     * @return array<int|string, AnswerInterface>
     * @throws InvalidAnswerException with a short reason, usable as the repair note
     */
    public function map(mixed $decoded, QuestionKeys $keys): array
    {
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw $this->invalid('the reply is not a JSON object.');
        }

        $answers = [];
        foreach (array_keys($keys->questions()) as $key) {
            $raw = $decoded[$key] ?? null;
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                throw $this->invalid(sprintf('%s is missing or not an object.', $key));
            }
            $confidence = $this->number($raw, 'confidence');
            if ($confidence === null || $confidence < 0.0 || $confidence > 1.0) {
                throw $this->invalid(sprintf('%s.confidence must be a number from 0 to 1.', $key));
            }

            $answers[$keys->idOf($key)] = match ($keys->type($key)) {
                'choice' => $this->choice($raw, $key, $keys->options($key), $confidence),
                'score' => $this->score($raw, $key, $keys->levelCount($key), $confidence),
                default => $this->noul($raw, $key, $confidence),
            };
        }

        return $answers;
    }

    /**
     * Choice answer; the choice must be one of the options exactly.
     *
     * @param array $raw
     * @param string $key
     * @param string[] $options
     * @param float $confidence
     * @return ChoiceAnswer
     * @throws InvalidAnswerException
     */
    private function choice(array $raw, string $key, array $options, float $confidence): ChoiceAnswer
    {
        $choice = $raw['choice'] ?? null;
        if (!is_string($choice) || !in_array($choice, $options, true)) {
            throw $this->invalid(sprintf('%s.choice must be one of the listed options, exactly as written.', $key));
        }

        return new ChoiceAnswer($choice, $this->spread($options, $choice, $confidence), $confidence);
    }

    /**
     * Score answer; the score must lie within the level indexes.
     *
     * @param array $raw
     * @param string $key
     * @param int $levels
     * @param float $confidence
     * @return ScoreAnswer
     * @throws InvalidAnswerException
     */
    private function score(array $raw, string $key, int $levels, float $confidence): ScoreAnswer
    {
        $score = $this->number($raw, 'score');
        if ($score === null || $score < 0.0 || $score > $levels - 1) {
            throw $this->invalid(sprintf('%s.score must be a number from 0 to %d.', $key, $levels - 1));
        }

        $probabilities = $this->spread(range(0, $levels - 1), (int)round($score), $confidence);

        return new ScoreAnswer($score, $probabilities, $confidence);
    }

    /**
     * Noul answer: true → c, false → 1 - c, null (unknown) → 0.5.
     *
     * @param array $raw
     * @param string $key
     * @param float $confidence
     * @return NoulAnswer
     * @throws InvalidAnswerException
     */
    private function noul(array $raw, string $key, float $confidence): NoulAnswer
    {
        $answer = array_key_exists('answer', $raw) ? $raw['answer'] : 'missing';
        if ($answer !== true && $answer !== false && $answer !== null) {
            throw $this->invalid(sprintf('%s.answer must be true, false or null.', $key));
        }

        return new NoulAnswer(match ($answer) {
            true => $confidence,
            false => 1.0 - $confidence,
            null => 0.5,
        });
    }

    /**
     * Probabilities: c for the picked value, (1 - c) / (n - 1) for each other.
     *
     * @param array $values
     * @param int|string $picked
     * @param float $confidence
     * @return array
     */
    private function spread(array $values, int|string $picked, float $confidence): array
    {
        $rest = count($values) > 1 ? (1.0 - $confidence) / (count($values) - 1) : 0.0;
        $probabilities = [];
        foreach ($values as $value) {
            $probabilities[$value] = $value === $picked ? $confidence : $rest;
        }

        return $probabilities;
    }

    /**
     * Int or float field as float; null when absent or not a number.
     *
     * @param array $raw
     * @param string $field
     * @return float|null
     */
    private function number(array $raw, string $field): ?float
    {
        $value = $raw[$field] ?? null;

        return is_int($value) || is_float($value) ? (float)$value : null;
    }

    /**
     * Invalid-answer exception with a short reason.
     *
     * @param string $reason
     * @return InvalidAnswerException
     */
    private function invalid(string $reason): InvalidAnswerException
    {
        return new InvalidAnswerException(__('Invalid answer: %1', $reason));
    }
}

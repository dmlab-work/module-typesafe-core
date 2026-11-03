<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Llm;

use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;

/**
 * Fixed system prompt and JSON user message shared by every LLM provider.
 */
class PromptBuilder
{
    /** Part of every LLM cache scope: bump on any prompt or schema change so cached answers are not reused. */
    public const PROMPT_VERSION = '1';

    private const BASE = 'You answer questions about a state. The user message is JSON: "state" is the context, '
        . '"questions" maps answer keys (q1, q2, ...) to questions with "type", "instructions" and "criteria". '
        . 'Use only facts stated in or clearly implied by the state; never invent facts. '
        . 'Reply with one JSON object that maps every answer key to its answer, as the response schema describes, '
        . 'and nothing else. "confidence" is your probability, from 0 to 1, that the answer is correct.';

    private const SECTIONS = [
        'noul' => 'Type "noul": decide whether "instructions" is true for the state; "criteria", when present, '
            . 'describes what counts as true and as false. Answer {"answer": true, false or null, '
            . '"confidence": number}; answer null when the state does not tell.',
        'choice' => 'Type "choice": pick the option that fits the state best. "criteria" maps each option to its '
            . 'description (null: the option name says it). Answer {"choice": the option exactly as written, '
            . '"confidence": number}.',
        'score' => 'Type "score": rate the state on the levels in "criteria", lowest first. Answer '
            . '{"score": number, "confidence": number}; score is the 0-based level index (0 = first level) '
            . 'and may be fractional between two adjacent levels.',
    ];

    /**
     * Base section plus one section per question type present, in the order noul, choice, score.
     *
     * @param QuestionKeys $keys
     * @return string
     */
    public function system(QuestionKeys $keys): string
    {
        $types = [];
        foreach ($keys->questions() as $question) {
            $types[$question->toArray()['type'] ?? ''] = true;
        }

        $sections = [self::BASE];
        foreach (self::SECTIONS as $type => $section) {
            if (isset($types[$type])) {
                $sections[] = $section;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * JSON `{state, questions: {qN: question}}`, then the repair note as a second paragraph.
     *
     * @param Request $request
     * @param QuestionKeys $keys
     * @param string|null $repairNote why the previous reply was rejected
     * @return string
     * @throws \JsonException
     */
    public function user(Request $request, QuestionKeys $keys, ?string $repairNote = null): string
    {
        $message = json_encode([
            'state' => $request->getState(),
            'questions' => array_map(static fn ($question) => $question->toArray(), $keys->questions()),
        ], CanonicalJson::FLAGS);

        if ($repairNote !== null && trim($repairNote) !== '') {
            $message .= "\n\nYour previous reply was rejected: " . trim($repairNote)
                . ' Reply again with one JSON object that follows the schema.';
        }

        return $message;
    }
}

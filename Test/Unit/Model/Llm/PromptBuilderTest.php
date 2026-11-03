<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Llm;

use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\QuestionInterface;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Model\Llm\PromptBuilder;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use PHPUnit\Framework\TestCase;

class PromptBuilderTest extends TestCase
{
    /** Pinned so a prompt edit fails here until PROMPT_VERSION is bumped and this hash updated. */
    private const PROMPT_V1_SHA256 = 'afed63ce49ca80b8242c7556a612b62ca6049efd6589113b42820707c33691da';

    /**
     * @param array<string, QuestionInterface> $questions
     * @param string|array $state
     * @return array{Request, QuestionKeys}
     */
    private function request(array $questions, string|array $state = 'A red wool sofa.'): array
    {
        $request = new Request($state, $questions, 'catalog_enrich');

        return [$request, new QuestionKeys($request)];
    }

    private function all(): array
    {
        return $this->request([
            'size' => new ScoreQuestion('How big?', ['small', 'large']),
            'color:rev' => new ChoiceQuestion('Color?', ['red' => 'Red-ish', 'blue' => null]),
            'wool' => new NoulQuestion('Is it wool?', 'Wool is named.'),
        ]);
    }

    public function testSystemPromptHasOnlyPresentTypesInFixedOrder(): void
    {
        $builder = new PromptBuilder();

        [, $noul] = $this->request(['a' => new NoulQuestion('Red?')]);
        $system = $builder->system($noul);
        self::assertStringContainsString('Type "noul"', $system);
        self::assertStringNotContainsString('Type "choice"', $system);
        self::assertStringNotContainsString('Type "score"', $system);

        [, $keys] = $this->all();
        $system = $builder->system($keys);
        $noulAt = strpos($system, 'Type "noul"');
        $choiceAt = strpos($system, 'Type "choice"');
        $scoreAt = strpos($system, 'Type "score"');
        self::assertIsInt($noulAt);
        self::assertTrue($noulAt < $choiceAt && $choiceAt < $scoreAt, 'order noul, choice, score');
        self::assertStringStartsWith('You answer questions about a state.', $system);
    }

    public function testSystemPromptIsStableByteForByte(): void
    {
        [, $keys] = $this->all();
        $system = (new PromptBuilder())->system($keys);

        self::assertSame($system, (new PromptBuilder())->system($keys));
        self::assertSame('1', PromptBuilder::PROMPT_VERSION);
        self::assertSame(self::PROMPT_V1_SHA256, hash('sha256', $system), 'Prompt changed: bump PROMPT_VERSION.');
    }

    public function testUserMessageIsJsonKeyedByAnswerKeys(): void
    {
        [$request, $keys] = $this->all();
        $user = (new PromptBuilder())->user($request, $keys);

        self::assertSame(
            '{"state":"A red wool sofa.","questions":{'
            . '"q1":{"type":"score","instructions":"How big?","criteria":["small","large"]},'
            . '"q2":{"type":"choice","instructions":"Color?","criteria":{"red":"Red-ish","blue":null}},'
            . '"q3":{"type":"noul","instructions":"Is it wool?","criteria":{"true":"Wool is named."}}}}',
            $user
        );
        self::assertStringNotContainsString('color:rev', $user);
    }

    public function testRepairNoteIsASecondParagraph(): void
    {
        [$request, $keys] = $this->request(['a' => new NoulQuestion('Red?')], ['title' => 'Sofa', 'tags' => ['a']]);
        $user = (new PromptBuilder())->user($request, $keys, ' Invalid answer: q1 is missing or not an object. ');

        [$json, $note] = explode("\n\n", $user, 2);
        self::assertSame(
            '{"state":{"title":"Sofa","tags":["a"]},"questions":{"q1":{"type":"noul","instructions":"Red?"}}}',
            $json
        );
        self::assertSame(
            'Your previous reply was rejected: Invalid answer: q1 is missing or not an object.'
            . ' Reply again with one JSON object that follows the schema.',
            $note
        );
        self::assertSame($json, (new PromptBuilder())->user($request, $keys, '  '));
    }

    public function testUnencodableStateThrowsJsonException(): void
    {
        [$request, $keys] = $this->request(['a' => new NoulQuestion('Red?')], ['x' => NAN]);

        $this->expectException(\JsonException::class);
        (new PromptBuilder())->user($request, $keys);
    }
}

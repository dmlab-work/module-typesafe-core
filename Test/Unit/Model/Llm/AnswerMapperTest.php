<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Llm;

use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Exception\InvalidAnswerException;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\Llm\AnswerMapper;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnswerMapperTest extends TestCase
{
    private const VALID = [
        'q1' => ['choice' => 'wool', 'confidence' => 0.8],
        'q2' => ['score' => 1.6, 'confidence' => 0.7],
        'q3' => ['answer' => true, 'confidence' => 0.9],
        'q4' => ['answer' => false, 'confidence' => 0.75],
        'q5' => ['answer' => null, 'confidence' => 0.3],
    ];

    private function keys(): QuestionKeys
    {
        return new QuestionKeys(new Request('state', [
            'material:rev' => new ChoiceQuestion('Material?', ['wool' => null, 'cotton' => null, '0' => null]),
            'size' => new ScoreQuestion('Size?', ['s', 'm', 'l']),
            'red' => new NoulQuestion('Red?'),
            412 => new NoulQuestion('Blue?'),
            'x:9' => new NoulQuestion('Green?'),
        ], 'catalog_enrich'));
    }

    public function testMapsEveryTypeKeyedByOriginalIds(): void
    {
        $answers = (new AnswerMapper())->map(self::VALID + ['q9' => 'extra keys are ignored'], $this->keys());

        self::assertSame(['material:rev', 'size', 'red', 412, 'x:9'], array_keys($answers));

        $choice = $answers['material:rev'];
        self::assertInstanceOf(ChoiceAnswer::class, $choice);
        self::assertSame('wool', $choice->getChoice());
        self::assertSame(0.8, $choice->getConfidence());
        self::assertEqualsWithDelta(['wool' => 0.8, 'cotton' => 0.1, 0 => 0.1], $choice->getProbabilities(), 1e-9);
        self::assertSame(['wool', 'cotton', 0], array_keys($choice->getProbabilities()));

        $score = $answers['size'];
        self::assertInstanceOf(ScoreAnswer::class, $score);
        self::assertSame(1.6, $score->getScore());
        self::assertSame(0.7, $score->getConfidence());
        self::assertEqualsWithDelta([0 => 0.15, 1 => 0.15, 2 => 0.7], $score->getProbabilities(), 1e-9);

        self::assertInstanceOf(NoulAnswer::class, $answers['red']);
        self::assertSame(0.9, $answers['red']->getProbability());
        self::assertSame(0.25, $answers[412]->getProbability());
        self::assertSame(0.5, $answers['x:9']->getProbability());
    }

    public function testNumericOptionAndIntegerNumbers(): void
    {
        $answers = (new AnswerMapper())->map(
            ['q1' => ['choice' => '0', 'confidence' => 1], 'q2' => ['score' => 0, 'confidence' => 0]] + self::VALID,
            $this->keys()
        );

        self::assertSame('0', $answers['material:rev']->getChoice());
        self::assertSame([0.0, 0.0, 1.0], array_values($answers['material:rev']->getProbabilities()));
        self::assertSame(0.0, $answers['size']->getScore());
        self::assertSame([0 => 0.0, 1 => 0.5, 2 => 0.5], $answers['size']->getProbabilities());
    }

    public function testSingleOptionChoiceGetsTheWholeConfidence(): void
    {
        $keys = new QuestionKeys(new Request('s', ['c' => new ChoiceQuestion('Pick', ['only' => null])], 'x'));

        $answer = (new AnswerMapper())->map(['q1' => ['choice' => 'only', 'confidence' => 0.6]], $keys)['c'];

        self::assertSame(['only' => 0.6], $answer->getProbabilities());
    }

    public static function invalidProvider(): array
    {
        $with = static fn (string $key, mixed $value) => array_replace(self::VALID, [$key => $value]);

        return [
            'not an object' => ['nope', 'the reply is not a JSON object.'],
            'list' => [[1, 2], 'the reply is not a JSON object.'],
            'empty' => [[], 'q1 is missing or not an object.'],
            'missing key' => [array_diff_key(self::VALID, ['q3' => 1]), 'q3 is missing or not an object.'],
            'answer is a list' => [$with('q2', [1, 0.5]), 'q2 is missing or not an object.'],
            'no confidence' => [$with('q1', ['choice' => 'wool']), 'q1.confidence must be a number from 0 to 1.'],
            'string confidence' => [
                $with('q1', ['choice' => 'wool', 'confidence' => '0.9']),
                'q1.confidence must be a number from 0 to 1.',
            ],
            'confidence above 1' => [
                $with('q3', ['answer' => true, 'confidence' => 1.01]),
                'q3.confidence must be a number from 0 to 1.',
            ],
            'confidence below 0' => [
                $with('q3', ['answer' => true, 'confidence' => -0.1]),
                'q3.confidence must be a number from 0 to 1.',
            ],
            'unknown choice' => [
                $with('q1', ['choice' => 'silk', 'confidence' => 0.5]),
                'q1.choice must be one of the listed options, exactly as written.',
            ],
            'choice case differs' => [
                $with('q1', ['choice' => 'Wool', 'confidence' => 0.5]),
                'q1.choice must be one of the listed options, exactly as written.',
            ],
            'int choice' => [
                $with('q1', ['choice' => 0, 'confidence' => 0.5]),
                'q1.choice must be one of the listed options, exactly as written.',
            ],
            'score above range' => [
                $with('q2', ['score' => 2.5, 'confidence' => 0.5]),
                'q2.score must be a number from 0 to 2.',
            ],
            'negative score' => [
                $with('q2', ['score' => -1, 'confidence' => 0.5]),
                'q2.score must be a number from 0 to 2.',
            ],
            'string score' => [
                $with('q2', ['score' => '1', 'confidence' => 0.5]),
                'q2.score must be a number from 0 to 2.',
            ],
            'missing noul answer' => [
                $with('q4', ['confidence' => 0.5]),
                'q4.answer must be true, false or null.',
            ],
            'string noul answer' => [
                $with('q4', ['answer' => 'yes', 'confidence' => 0.5]),
                'q4.answer must be true, false or null.',
            ],
            'int noul answer' => [
                $with('q5', ['answer' => 1, 'confidence' => 0.5]),
                'q5.answer must be true, false or null.',
            ],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidAnswers(mixed $decoded, string $reason): void
    {
        try {
            (new AnswerMapper())->map($decoded, $this->keys());
            self::fail('Expected InvalidAnswerException.');
        } catch (InvalidAnswerException $e) {
            self::assertInstanceOf(TransportException::class, $e);
            self::assertSame('Invalid answer: ' . $reason, $e->getMessage());
        }
    }
}

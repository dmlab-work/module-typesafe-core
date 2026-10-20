<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api\Data\Question;

use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\QuestionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NoulQuestionTest extends TestCase
{
    public function testCriteriaOmittedWhenBothSidesNull(): void
    {
        $question = new NoulQuestion('Is it red?');

        self::assertInstanceOf(QuestionInterface::class, $question);
        self::assertSame(['type' => 'noul', 'instructions' => 'Is it red?'], $question->toArray());
    }

    public function testBothSides(): void
    {
        $question = new NoulQuestion(['task' => 'Is it red?'], 'Mostly red', ['note' => 'Any other colour']);

        self::assertSame(
            [
                'type' => 'noul',
                'instructions' => ['task' => 'Is it red?'],
                'criteria' => ['true' => 'Mostly red', 'false' => ['note' => 'Any other colour']],
            ],
            $question->toArray()
        );
    }

    public function testOnlyTrueSide(): void
    {
        self::assertSame(['true' => 'Red'], (new NoulQuestion('Is it red?', 'Red'))->toArray()['criteria']);
    }

    public function testOnlyFalseSide(): void
    {
        self::assertSame(['false' => 'Blue'], (new NoulQuestion('Is it red?', null, 'Blue'))->toArray()['criteria']);
    }

    /**
     * @return array<string, array{0: string|array, 1: string|array|null, 2: string|array|null}>
     */
    public static function invalidProvider(): array
    {
        return [
            'empty instructions' => ['', null, null],
            'blank instructions' => ["  \n", null, null],
            'empty array instructions' => [[], null, null],
            'empty true side' => ['Q', ' ', null],
            'empty false side' => ['Q', null, []],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputThrows(
        string|array $instructions,
        string|array|null $true,
        string|array|null $false
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new NoulQuestion($instructions, $true, $false);
    }
}

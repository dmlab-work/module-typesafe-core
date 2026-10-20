<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api\Data\Question;

use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoreQuestionTest extends TestCase
{
    public function testToArrayShape(): void
    {
        self::assertSame(
            ['type' => 'score', 'instructions' => 'How good?', 'criteria' => ['bad', ['label' => 'ok'], 'great']],
            (new ScoreQuestion('How good?', ['bad', ['label' => 'ok'], 'great']))->toArray()
        );
    }

    public function testLevelsReindexedAsList(): void
    {
        $criteria = (new ScoreQuestion('How good?', array_fill(0, 10, 'level')))->toArray()['criteria'];

        self::assertTrue(array_is_list($criteria));
        self::assertSame(array_fill(0, 10, 'level'), $criteria);
        self::assertStringStartsWith('[', json_encode($criteria, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{0: string|array, 1: array}>
     */
    public static function invalidProvider(): array
    {
        return [
            'one level' => ['Q', ['only']],
            'eleven levels' => ['Q', array_fill(0, 11, 'level')],
            'non-list' => ['Q', [1 => 'a', 2 => 'b']],
            'assoc' => ['Q', ['low' => 'a', 'high' => 'b']],
            'empty level' => ['Q', ['a', ' ']],
            'empty instructions' => [' ', ['a', 'b']],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputThrows(string|array $instructions, array $levels): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScoreQuestion($instructions, $levels);
    }
}

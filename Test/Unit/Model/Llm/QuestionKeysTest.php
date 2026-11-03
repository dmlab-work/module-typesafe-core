<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Llm;

use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Model\Llm\QuestionKeys;
use PHPUnit\Framework\TestCase;

class QuestionKeysTest extends TestCase
{
    private function keys(): QuestionKeys
    {
        return new QuestionKeys(new Request('state', [
            'color:rev' => new ChoiceQuestion('Color?', ['red' => null, '0' => 'zero', '12' => null]),
            'bedroom_suite_incl:412' => new NoulQuestion('Has a suite?'),
            412 => new ScoreQuestion('Quality?', ['low', 'mid', 'high']),
            'größe / "x"' => new NoulQuestion('Odd id?'),
        ], 'catalog_enrich'));
    }

    public function testPositionalKeysRoundTripOddIds(): void
    {
        $keys = $this->keys();

        self::assertSame(['q1', 'q2', 'q3', 'q4'], array_keys($keys->questions()));
        $expected = ['color:rev' => 'q1', 'bedroom_suite_incl:412' => 'q2', '412' => 'q3', 'größe / "x"' => 'q4'];
        foreach ($expected as $id => $key) {
            self::assertSame($key, $keys->keyOf((string)$id));
            self::assertSame((string)$id, (string)$keys->idOf($key));
        }
        self::assertSame(412, $keys->idOf('q3'), 'numeric ids stay int keys, like Request::getQuestions()');
    }

    public function testTypeOptionsAndLevels(): void
    {
        $keys = $this->keys();

        self::assertSame(['choice', 'noul', 'score'], [$keys->type('q1'), $keys->type('q2'), $keys->type('q3')]);
        self::assertSame(['red', '0', '12'], $keys->options('q1'));
        self::assertSame([], $keys->options('q2'));
        self::assertSame(3, $keys->levelCount('q3'));
        self::assertSame(0, $keys->levelCount('q1'));
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->keys()->keyOf('nope');
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->keys()->idOf('q9');
    }

    public function testUnknownKeyTypeThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        $this->keys()->type('q0');
    }
}

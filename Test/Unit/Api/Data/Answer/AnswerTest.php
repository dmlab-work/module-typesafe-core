<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api\Data\Answer;

use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use PHPUnit\Framework\TestCase;

class AnswerTest extends TestCase
{
    public function testNoul(): void
    {
        $answer = new NoulAnswer(0.95);

        self::assertSame('noul', $answer->getType());
        self::assertSame(0.95, $answer->getProbability());
    }

    public function testChoice(): void
    {
        $answer = new ChoiceAnswer('billing', ['billing' => 0.88, 'other' => 0.12], 0.81);

        self::assertSame('choice', $answer->getType());
        self::assertSame('billing', $answer->getChoice());
        self::assertSame(['billing' => 0.88, 'other' => 0.12], $answer->getProbabilities());
        self::assertSame(0.12, $answer->getProbability('other'));
        self::assertSame(0.0, $answer->getProbability('unknown'));
        self::assertSame(0.81, $answer->getConfidence());
    }

    public function testChoiceNumericStringOptions(): void
    {
        $answer = new ChoiceAnswer('1', ['0' => 0.25, '1' => 0.75], 0.5);

        self::assertSame(0.25, $answer->getProbability('0'));
        self::assertSame(0.75, $answer->getProbability('1'));
        self::assertSame(0.0, $answer->getProbability('2'));
    }

    public function testScore(): void
    {
        $answer = new ScoreAnswer(1.05, [0.0, 0.95, 0.05], 0.92);

        self::assertSame('score', $answer->getType());
        self::assertSame(1.05, $answer->getScore());
        self::assertSame([0.0, 0.95, 0.05], $answer->getProbabilities());
        self::assertSame(0.92, $answer->getConfidence());
    }
}

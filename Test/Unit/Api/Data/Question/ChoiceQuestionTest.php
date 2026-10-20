<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api\Data\Question;

use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChoiceQuestionTest extends TestCase
{
    public function testToArrayShapeKeepsOptionOrder(): void
    {
        $data = (new ChoiceQuestion('Which team?', ['sales' => 'Buying', 'billing' => null, 'tech' => ['x' => 1]]))
            ->toArray();

        self::assertSame('choice', $data['type']);
        self::assertSame('Which team?', $data['instructions']);
        self::assertInstanceOf(\stdClass::class, $data['criteria']);
        self::assertSame(
            '{"sales":"Buying","billing":null,"tech":{"x":1}}',
            json_encode($data['criteria'], JSON_THROW_ON_ERROR)
        );
    }

    public function testNumericStringOptionsEncodeAsObject(): void
    {
        $data = (new ChoiceQuestion('Pick', ['1' => 'one', '0' => 'zero']))->toArray();

        self::assertSame('{"1":"one","0":"zero"}', json_encode($data['criteria'], JSON_THROW_ON_ERROR));
        self::assertSame(['1', '0'], array_map('strval', array_keys(get_object_vars($data['criteria']))));
    }

    public function testListOptionsEncodeAsObject(): void
    {
        $data = (new ChoiceQuestion('Pick', ['zero', 'one']))->toArray();

        self::assertSame('{"0":"zero","1":"one"}', json_encode($data['criteria'], JSON_THROW_ON_ERROR));
    }

    public function testAcceptsMaxOptions(): void
    {
        $criteria = array_fill_keys(array_map(static fn (int $i) => 'o' . $i, range(1, 255)), null);

        self::assertCount(255, get_object_vars((new ChoiceQuestion('Pick', $criteria))->toArray()['criteria']));
    }

    /**
     * @return array<string, array{0: string|array, 1: array}>
     */
    public static function invalidProvider(): array
    {
        return [
            'no options' => ['Pick', []],
            '256 options' => ['Pick', array_fill_keys(array_map(static fn (int $i) => 'o' . $i, range(1, 256)), null)],
            'empty option' => ['Pick', ['a' => null, '' => null]],
            'blank option' => ['Pick', [' ' => null]],
            'empty description' => ['Pick', ['a' => '']],
            'empty instructions' => ['', ['a' => null]],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputThrows(string|array $instructions, array $criteria): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChoiceQuestion($instructions, $criteria);
    }
}

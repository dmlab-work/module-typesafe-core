<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api\Data;

use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testGetters(): void
    {
        $question = new NoulQuestion('Is it red?');
        $questions = ['red' => $question, '0' => $question];
        $request = new Request(['name' => 'Shirt'], $questions, 'catalog_enrich', 'jev-1.14.0');

        self::assertSame(['name' => 'Shirt'], $request->getState());
        self::assertSame(['red' => $question, 0 => $question], $request->getQuestions());
        self::assertSame('catalog_enrich', $request->getConsumer());
        self::assertSame('jev-1.14.0', $request->getModel());
    }

    public function testModelDefaultsToNullAndStringState(): void
    {
        $request = new Request('A red shirt', ['q' => new NoulQuestion('Is it red?')], 'c');

        self::assertSame('A red shirt', $request->getState());
        self::assertNull($request->getModel());
    }

    public function testConsumerOfMaxLength(): void
    {
        $consumer = 'a' . str_repeat('b_1', 21);

        self::assertSame($consumer, (new Request('s', ['q' => new NoulQuestion('Q')], $consumer))->getConsumer());
    }

    /**
     * @return array<string, array{0: string|array, 1: array, 2: string, 3: ?string}>
     */
    public static function invalidProvider(): array
    {
        $q = new NoulQuestion('Q');

        return [
            'empty state' => ['', ['q' => $q], 'c', null],
            'blank state' => ['  ', ['q' => $q], 'c', null],
            'empty array state' => [[], ['q' => $q], 'c', null],
            'no questions' => ['s', [], 'c', null],
            'non-question value' => ['s', ['q' => 'Is it red?'], 'c', null],
            'empty question id' => ['s', ['' => $q], 'c', null],
            'uppercase consumer' => ['s', ['q' => $q], 'Catalog', null],
            'leading digit consumer' => ['s', ['q' => $q], '1catalog', null],
            'dash consumer' => ['s', ['q' => $q], 'catalog-enrich', null],
            'empty consumer' => ['s', ['q' => $q], '', null],
            'too long consumer' => ['s', ['q' => $q], str_repeat('a', 65), null],
            'empty model' => ['s', ['q' => $q], 'c', ' '],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidInputThrows(
        string|array $state,
        array $questions,
        string $consumer,
        ?string $model
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new Request($state, $questions, $consumer, $model);
    }
}

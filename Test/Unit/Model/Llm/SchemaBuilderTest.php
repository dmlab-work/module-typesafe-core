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
use DmLab\TypeSafeCore\Model\Llm\SchemaBuilder;
use PHPUnit\Framework\TestCase;

class SchemaBuilderTest extends TestCase
{
    private function keys(array $questions): QuestionKeys
    {
        return new QuestionKeys(new Request('state', $questions, 'catalog_enrich'));
    }

    private function all(): QuestionKeys
    {
        return $this->keys([
            'color:rev' => new ChoiceQuestion('Color?', ['red' => null, '0' => null]),
            'size' => new ScoreQuestion('Size?', ['s', 'm', 'l']),
            'wool' => new NoulQuestion('Wool?'),
        ]);
    }

    public function testOpenAiDialect(): void
    {
        $confidence = ['type' => 'number', 'minimum' => 0, 'maximum' => 1];
        $object = static fn (array $properties) => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_map('strval', array_keys($properties)),
            'additionalProperties' => false,
        ];

        self::assertSame($object([
            'q1' => $object(['choice' => ['type' => 'string', 'enum' => ['red', '0']], 'confidence' => $confidence]),
            'q2' => $object([
                'score' => ['type' => 'number', 'minimum' => 0, 'maximum' => 2],
                'confidence' => $confidence,
            ]),
            'q3' => $object(['answer' => ['type' => ['boolean', 'null']], 'confidence' => $confidence]),
        ]), (new SchemaBuilder())->build($this->all(), SchemaBuilder::DIALECT_OPENAI));
    }

    public function testAnthropicDialectHasNoNumericBoundsAndAnyOfNullable(): void
    {
        $object = static fn (array $properties) => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_map('strval', array_keys($properties)),
            'additionalProperties' => false,
        ];
        $number = ['type' => 'number'];

        $schema = (new SchemaBuilder())->build($this->all(), SchemaBuilder::DIALECT_ANTHROPIC);

        self::assertSame($object([
            'q1' => $object(['choice' => ['type' => 'string', 'enum' => ['red', '0']], 'confidence' => $number]),
            'q2' => $object(['score' => $number, 'confidence' => $number]),
            'q3' => $object([
                'answer' => ['anyOf' => [['type' => 'boolean'], ['type' => 'null']]],
                'confidence' => $number,
            ]),
        ]), $schema);
        self::assertStringNotContainsString('minimum', json_encode($schema));
    }

    public function testGeminiDialectUsesOpenApiSubset(): void
    {
        $object = static fn (array $properties) => [
            'type' => 'OBJECT',
            'properties' => $properties,
            'required' => array_map('strval', array_keys($properties)),
            'propertyOrdering' => array_map('strval', array_keys($properties)),
        ];
        $confidence = ['type' => 'NUMBER', 'minimum' => 0, 'maximum' => 1];

        $schema = (new SchemaBuilder())->build($this->all(), SchemaBuilder::DIALECT_GEMINI);

        self::assertSame($object([
            'q1' => $object([
                'choice' => ['type' => 'STRING', 'format' => 'enum', 'enum' => ['red', '0']],
                'confidence' => $confidence,
            ]),
            'q2' => $object([
                'score' => ['type' => 'NUMBER', 'minimum' => 0, 'maximum' => 2],
                'confidence' => $confidence,
            ]),
            'q3' => $object(['answer' => ['type' => 'BOOLEAN', 'nullable' => true], 'confidence' => $confidence]),
        ]), $schema);
        self::assertStringNotContainsString('additionalProperties', json_encode($schema));
    }

    public function testSchemaEncodesAsJsonObjects(): void
    {
        $json = json_encode((new SchemaBuilder())->build($this->all(), SchemaBuilder::DIALECT_OPENAI));

        self::assertStringStartsWith('{"type":"object","properties":{"q1":{"type":"object","properties":{', $json);
        self::assertStringContainsString('"enum":["red","0"]', $json);
    }

    public function testUnknownDialectThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SchemaBuilder())->build($this->all(), 'mistral');
    }

    public function testChoicesBeyondTotalEnumLimitBecomePlainStrings(): void
    {
        $questions = [];
        for ($i = 1; $i <= 4; $i++) {
            $options = [];
            for ($o = 0; $o < 255; $o++) {
                $options["c{$i}_{$o}"] = null;
            }
            $questions["c$i"] = new ChoiceQuestion('Pick', $options);
        }
        $questions['small'] = new ChoiceQuestion('Pick', ['a' => null, 'b' => null]);

        foreach ([SchemaBuilder::DIALECT_OPENAI, SchemaBuilder::DIALECT_ANTHROPIC] as $dialect) {
            $schema = (new SchemaBuilder())->build($this->keys($questions), $dialect)['properties'];
            for ($i = 1; $i <= 3; $i++) {
                self::assertCount(255, $schema["q$i"]['properties']['choice']['enum'], "q$i keeps its enum");
            }
            self::assertSame(['type' => 'string'], $schema['q4']['properties']['choice'], '765 + 255 > 1000');
            self::assertSame(['a', 'b'], $schema['q5']['properties']['choice']['enum'], 'still fits: 767 values');
        }
        $gemini = (new SchemaBuilder())->build($this->keys($questions), SchemaBuilder::DIALECT_GEMINI)['properties'];
        self::assertSame(['type' => 'STRING'], $gemini['q4']['properties']['choice']);
    }

    public function testLargeEnumOverCharLimitBecomesPlainString(): void
    {
        $long = [];
        $short = [];
        for ($o = 0; $o < 251; $o++) {
            $long[sprintf('%060d', $o)] = null;
            $short[sprintf('%059d', $o)] = null;
        }
        $schema = (new SchemaBuilder())->build($this->keys([
            'long' => new ChoiceQuestion('Pick', $long),
            'short' => new ChoiceQuestion('Pick', $short),
        ]), SchemaBuilder::DIALECT_OPENAI)['properties'];

        self::assertSame(['type' => 'string'], $schema['q1']['properties']['choice'], '251 × 60 = 15 060 chars');
        self::assertCount(251, $schema['q2']['properties']['choice']['enum'], '251 × 59 = 14 809 chars');
    }

    public function testCharLimitAppliesOnlyAboveTwoHundredFiftyValues(): void
    {
        $options = [];
        for ($o = 0; $o < 250; $o++) {
            $options[sprintf('%0100d', $o)] = null;
        }
        $schema = (new SchemaBuilder())->build(
            $this->keys(['x' => new ChoiceQuestion('Pick', $options)]),
            SchemaBuilder::DIALECT_OPENAI
        );

        self::assertCount(250, $schema['properties']['q1']['properties']['choice']['enum']);
    }
}

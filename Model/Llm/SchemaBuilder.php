<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Llm;

/**
 * Structured-output schema of the answer object, in each provider's schema dialect.
 *
 * `openai`: strict JSON Schema; `anthropic`: JSON Schema without numeric bounds;
 * `gemini`: OpenAPI Schema subset. Choices beyond the enum limits get a plain string (AnswerMapper validates it).
 */
class SchemaBuilder
{
    public const DIALECT_OPENAI = 'openai';
    public const DIALECT_ANTHROPIC = 'anthropic';
    public const DIALECT_GEMINI = 'gemini';

    /** OpenAI Structured Outputs limits: enum values per schema, and chars of one enum with >250 values. */
    public const MAX_ENUM_VALUES = 1000;
    public const LARGE_ENUM_SIZE = 250;
    public const LARGE_ENUM_MAX_CHARS = 15000;

    private const DIALECTS = [self::DIALECT_OPENAI, self::DIALECT_ANTHROPIC, self::DIALECT_GEMINI];

    /**
     * Root object schema with one required property per answer key.
     *
     * @param QuestionKeys $keys
     * @param string $dialect one of the DIALECT_* constants
     * @return array
     * @throws \InvalidArgumentException
     */
    public function build(QuestionKeys $keys, string $dialect): array
    {
        if (!in_array($dialect, self::DIALECTS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown schema dialect "%s".', $dialect));
        }

        $enums = $this->enumKeys($keys);
        $properties = [];
        foreach (array_keys($keys->questions()) as $key) {
            $properties[$key] = match ($keys->type($key)) {
                'choice' => $this->object($dialect, [
                    'choice' => $this->choice($dialect, isset($enums[$key]) ? $keys->options($key) : null),
                    'confidence' => $this->number($dialect, 0, 1),
                ]),
                'score' => $this->object($dialect, [
                    'score' => $this->number($dialect, 0, $keys->levelCount($key) - 1),
                    'confidence' => $this->number($dialect, 0, 1),
                ]),
                default => $this->object($dialect, [
                    'answer' => $this->nullableBoolean($dialect),
                    'confidence' => $this->number($dialect, 0, 1),
                ]),
            };
        }

        return $this->object($dialect, $properties);
    }

    /**
     * Choice keys that keep their enum: greedily within MAX_ENUM_VALUES, large enums within LARGE_ENUM_MAX_CHARS.
     *
     * @param QuestionKeys $keys
     * @return array<string, true>
     */
    private function enumKeys(QuestionKeys $keys): array
    {
        $total = 0;
        $result = [];
        foreach (array_keys($keys->questions()) as $key) {
            $options = $keys->options($key);
            $count = count($options);
            if ($count === 0) {
                continue;
            }
            $chars = array_sum(array_map('mb_strlen', $options));
            if (($count > self::LARGE_ENUM_SIZE && $chars > self::LARGE_ENUM_MAX_CHARS)
                || $total + $count > self::MAX_ENUM_VALUES
            ) {
                continue;
            }
            $total += $count;
            $result[$key] = true;
        }

        return $result;
    }

    /**
     * Object with every property required and no other properties.
     *
     * @param string $dialect
     * @param array $properties
     * @return array
     */
    private function object(string $dialect, array $properties): array
    {
        $names = array_map('strval', array_keys($properties));
        if ($dialect === self::DIALECT_GEMINI) {
            return [
                'type' => 'OBJECT',
                'properties' => $properties,
                'required' => $names,
                'propertyOrdering' => $names,
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $names,
            'additionalProperties' => false,
        ];
    }

    /**
     * Choice string, an enum of the options when given.
     *
     * @param string $dialect
     * @param string[]|null $options
     * @return array
     */
    private function choice(string $dialect, ?array $options): array
    {
        $gemini = $dialect === self::DIALECT_GEMINI;
        $schema = ['type' => $gemini ? 'STRING' : 'string'];
        if ($options !== null) {
            if ($gemini) {
                $schema['format'] = 'enum';
            }
            $schema['enum'] = $options;
        }

        return $schema;
    }

    /**
     * Number, bounded where the dialect supports bounds.
     *
     * @param string $dialect
     * @param int $minimum
     * @param int $maximum
     * @return array
     */
    private function number(string $dialect, int $minimum, int $maximum): array
    {
        return match ($dialect) {
            self::DIALECT_ANTHROPIC => ['type' => 'number'],
            self::DIALECT_GEMINI => ['type' => 'NUMBER', 'minimum' => $minimum, 'maximum' => $maximum],
            default => ['type' => 'number', 'minimum' => $minimum, 'maximum' => $maximum],
        };
    }

    /**
     * Boolean or null.
     *
     * @param string $dialect
     * @return array
     */
    private function nullableBoolean(string $dialect): array
    {
        return match ($dialect) {
            self::DIALECT_ANTHROPIC => ['anyOf' => [['type' => 'boolean'], ['type' => 'null']]],
            self::DIALECT_GEMINI => ['type' => 'BOOLEAN', 'nullable' => true],
            default => ['type' => ['boolean', 'null']],
        };
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Answer\ChoiceAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\NoulAnswer;
use DmLab\TypeSafeCore\Api\Data\Answer\ScoreAnswer;
use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Exception\TransportException;
use DmLab\TypeSafeCore\Model\ResponseParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResponseParserTest extends TestCase
{
    private const C1_BODY = <<<'JSON'
{
  "model": "jev-1.13.0",
  "answers": {
    "red": {"type": "noul", "noul": 0.95},
    "topic": {"type": "choice", "choice": "billing",
      "probabilities": {"billing": 0.88, "shipping": 0.12}, "confidence": 0.81},
    "quality": {"type": "score", "score": 1.05, "legend": {"0": "bad", "1": "ok", "2": "good"},
      "probabilities": {"0": 0.0, "1": 0.95, "2": 0.05}, "confidence": 0.92}
  },
  "usage": {"input_tokens": 1234, "output_tokens": 3}
}
JSON;

    /**
     * @var ResponseParser
     */
    private ResponseParser $parser;

    protected function setUp(): void
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getModel')->willReturn('jev-config');
        $this->parser = new ResponseParser($config);
    }

    private function request(?string $model = null): Request
    {
        return new Request('A red shirt', [
            'red' => new NoulQuestion('Is it red?'),
            'topic' => new ChoiceQuestion('Topic?', ['billing' => null, 'shipping' => null]),
            'quality' => new ScoreQuestion('Quality?', ['bad', 'ok', 'good']),
        ], 'catalog_enrich', $model);
    }

    public function testParsesEveryAnswerType(): void
    {
        $response = $this->parser->parse(self::C1_BODY, $this->request(), false);

        $noul = $response->getAnswer('red');
        self::assertInstanceOf(NoulAnswer::class, $noul);
        self::assertSame(0.95, $noul->getProbability());

        $choice = $response->getAnswer('topic');
        self::assertInstanceOf(ChoiceAnswer::class, $choice);
        self::assertSame('billing', $choice->getChoice());
        self::assertSame(['billing' => 0.88, 'shipping' => 0.12], $choice->getProbabilities());
        self::assertSame(0.81, $choice->getConfidence());

        $score = $response->getAnswer('quality');
        self::assertInstanceOf(ScoreAnswer::class, $score);
        self::assertSame(1.05, $score->getScore());
        self::assertSame([0.0, 0.95, 0.05], $score->getProbabilities());
        self::assertSame(0.92, $score->getConfidence());

        self::assertSame(['red', 'topic', 'quality'], array_keys($response->getAnswers()));
    }

    public function testModelAndUsageExtracted(): void
    {
        $response = $this->parser->parse(self::C1_BODY, $this->request(), false);

        self::assertSame('jev-1.13.0', $response->getModel());
        self::assertSame(1234, $response->getInputTokens());
        self::assertFalse($response->isCached());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function tokenProvider(): array
    {
        return [
            'negative' => ['-5', 0],
            'non-numeric' => ['"many"', 0],
            'absent' => ['null', 0],
        ];
    }

    #[DataProvider('tokenProvider')]
    public function testInvalidInputTokensAreClampedToZero(string $tokens, int $expected): void
    {
        $body = str_replace('"input_tokens": 1234', '"input_tokens": ' . $tokens, self::C1_BODY);

        self::assertSame($expected, $this->parser->parse($body, $this->request(), false)->getInputTokens());
    }

    public function testCachedReportsZeroTokens(): void
    {
        $response = $this->parser->parse(self::C1_BODY, $this->request(), true);

        self::assertTrue($response->isCached());
        self::assertSame(0, $response->getInputTokens());
        self::assertSame('jev-1.13.0', $response->getModel());
    }

    public function testMissingModelFallsBackToRequestThenConfig(): void
    {
        $body = '{"answers":{"q":{"type":"noul","noul":1}}}';
        $question = ['q' => new NoulQuestion('Q')];

        $override = $this->parser->parse($body, new Request('s', $question, 'c', 'jev-1.14.0'), false);
        $default = $this->parser->parse($body, new Request('s', $question, 'c'), false);

        self::assertSame('jev-1.14.0', $override->getModel());
        self::assertSame('jev-config', $default->getModel());
        self::assertSame(0, $default->getInputTokens());
        self::assertSame(1.0, $default->getAnswer('q')->getProbability());
    }

    public function testNumericStringOptionsAndIds(): void
    {
        $request = new Request('s', ['0' => new ChoiceQuestion('Pick', ['0' => 'zero', '1' => 'one'])], 'c');
        $body = '{"model":"m","answers":{"0":{"type":"choice","choice":"1",'
            . '"probabilities":{"0":0.3,"1":"0.7"},"confidence":1}}}';

        $answer = $this->parser->parse($body, $request, false)->getAnswer('0');

        self::assertInstanceOf(ChoiceAnswer::class, $answer);
        self::assertSame('1', $answer->getChoice());
        self::assertSame(0.3, $answer->getProbability('0'));
        self::assertSame(0.7, $answer->getProbability('1'));
        self::assertSame(1.0, $answer->getConfidence());
    }

    public function testExtraAnswersIgnored(): void
    {
        $request = new Request('s', ['red' => new NoulQuestion('Is it red?')], 'c');

        $response = $this->parser->parse(self::C1_BODY, $request, false);

        self::assertSame(['red'], array_keys($response->getAnswers()));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedProvider(): array
    {
        $noul = '"red":{"type":"noul","noul":0.9}';
        $score = '"quality":{"type":"score","score":1,"probabilities":{"0":0.5},"confidence":0.5}';
        $wrap = static fn (string $topic): string => '{"answers":{' . $noul . ',' . $topic . ',' . $score . '}}';

        return [
            'invalid JSON' => ['{"answers":', 'invalid JSON'],
            'scalar top level' => ['5', 'no "answers" object'],
            'no answers' => ['{"model":"m"}', 'no "answers" object'],
            'answers not an object' => ['{"answers":"x"}', 'no "answers" object'],
            'missing answer' => ['{"answers":{' . $noul . ',' . $score . '}}', 'missing answer "topic"'],
            'type mismatch' => [
                $wrap('"topic":{"type":"noul","noul":0.5}'),
                'answer "topic" is not of type "choice"',
            ],
            'missing noul' => [
                '{"answers":{"red":{"type":"noul"},"topic":{},"quality":{}}}',
                'answer "red" has no numeric "noul"',
            ],
            'non-numeric noul' => [
                '{"answers":{"red":{"type":"noul","noul":"high"}}}',
                'answer "red" has no numeric "noul"',
            ],
            'missing choice' => [
                $wrap('"topic":{"type":"choice","probabilities":{},"confidence":1}'),
                'answer "topic" has no "choice"',
            ],
            'missing probabilities' => [
                $wrap('"topic":{"type":"choice","choice":"billing","confidence":1}'),
                'answer "topic" has no "probabilities"',
            ],
            'non-numeric probability' => [
                $wrap('"topic":{"type":"choice","choice":"billing","probabilities":{"billing":"x"},"confidence":1}'),
                'non-numeric probability for "billing"',
            ],
            'missing confidence' => [
                $wrap('"topic":{"type":"choice","choice":"billing","probabilities":{}}'),
                'answer "topic" has no numeric "confidence"',
            ],
            'missing score' => [
                '{"answers":{' . $noul . ',"topic":{"type":"choice","choice":"billing","probabilities":{},'
                    . '"confidence":1},"quality":{"type":"score","probabilities":{},"confidence":1}}}',
                'answer "quality" has no numeric "score"',
            ],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedBodies(string $body, string $detail): void
    {
        try {
            $this->parser->parse($body, $this->request(), false);
            self::fail('TransportException expected.');
        } catch (TransportException $e) {
            self::assertStringStartsWith('TypeSafe returned a malformed response: ', $e->getMessage());
            self::assertStringContainsString($detail, $e->getMessage());
        }
    }

    public function testInvalidJsonKeepsCause(): void
    {
        try {
            $this->parser->parse('not json', $this->request(), false);
            self::fail('TransportException expected.');
        } catch (TransportException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function billedBodies(): array
    {
        return [
            'answers missing' => ['{"usage":{"input_tokens":120}}', 120],
            'string count' => ['{"usage":{"input_tokens":"7"}}', 7],
            'negative' => ['{"usage":{"input_tokens":-5}}', 0],
            'no usage' => ['{"answers":{}}', 0],
            'non-numeric' => ['{"usage":{"input_tokens":"x"}}', 0],
            'invalid JSON' => ['not json', 0],
        ];
    }

    #[DataProvider('billedBodies')]
    public function testBilledTokens(string $body, int $expected): void
    {
        self::assertSame($expected, $this->parser->billedTokens($body));
    }
}

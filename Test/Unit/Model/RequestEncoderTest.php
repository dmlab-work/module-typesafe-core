<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\Data\Question\ChoiceQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\NoulQuestion;
use DmLab\TypeSafeCore\Api\Data\Question\QuestionInterface;
use DmLab\TypeSafeCore\Api\Data\Question\ScoreQuestion;
use DmLab\TypeSafeCore\Api\Data\Request;
use DmLab\TypeSafeCore\Exception\RequestException;
use DmLab\TypeSafeCore\Model\Http\Backoff;
use DmLab\TypeSafeCore\Model\Json\CanonicalJson;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\ResponseParser;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use DmLab\TypeSafeCore\Model\RequestEncoder;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeProvider;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeTransport;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class RequestEncoderTest extends TestCase
{
    private const MODEL = 'jev-1.13.0';
    private const CANONICAL =
        '{"model":"jev-1.13.0","questions":{"q":{"instructions":"Q?","type":"noul"}},"state":"s"}';

    /**
     * @var RequestEncoder
     */
    private RequestEncoder $encoder;

    /**
     * @var ProviderSettings
     */
    private ProviderSettings $settings;

    /**
     * @var TypeSafeProvider
     */
    private TypeSafeProvider $typeSafe;

    protected function setUp(): void
    {
        $this->encoder = $this->encoder('jev-config');
        $this->settings = new ProviderSettings($this->createStub(ScopeConfigInterface::class));
        $this->typeSafe = new TypeSafeProvider(
            $this->settings,
            new ResponseParser(),
            new Backoff(),
            new FakeTransport()
        );
    }

    private function encoder(string $configModel): RequestEncoder
    {
        $config = $this->createStub(ConfigInterface::class);
        $config->method('getModel')->willReturn($configModel);

        return new RequestEncoder($config, new CanonicalJson());
    }

    private function key(Request $request, string $model = self::MODEL): string
    {
        return $this->encoder->cacheKey($request, $model, $this->typeSafe);
    }

    private function body(Request $request): string
    {
        return (string)$this->typeSafe->buildRequest($request, self::MODEL, null)->getBody();
    }

    private function request(
        string|array $state = ['name' => 'Shirt', 'attrs' => ['color' => 'red', 'size' => 'M']],
        ?array $questions = null,
        string $consumer = 'catalog_enrich',
        ?string $model = null
    ): Request {
        return new Request($state, $questions ?? [
            'red' => new NoulQuestion('Is it red?', 'Red', 'Not red'),
            'size' => new ChoiceQuestion('Size?', ['S' => null, 'M' => 'Medium']),
            'quality' => new ScoreQuestion('Quality?', ['bad', 'ok', 'good']),
        ], $consumer, $model);
    }

    public function testResolveModelPrefersRequestOverride(): void
    {
        $this->assertSame('jev-config', $this->encoder->resolveModel($this->request()));
        $this->assertSame('jev-x', $this->encoder->resolveModel($this->request(model: 'jev-x')));
    }

    public function testKeyIsSha256Hex(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->key($this->request()));
    }

    public function testKeyIgnoresStateKeyOrderIncludingNested(): void
    {
        $this->assertSame(
            $this->key($this->request(['name' => 'Shirt', 'attrs' => ['color' => 'red', 'size' => 'M']])),
            $this->key($this->request(['attrs' => ['size' => 'M', 'color' => 'red'], 'name' => 'Shirt']))
        );
    }

    public function testKeyDiffersWhenBodiesDiffer(): void
    {
        $pairs = $this->request(['x' => [['a', 1]]]);
        $map = $this->request(['x' => (object)['a' => 1]]);
        $this->assertNotSame($this->body($pairs), $this->body($map));
        $this->assertNotSame($this->key($pairs), $this->key($map));

        $list = $this->request(['x' => ['b', 'a']]);
        $unsorted = $this->request(['x' => [1 => 'a', 0 => 'b']]);
        $this->assertNotSame($this->body($list), $this->body($unsorted));
        $this->assertNotSame($this->key($list), $this->key($unsorted));
    }

    public function testKeyIgnoresQuestionMapOrder(): void
    {
        $a = new NoulQuestion('A?');
        $b = new NoulQuestion('B?');
        $this->assertSame(
            $this->key($this->request(questions: ['a' => $a, 'b' => $b])),
            $this->key($this->request(questions: ['b' => $b, 'a' => $a]))
        );
        $this->assertSame(
            $this->key($this->request(questions: ['0' => $a, '1' => $b])),
            $this->key($this->request(questions: ['1' => $b, '0' => $a]))
        );
    }

    public function testKeyIgnoresInstructionObjectKeyOrder(): void
    {
        $this->assertSame(
            $this->key($this->request(questions: ['q' => new NoulQuestion(['task' => 'Red?', 'hint' => 'Hue'])])),
            $this->key($this->request(questions: ['q' => new NoulQuestion(['hint' => 'Hue', 'task' => 'Red?'])]))
        );
    }

    public function testKeyIgnoresNoulCriteriaOrder(): void
    {
        $trueFirst = $this->createStub(QuestionInterface::class);
        $trueFirst->method('toArray')
            ->willReturn(['type' => 'noul', 'instructions' => 'Red?', 'criteria' => ['true' => 'Y', 'false' => 'N']]);
        $falseFirst = $this->createStub(QuestionInterface::class);
        $falseFirst->method('toArray')
            ->willReturn(['type' => 'noul', 'instructions' => 'Red?', 'criteria' => ['false' => 'N', 'true' => 'Y']]);

        $this->assertSame(
            $this->key($this->request(questions: ['q' => $trueFirst])),
            $this->key($this->request(questions: ['q' => $falseFirst]))
        );
        $this->assertSame(
            $this->key($this->request(questions: ['q' => $trueFirst])),
            $this->key($this->request(questions: ['q' => new NoulQuestion('Red?', 'Y', 'N')]))
        );
    }

    public function testKeyIgnoresConsumer(): void
    {
        $this->assertSame(
            $this->key($this->request(consumer: 'catalog_enrich')),
            $this->key($this->request(consumer: 'catalog_enrich_calibrate'))
        );
    }

    public function testKeyChangesWithModel(): void
    {
        $request = $this->request();
        $this->assertNotSame($this->key($request, 'jev-1.13.0'), $this->key($request, 'jev-1.14.0'));

        $override = $this->request(model: 'jev-1.14.0');
        $this->assertNotSame(
            $this->key($request, $this->encoder->resolveModel($request)),
            $this->key($override, $this->encoder->resolveModel($override))
        );

        $other = $this->encoder('jev-other');
        $this->assertNotSame(
            $this->key($request, $this->encoder->resolveModel($request)),
            $other->cacheKey($request, $other->resolveModel($request), $this->typeSafe)
        );
    }

    public function testKeyChangesWithStateValue(): void
    {
        $this->assertNotSame(
            $this->key($this->request(['name' => 'Shirt'])),
            $this->key($this->request(['name' => 'Shirts']))
        );
        $this->assertNotSame($this->key($this->request('a')), $this->key($this->request(['a'])));
    }

    public function testKeyChangesWithInstructionText(): void
    {
        $this->assertNotSame(
            $this->key($this->request(questions: ['q' => new NoulQuestion('Is it red?')])),
            $this->key($this->request(questions: ['q' => new NoulQuestion('Is it blue?')]))
        );
    }

    public function testKeyChangesWithLevelText(): void
    {
        $this->assertNotSame(
            $this->key($this->request(questions: ['q' => new ScoreQuestion('Q?', ['bad', 'good'])])),
            $this->key($this->request(questions: ['q' => new ScoreQuestion('Q?', ['bad', 'great'])]))
        );
    }

    public function testKeyChangesWithChoiceOptionOrder(): void
    {
        $this->assertNotSame(
            $this->key($this->request(questions: ['q' => new ChoiceQuestion('Size?', ['S' => null, 'M' => null])])),
            $this->key($this->request(questions: ['q' => new ChoiceQuestion('Size?', ['M' => null, 'S' => null])]))
        );
        $this->assertNotSame(
            $this->key($this->request(questions: ['q' => new ChoiceQuestion('N?', ['0' => null, '1' => null])])),
            $this->key($this->request(questions: ['q' => new ChoiceQuestion('N?', ['1' => null, '0' => null])]))
        );
    }

    public function testEmptyScopeKeyIsTheBareCanonicalHash(): void
    {
        $request = new Request('s', ['q' => new NoulQuestion('Q?')], 'c');

        $this->assertSame(hash('sha256', self::CANONICAL), $this->key($request));
    }

    public function testScopeIsPrefixedToTheCanonicalJson(): void
    {
        $request = new Request('s', ['q' => new NoulQuestion('Q?')], 'c');
        $scoped = $this->encoder->cacheKey($request, self::MODEL, new FakeProvider('anthropic', $this->settings));

        $this->assertSame(
            hash(
                'sha256',
                'anthropic{"model":"jev-1.13.0","questions":{"\\u0000":[["q",{"instructions":"Q?","type":"noul"}]]},'
                . '"state":"s"}'
            ),
            $scoped
        );
        $this->assertNotSame($this->key($request), $scoped);
    }

    public function testScopedKeyDependsOnQuestionOrder(): void
    {
        $a = new NoulQuestion('A?');
        $b = new NoulQuestion('B?');
        $provider = new FakeProvider('anthropic', $this->settings);

        $this->assertNotSame(
            $this->encoder->cacheKey(new Request('s', ['a' => $a, 'b' => $b], 'c'), self::MODEL, $provider),
            $this->encoder->cacheKey(new Request('s', ['b' => $b, 'a' => $a], 'c'), self::MODEL, $provider)
        );
    }

    public function testInvalidUtf8IsSubstitutedInTheKey(): void
    {
        $this->assertSame(
            $this->key($this->request(['name' => "\u{FFFD}1"])),
            $this->key($this->request(['name' => "\xB1\x31"]))
        );
    }

    public function testNanIsARequestExceptionWithTheProviderLabel(): void
    {
        try {
            $this->key($this->request(['weight' => NAN]));
            $this->fail('Expected RequestException');
        } catch (RequestException $e) {
            $this->assertStringStartsWith('TypeSafe request could not be encoded: ', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }
}

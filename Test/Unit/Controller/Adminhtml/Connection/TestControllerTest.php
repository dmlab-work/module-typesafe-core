<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Controller\Adminhtml\Connection;

use DmLab\TypeSafeCore\Controller\Adminhtml\Connection\Test;
use DmLab\TypeSafeCore\Exception\AuthenticationException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeProvider;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TestControllerTest extends TestCase
{
    /** @var array|null */
    private ?array $data = null;

    /** @var string[] */
    private array $errors = [];

    /** @var bool */
    private bool $enabled = true;

    /** @var string */
    private string $active = 'typesafe';

    /** @var array<string, array|\Throwable> code => listModels() result */
    private array $results = [];

    /** @var string[] codes whose models were listed */
    private array $listed = [];

    private function execute(?string $provider): void
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use (&$json) {
            $this->data = $data;

            return $json;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message): void {
            $this->errors[] = (string)$message;
        });

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturnCallback(fn () => $this->enabled);
        $config->method('getProvider')->willReturnCallback(fn () => $this->active);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn ($key) => $key === Test::PARAM_PROVIDER ? $provider : null
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);

        $result = (new Test($context, $factory, $this->pool(), $logger, $config))->execute();
        $this->assertInstanceOf(Json::class, $result);
    }

    private function pool(): ProviderPool
    {
        $settings = new ProviderSettings($this->createStub(ScopeConfigInterface::class));
        $providers = [];
        foreach (['typesafe' => 'TypeSafe', 'anthropic' => 'Anthropic API'] as $code => $label) {
            $provider = $this->createStub(FakeProvider::class);
            $provider->method('getCode')->willReturn($code);
            $provider->method('getLabel')->willReturn($label);
            $provider->method('getModel')->willReturn($code === 'typesafe' ? 'jev-1.13.0' : 'claude-haiku-5-5');
            $provider->method('listModels')->willReturnCallback(function () use ($code): array {
                $this->listed[] = $code;
                $result = $this->results[$code] ?? [];
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            });
            $providers[$code] = $provider;
        }

        return new ProviderPool($settings, $providers);
    }

    public function testIsPostOnlyAndAclGuarded(): void
    {
        $this->assertTrue(is_a(Test::class, HttpPostActionInterface::class, true));
        $this->assertSame('DmLab_TypeSafeCore::config', Test::ADMIN_RESOURCE);
    }

    public function testTypeSafeSuccessListsModelsAndNotesAliases(): void
    {
        $this->results['typesafe'] = ['jev-latest', 'jev-preview'];

        $this->execute('typesafe');

        $this->assertSame(['typesafe'], $this->listed);
        $this->assertSame(
            [
                'success' => true,
                'message' => 'Connected to TypeSafe. Models: jev-latest, jev-preview',
                'model' => 'Configured model: jev-1.13.0',
                'note' => 'The list shows aliases only; pinned versions such as jev-1.13.0 are accepted too.',
            ],
            $this->data
        );
    }

    public function testListedConfiguredModelHasNoNote(): void
    {
        $this->results['typesafe'] = ['jev-1.13.0', 'jev-latest'];

        $this->execute('typesafe');

        $this->assertSame(
            [
                'success' => true,
                'message' => 'Connected to TypeSafe. Models: jev-1.13.0, jev-latest',
                'model' => 'Configured model: jev-1.13.0',
            ],
            $this->data
        );
    }

    public function testActiveOtherProviderUsesItsOwnModelsAndNote(): void
    {
        $this->active = 'anthropic';
        $this->results['anthropic'] = ['claude-sonnet-5-5'];

        $this->execute('anthropic');

        $this->assertSame(['anthropic'], $this->listed);
        $this->assertSame(
            [
                'success' => true,
                'message' => 'Connected to Anthropic API. Models: claude-sonnet-5-5',
                'model' => 'Configured model: claude-haiku-5-5',
                'note' => 'The configured model is not in the list; check its id.',
            ],
            $this->data
        );
    }

    public function testInactiveProviderSucceedsWithAWarning(): void
    {
        $this->results['anthropic'] = ['claude-haiku-5-5'];

        $this->execute('anthropic');

        $this->assertSame(
            [
                'success' => true,
                'message' => 'Connected to Anthropic API. Models: claude-haiku-5-5',
                'model' => 'Configured model: claude-haiku-5-5',
                'warning' => 'Anthropic API is not the active provider: select it under General to use it.',
            ],
            $this->data
        );
    }

    public function testDisabledModuleStillSucceedsWithAWarning(): void
    {
        $this->enabled = false;
        $this->results['typesafe'] = ['jev-1.13.0'];

        $this->execute('typesafe');

        $this->assertSame('The AI client is disabled: enable it to use it.', $this->data['warning']);
        $this->assertTrue($this->data['success']);
    }

    public function testDisabledAndInactiveWarningsAreJoined(): void
    {
        $this->enabled = false;
        $this->results['anthropic'] = ['claude-haiku-5-5'];

        $this->execute('anthropic');

        $this->assertSame(
            'The AI client is disabled: enable it to use it. '
            . 'Anthropic API is not the active provider: select it under General to use it.',
            $this->data['warning']
        );
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function unknownCodeProvider(): array
    {
        return [
            'unknown' => ['openai', 'Unknown AI provider "openai".'],
            'missing' => [null, 'Unknown AI provider "".'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unknownCodeProvider')]
    public function testUnknownProviderIsRejected(?string $code, string $message): void
    {
        $this->execute($code);

        $this->assertSame(['success' => false, 'message' => $message], $this->data);
        $this->assertSame([], $this->listed);
        $this->assertSame([], $this->errors);
    }

    public function testProviderErrorReturnsItsMessageWithoutWarning(): void
    {
        $this->enabled = false;
        $this->results['anthropic'] = new AuthenticationException(
            __('%1 rejected the API key (HTTP %2).', 'Anthropic API', 401),
            null,
            401
        );

        $this->execute('anthropic');

        $this->assertSame(
            ['success' => false, 'message' => 'Anthropic API rejected the API key (HTTP 401).'],
            $this->data
        );
        $this->assertSame([], $this->errors);
    }

    public function testUnexpectedThrowableIsReportedNotRaised(): void
    {
        $this->results['anthropic'] = new \TypeError('boom');

        $this->execute('anthropic');

        $this->assertSame(['success' => false, 'message' => 'Anthropic API client error: boom'], $this->data);
        $this->assertSame(['Anthropic API connection test failed: boom'], $this->errors);
    }

    public function testScriptTranslatesItsOwnTextAndShowsTheWarning(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 5) . '/view/adminhtml/web/js/test-connection.js');

        $this->assertStringContainsString("define(['jquery', 'mage/translate'], function ($, \$t)", $js);
        $this->assertStringContainsString("\$t('Connection test request failed.')", $js);
        $this->assertStringContainsString('data.warning', $js);
        $this->assertStringContainsString('data.model', $js);
        $this->assertStringContainsString('data.note', $js);
    }
}

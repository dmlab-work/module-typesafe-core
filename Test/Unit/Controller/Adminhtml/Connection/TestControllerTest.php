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
use DmLab\TypeSafeCore\Model\Connection\ModelLister;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
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

    private function controller(ModelLister $lister): Test
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
        $config->method('getModel')->willReturn('jev-1.13.0');

        return new Test($this->createStub(Context::class), $factory, $lister, $logger, $config);
    }

    private function lister(array|\Throwable $result): ModelLister
    {
        $lister = $this->createStub(ModelLister::class);
        if ($result instanceof \Throwable) {
            $lister->method('listModels')->willThrowException($result);
        } else {
            $lister->method('listModels')->willReturn($result);
        }

        return $lister;
    }

    public function testIsPostOnlyAndAclGuarded(): void
    {
        $this->assertInstanceOf(HttpPostActionInterface::class, $this->controller($this->lister([])));
        $this->assertSame('DmLab_TypeSafeCore::config', Test::ADMIN_RESOURCE);
    }

    public function testSuccessListsModelsAndNotesAnUnlistedConfiguredModel(): void
    {
        $result = $this->controller($this->lister(['jev-latest', 'jev-preview']))->execute();

        $this->assertInstanceOf(Json::class, $result);
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
        $this->controller($this->lister(['jev-1.13.0', 'jev-latest']))->execute();

        $this->assertSame(
            [
                'success' => true,
                'message' => 'Connected to TypeSafe. Models: jev-1.13.0, jev-latest',
                'model' => 'Configured model: jev-1.13.0',
            ],
            $this->data
        );
    }

    public function testDisabledModuleStillSucceedsWithAWarning(): void
    {
        $this->enabled = false;

        $this->controller($this->lister(['jev-1.13.0']))->execute();

        $this->assertSame(
            [
                'success' => true,
                'message' => 'Connected to TypeSafe. Models: jev-1.13.0',
                'model' => 'Configured model: jev-1.13.0',
                'warning' => 'TypeSafe is disabled: enable it to use it.',
            ],
            $this->data
        );
    }

    public function testDisabledModuleFailureHasNoWarning(): void
    {
        $this->enabled = false;

        $this->controller($this->lister(
            new AuthenticationException(__('TypeSafe rejected the API key (HTTP %1).', 401), null, 401)
        ))->execute();

        $this->assertSame(['success' => false, 'message' => 'TypeSafe rejected the API key (HTTP 401).'], $this->data);
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

    public function testTypeSafeFailureReturnsItsMessage(): void
    {
        $this->controller($this->lister(
            new AuthenticationException(__('TypeSafe rejected the API key (HTTP %1).', 401), null, 401)
        ))->execute();

        $this->assertSame(['success' => false, 'message' => 'TypeSafe rejected the API key (HTTP 401).'], $this->data);
        $this->assertSame([], $this->errors);
    }

    public function testUnexpectedThrowableIsReportedNotRaised(): void
    {
        $this->controller($this->lister(new \TypeError('boom')))->execute();

        $this->assertSame(['success' => false, 'message' => 'TypeSafe client error: boom'], $this->data);
        $this->assertCount(1, $this->errors);
    }
}

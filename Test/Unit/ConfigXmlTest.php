<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit;

use DmLab\TypeSafeCore\Block\Adminhtml\System\Config\TestConnectionButton;
use DmLab\TypeSafeCore\Model\Config\Backend\BaseUrl;
use DmLab\TypeSafeCore\Model\Config\Backend\OpenAiCompatibleBaseUrl;
use DmLab\TypeSafeCore\Model\Config\Source\Provider;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigXmlTest extends TestCase
{
    private const ENCRYPTED = 'Magento\Config\Model\Config\Backend\Encrypted';
    private const LLM_PROVIDERS = ['openai_compatible', 'anthropic', 'gemini'];

    public function testSectionAndAclAreLabelledAiClient(): void
    {
        self::assertSame('AI Client', (string)$this->system()->xpath('//section[@id="dmlab_typesafe"]/label')[0]);
        $acl = simplexml_load_file($this->path('etc/acl.xml'));
        self::assertCount(1, $acl->xpath('//resource[@id="DmLab_TypeSafeCore::config"][@title="AI Client Section"]'));
    }

    public function testGroupsInOrder(): void
    {
        $groups = array_map(
            static fn (\SimpleXMLElement $group): string => (string)$group['id'],
            $this->system()->xpath('//section[@id="dmlab_typesafe"]/group')
        );

        self::assertSame(['general', 'typesafe', 'openai_compatible', 'anthropic', 'gemini', 'limits'], $groups);
        self::assertSame('Apply to every provider.', (string)$this->group('limits')->comment);
    }

    public function testGeneralGroupHasEnableAndProviderSelect(): void
    {
        $general = $this->group('general');

        self::assertSame(['enabled', 'provider', 'api_key', 'base_url'], $this->fieldIds($general));
        self::assertSame(Provider::class, (string)$this->field('general', 'provider')->source_model);
        self::assertSame('Enable', (string)$this->field('general', 'enabled')->label);
    }

    public function testLegacyPathsKeepBackendModelsForConfigSet(): void
    {
        $expected = ['api_key' => self::ENCRYPTED, 'base_url' => BaseUrl::class];
        foreach ($expected as $id => $backend) {
            $field = $this->field('general', $id);
            self::assertSame($backend, (string)$field->backend_model, $id);
            self::assertSame((string)$this->field('typesafe', $id)->backend_model, $backend, $id);
            foreach (['showInDefault', 'showInWebsite', 'showInStore'] as $scope) {
                self::assertSame('0', (string)$field[$scope], $id . ' ' . $scope);
            }
        }
    }

    public function testTypeSafeFieldsKeepTheirLegacyPaths(): void
    {
        $fields = $this->fieldIds($this->group('typesafe'));

        self::assertSame(['api_key', 'model', 'base_url', 'test_connection'], $fields);
        foreach (['api_key', 'model', 'base_url'] as $id) {
            self::assertSame(
                'dmlab_typesafe/general/' . $id,
                (string)$this->field('typesafe', $id)->config_path,
                $id
            );
        }
        self::assertSame(self::ENCRYPTED, (string)$this->field('typesafe', 'api_key')->backend_model);
        self::assertSame(BaseUrl::class, (string)$this->field('typesafe', 'base_url')->backend_model);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function llmGroupProvider(): array
    {
        return [
            'openai_compatible' => ['openai_compatible', OpenAiCompatibleBaseUrl::class],
            'anthropic' => ['anthropic', BaseUrl::class],
            'gemini' => ['gemini', BaseUrl::class],
        ];
    }

    #[DataProvider('llmGroupProvider')]
    public function testLlmGroupFields(string $code, string $baseUrlBackend): void
    {
        $expected = ['base_url', 'api_key', 'model', 'test_connection'];
        if ($code === 'openai_compatible') {
            array_splice($expected, 3, 0, ['send_temperature']);
        }

        self::assertSame($expected, $this->fieldIds($this->group($code)));
        self::assertSame('obscure', (string)$this->field($code, 'api_key')['type']);
        self::assertSame(self::ENCRYPTED, (string)$this->field($code, 'api_key')->backend_model);
        self::assertSame($baseUrlBackend, (string)$this->field($code, 'base_url')->backend_model);
        self::assertSame(
            TestConnectionButton::class,
            (string)$this->field($code, 'test_connection')->frontend_model
        );
        foreach ($this->group($code)->field as $field) {
            self::assertSame('', (string)$field->config_path, (string)$field['id']);
        }
    }

    public function testConfigXmlDefaults(): void
    {
        $settings = new ProviderSettings($this->createStub(ScopeConfigInterface::class));
        $default = simplexml_load_file($this->path('etc/config.xml'))->xpath('/config/default/dmlab_typesafe')[0];

        self::assertSame('typesafe', (string)$default->general->provider);
        self::assertSame(self::ENCRYPTED, (string)$default->general->api_key['backend_model']);
        foreach (self::LLM_PROVIDERS as $code) {
            $group = $default->{$code};
            self::assertSame($settings->getBaseUrl($code), (string)$group->base_url, $code);
            self::assertSame($settings->getModel($code), (string)$group->model, $code);
            self::assertSame(self::ENCRYPTED, (string)$group->api_key['backend_model'], $code);
        }
        self::assertSame('https://api.openai.com/v1', (string)$default->openai_compatible->base_url);
        self::assertSame('gpt-6-luna', (string)$default->openai_compatible->model);
        self::assertSame('0', (string)$default->openai_compatible->send_temperature);
        self::assertSame('claude-haiku-5-5', (string)$default->anthropic->model);
        self::assertSame('gemini-3.5-flash-lite', (string)$default->gemini->model);
    }

    public function testEveryApiKeyIsSensitive(): void
    {
        $di = simplexml_load_file($this->path('etc/di.xml'));
        $items = [];
        $path = '/config/type[@name="Magento\Config\Model\Config\TypePool"]/arguments/argument[@name="sensitive"]/item';
        foreach ($di->xpath($path) as $item) {
            $items[(string)$item['name']] = trim((string)$item);
        }

        self::assertSame(
            [
                'dmlab_typesafe/general/api_key' => '1',
                'dmlab_typesafe/openai_compatible/api_key' => '1',
                'dmlab_typesafe/anthropic/api_key' => '1',
                'dmlab_typesafe/gemini/api_key' => '1',
            ],
            $items
        );
    }

    public function testTransportAllowsHttpForSelfHostedEndpoints(): void
    {
        $di = simplexml_load_file($this->path('etc/di.xml'));
        $path = '/config/type[@name="DmLab\TypeSafeCore\Model\Http\CurlMultiTransport"]'
            . '/arguments/argument[@name="allowedProtocols"]';
        $arguments = $di->xpath($path);

        self::assertCount(1, $arguments);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, (int)trim((string)$arguments[0]));
    }

    private function system(): \SimpleXMLElement
    {
        $xml = simplexml_load_file($this->path('etc/adminhtml/system.xml'));
        self::assertNotFalse($xml);

        return $xml;
    }

    private function group(string $id): \SimpleXMLElement
    {
        $groups = $this->system()->xpath(sprintf('//section[@id="dmlab_typesafe"]/group[@id="%s"]', $id));
        self::assertCount(1, $groups, $id);

        return $groups[0];
    }

    private function field(string $group, string $id): \SimpleXMLElement
    {
        $fields = $this->group($group)->xpath(sprintf('field[@id="%s"]', $id));
        self::assertCount(1, $fields, $group . '/' . $id);

        return $fields[0];
    }

    /**
     * @return string[]
     */
    private function fieldIds(\SimpleXMLElement $group): array
    {
        $ids = [];
        foreach ($group->field as $field) {
            $ids[] = (string)$field['id'];
        }

        return $ids;
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }
}

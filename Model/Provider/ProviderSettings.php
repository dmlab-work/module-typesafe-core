<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model\Provider;

use DmLab\TypeSafeCore\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Per-provider settings under `dmlab_typesafe/<code>/…` (default scope only).
 *
 * `typesafe` reads `dmlab_typesafe/general/…`, so existing installs need no migration.
 */
class ProviderSettings
{
    public const XML_PATH_PROVIDER = 'dmlab_typesafe/general/provider';
    public const DEFAULT_PROVIDER = 'typesafe';

    private const SECTION = 'dmlab_typesafe';
    private const LEGACY_GROUP = ['typesafe' => 'general'];
    private const DEFAULTS = [
        'typesafe' => ['base_url' => Config::DEFAULT_BASE_URL, 'model' => Config::DEFAULT_MODEL],
        'openai_compatible' => ['base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-6-luna'],
        'anthropic' => ['base_url' => 'https://api.anthropic.com', 'model' => 'claude-haiku-5-5'],
        'gemini' => ['base_url' => 'https://generativelanguage.googleapis.com', 'model' => 'gemini-3.5-flash-lite'],
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Code of the selected provider, default 'typesafe'.
     *
     * @return string
     */
    public function getActiveCode(): string
    {
        $code = $this->getString(self::XML_PATH_PROVIDER);

        return $code !== '' ? $code : self::DEFAULT_PROVIDER;
    }

    /**
     * Built-in default base URL of a provider; '' for an unknown code.
     *
     * @param string $code
     * @return string
     */
    public function getDefaultBaseUrl(string $code): string
    {
        return self::DEFAULTS[$code]['base_url'] ?? '';
    }

    /**
     * Provider code of a `dmlab_typesafe/<group>/<field>` config path.
     *
     * @param string $path
     * @return string
     */
    public function codeOfPath(string $path): string
    {
        $group = explode('/', $path)[1] ?? '';

        return array_search($group, self::LEGACY_GROUP, true) ?: $group;
    }

    /**
     * Base URL without a trailing slash; the provider default when unset.
     *
     * @param string $code
     * @return string
     */
    public function getBaseUrl(string $code): string
    {
        $url = rtrim($this->getString($this->path($code, 'base_url')), '/');

        return $url !== '' ? $url : $this->getDefaultBaseUrl($code);
    }

    /**
     * Trimmed API key; '' when unset. Already decrypted by the config.xml backend model.
     *
     * @param string $code
     * @return string
     */
    public function getApiKey(string $code): string
    {
        return $this->getString($this->path($code, 'api_key'));
    }

    /**
     * Model id; the provider default when unset.
     *
     * @param string $code
     * @return string
     */
    public function getModel(string $code): string
    {
        $model = $this->getString($this->path($code, 'model'));

        return $model !== '' ? $model : (self::DEFAULTS[$code]['model'] ?? '');
    }

    /**
     * Request timeout in seconds, 1–120, shared by every provider.
     *
     * @return int
     */
    public function getTimeout(): int
    {
        $value = $this->getString(Config::XML_PATH_TIMEOUT);

        return max(1, min(120, is_numeric($value) ? (int)$value : 20));
    }

    /**
     * Whether `openai_compatible` sends `temperature: 0`; off when unset (reasoning models reject it).
     *
     * @return bool
     */
    public function isSendTemperature(): bool
    {
        $value = $this->getString($this->path('openai_compatible', 'send_temperature'));

        return (bool)(int)$value;
    }

    /**
     * Config path of a provider field.
     *
     * @param string $code
     * @param string $field
     * @return string
     */
    public function path(string $code, string $field): string
    {
        return self::SECTION . '/' . (self::LEGACY_GROUP[$code] ?? $code) . '/' . $field;
    }

    /**
     * Trimmed string value; '' when unset.
     *
     * @param string $path
     * @return string
     */
    private function getString(string $path): string
    {
        $value = $this->scopeConfig->getValue($path);

        return is_scalar($value) ? trim((string)$value) : '';
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Model;

use DmLab\TypeSafeCore\Api\ConfigInterface;
use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\NotConfiguredException;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Typed reader over the `dmlab_typesafe` config section (default scope only).
 */
class Config implements ConfigInterface
{
    public const XML_PATH_ENABLED = 'dmlab_typesafe/general/enabled';
    public const XML_PATH_API_KEY = 'dmlab_typesafe/general/api_key';
    public const XML_PATH_MODEL = 'dmlab_typesafe/general/model';
    public const XML_PATH_BASE_URL = 'dmlab_typesafe/general/base_url';
    public const XML_PATH_TIMEOUT = 'dmlab_typesafe/limits/timeout';
    public const XML_PATH_CONCURRENCY = 'dmlab_typesafe/limits/concurrency';
    public const XML_PATH_MAX_ATTEMPTS = 'dmlab_typesafe/limits/max_attempts';
    public const XML_PATH_MONTHLY_TOKEN_BUDGET = 'dmlab_typesafe/limits/monthly_token_budget';
    public const XML_PATH_CACHE_TTL_DAYS = 'dmlab_typesafe/limits/cache_ttl_days';

    public const DEFAULT_MODEL = 'jev-1.13.0';
    public const DEFAULT_BASE_URL = 'https://api.typesafe.ai';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ProviderSettings $providerSettings
     * @param ProviderPool $providerPool
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ProviderSettings $providerSettings,
        private readonly ProviderPool $providerPool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED);
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return $this->isEnabled() && $this->getActiveProvider()?->isConfigured() === true;
    }

    /**
     * Code of the selected AI provider, default 'typesafe'.
     *
     * @return string
     */
    public function getProvider(): string
    {
        return $this->providerSettings->getActiveCode();
    }

    /**
     * TypeSafe API key, trimmed; '' when unset. Already decrypted by the config.xml backend model.
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->providerSettings->getApiKey(ProviderSettings::DEFAULT_PROVIDER);
    }

    /**
     * @inheritDoc
     */
    public function getModel(): string
    {
        return $this->getActiveProvider()?->getModel()
            ?? $this->providerSettings->getModel($this->getProvider());
    }

    /**
     * TypeSafe API base URL without a trailing slash.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->providerSettings->getBaseUrl(ProviderSettings::DEFAULT_PROVIDER);
    }

    /**
     * Request timeout in seconds, 1–120.
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->providerSettings->getTimeout();
    }

    /**
     * Parallel requests per wave, 1–16.
     *
     * @return int
     */
    public function getConcurrency(): int
    {
        return $this->getClampedInt(self::XML_PATH_CONCURRENCY, 4, 1, 16);
    }

    /**
     * Attempts per request including the first, 1–10.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->getClampedInt(self::XML_PATH_MAX_ATTEMPTS, 4, 1, 10);
    }

    /**
     * Monthly input-token budget across consumers; 0 = unlimited.
     *
     * @return int
     */
    public function getMonthlyTokenBudget(): int
    {
        return $this->getClampedInt(self::XML_PATH_MONTHLY_TOKEN_BUDGET, 0, 0, PHP_INT_MAX);
    }

    /**
     * Response cache lifetime in days; 0 = cache off.
     *
     * @return int
     */
    public function getCacheTtlDays(): int
    {
        return $this->getClampedInt(self::XML_PATH_CACHE_TTL_DAYS, 30, 0, PHP_INT_MAX);
    }

    /**
     * Selected provider; null when its code is not registered.
     *
     * @return ProviderInterface|null
     */
    private function getActiveProvider(): ?ProviderInterface
    {
        try {
            return $this->providerPool->getActive();
        } catch (NotConfiguredException) {
            return null;
        }
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

    /**
     * Integer value clamped to [min, max]; default when unset or non-numeric.
     *
     * @param string $path
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private function getClampedInt(string $path, int $default, int $min, int $max): int
    {
        $value = $this->getString($path);
        $int = is_numeric($value) ? (int)$value : $default;

        return max($min, min($max, $int));
    }
}

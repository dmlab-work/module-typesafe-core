<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Controller\Adminhtml\Connection;

use DmLab\TypeSafeCore\Api\ProviderInterface;
use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\TypeSafe\TypeSafeProvider;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Admin "Test Connection": lists the posted provider's models with its saved settings.
 *
 * Answers `{success, message, model}`, plus `note` when the configured model is not listed and `warning` when the
 * module is disabled or the provider is not the active one.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_TypeSafeCore::config';
    public const PARAM_PROVIDER = 'provider';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param ProviderPool $providerPool
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ProviderPool $providerPool,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $provider = null;
        try {
            $provider = $this->providerPool->get((string)$this->getRequest()->getParam(self::PARAM_PROVIDER));
            $data = $this->test($provider);
        } catch (TypeSafeException $e) {
            $data = ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $label = $provider?->getLabel() ?? 'AI';
            $this->logger->error(sprintf('%s connection test failed: %s', $label, $e->getMessage()));
            $data = ['success' => false, 'message' => (string)__('%1 client error: %2', $label, $e->getMessage())];
        }

        return $this->jsonFactory->create()->setData($data);
    }

    /**
     * Result of a successful model listing.
     *
     * @param ProviderInterface $provider
     * @return array<string, mixed>
     * @throws TypeSafeException
     */
    private function test(ProviderInterface $provider): array
    {
        $models = $provider->listModels();
        $model = $provider->getModel();
        $data = [
            'success' => true,
            'message' => (string)__('Connected to %1. Models: %2', $provider->getLabel(), implode(', ', $models)),
            'model' => (string)__('Configured model: %1', $model),
        ];
        if (!in_array($model, $models, true)) {
            $data['note'] = $provider->getCode() === TypeSafeProvider::CODE
                ? (string)__('The list shows aliases only; pinned versions such as jev-1.13.0 are accepted too.')
                : (string)__('The configured model is not in the list; check its id.');
        }
        $warnings = [];
        if (!$this->config->isEnabled()) {
            $warnings[] = (string)__('The AI client is disabled: enable it to use it.');
        }
        if ($this->config->getProvider() !== $provider->getCode()) {
            $warnings[] = (string)__(
                '%1 is not the active provider: select it under General to use it.',
                $provider->getLabel()
            );
        }
        if ($warnings !== []) {
            $data['warning'] = implode(' ', $warnings);
        }

        return $data;
    }
}

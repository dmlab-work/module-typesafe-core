<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Controller\Adminhtml\Connection;

use DmLab\TypeSafeCore\Exception\TypeSafeException;
use DmLab\TypeSafeCore\Model\Config;
use DmLab\TypeSafeCore\Model\Connection\ModelLister;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Admin "Test Connection": lists models with the saved key.
 *
 * Answers `{success, message, model}`, plus `note` when the configured model is not listed and `warning` when disabled.
 */
class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'DmLab_TypeSafeCore::config';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param ModelLister $modelLister
     * @param LoggerInterface $logger
     * @param Config $config
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ModelLister $modelLister,
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
        try {
            $models = $this->modelLister->listModels();
            $model = $this->config->getModel();
            $data = [
                'success' => true,
                'message' => (string)__('Connected to TypeSafe. Models: %1', implode(', ', $models)),
                'model' => (string)__('Configured model: %1', $model),
            ];
            if (!in_array($model, $models, true)) {
                $data['note'] = (string)__(
                    'The list shows aliases only; pinned versions such as jev-1.13.0 are accepted too.'
                );
            }
            if (!$this->config->isEnabled()) {
                $data['warning'] = (string)__('TypeSafe is disabled: enable it to use it.');
            }
        } catch (TypeSafeException $e) {
            $data = ['success' => false, 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->logger->error('TypeSafe connection test failed: ' . $e->getMessage());
            $data = ['success' => false, 'message' => (string)__('TypeSafe client error: %1', $e->getMessage())];
        }

        return $this->jsonFactory->create()->setData($data);
    }
}

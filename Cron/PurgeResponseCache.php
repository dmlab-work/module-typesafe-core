<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Cron;

use DmLab\TypeSafeCore\Model\Cache\ResponseCache;
use Psr\Log\LoggerInterface;

/**
 * Daily removal of response cache rows older than `cache_ttl_days`.
 */
class PurgeResponseCache
{
    /**
     * @param ResponseCache $responseCache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResponseCache $responseCache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Purge expired rows; failures are logged, never rethrown.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $deleted = $this->responseCache->purgeExpired();
        } catch (\Throwable $e) {
            $this->logger->error('TypeSafe response cache purge failed: ' . $e->getMessage());
            return;
        }

        if ($deleted > 0) {
            $this->logger->info(sprintf('TypeSafe response cache purge removed %d expired rows.', $deleted));
        }
    }
}

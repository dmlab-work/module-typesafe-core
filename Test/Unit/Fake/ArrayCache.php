<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Fake;

use Magento\Framework\App\CacheInterface;

/**
 * In-memory cache double; records lifetimes and can be told to throw.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string, string> */
    public array $data = [];

    /** @var array<string, int|null> */
    public array $lifetimes = [];

    /** @var \Exception|null */
    public ?\Exception $failWith = null;

    /**
     * @inheritDoc
     */
    public function getFrontend()
    {
        throw new \LogicException('Not supported by ArrayCache.');
    }

    /**
     * @inheritDoc
     */
    public function load($identifier)
    {
        $this->failIfAsked();

        return $this->data[$identifier] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->failIfAsked();
        $this->data[$identifier] = (string)$data;
        $this->lifetimes[$identifier] = $lifeTime;

        return true;
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        $this->failIfAsked();
        unset($this->data[$identifier], $this->lifetimes[$identifier]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clean($tags = [])
    {
        $this->failIfAsked();
        $this->data = [];
        $this->lifetimes = [];

        return true;
    }

    /**
     * Throw the configured exception, if any.
     *
     * @return void
     */
    private function failIfAsked(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}

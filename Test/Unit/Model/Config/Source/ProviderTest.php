<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Model\Config\Source;

use DmLab\TypeSafeCore\Model\Config\Source\Provider;
use DmLab\TypeSafeCore\Model\Provider\ProviderPool;
use DmLab\TypeSafeCore\Model\Provider\ProviderSettings;
use DmLab\TypeSafeCore\Test\Unit\Fake\FakeProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    public function testOptionsListEveryPoolProviderInDiOrder(): void
    {
        $settings = new ProviderSettings($this->createStub(ScopeConfigInterface::class));
        $pool = new ProviderPool($settings, [
            'typesafe' => new FakeProvider('typesafe', $settings),
            'custom' => new FakeProvider('custom', $settings),
        ]);

        self::assertSame(
            [
                ['value' => 'typesafe', 'label' => 'Fake typesafe'],
                ['value' => 'custom', 'label' => 'Fake custom'],
            ],
            (new Provider($pool))->toOptionArray()
        );
    }
}

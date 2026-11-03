<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit\Api;

use DmLab\TypeSafeCore\Api\ProviderInterface;
use PHPUnit\Framework\TestCase;

class ProviderInterfaceTest extends TestCase
{
    public function testDeclaresTheProviderContract(): void
    {
        $reflection = new \ReflectionClass(ProviderInterface::class);
        $signatures = [];
        foreach ($reflection->getMethods() as $method) {
            $params = array_map(
                static fn (\ReflectionParameter $p) => $p->getType() . ' $' . $p->getName(),
                $method->getParameters()
            );
            $signatures[$method->getName()] = implode(', ', $params) . ': ' . $method->getReturnType();
        }
        ksort($signatures);

        self::assertSame(
            [
                'buildRequest' => 'DmLab\TypeSafeCore\Api\Data\Request $request, string $model, ?string $repairNote: '
                    . 'DmLab\TypeSafeCore\Model\Http\HttpRequest',
                'cacheScope' => ': string',
                'classifyError' => 'DmLab\TypeSafeCore\Model\Http\HttpResponse $response: '
                    . '?DmLab\TypeSafeCore\Exception\TypeSafeException',
                'getCode' => ': string',
                'getLabel' => ': string',
                'getModel' => ': string',
                'isConfigured' => ': bool',
                'listModels' => ': array',
                'parseResponse' => 'string $body, DmLab\TypeSafeCore\Api\Data\Request $request, string $model, '
                    . 'bool $cached: DmLab\TypeSafeCore\Api\Data\Response',
                'retryAfter' => 'DmLab\TypeSafeCore\Model\Http\HttpResponse $response: ?int',
                'usage' => 'string $body: array',
            ],
            $signatures
        );
        self::assertStringContainsString('@api', (string)$reflection->getDocComment());
    }
}

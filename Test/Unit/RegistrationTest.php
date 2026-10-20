<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypeSafeCore\Test\Unit;

use Magento\Framework\Component\ComponentRegistrar;
use PHPUnit\Framework\TestCase;

class RegistrationTest extends TestCase
{
    public function testModuleIsRegisteredAtThisDirectory(): void
    {
        $paths = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        self::assertArrayHasKey('DmLab_TypeSafeCore', $paths);
        self::assertSame(realpath(dirname(__DIR__, 2)), realpath($paths['DmLab_TypeSafeCore']));
    }

    public function testModuleXmlHasNoSetupVersionAndDeclaresSequence(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/etc/module.xml');
        self::assertNotFalse($xml);

        $module = $xml->module;
        self::assertSame('DmLab_TypeSafeCore', (string)$module['name']);
        self::assertFalse(isset($module['setup_version']));

        $sequence = [];
        foreach ($module->sequence->module as $dependency) {
            $sequence[] = (string)$dependency['name'];
        }
        self::assertSame(['Magento_Config', 'Magento_Backend'], $sequence);
    }
}

<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

$moduleRoot = dirname(__DIR__, 2);

$candidates = [
    getenv('MAGENTO_ROOT') ? rtrim((string)getenv('MAGENTO_ROOT'), '/') . '/vendor/autoload.php' : null,
    '/var/www/html/vendor/autoload.php',
    $moduleRoot . '/vendor/autoload.php',
];

$autoloaderLoaded = false;
foreach ($candidates as $candidate) {
    if ($candidate !== null && is_file($candidate)) {
        require $candidate;
        $autoloaderLoaded = true;
        break;
    }
}

if (!$autoloaderLoaded) {
    fwrite(STDERR, "Unable to locate a Composer autoloader (set MAGENTO_ROOT).\n");
    // phpcs:ignore Magento2.Security.LanguageConstruct.ExitUsage
    exit(1);
}

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
    $prefix = 'DmLab\\TypeSafeCore\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $moduleRoot . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// When composer-installed, Magento's autoloader already ran registration.php; re-requiring throws.
try {
    require $moduleRoot . '/registration.php';
} catch (\LogicException $e) {
    // Already registered.
}

// Generate Factory/Proxy classes on demand when Magento's generated/ code is absent.
$generatedCodeAutoloader = new \Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader(
    [
        new \Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator(),
        new \Magento\Framework\TestFramework\Unit\Autoloader\ProxyGenerator(),
    ],
    new \Magento\Framework\Code\Generator\Io(
        new \Magento\Framework\Filesystem\Driver\File(),
        sys_get_temp_dir() . '/dmlab-typesafe-unit-generated'
    )
);
spl_autoload_register([$generatedCodeAutoloader, 'load']);

<?php
/**
 * PHPUnit Bootstrap for Fortis Payment Gateway Unit Tests
 *
 * This bootstrap is for UNIT tests - mocks all Magento dependencies
 */

// Set up error handling
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Try to find Magento's autoloader
$magentoAutoloader = null;
$possiblePaths = [
    __DIR__ . '/../../../../../../vendor/autoload.php', // From app/code/Fortispay/Fortis
    __DIR__ . '/../../../../vendor/autoload.php',       // From Fortispay/Fortis in workspace root
];

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        $magentoAutoloader = $path;
        break;
    }
}

if ($magentoAutoloader) {
    $rootLoader = require_once $magentoAutoloader;

    if ($rootLoader instanceof \Composer\Autoload\ClassLoader) {
        $rootLoader->unregister();
        $rootLoader->register(false);
    }
} else {
    // Fallback: just load the module's autoloader
    if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
        require_once __DIR__ . '/../../vendor/autoload.php';
    }
}

// Generate "<Class>Factory" classes on demand (e.g. Model\ConfigFactory), as Magento's own
// dev/tests/unit/framework/autoload.php does, so tests can stub them
if (class_exists(\Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader::class)) {
    $generatedCodeAutoloader = new \Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader(
        [new \Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator()],
        new \Magento\Framework\Code\Generator\Io(
            new \Magento\Framework\Filesystem\Driver\File(),
            sys_get_temp_dir() . '/fortis-unit-generated'
        )
    );
    spl_autoload_register([$generatedCodeAutoloader, 'load']);
}

// DO NOT load registration.php for unit tests
// It requires Magento's ComponentRegistrar which is not needed for isolated unit tests
// The classes under test will be autoloaded via PSR-4

<?php

/**
 * PHPUnit bootstrap - pure-PHP unit tests only.
 *
 * These tests exercise regression-critical logic that can run without a Craft
 * bootstrap (bucket math, enum cases, regex validation). For full integration
 * tests that need Craft's runtime, use Codeception with the craftcms/cms test
 * harness - see tests/README.md.
 */

declare(strict_types=1);

// Support running from both the plugin root (with its own vendor/)
// and from a host Craft project where the plugin is a dependency.
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../../vendor/autoload.php',
];

$loaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Could not find vendor/autoload.php\n");
    exit(1);
}

<?php

declare(strict_types=1);

// PHP CS Fixer is this package's formatter; pinned in composer.json.
$finder = PhpCsFixer\Finder::create()->in([
    __DIR__ . '/src',
    __DIR__ . '/tests',
    __DIR__ . '/examples',
    __DIR__ . '/test_app',
]);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true])
    ->setUsingCache(false)
    ->setFinder($finder);

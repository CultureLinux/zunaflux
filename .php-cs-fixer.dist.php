<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->name('*.php')
    ->notPath('Browser/router.php');

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(false)
    ->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
        // The code base mixes both comparison orders; flipping them is noise, not style.
        'yoda_style' => false,
        // Keep the explicit "no summary" short docblocks that carry only @param/@return types.
        'phpdoc_summary' => false,
        'phpdoc_to_comment' => false,
        'single_line_throw' => false,
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache');

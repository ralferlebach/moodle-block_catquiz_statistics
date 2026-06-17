#!/usr/bin/env php
<?php
/**
 * Developer tool: batch-fix PHPDoc @package annotations.
 *
 * Scans all *.php files under the given directory and ensures every file
 * docblock contains the correct @package tag for block_catquizstatistics.
 *
 * Usage:
 *   php tools/fix_phpdoc.php [<plugin_dir>]
 *
 * If <plugin_dir> is omitted, the parent directory of this script is used.
 *
 * NOT shipped with the plugin (excluded in .gitattributes / .phpcsignore).
 */

$plugindir = $argv[1] ?? dirname(__DIR__);

if (!is_dir($plugindir)) {
    fwrite(STDERR, "ERROR: directory not found: {$plugindir}\n");
    exit(1);
}

$component = 'block_catquizstatistics';
$iterator  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugindir, FilesystemIterator::SKIP_DOTS)
);

$fixed   = 0;
$skipped = 0;

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    // Skip tooling and vendor files.
    $path = $file->getPathname();
    if (
        strpos($path, DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR) !== false
        || strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false
    ) {
        continue;
    }

    $content = file_get_contents($path);

    // Already has correct @package.
    if (strpos($content, "@package    {$component}") !== false) {
        ++$skipped;
        continue;
    }

    // Has a @package with wrong value → fix in place.
    $updated = preg_replace(
        '/@package\s+\S+/',
        "@package    {$component}",
        $content
    );

    if ($updated !== null && $updated !== $content) {
        file_put_contents($path, $updated);
        echo "FIXED: {$path}\n";
        ++$fixed;
    } else {
        echo "WARN:  no @package tag found in {$path}\n";
        ++$skipped;
    }
}

echo "\nDone. Fixed: {$fixed}  Skipped/unchanged: {$skipped}\n";

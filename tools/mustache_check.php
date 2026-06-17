#!/usr/bin/env php
<?php
/**
 * Developer tool: basic Mustache template syntax checker.
 *
 * Validates that every .mustache file under the given directory:
 *   1. Contains the required file-level docblock comment {{! ... }}.
 *   2. Has a matching Example context JSON block.
 *   3. Has balanced {{ / }} delimiters.
 *   4. Does not contain PHP short open tags <? (common copy-paste error).
 *
 * Usage:
 *   php tools/mustache_check.php [<templates_dir>]
 *
 * Exits with code 0 when all checks pass, code 1 on any error.
 *
 * NOT shipped with the plugin (excluded in .gitattributes / .phpcsignore).
 */

$dir = $argv[1] ?? dirname(__DIR__) . '/templates';

if (!is_dir($dir)) {
    fwrite(STDERR, "ERROR: directory not found: {$dir}\n");
    exit(1);
}

$errors = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'mustache') {
        continue;
    }

    $path    = $file->getPathname();
    $content = file_get_contents($path);
    $name    = basename($path);
    $ok      = true;

    // 1. File-level docblock present.
    if (!preg_match('/^\{\{!/', ltrim($content))) {
        echo "ERROR [{$name}]: missing opening {{! docblock\n";
        $ok = false;
    }

    // 2. Example context JSON block present.
    if (strpos($content, 'Example context (json)') === false) {
        echo "ERROR [{$name}]: missing 'Example context (json)' block in docblock\n";
        $ok = false;
    }

    // 3. PHP short open tags.
    if (preg_match('/<\?(?!php|xml)/', $content)) {
        echo "ERROR [{$name}]: PHP short open tag '<?' found — use PHP 8 templates only via renderable\n";
        $ok = false;
    }

    // 4. Balanced {{ / }} — rudimentary check.
    $opens  = preg_match_all('/\{\{/', $content);
    $closes = preg_match_all('/\}\}/', $content);
    if ($opens !== $closes) {
        echo "ERROR [{$name}]: unbalanced delimiters ({{ × {$opens}  }} × {$closes})\n";
        $ok = false;
    }

    if ($ok) {
        echo "OK:    {$name}\n";
    } else {
        ++$errors;
    }
}

echo "\nTotal errors: {$errors}\n";
exit($errors > 0 ? 1 : 0);

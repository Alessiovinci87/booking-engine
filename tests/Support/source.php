<?php

/**
 * File PHP di app/ (percorsi relativi alla root, con /) che contengono almeno una delle stringhe date.
 *
 * @param  list<string>  $needles
 * @param  list<string>  $except  percorsi esclusi
 * @param  string|null  $exceptPattern  regex sui percorsi esclusi
 * @return list<string>
 */
function sourceFilesContaining(array $needles, array $except = [], ?string $exceptPattern = null, string $directory = 'app'): array
{
    $root = dirname(__DIR__, 2);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$directory, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        if (in_array($relative, $except, true) || ($exceptPattern !== null && preg_match($exceptPattern, $relative))) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach ($needles as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = $relative;
                break;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

<?php

/**
 * Validate report files against the v1 schema:
 *   php tests/validate.php tests/fixtures/report-v1.example.json [more.json …]
 * Exits non-zero if any file is invalid.
 */

declare(strict_types=1);

require __DIR__ . '/stub-server/lib.php';

$files = array_slice($argv, 1);
if (!$files) {
    fwrite(STDERR, "Usage: php tests/validate.php <report.json> [...]\n");
    exit(2);
}

$failed = false;
foreach ($files as $file) {
    $errors = validate_report((string) file_get_contents($file));
    if (!$errors) {
        echo "ok    $file\n";
        continue;
    }
    $failed = true;
    echo "FAIL  $file\n";
    foreach ($errors as $pointer => $messages) {
        foreach ($messages as $message) {
            echo "      $pointer: $message\n";
        }
    }
}

exit($failed ? 1 : 0);

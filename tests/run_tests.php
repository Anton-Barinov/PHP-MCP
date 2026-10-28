#!/usr/bin/env php
<?php

declare(strict_types=1);

$files = [
    __DIR__ . '/ServerTest.php',
    __DIR__ . '/StdioTransportTest.php',
    __DIR__ . '/PdoAdapterTest.php',
    __DIR__ . '/HttpTransportTest.php',
    __DIR__ . '/SseTransportTest.php',
];

$failed = 0;
echo "=========================================\n";
echo " Running PHP-MCP Full Test Suite\n";
echo "=========================================\n\n";

foreach ($files as $file) {
    $cmd = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($cmd, $returnVar);
    if ($returnVar !== 0) {
        $failed++;
        echo "❌ FAILED: " . basename($file) . "\n\n";
    } else {
        echo "✅ PASSED: " . basename($file) . "\n\n";
    }
}

if ($failed > 0) {
    echo "Summary: $failed test file(s) failed.\n";
    exit(1);
}

echo "All tests passed successfully! 🎉\n";
exit(0);

#!/usr/bin/env php
<?php
/**
 * Restatify AI Router - CLI Test Runner
 * 
 * Usage: php test-router-cli.php
 * 
 * Runs all end-to-end test scenarios and displays results.
 */

// Setup WordPress environment
$wp_load = null;
$possible_paths = [
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
];

foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $wp_load = $path;
        break;
    }
}

if (!$wp_load) {
    echo "❌ Error: Could not find WordPress wp-load.php\n";
    exit(1);
}

require_once $wp_load;

// Verify plugin is loaded
if (!class_exists('Restatify_Ai_Router_Test_Scenarios')) {
    echo "❌ Error: Restatify Router Test Scenarios class not found\n";
    echo "   Make sure the plugin is activated.\n";
    exit(1);
}

// Run all tests
echo "\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "  Restatify AI Router - End-to-End Test Suite\n";
echo "════════════════════════════════════════════════════════════════\n";
echo "\n";

$test_results = Restatify_Ai_Router_Test_Scenarios::run_all_tests();
$formatted = Restatify_Ai_Router_Test_Scenarios::format_results($test_results);

echo $formatted;
echo "\n";

// Return exit code based on pass/fail
$total = $test_results['total_scenarios'];
$passed = array_sum(array_column($test_results['results'], 'passed' => 1));

if ($passed === $total) {
    echo "✓ All tests passed! System ready for integration.\n\n";
    exit(0);
} else {
    echo "✗ Some tests failed. Review the output above.\n\n";
    exit(1);
}

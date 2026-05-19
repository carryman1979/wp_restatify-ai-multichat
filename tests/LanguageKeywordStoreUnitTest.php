<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-restatify-ai-language-keyword-store.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LanguageKeywordStoreUnitTest extends TestCase {
    private $originalWpdb = null;

    protected function setUp(): void {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new Restatify_Test_Wpdb_Stub();
    }

    protected function tearDown(): void {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    public function testKeywordSetDefaultsToGermanBaselineForUnknownLanguageWithoutDbOrLlm(): void {
        $set = Restatify_Ai_Language_Keyword_Store::keyword_set_for_language('xx', []);

        self::assertContains('termin', $set['booking'] ?? []);
        self::assertContains('morgen', $set['date'] ?? []);
        self::assertNotContains('appointment', $set['booking'] ?? []);
    }

    public function testGermanKeywordSetMergesBaselineAndDbKeywords(): void {
        /** @var Restatify_Test_Wpdb_Stub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->resultsByLanguage['de'] = [
            [
                'intent_group' => 'booking',
                'keywords' => json_encode(['termin', 'ersttermin', 'strategie-call']),
            ],
            [
                'intent_group' => 'affirmation',
                'keywords' => json_encode(['absolut', 'jawohl']),
            ],
        ];

        $set = Restatify_Ai_Language_Keyword_Store::keyword_set_for_language('de', []);

        self::assertContains('termin', $set['booking'] ?? []);
        self::assertContains('ersttermin', $set['booking'] ?? []);
        self::assertContains('strategie-call', $set['booking'] ?? []);
        self::assertContains('absolut', $set['affirmation'] ?? []);
        self::assertContains('ja', $set['affirmation'] ?? []);
    }

    public function testKnownLanguageCodesIncludesDbCodesAndBaselineCodes(): void {
        /** @var Restatify_Test_Wpdb_Stub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->languageCodes = ['fr', 'EN', 'de'];

        $codes = Restatify_Ai_Language_Keyword_Store::known_language_codes();

        self::assertSame(['de', 'en', 'fr'], $codes);
    }
}

final class Restatify_Test_Wpdb_Stub {
    public string $prefix = 'wp_';

    /** @var array<string,array<int,array<string,string>>> */
    public array $resultsByLanguage = [];

    /** @var array<int,string> */
    public array $languageCodes = [];

    public function prepare(string $query, string $language): string {
        return str_replace('%s', "'" . addslashes($language) . "'", $query);
    }

    /**
     * @return array<int,array<string,string>>
     */
    public function get_results(string $query, string $outputType = ARRAY_A): array {
        if (preg_match("/WHERE\s+language_code\s*=\s*'([^']+)'/i", $query, $m) !== 1) {
            return [];
        }

        $lang = strtolower(trim((string) ($m[1] ?? '')));
        return $this->resultsByLanguage[$lang] ?? [];
    }

    /**
     * @return array<int,string>
     */
    public function get_col(string $query): array {
        return $this->languageCodes;
    }
}

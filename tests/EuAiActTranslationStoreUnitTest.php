<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-restatify-ai-eu-ai-act-translation-store.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class EuAiActTranslationStoreUnitTest extends TestCase {
    private $originalWpdb = null;

    protected function setUp(): void {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new Restatify_Test_EuAiAct_Wpdb_Stub();
    }

    protected function tearDown(): void {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    public function testGetReturnsCachedTranslationWithoutLlmCall(): void {
        /** @var Restatify_Test_EuAiAct_Wpdb_Stub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->seedTranslation('booking', 'question', 'de', 'fr', 'Bitte bestaetigen Sie mit Ja oder Nein.', 'Veuillez repondre par oui ou non.');

        $value = Restatify_Ai_Eu_Ai_Act_Translation_Store::get(
            'booking',
            'question',
            'Bitte bestaetigen Sie mit Ja oder Nein.',
            'fr',
            []
        );

        self::assertSame('Veuillez repondre par oui ou non.', $value);
    }

    public function testListEntriesPaginatesTenItemsPerPage(): void {
        /** @var Restatify_Test_EuAiAct_Wpdb_Stub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        for ($i = 1; $i <= 12; $i++) {
            $wpdb->seedTranslation('contact', 'retry_prompt', 'de', 'pl', 'Prompt ' . $i, 'Tlumaczenie ' . $i, $i);
        }

        $pageOne = Restatify_Ai_Eu_Ai_Act_Translation_Store::list_entries(1, 10);
        $pageTwo = Restatify_Ai_Eu_Ai_Act_Translation_Store::list_entries(2, 10);

        self::assertSame(12, $pageOne['total']);
        self::assertSame(2, $pageOne['total_pages']);
        self::assertCount(10, $pageOne['items']);
        self::assertCount(2, $pageTwo['items']);
    }

    public function testUpdateTranslationPersistsManualEdit(): void {
        /** @var Restatify_Test_EuAiAct_Wpdb_Stub $wpdb */
        $wpdb = $GLOBALS['wpdb'];
        $id = $wpdb->seedTranslation('contact', 'trigger_answer', 'de', 'es', 'Ja', 'Si');

        $ok = Restatify_Ai_Eu_Ai_Act_Translation_Store::update_translation($id, 'Claro');
        $items = Restatify_Ai_Eu_Ai_Act_Translation_Store::list_entries(1, 10);

        self::assertTrue($ok);
        self::assertSame('Claro', (string) ($items['items'][0]['translated_text'] ?? ''));
        self::assertSame('manual', (string) ($items['items'][0]['source'] ?? ''));
    }
}

final class Restatify_Test_EuAiAct_Wpdb_Stub {
    public string $prefix = 'wp_';
    public string $charset = 'utf8mb4';

    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

    public function get_charset_collate(): string {
        return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function get_var(string $query) {
        if (stripos($query, 'COUNT(*)') !== false) {
            return (string) count($this->rows);
        }

        if (
            preg_match("/action_type\s*=\s*'([^']+)'/i", $query, $action) === 1
            && preg_match("/field_kind\s*=\s*'([^']+)'/i", $query, $field) === 1
            && preg_match("/target_language_code\s*=\s*'([^']+)'/i", $query, $target) === 1
            && preg_match("/source_key\s*=\s*'([^']+)'/i", $query, $sourceKey) === 1
        ) {
            foreach ($this->rows as $row) {
                if (
                    (string) $row['action_type'] === (string) $action[1]
                    && (string) $row['field_kind'] === (string) $field[1]
                    && (string) $row['target_language_code'] === (string) $target[1]
                    && (string) $row['source_key'] === (string) $sourceKey[1]
                ) {
                    return (string) $row['translated_text'];
                }
            }
        }

        return null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function get_results(string $query, string $outputType = ARRAY_A): array {
        $rows = $this->rows;
        usort($rows, static function (array $left, array $right): int {
            return ((int) $right['id']) <=> ((int) $left['id']);
        });

        $limit = 10;
        $offset = 0;
        if (preg_match('/LIMIT\s+(\d+)\s+OFFSET\s+(\d+)/i', $query, $m) === 1) {
            $limit = (int) $m[1];
            $offset = (int) $m[2];
        }

        return array_slice($rows, $offset, $limit);
    }

    public function replace(string $table, array $data, array $formats = []): int {
        foreach ($this->rows as $index => $row) {
            if (
                (string) $row['action_type'] === (string) ($data['action_type'] ?? '')
                && (string) $row['field_kind'] === (string) ($data['field_kind'] ?? '')
                && (string) $row['target_language_code'] === (string) ($data['target_language_code'] ?? '')
                && (string) $row['source_key'] === (string) ($data['source_key'] ?? '')
            ) {
                $data['id'] = $row['id'];
                $this->rows[$index] = $data;
                return 1;
            }
        }

        $data['id'] = count($this->rows) + 1;
        $this->rows[] = $data;
        return 1;
    }

    public function update(string $table, array $data, array $where, array $dataFormats = [], array $whereFormats = []): int|false {
        $id = (int) ($where['id'] ?? 0);
        foreach ($this->rows as $index => $row) {
            if ((int) ($row['id'] ?? 0) !== $id) {
                continue;
            }

            $this->rows[$index] = array_merge($row, $data);
            return 1;
        }

        return false;
    }

    public function seedTranslation(string $actionType, string $fieldKind, string $sourceLanguageCode, string $targetLanguageCode, string $sourceText, string $translatedText, ?int $forcedId = null): int {
        $id = $forcedId ?? (count($this->rows) + 1);
        $this->rows[] = [
            'id' => $id,
            'action_type' => $actionType,
            'field_kind' => $fieldKind,
            'source_language_code' => $sourceLanguageCode,
            'target_language_code' => $targetLanguageCode,
            'source_key' => sha1(trim(preg_replace('/\s+/u', ' ', $sourceText) ?? $sourceText)),
            'source_text' => $sourceText,
            'translated_text' => $translatedText,
            'source' => 'llm',
            'model' => 'gpt-4o-mini',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        return $id;
    }
}
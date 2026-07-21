<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stores translated EU AI Act confirmation texts per action/language.
 */
class Restatify_Ai_Eu_Ai_Act_Translation_Store {
    private const SCHEMA_VERSION = '1.0.0';
    private const SCHEMA_OPTION_KEY = 'restatify_mco_eu_ai_act_translation_schema_version';

    private static function table_name(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }

        return $wpdb->prefix . 'restatify_mco_eu_ai_translations';
    }

    public static function maybe_install_table(): void {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }

        $installed = (string) get_option(self::SCHEMA_OPTION_KEY, '');
        if ($installed === self::SCHEMA_VERSION) {
            return;
        }

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        if (!function_exists('dbDelta')) {
            return;
        }

        $table = self::table_name();
        if ($table === '') {
            return;
        }

        global $wpdb;
        $charset_collate = isset($wpdb->charset) ? $wpdb->get_charset_collate() : '';
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_type varchar(20) NOT NULL,
            field_kind varchar(30) NOT NULL,
            source_language_code varchar(12) NOT NULL,
            target_language_code varchar(12) NOT NULL,
            source_key char(40) NOT NULL,
            source_text text NOT NULL,
            translated_text text NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'llm',
            model varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY translation_lookup (action_type, field_kind, target_language_code, source_key)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::SCHEMA_OPTION_KEY, self::SCHEMA_VERSION, false);
    }

    public static function get(string $actionType, string $fieldKind, string $sourceText, string $targetLanguageCode, array $aiOptions = [], string $sourceLanguageCode = ''): string {
        $sourceText = trim($sourceText);
        if ($sourceText === '') {
            return '';
        }

        $target = self::normalize_language_code($targetLanguageCode);
        $source = self::normalize_language_code($sourceLanguageCode !== '' ? $sourceLanguageCode : self::detect_source_language_code());
        if ($target === '' || $target === $source) {
            return $sourceText;
        }

        $cached = self::load_from_db($actionType, $fieldKind, $sourceText, $target);
        if ($cached !== null) {
            return $cached;
        }

        $translated = self::translate_text($sourceText, $source, $target, $aiOptions);
        if ($translated === '') {
            return $sourceText;
        }

        self::save_to_db($actionType, $fieldKind, $source, $target, $sourceText, $translated, (string) ($aiOptions['ai_model'] ?? ''));
        return $translated;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int,total_pages:int,current_page:int}
     */
    public static function list_entries(int $page = 1, int $perPage = 10): array {
        global $wpdb;
        $table = self::table_name();
        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return [
                'items' => [],
                'total' => 0,
                'total_pages' => 0,
                'current_page' => 1,
            ];
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, action_type, field_kind, source_language_code, target_language_code, source_text, translated_text, source, model, updated_at FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
                $perPage,
                $offset
            ),
            ARRAY_A
        );

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $perPage) : 0,
            'current_page' => $page,
        ];
    }

    public static function update_translation(int $id, string $translatedText): bool {
        global $wpdb;
        $table = self::table_name();
        if (!isset($wpdb) || !is_object($wpdb) || $table === '' || $id <= 0) {
            return false;
        }

        $translatedText = trim($translatedText);
        if ($translatedText === '') {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            [
                'translated_text' => $translatedText,
                'source' => 'manual',
                'updated_at' => function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    private static function load_from_db(string $actionType, string $fieldKind, string $sourceText, string $targetLanguageCode): ?string {
        global $wpdb;
        $table = self::table_name();
        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return null;
        }

        $sourceKey = sha1(self::normalize_source_text($sourceText));
        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT translated_text FROM {$table} WHERE action_type = %s AND field_kind = %s AND target_language_code = %s AND source_key = %s LIMIT 1",
                $actionType,
                $fieldKind,
                $targetLanguageCode,
                $sourceKey
            )
        );

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function save_to_db(string $actionType, string $fieldKind, string $sourceLanguageCode, string $targetLanguageCode, string $sourceText, string $translatedText, string $model): void {
        global $wpdb;
        $table = self::table_name();
        if (!isset($wpdb) || !is_object($wpdb) || $table === '') {
            return;
        }

        $now = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        $wpdb->replace(
            $table,
            [
                'action_type' => $actionType,
                'field_kind' => $fieldKind,
                'source_language_code' => $sourceLanguageCode,
                'target_language_code' => $targetLanguageCode,
                'source_key' => sha1(self::normalize_source_text($sourceText)),
                'source_text' => $sourceText,
                'translated_text' => $translatedText,
                'source' => 'llm',
                'model' => $model,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    private static function translate_text(string $sourceText, string $sourceLanguageCode, string $targetLanguageCode, array $aiOptions): string {
        $endpoint = trim((string) ($aiOptions['ai_api_endpoint'] ?? ''));
        $apiKey = trim((string) ($aiOptions['ai_api_key'] ?? ''));
        $model = trim((string) ($aiOptions['ai_model'] ?? 'gpt-4o-mini'));
        if ($endpoint === '' || $apiKey === '' || !function_exists('wp_remote_post') || !function_exists('wp_remote_retrieve_body') || !function_exists('is_wp_error')) {
            return '';
        }

        $prompt = 'Translate the following compliance UI text from language code "' . $sourceLanguageCode . '" to language code "' . $targetLanguageCode . '". '
            . 'Return only the translated text. Keep it short and exact because the output is used verbatim in a chat confirmation flow. Text: ' . $sourceText;

        $endpointLower = strtolower($endpoint);
        $isGemini = strpos($endpointLower, 'generativelanguage.googleapis.com') !== false || strpos($endpointLower, 'gemini') !== false;

        if ($isGemini) {
            $url = $endpoint;
            if (strpos($url, 'key=') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'key=' . rawurlencode($apiKey);
            }

            $body = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [[ 'text' => $prompt ]],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.0,
                    'maxOutputTokens' => 180,
                ],
            ];

            $response = wp_remote_post($url, [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => function_exists('wp_json_encode') ? (string) wp_json_encode($body) : (string) json_encode($body),
            ]);

            if (is_wp_error($response)) {
                return '';
            }

            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
            return trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'Return only the translated text.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.0,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            'body' => function_exists('wp_json_encode') ? (string) wp_json_encode($payload) : (string) json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
    }

    private static function detect_source_language_code(): string {
        if (function_exists('get_locale')) {
            return self::normalize_language_code((string) get_locale());
        }

        return 'de';
    }

    private static function normalize_language_code(string $languageCode): string {
        $value = strtolower(trim($languageCode));
        if ($value === '') {
            return 'de';
        }

        if (preg_match('/^[a-z]{2,3}/', $value, $m) !== 1) {
            return 'de';
        }

        return (string) $m[0];
    }

    private static function normalize_source_text(string $sourceText): string {
        return trim(preg_replace('/\s+/u', ' ', $sourceText) ?? $sourceText);
    }
}
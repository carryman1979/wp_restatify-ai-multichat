<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists language-specific intent keyword sets for router classification.
 */
class Restatify_Ai_Language_Keyword_Store {
    private const SCHEMA_VERSION = '1.0.0';
    private const SCHEMA_OPTION_KEY = 'restatify_mco_language_keywords_schema_version';

    /**
     * @return array<string,array<int,string>>
     */
    private static function baseline_keywords(string $language_code): array {
        $de = [
            'booking' => ['termin', 'buchen', 'buchung', 'reservierung', 'vereinbaren', 'erstgespraech', 'gespraech', 'meeting', 'call'],
            'affirmation' => ['ja', 'gerne', 'okay', 'ok', 'klar', 'natuerlich', 'auf jeden fall', 'passt', 'machen wir', 'klingt gut', 'sehr gerne', 'einverstanden'],
            'rejection' => ['nicht', 'kein', 'keine', 'nein', 'noe', 'gar nicht', 'lieber nicht', 'im moment nicht', 'jetzt nicht'],
            'scheduling_context' => ['termin', 'call', 'zeitraum', 'wann', 'telefonieren', 'anrufen', 'besprechung', 'meeting', 'gespraech', 'erreichbar', 'verfuegbar', 'passen', 'vereinbaren', 'callback', 'rueckruf', 'erstgespraech', 'uhrzeit', 'tage', 'datum'],
            'date' => ['morgen', 'uebermorgen', 'heute', 'naechste woche', 'montag', 'dienstag', 'mittwoch', 'donnerstag', 'freitag', 'samstag', 'sonntag'],
            'time_of_day' => ['nachmittag', 'nachmittags', 'vormittag', 'vormittags', 'morgens', 'abends', 'mittags'],
            'timing_question' => ['wann', 'wann genau', 'um wie viel', 'welche uhrzeit', 'welcher tag', 'wie viel uhr'],
        ];

        $en = [
            'booking' => ['appointment', 'book', 'booking', 'reserve', 'reservation', 'schedule', 'meeting', 'call', 'consultation', 'slot'],
            'affirmation' => ['yes', 'sure', 'okay', 'ok', 'sounds good', 'perfect', 'works for me', 'agreed'],
            'rejection' => ['no', 'not now', 'do not', "don't", 'no thanks', 'later', 'not interested'],
            'scheduling_context' => ['appointment', 'book', 'booking', 'schedule', 'meeting', 'call', 'time', 'available', 'availability', 'tomorrow', 'next week', 'slot'],
            'date' => ['today', 'tomorrow', 'day after tomorrow', 'next week', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'time_of_day' => ['morning', 'afternoon', 'evening', 'noon'],
            'timing_question' => ['when', 'what time', 'which day', 'how about', 'around'],
        ];

        if ($language_code === 'de') {
            return $de;
        }

        if ($language_code === 'en') {
            return $en;
        }

        return $de;
    }

    private static function table_name(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }

        return $wpdb->prefix . 'restatify_mco_intent_keywords';
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
            language_code varchar(12) NOT NULL,
            intent_group varchar(40) NOT NULL,
            keywords longtext NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'baseline',
            model varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lang_group (language_code, intent_group)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::SCHEMA_OPTION_KEY, self::SCHEMA_VERSION, false);
    }

    /**
     * @param array<string,mixed> $ai_options
     * @return array<string,array<int,string>>
     */
    public static function keyword_set_for_language(string $language_code, array $ai_options = []): array {
        $lang = self::normalize_language_code($language_code);
        $from_db = self::load_language_from_db($lang);
        if ($lang === 'de' || $lang === 'en') {
            $baseline = self::baseline_keywords($lang);
            if (count($from_db) > 0) {
                return self::merge_keyword_sets($baseline, $from_db);
            }

            return $baseline;
        }

        if (count($from_db) > 0) {
            return $from_db;
        }

        $generated = self::generate_keywords_via_llm($lang, $ai_options);
        if (count($generated) > 0) {
            self::save_language_to_db($lang, $generated, (string) ($ai_options['ai_model'] ?? ''));
            return $generated;
        }

        return self::baseline_keywords('de');
    }

    /**
     * @param array<string,array<int,string>> $base
     * @param array<string,array<int,string>> $extra
     * @return array<string,array<int,string>>
     */
    private static function merge_keyword_sets(array $base, array $extra): array {
        $merged = $base;
        foreach ($extra as $group => $keywords) {
            if (!is_string($group) || $group === '' || !is_array($keywords)) {
                continue;
            }

            $target = $merged[$group] ?? [];
            if (!is_array($target)) {
                $target = [];
            }

            foreach ($keywords as $keyword) {
                $kw = mb_strtolower(trim((string) $keyword));
                if ($kw !== '') {
                    $target[] = $kw;
                }
            }

            $merged[$group] = array_values(array_unique($target));
        }

        return $merged;
    }

    public static function normalize_language_code(string $value): string {
        $value = strtolower(trim($value));
        if ($value === '') {
            return 'de';
        }

        if (preg_match('/^[a-z]{2,3}/', $value, $m) !== 1) {
            return 'de';
        }

        $code = (string) $m[0];
        if (strlen($code) > 3) {
            $code = substr($code, 0, 3);
        }

        return $code;
    }

    /**
     * @return array<int,string>
     */
    public static function known_language_codes(): array {
        $known = ['de', 'en'];

        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return $known;
        }

        $table = self::table_name();
        if ($table === '') {
            return $known;
        }

        $rows = $wpdb->get_col("SELECT DISTINCT language_code FROM {$table}");
        if (!is_array($rows)) {
            return $known;
        }

        foreach ($rows as $row) {
            $code = self::normalize_language_code((string) $row);
            if ($code !== '') {
                $known[] = $code;
            }
        }

        $known = array_values(array_unique($known));
        sort($known);
        return $known;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function load_language_from_db(string $language_code): array {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return [];
        }

        $table = self::table_name();
        if ($table === '') {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT intent_group, keywords FROM {$table} WHERE language_code = %s",
                $language_code
            ),
            ARRAY_A
        );

        if (!is_array($rows) || count($rows) === 0) {
            return [];
        }

        $set = [];
        foreach ($rows as $row) {
            $group = (string) ($row['intent_group'] ?? '');
            $keywords_json = (string) ($row['keywords'] ?? '[]');
            if ($group === '') {
                continue;
            }

            $decoded = json_decode($keywords_json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $clean = [];
            foreach ($decoded as $keyword) {
                $kw = trim((string) $keyword);
                if ($kw !== '') {
                    $clean[] = mb_strtolower($kw);
                }
            }

            if (count($clean) > 0) {
                $set[$group] = array_values(array_unique($clean));
            }
        }

        return $set;
    }

    /**
     * @param array<string,array<int,string>> $set
     */
    private static function save_language_to_db(string $language_code, array $set, string $model): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }

        $table = self::table_name();
        if ($table === '') {
            return;
        }

        $now = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        foreach ($set as $group => $keywords) {
            if (!is_string($group) || trim($group) === '' || !is_array($keywords) || count($keywords) === 0) {
                continue;
            }

            $keywords = array_values(array_unique(array_map(static function ($k): string {
                return mb_strtolower(trim((string) $k));
            }, $keywords)));

            $wpdb->replace(
                $table,
                [
                    'language_code' => $language_code,
                    'intent_group' => $group,
                    'keywords' => wp_json_encode($keywords),
                    'source' => 'llm',
                    'model' => $model,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }
    }

    /**
     * @param array<string,mixed> $ai_options
     * @return array<string,array<int,string>>
     */
    private static function generate_keywords_via_llm(string $language_code, array $ai_options): array {
        $endpoint = trim((string) ($ai_options['ai_api_endpoint'] ?? ''));
        $api_key = trim((string) ($ai_options['ai_api_key'] ?? ''));
        $model = trim((string) ($ai_options['ai_model'] ?? ''));

        if ($endpoint === '' || $api_key === '') {
            return [];
        }

        $prompt = 'You are generating multilingual booking intent keywords for a website chat router. '
            . 'Target language code: ' . $language_code . '. '
            . 'Return strict JSON with arrays for keys: booking, affirmation, rejection, scheduling_context, date, time_of_day, timing_question. '
            . 'Each array should contain 6-20 short natural phrases for that language, relevant to appointment scheduling contexts. '
            . 'Do not include explanations.';

        $provider = 'openai';
        $endpoint_lower = strtolower($endpoint);
        if (strpos($endpoint_lower, 'generativelanguage.googleapis.com') !== false || strpos($endpoint_lower, 'gemini') !== false) {
            $provider = 'gemini';
        }

        if ($provider === 'gemini') {
            $url = $endpoint;
            if (strpos($url, 'key=') === false) {
                $separator = strpos($url, '?') === false ? '?' : '&';
                $url .= $separator . 'key=' . rawurlencode($api_key);
            }

            $body = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ];

            $response = wp_remote_post($url, [
                'timeout' => 20,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($body),
            ]);

            if (is_wp_error($response)) {
                return [];
            }

            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
            $text = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
            return self::extract_json_keyword_set($text);
        }

        $payload = [
            'model' => $model !== '' ? $model : 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Return strict JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $text = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        return self::extract_json_keyword_set($text);
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function extract_json_keyword_set(string $text): array {
        $candidate = trim($text);
        if ($candidate === '') {
            return [];
        }

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $candidate = substr($candidate, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return [];
        }

        $groups = ['booking', 'affirmation', 'rejection', 'scheduling_context', 'date', 'time_of_day', 'timing_question'];
        $result = [];
        foreach ($groups as $group) {
            $raw = $decoded[$group] ?? null;
            if (!is_array($raw)) {
                continue;
            }

            $clean = [];
            foreach ($raw as $kw) {
                $value = mb_strtolower(trim((string) $kw));
                if ($value !== '') {
                    $clean[] = $value;
                }
            }

            if (count($clean) > 0) {
                $result[$group] = array_values(array_unique($clean));
            }
        }

        return $result;
    }
}

<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Persists UI strings (Session-1 booking questions, overlay texts, clarification
 * phrases) per language in the database.
 *
 * Strategy:
 *  - de + en are always available as hardcoded baselines (no DB / LLM needed).
 *  - Any other language is translated once via LLM on first request, then cached
 *    in the DB so subsequent requests are instant and cost-free.
 *
 * DB table: {prefix}restatify_mco_ui_strings
 *   language_code  varchar(12)   – e.g. "pl", "ru", "tr"
 *   string_key     varchar(80)   – e.g. "question.name", "open_overlay.complete"
 *   string_value   text          – the translated string
 *   source         varchar(20)   – 'baseline' | 'llm'
 *   model          varchar(120)  – model that produced the translation
 *   created_at     datetime
 *   updated_at     datetime
 */
class Restatify_Ai_Ui_String_Store {

    private const SCHEMA_VERSION   = '1.0.0';
    private const SCHEMA_OPTION_KEY = 'restatify_mco_ui_strings_schema_version';

    // ------------------------------------------------------------------ schema

    private static function table_name(): string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return '';
        }
        return $wpdb->prefix . 'restatify_mco_ui_strings';
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
            string_key varchar(80) NOT NULL,
            string_value text NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'baseline',
            model varchar(120) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lang_key (language_code, string_key)
        ) {$charset_collate};";
        dbDelta($sql);
        update_option(self::SCHEMA_OPTION_KEY, self::SCHEMA_VERSION, false);
    }

    // ------------------------------------------------------------------ public

    /**
     * Fetch one UI string for the given language.
     *
     * Falls back to the English baseline if the language is not found and LLM
     * translation fails.
     *
     * @param string              $string_key  e.g. "question.name"
     * @param string              $language_code
     * @param array<string,mixed> $ai_options  Required for first-time LLM translation.
     * @return string
     */
    public static function get(string $string_key, string $language_code, array $ai_options = []): string {
        $lang = self::normalize_language_code($language_code);

        // de / en – always use the in-code baseline (authoritative, no DB round-trip).
        if ($lang === 'de' || $lang === 'en') {
            return self::baseline($string_key, $lang);
        }

        // Unknown language – check DB cache first.
        $cached = self::load_from_db($string_key, $lang);
        if ($cached !== null) {
            return $cached;
        }

        // Not cached yet – translate the full string set for this language once.
        self::translate_and_cache_language($lang, $ai_options);

        // Try DB again after translation.
        $cached = self::load_from_db($string_key, $lang);
        if ($cached !== null) {
            return $cached;
        }

        // LLM failed or DB not available – fall back to English.
        return self::baseline($string_key, 'en');
    }

    // ------------------------------------------------------------------ baselines

    /**
     * @return array<string,string>
     */
    private static function baselines(): array {
        return [
            // ── Session-1 booking questions ────────────────────────────────
            'de' => [
                'question.name'          => 'Gerne. Wie ist Ihr Name für den Termin?',
                'question.email'         => 'Gerne. Unter welcher E-Mail-Adresse dürfen wir den Termin bestätigen?',
                'question.subject'       => 'Wie soll ich den Termin kurz betiteln?',
                'question.contact_extra' => 'Perfekt. Welche Telefonnummer oder welcher Kontaktkanal passt zusätzlich für die Rückmeldung am besten?',
                'question.contact_value' => 'Wie können wir Sie am besten erreichen? Eine E-Mail-Adresse oder Telefonnummer reicht.',
                'question.default'       => 'Welche Angabe möchten Sie noch für den Termin hinterlegen?',
                // ── Open-overlay texts ─────────────────────────────────────
                'open_overlay.complete'   => 'Einen Moment – ich öffne das Buchungsformular für Sie.',
                'open_overlay.incomplete' => 'Ich öffne jetzt das Buchungsformular mit den bisher bekannten Angaben. Die fehlenden Details können Sie dort direkt ergänzen.',
                // ── Clarification question ─────────────────────────────────
                'clarification'           => 'Darf ich kurz fragen: Möchten Sie gleich einen Termin vereinbaren?',
                'clarification.contact'   => 'Darf ich kurz fragen: Möchten Sie lieber einfach eine Nachricht über das Kontaktformular hinterlassen?',
                'open_contact_form.complete' => 'Alles klar - ich öffne jetzt das Kontaktformular mit den bereits erkannten Angaben.',
                'open_contact_form.incomplete' => 'Alles klar - ich öffne jetzt das Kontaktformular. Die fehlenden Angaben können Sie dort direkt ergänzen.',
                'policy.malicious_injection' => 'Netter Versuch - aber heute leider ohne Zauberwort. Ich bleibe bei meinen Leitplanken. Wenn Sie möchten, helfe ich Ihnen gern bei einer fachlichen Anfrage in unserem Beratungsbereich weiter.',
                'policy.out_of_domain' => 'Das liegt außerhalb meines aktuellen Einsatzbereichs. Für allgemeine Themen sind ChatGPT, Gemini oder Mistral eine gute Wahl. Wenn Sie möchten, unterstütze ich Sie gern bei passenden Beratungs-, Kontakt- oder Terminfragen.',
            ],
            'en' => [
                'question.name'          => 'Sure! What name should we put on the appointment?',
                'question.email'         => 'Great. What email address should we use to confirm the appointment?',
                'question.subject'       => 'How would you like to briefly title the appointment?',
                'question.contact_extra' => 'Perfect. What phone number or contact channel works best for follow-up?',
                'question.contact_value' => 'How can we best reach you? An email address or phone number is fine.',
                'question.default'       => 'What other detail would you like to add for the appointment?',
                'open_overlay.complete'   => 'One moment – I\'m opening the booking form for you.',
                'open_overlay.incomplete' => 'I\'m now opening the booking form with the details collected so far. You can fill in the remaining information there.',
                'clarification'           => 'Quick check: would you like to schedule an appointment now?',
                'clarification.contact'   => 'Quick check: would you prefer to leave a message through the contact form?',
                'open_contact_form.complete' => 'Alright - I\'m opening the contact form now with the details already recognized.',
                'open_contact_form.incomplete' => 'Alright - I\'m opening the contact form now. You can fill in the missing details there.',
                'policy.malicious_injection' => 'Nice try - but no magic override today. I have to keep my guardrails. If you want, I can still help with a valid business-related request.',
                'policy.out_of_domain' => 'That topic is outside my current scope. For broad general-purpose tasks, ChatGPT, Gemini, or Mistral are great options. If you like, I can help with relevant consulting, contact, or booking requests here.',
            ],
        ];
    }

    private static function baseline(string $string_key, string $lang): string {
        $all = self::baselines();
        return (string) (($all[$lang] ?? $all['en'])[$string_key] ?? ($all['en'][$string_key] ?? ''));
    }

    // ------------------------------------------------------------------ DB helpers

    private static function load_from_db(string $string_key, string $language_code): ?string {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return null;
        }
        $table = self::table_name();
        if ($table === '') {
            return null;
        }
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT string_value FROM {$table} WHERE language_code = %s AND string_key = %s LIMIT 1",
                $language_code,
                $string_key
            )
        );
        return is_string($row) ? $row : null;
    }

    /**
     * @param array<string,string> $strings  key => translated value
     */
    private static function save_to_db(string $language_code, array $strings, string $model): void {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return;
        }
        $table = self::table_name();
        if ($table === '') {
            return;
        }
        $now = function_exists('current_time') ? (string) current_time('mysql', true) : gmdate('Y-m-d H:i:s');
        foreach ($strings as $key => $value) {
            if (!is_string($key) || trim($key) === '' || !is_string($value) || trim($value) === '') {
                continue;
            }
            $wpdb->replace(
                $table,
                [
                    'language_code' => $language_code,
                    'string_key'    => $key,
                    'string_value'  => $value,
                    'source'        => 'llm',
                    'model'         => $model,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
        }
    }

    // ------------------------------------------------------------------ LLM translation

    /**
     * @param array<string,mixed> $ai_options
     */
    private static function translate_and_cache_language(string $language_code, array $ai_options): void {
        $endpoint = trim((string) ($ai_options['ai_api_endpoint'] ?? ''));
        $api_key  = trim((string) ($ai_options['ai_api_key'] ?? ''));
        $model    = trim((string) ($ai_options['ai_model'] ?? ''));

        if ($endpoint === '' || $api_key === '') {
            return;
        }

        // Build the English source strings to translate.
        $en = self::baselines()['en'];
        $source_json = wp_json_encode($en, JSON_UNESCAPED_UNICODE);

        $prompt = 'You are a professional UI translator. '
            . 'Translate every value in the following JSON object into language code "' . $language_code . '". '
            . 'Keep all JSON keys exactly as-is. Return ONLY the translated JSON object – no explanations, no markdown, no code blocks. '
            . 'Source JSON: ' . $source_json;

        $text = self::call_llm($endpoint, $api_key, $model, $prompt);
        if ($text === '') {
            return;
        }

        $translated = self::extract_json_strings($text);
        if (count($translated) === 0) {
            return;
        }

        self::save_to_db($language_code, $translated, $model);
    }

    /**
     * @return array<string,string>
     */
    private static function extract_json_strings(string $text): array {
        $candidate = trim($text);
        $start = strpos($candidate, '{');
        $end   = strrpos($candidate, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }
        $candidate = substr($candidate, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value) && trim($value) !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private static function call_llm(string $endpoint, string $api_key, string $model, string $prompt): string {
        $endpoint_lower = strtolower($endpoint);
        $is_gemini = strpos($endpoint_lower, 'generativelanguage.googleapis.com') !== false
                  || strpos($endpoint_lower, 'gemini') !== false;

        if ($is_gemini) {
            $url = $endpoint;
            if (strpos($url, 'key=') === false) {
                $sep = strpos($url, '?') === false ? '?' : '&';
                $url .= $sep . 'key=' . rawurlencode($api_key);
            }
            $body = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
            ];
            $response = wp_remote_post($url, [
                'timeout' => 25,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode($body),
            ]);
            if (is_wp_error($response)) {
                return '';
            }
            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
            return (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
        }

        // OpenAI-compatible
        $payload = [
            'model'    => $model !== '' ? $model : 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'Return strict JSON only.'],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.2,
        ];
        $response = wp_remote_post($endpoint, [
            'timeout' => 25,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);
        if (is_wp_error($response)) {
            return '';
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        return (string) ($decoded['choices'][0]['message']['content'] ?? '');
    }

    // ------------------------------------------------------------------ utilities

    public static function normalize_language_code(string $value): string {
        $value = strtolower(trim($value));
        if ($value === '') {
            return 'de';
        }
        if (preg_match('/^[a-z]{2,3}/', $value, $m) !== 1) {
            return 'de';
        }
        return substr((string) $m[0], 0, 3);
    }
}

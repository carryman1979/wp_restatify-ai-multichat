<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core AI request/booking helper layer for chat runtime.
 *
 * Handles AI provider detection, prompt building, HTTP request/retry loop,
 * response extraction and the booking-plugin integration helpers used by
 * Restatify_Ai_Multichat_Chat_Runtime_Ai_Base and higher layers.
 *
 * Inheritance order:
 *   Options_Runtime → Ai_Core_Base → Ai_Base → Transport_Base → Chat_Runtime → Admin_Runtime → Plugin
 */
abstract class Restatify_Ai_Multichat_Chat_Runtime_Ai_Core_Base extends Restatify_Ai_Multichat_Options_Runtime {

    protected function generate_ai_reply(array $options, array $conversation, string $latest_message): string {
        if (empty($options['ai_enabled'])) {
            return '';
        }

        $debug_enabled = !empty($options['ai_debug_enabled']);

        $endpoint = $this->sanitize_ai_endpoint((string) ($options['ai_api_endpoint'] ?? Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT));
        $provider = $this->detect_ai_provider($endpoint);
        $api_key = trim((string) ($options['ai_api_key'] ?? ''));

        $this->log_ai_debug($debug_enabled, 'AI request initialized', [
            'provider' => $provider,
            'endpoint' => $endpoint,
            'model' => (string) ($options['ai_model'] ?? ''),
            'has_api_key' => $api_key !== '',
        ]);

        if ($api_key === '' && !($provider === 'llama' && $this->is_local_ai_endpoint($endpoint))) {
            $this->log_ai_debug($debug_enabled, 'AI request aborted: missing API key');
            return '';
        }

        $model = trim((string) ($options['ai_model'] ?? 'gpt-4o-mini'));
        if ($model === '') {
            $model = 'gpt-4o-mini';
        }

        $max_response_chars = $this->normalize_ai_max_response_chars((int) ($options['ai_max_response_chars'] ?? Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_DEFAULT));
        $booking_contact_methods = $this->get_booking_contact_methods();
        $conversation_language = $this->resolve_conversation_language_code($conversation, $latest_message);
        $system_prompt = $this->build_effective_system_prompt(
            trim((string) ($options['ai_system_prompt'] ?? '')),
            $max_response_chars,
            $this->is_booking_plugin_available(),
            $booking_contact_methods
        );
        $system_prompt = $this->append_response_language_instruction($system_prompt, $conversation_language);
        $messages = $this->build_ai_messages($conversation, $latest_message, $system_prompt);

        $request = $this->build_ai_request($provider, $endpoint, $model, $api_key, $messages, '');

        $this->log_ai_debug($debug_enabled, 'AI request payload prepared', [
            'provider' => $provider,
            'endpoint' => (string) ($request['endpoint'] ?? ''),
            'message_count' => count($messages),
            'max_response_chars' => $max_response_chars,
            'header_keys' => array_keys((array) ($request['headers'] ?? [])),
        ]);

        $max_attempts = max(1, min(10, absint($options['chat_send_retry_max_attempts'] ?? 3)));
        $retry_wait_ms = max(0, min(60000, absint($options['chat_send_retry_wait_ms'] ?? 500)));
        $timeout_ms = max(1000, min(120000, absint($options['chat_send_timeout_ms'] ?? 20000)));
        $timeout_seconds = max(1, (int) ceil($timeout_ms / 1000));

        $this->log_ai_debug($debug_enabled, 'AI HTTP retry policy', [
            'provider' => $provider,
            'max_attempts' => $max_attempts,
            'retry_wait_ms' => $retry_wait_ms,
            'timeout_ms' => $timeout_ms,
        ]);

        $response = null;
        $last_error = '';
        $last_status_code = 0;
        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $this->log_ai_debug($debug_enabled, 'AI request attempt started', [
                'provider' => $provider,
                'attempt' => $attempt,
                'max_attempts' => $max_attempts,
            ]);

            $attempt_started_at = microtime(true);

            $response = wp_remote_post($request['endpoint'], [
                'timeout' => $timeout_seconds,
                'headers' => $request['headers'],
                'body' => wp_json_encode($request['payload']),
            ]);

            $attempt_duration_ms = (int) round((microtime(true) - $attempt_started_at) * 1000);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                $last_status_code = 0;
                $this->log_ai_debug($debug_enabled, 'AI HTTP error', [
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'max_attempts' => $max_attempts,
                    'duration_ms' => $attempt_duration_ms,
                    'error' => $last_error,
                ]);

                if ($attempt < $max_attempts) {
                    $wait_ms = $this->calculate_ai_retry_wait_ms($retry_wait_ms, 0, $attempt, []);
                    if ($wait_ms > 0) {
                        $this->log_ai_debug($debug_enabled, 'AI retry wait scheduled', [
                            'provider' => $provider,
                            'attempt' => $attempt,
                            'max_attempts' => $max_attempts,
                            'wait_ms' => $wait_ms,
                            'reason' => 'transport_error',
                        ]);
                        usleep($wait_ms * 1000);
                    }
                }
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status < 200 || $status >= 300) {
                $last_error = 'HTTP ' . $status;
                $last_status_code = $status;
                $this->log_ai_debug($debug_enabled, 'AI non-2xx response', [
                    'provider' => $provider,
                    'attempt' => $attempt,
                    'max_attempts' => $max_attempts,
                    'duration_ms' => $attempt_duration_ms,
                    'status' => $status,
                    'body' => $this->shorten_for_log((string) wp_remote_retrieve_body($response)),
                ]);

                $retryable = $this->is_ai_retryable_http_status($status);
                if (!$retryable) {
                    $this->log_ai_debug($debug_enabled, 'AI non-retryable response encountered', [
                        'provider' => $provider,
                        'attempt' => $attempt,
                        'max_attempts' => $max_attempts,
                        'status' => $status,
                    ]);
                    break;
                }

                if ($attempt < $max_attempts) {
                    $wait_ms = $this->calculate_ai_retry_wait_ms($retry_wait_ms, $status, $attempt, $response);
                    if ($wait_ms > 0) {
                        $this->log_ai_debug($debug_enabled, 'AI retry wait scheduled', [
                            'provider' => $provider,
                            'attempt' => $attempt,
                            'max_attempts' => $max_attempts,
                            'wait_ms' => $wait_ms,
                            'reason' => 'http_' . $status,
                        ]);
                        usleep($wait_ms * 1000);
                    }
                }
                continue;
            }

            $this->log_ai_debug($debug_enabled, 'AI request attempt succeeded', [
                'provider' => $provider,
                'attempt' => $attempt,
                'max_attempts' => $max_attempts,
                'duration_ms' => $attempt_duration_ms,
            ]);

            $last_error = '';
            break;
        }

        if ($last_error !== '' || is_wp_error($response) || !is_array($response)) {
            $this->log_ai_debug($debug_enabled, 'AI request failed after retries', [
                'provider' => $provider,
                'max_attempts' => $max_attempts,
                'last_error' => $last_error,
                'last_status_code' => $last_status_code,
            ]);
            return $this->resolve_ai_failure_notice($options, $last_status_code);
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($provider === 'gemini' && empty($body['candidates']) && !empty($body['promptFeedback'])) {
            $this->log_ai_debug($debug_enabled, 'Gemini returned prompt feedback without candidates', [
                'feedback' => $body['promptFeedback'],
            ]);
        }

        $content = $this->extract_ai_response_content($provider, is_array($body) ? $body : []);
        if ($content === '') {
            $this->log_ai_debug($debug_enabled, 'AI response had no extractable text', [
                'provider' => $provider,
                'body' => $this->shorten_for_log((string) wp_json_encode($body)),
            ]);
            return $this->resolve_ai_failure_notice($options, 0);
        }

        $this->log_ai_debug($debug_enabled, 'AI response parsed successfully', [
            'provider' => $provider,
            'content_length' => strlen($content),
        ]);

        $booking_payload = null;
        if ($this->is_booking_plugin_available()) {
            $booking_payload = $this->extract_booking_prefill_payload($content);
            if (is_array($booking_payload)) {
                $booking_payload = $this->enrich_booking_prefill_payload($booking_payload, $conversation, $latest_message);
                $content = $this->attach_booking_prefill_trigger($content, $booking_payload);
            }
        }

        return $this->truncate_chat_text($content, $max_response_chars);
    }

    protected function truncate_chat_text(string $text, int $hard_limit = 3000): string {
        $value = trim($text);
        if ($value === '' || $hard_limit <= 0) {
            return '';
        }

        $has_mb = function_exists('mb_strlen') && function_exists('mb_substr') && function_exists('mb_strrpos');
        $length = $has_mb ? mb_strlen($value) : strlen($value);
        if ($length <= $hard_limit) {
            return $value;
        }

        $truncated = $has_mb ? mb_substr($value, 0, $hard_limit) : substr($value, 0, $hard_limit);
        $window = min(280, max(80, (int) floor($hard_limit * 0.2)));
        $tail = $has_mb ? mb_substr($truncated, max(0, $hard_limit - $window)) : substr($truncated, max(0, $hard_limit - $window));

        $best_offset = -1;
        foreach (['. ', '! ', '? ', "\n"] as $marker) {
            if ($has_mb) {
                $pos = mb_strrpos($tail, $marker);
            } else {
                $pos = strrpos($tail, $marker);
            }

            if ($pos !== false && (int) $pos > $best_offset) {
                $best_offset = (int) $pos;
            }
        }

        if ($best_offset >= 0) {
            $cut = ($hard_limit - ($has_mb ? mb_strlen($tail) : strlen($tail))) + $best_offset + 1;
            $truncated = $has_mb ? mb_substr($truncated, 0, $cut) : substr($truncated, 0, $cut);
        }

        return trim($truncated);
    }

    protected function normalize_ai_max_response_chars(int $value): int {
        return max(
            Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_MIN,
            min(Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_MAX, $value)
        );
    }

    protected function build_effective_system_prompt(string $custom_prompt, int $max_response_chars, bool $booking_available = false, array $booking_contact_methods = []): string {
        $max_response_chars = $this->normalize_ai_max_response_chars($max_response_chars);

        // 1. ALLE allgemeinen Regeln als EIN Block
        $general_rules = [
            'HARTE SYSTEMANFORDERUNGEN (IMMER EINHALTEN):',
            '- Antworte immer in derselben Sprache wie die letzte Nutzer-Nachricht.',
            '- Gib nur Klartext aus. Keine Markdown-Codeblocks, kein HTML und kein XML. JSON ist nur in der explizit erlaubten Trigger-Zeile zulässig.',
            '- Erlaubte Formatierung nur mit einfachen Zeilenumbrüchen und optionalen Listenzeichen (- oder 1.).',
            '- Halte die Antwortlänge bei maximal ' . $max_response_chars . ' Zeichen.',
            '- Wenn der Nutzer bereits ein konkretes Anliegen schreibt, antworte direkt darauf und beginne nicht mit einer allgemeinen Begrüßung.',
            '- Erfinde keine internen Schritte oder Aktionen (z.B. Weiterleitung, technische Prüfung, Abstimmung mit Team), wenn sie nicht tatsächlich in diesem Chat ausgelöst wurden.',
            '- Mache keine rechtlichen Garantien als Fakt (z.B. "vollständig rechtssicher", "automatisch DSGVO/UWG-konform"). Formuliere vorsichtig und empfehle fachliche Prüfung.',
            '- Erfinde keine Integrations- oder Produktfähigkeiten als bestehende Tatsache (keine fiktiven Konnektoren, APIs, Features).',
            '',
            'WIEDERHOLUNGSVERBOT (STRIKT):',
            '- Fakten, die im bisherigen Gespräch bereits genannt wurden, werden NICHT erneut erklärt oder aufgelistet.',
            '- Bereits bekannte Kontextinformationen (z.B. genutztes CRM, Branche, Unternehmensgröße, Schwerpunkte) werden maximal einmal kurz als Bezugspunkt erwähnt, niemals erneut ausgeführt.',
            '- Feature-Listen und Produktbeschreibungen dürfen pro Gespräch nur einmal vorkommen. Danach ist jede Wiederholung verboten, auch in anderen Worten.',
            '',
            'ANTWORTLÄNGE – PROGRESSIV KÜRZEN:',
            '- Zähle die Gesprächsrunden seit Gesprächsbeginn. Eine Runde = eine Nutzerantwort + eine KI-Antwort.',
            '- Runde 1-2: ausführliche Antwort erlaubt (Kontext aufbauen).',
            '- Runde 3-4: maximal 5 Sätze oder 2 kurze Listenpunkte.',
            '- Runde 5+: maximal 3 Sätze. Kein Einstieg mit Produktpräsentation.',
            '- Im Buchungsmodus (s.u.): maximal 1-2 gezielte Rückfragen, kein Beratungstext.',
            '',
            'KURZANTWORT-REGEL (KRITISCH):',
            '- Wenn deine vorherige Antwort mit einer Frage endete UND die aktuelle Nutzerantwort kürzer als 60 Zeichen ist, gilt sie IMMER als direkte Antwort auf deine Frage.',
            '- In diesem Fall: Beantworte oder verarbeite die Antwort direkt. KEIN erneuter Pitch, KEINE Feature-Liste, KEINE Zusammenfassung bereits bekannter Vorteile.',
            '- Beispiel: Du fragst "Wäre ein kurzer Termin sinnvoll?" → Nutzer antwortet "Ja, habe ich." → Du gehst SOFORT zum Buchungsworkflow, ohne weitere Erklärungen.',
            '',
            'BUCHUNGSMODUS (AKTIVIERUNG UND VERHALTEN):',
            '- Der BUCHUNGSMODUS wird aktiviert, sobald der Nutzer zum zweiten Mal Zustimmung oder Termininteresse signalisiert (z.B. "ja", "gerne", "wäre toll", "passt", "ich habe Interesse", "bin offen").',
            '- Im BUCHUNGSMODUS gilt ausschließlich: Sammle die fehlenden Pflichtinformationen für die Terminbuchung (Name, Kontaktweg, Wunschtermin/Datum+Uhrzeit). Eine Frage pro Nachricht.',
            '- Im BUCHUNGSMODUS sind verboten: Produktbeschreibungen, Feature-Listen, Lösungsvorschläge, Zusammenfassungen, Fragen nach dem Anliegen (das ist bereits bekannt).',
            '- Sobald alle Pflichtinformationen vorhanden sind, gib den Booking-Trigger aus.',
            'BUCHUNGS-/LINK-REGELN (NUR BEACHTEN, WENN TERMINVEREINBARUNG ODER BUCHUNGSWUNSCH VORLIEGT):',
            '- Biete NIEMALS von dir aus einen Kalender-Link, Meeting-Link, Zugangsdaten oder Einwahldaten an – auch nicht nach einer Terminvereinbarung oder Uhrzeitbestätigung. Sende solche Links oder Daten AUSSCHLIESSLICH, wenn der Nutzer EXPLIZIT danach fragt (z. B. "Bitte senden Sie mir den Link", "Wie komme ich ins Meeting?", "Ich brauche die Zugangsdaten", "Schicken Sie mir die Einwahldaten").'
        ];

        // 2. Danach: bedingungsabhängige Buchungs-/Link-Regeln und Trigger-JSON
        $booking_rules = [];
        if ($booking_available) {
            $booking_url = '#booking-open';
            if (function_exists('home_url')) {
                $current_url = $_SERVER['REQUEST_URI'] ?? '';
                $booking_url = home_url($current_url) . '#booking-open';
            }
            $booking_rules = [
                '- DER EINZIG GÜLTIGE LINK DEN DU ANBIETEN DARFST IST: '.$booking_url.'#booking',
                '- Nach einer Terminvereinbarung oder Uhrzeitbestätigung: Biete an, das Terminbuchungstool (Popup) zu öffnen, aber sende KEINEN Link, außer der Nutzer fordert explizit einen Link oder Zugangsdaten an.',
                '- Beispiel: Nutzer: "11 Uhr wäre mir lieber." → Du bestätigst nur den Termin, bietest aber KEINEN Link an. Stattdessen: "Soll ich das Terminbuchungstool für Sie öffnen?"',
                '- Beispiel: Nutzer: "Können Sie mir den Link schicken?" → Jetzt darfst du den Link senden: '.$booking_url.'#booking'
            ];
        } else {
            $booking_rules = [
                '- Nach einer Terminvereinbarung oder Uhrzeitbestätigung: Informiere, dass ein Mitarbeiter über diesen Chat informiert wird und versucht, dazu zu kommen, um die Terminvereinbarung abzuschließen. Du kannst immer anbieten, einen Mitarbeiter in den Chat dazuzuholen.',
                '- Beispiel 1: Nutzer: "11 Uhr wäre mir lieber." → Du bestätigst nur den Termin, bietest aber KEINEN Link an. Stattdessen: "Ich informiere einen Kollegen, der Sie gleich im Chat unterstützt."',
                '- Beispiel 2: Nutzer: "Können Sie mir den Link schicken?" → Du: "Natürlich! Ich hole einen Kollegen, der Ihnen die Details zum Vorgang erläutern kann."',
                '- Beispiel 3: Nutzer: "Ich brauche die Zugangsdaten." → Du: "Ein Moment – ich informiere einen Kollegen, der mit Ihnen sofort die Zugangsdaten und Kalender-Details besprechen wird."',
                '- Beispiel 4: Nutzer: "Perfekt, Mittwoch 14 Uhr passt." → Du bestätigst: "Prima! Ein Mitarbeiter von uns kommt gleich in den Chat und hilft Ihnen mit den finalen Details und dem Termin."',
                '- Beispiel 5: Nutzer: "Wie bekomme ich den Kalender-Link?" → Du: "Gerne! Ich hole einen Kollegen, der dir sofort alle notwendigen Links und Informationen schickt."'
            ];
        }

        // Trigger-JSON und Kontaktmethodenhinweise nur, wenn Buchungstool verfügbar
        if ($booking_available) {
            $booking_contact_methods = array_values(array_unique(array_filter(array_map('sanitize_key', $booking_contact_methods), static function ($value) {
                return $value !== '';
            })));
            if (count($booking_contact_methods) === 0) {
                $booking_contact_methods = $this->get_booking_default_contact_methods();
            }

            $contact_methods_hint = implode('|', $booking_contact_methods);
            $prefill_token = $this->get_booking_prefill_token();
            $schema_keys_hint = implode(', ', $this->get_booking_prefill_allowed_keys());
            $booking_rules[] = '- Wenn der Nutzer eine Terminvereinbarung bestätigt oder konkrete Buchungsdaten nennt, füge am Ende genau eine Trigger-Zeile hinzu:';
            $booking_rules[] = '  ' . $prefill_token . '{"intent":"booking_confirm","subject":"...","note":"...","name":"...","email":"...","contact_method":"' . $contact_methods_hint . '","contact_value":"...","date":"YYYY-MM-DD","time":"HH:MM"}';
            $booking_rules[] = '- Trigger-JSON hartes Schema: Erlaubte Keys sind nur ' . $schema_keys_hint . '. Keine weiteren Keys ausgeben.';
            $booking_rules[] = '- Datentypen strikt: alle Felder als String; contact_method nur einer von ' . $contact_methods_hint . '; date nur YYYY-MM-DD; time nur HH:MM.';
            $booking_rules[] = '- Wenn der Nutzer eine nicht unterstützte Kontaktmethode nennt, frage nach und biete nur diese erlaubten Optionen an: ' . $contact_methods_hint . '.';
            $booking_rules[] = '- Fülle subject immer als kurze Zusammenfassung der Terminanfrage aus dem Chat (maximal 90 Zeichen).';
            $booking_rules[] = '- Fülle note immer als kompakte Zusammenfassung der relevanten Chat-Infos aus (Anliegen, Rahmen, Terminwunsch, offene Punkte).';
            $booking_rules[] = '- Sammle vor dem Trigger fehlende Pflichtinfos per Rückfrage im Chat (mindestens Name, Kontaktweg und Terminwunsch mit Datum/Uhrzeit), außer der Nutzer will die Daten selbst im Popup ausfüllen.';
            $booking_rules[] = '- Wenn der Nutzer ausdrücklich selbst im Popup ausfüllen möchte, gib den Trigger trotzdem aus, mindestens als: ' . $prefill_token . '{"intent":"booking_confirm"}';
            $booking_rules[] = '- Gib diese Trigger-Zeile nur aus, wenn ein Termin wirklich gewünscht ist. Bei Ablehnung niemals Trigger ausgeben.';
            $booking_rules[] = '- Die Trigger-Zeile darf die einzige JSON-Ausgabe sein.';
        }

        $prompt = implode("\n", $general_rules) . "\n\n" . implode("\n", $booking_rules);
        $custom_prompt = trim($custom_prompt);

        if ($custom_prompt === '') {
            return $prompt;
        }

        return $custom_prompt . "\n\n" . $prompt;
    }

    protected function resolve_ai_failure_notice(array $options, int $status_code): string {
        $is_overload = in_array($status_code, [429, 503], true);

        if ($is_overload) {
            $overload_notice = $this->sanitize_chat_message_content((string) ($options['chat_send_overload_notice'] ?? ''));
            if ($overload_notice !== '') {
                return $overload_notice;
            }
        }

        return $this->sanitize_chat_message_content((string) ($options['chat_send_failed_notice'] ?? ''));
    }

    protected function is_ai_failure_notice_response(string $reply, array $options): bool {
        $normalized = $this->sanitize_chat_message_content($reply);
        if ($normalized === '') {
            return true;
        }

        $failed = $this->sanitize_chat_message_content((string) ($options['chat_send_failed_notice'] ?? ''));
        $overload = $this->sanitize_chat_message_content((string) ($options['chat_send_overload_notice'] ?? ''));

        return ($failed !== '' && $normalized === $failed)
            || ($overload !== '' && $normalized === $overload);
    }

    protected function is_booking_plugin_available(): bool {
        return function_exists('restatify_booking_ai_handle_message');
    }

    protected function extract_booking_prefill_payload(string &$content): ?array {
        $prefill_token = $this->get_booking_prefill_token();

        if (strpos($content, $prefill_token) === false) {
            return null;
        }

        $pattern = '/' . preg_quote($prefill_token, '/') . '\s*(\{[^\n\r]*\})/u';
        if (!preg_match($pattern, $content, $matches)) {
            return null;
        }

        $raw_json = trim((string) ($matches[1] ?? ''));
        if ($raw_json === '') {
            return null;
        }

        $decoded = json_decode($raw_json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $payload = $this->normalize_booking_prefill_payload($decoded);
        $content = trim(str_replace((string) $matches[0], '', $content));
        return count($payload) > 0 ? $payload : null;
    }

    protected function normalize_booking_prefill_payload(array $payload): array {
        $out = [];
        $allowed_contact_methods = $this->get_booking_contact_methods();
        if (count($allowed_contact_methods) === 0) {
            $allowed_contact_methods = $this->get_booking_default_contact_methods();
        }

        $intent = sanitize_key((string) ($payload['intent'] ?? ''));
        if ($intent === 'booking_confirm') {
            $out['intent'] = $intent;
        }

        $subject = sanitize_text_field((string) ($payload['subject'] ?? ''));
        if ($subject !== '') {
            $out['subject'] = $this->truncate_chat_text($subject, 190);
        }

        $note = sanitize_textarea_field((string) ($payload['note'] ?? ''));
        if ($note !== '') {
            $out['note'] = $this->truncate_chat_text($note, 1000);
        }

        $name = sanitize_text_field((string) ($payload['name'] ?? ''));
        if ($name !== '') {
            $out['name'] = $this->truncate_chat_text($name, 190);
        }

        $email = sanitize_email((string) ($payload['email'] ?? ''));
        if ($email !== '') {
            $out['email'] = $email;
        }

        $contact_method = sanitize_key((string) ($payload['contact_method'] ?? ''));
        if (in_array($contact_method, $allowed_contact_methods, true)) {
            $out['contact_method'] = $contact_method;
        }

        $contact_value = sanitize_text_field((string) ($payload['contact_value'] ?? ''));
        if ($contact_value !== '') {
            $out['contact_value'] = $this->truncate_chat_text($contact_value, 190);
        }

        $date = sanitize_text_field((string) ($payload['date'] ?? ''));
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $out['date'] = $date;
        }

        $time = sanitize_text_field((string) ($payload['time'] ?? ''));
        if ($time !== '' && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1) {
            $out['time'] = $time;
        }

        return $out;
    }

    protected function get_booking_contact_methods(): array {
        if (!class_exists('Restatify_Booking_Assistant_Constants')) {
            return [];
        }

        $option_key = defined('Restatify_Booking_Assistant_Constants::OPTION_KEY')
            ? (string) constant('Restatify_Booking_Assistant_Constants::OPTION_KEY')
            : 'restatify_booking_options';

        $options = get_option($option_key, []);
        if (!is_array($options)) {
            $options = [];
        }

        if (class_exists('\\Restatify\\Shared\\Util\\BookingContactMethodsResolver', false)) {
            return \Restatify\Shared\Util\BookingContactMethodsResolver::methodsFromOptions($options);
        }

        $methods = [];
        $channels = is_array($options['contact_channels'] ?? null) ? $options['contact_channels'] : [];

        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $key = sanitize_key((string) ($channel['key'] ?? ''));
            if ($key !== '') {
                $methods[] = $key;
            }
        }

        if (count($methods) === 0) {
            $raw = (string) ($options['contact_channels_raw'] ?? '');
            foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
                $parts = array_map('trim', explode('|', (string) $line));
                $key = sanitize_key((string) ($parts[0] ?? ''));
                if ($key !== '') {
                    $methods[] = $key;
                }
            }
        }

        $methods = array_values(array_unique(array_filter($methods, static function ($value) {
            return $value !== '';
        })));

        return $methods;
    }

    protected function enrich_booking_prefill_payload(array $payload, array $conversation, string $latest_message): array {
        if (empty($payload['subject'])) {
            $payload['subject'] = $this->build_booking_subject_from_context($conversation, $latest_message);
        }

        if (empty($payload['note'])) {
            $payload['note'] = $this->build_booking_note_from_context($conversation, $latest_message);
        }

        return $payload;
    }

    protected function build_booking_subject_from_context(array $conversation, string $latest_message): string {
        $candidates = [];

        $latest = sanitize_text_field($latest_message);
        if ($latest !== '') {
            $candidates[] = $latest;
        }

        $history = array_reverse((array) ($conversation['messages'] ?? []));
        foreach ($history as $item) {
            if (!is_array($item) || (string) ($item['sender'] ?? '') !== 'visitor') {
                continue;
            }

            $text = sanitize_text_field((string) ($item['message'] ?? ''));
            if ($text !== '') {
                $candidates[] = $text;
            }

            if (count($candidates) >= 3) {
                break;
            }
        }

        foreach ($candidates as $candidate) {
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate));
            if ($candidate !== '') {
                return $this->truncate_chat_text($candidate, 90);
            }
        }

        return 'Termin-Anfrage aus Chat';
    }

    protected function build_booking_note_from_context(array $conversation, string $latest_message): string {
        $lines = [];
        $history = array_slice((array) ($conversation['messages'] ?? []), -6);

        foreach ($history as $item) {
            if (!is_array($item) || empty($item['message'])) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            $role = $sender === 'visitor' ? 'Kunde' : 'Assistent';
            $text = sanitize_textarea_field((string) $item['message']);
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                continue;
            }

            $lines[] = $role . ': ' . $this->truncate_chat_text($text, 220);
        }

        $latest = sanitize_textarea_field($latest_message);
        $latest = trim(preg_replace('/\s+/u', ' ', $latest));
        if ($latest !== '') {
            $latest_line = 'Kunde: ' . $this->truncate_chat_text($latest, 220);
            if (empty($lines) || end($lines) !== $latest_line) {
                $lines[] = $latest_line;
            }
        }

        if (empty($lines)) {
            return 'Termin-Anfrage aus dem Chat. Details werden im Popup ergaenzt.';
        }

        $note = "Zusammenfassung aus dem Chat:\n" . implode("\n", $lines);
        return $this->truncate_chat_text($note, 1000);
    }

    protected function attach_booking_prefill_trigger(string $content, array $payload): string {
        $booking_open_token = defined('RESTATIFY_BOOKING_OPEN_TOKEN') ? (string) constant('RESTATIFY_BOOKING_OPEN_TOKEN') : '[[RESTATIFY_BOOKING_OPEN]]';
        $prefill_token = $this->get_booking_prefill_token();
        $payload_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload_json) || $payload_json === '') {
            return $content;
        }

        $trigger = $booking_open_token . ' ' . $prefill_token . $payload_json;
        return trim($content) === '' ? $trigger : trim($content) . "\n" . $trigger;
    }

    protected function get_booking_prefill_token(): string {
        if (class_exists('\\Restatify\\Shared\\Contracts\\BookingPrefillSchema', false)) {
            return (string) \Restatify\Shared\Contracts\BookingPrefillSchema::TOKEN;
        }

        return '[[RESTATIFY_BOOKING_PREFILL]]';
    }

    /**
     * @return array<int,string>
     */
    protected function get_booking_prefill_allowed_keys(): array {
        if (class_exists('\\Restatify\\Shared\\Contracts\\BookingPrefillSchema', false)) {
            return \Restatify\Shared\Contracts\BookingPrefillSchema::allowedKeys();
        }

        return ['intent', 'subject', 'note', 'name', 'email', 'contact_method', 'contact_value', 'date', 'time'];
    }

    /**
     * @return array<int,string>
     */
    protected function get_booking_default_contact_methods(): array {
        if (class_exists('\\Restatify\\Shared\\Contracts\\BookingPrefillSchema', false)) {
            return \Restatify\Shared\Contracts\BookingPrefillSchema::defaultContactMethods();
        }

        return ['phone', 'zoom', 'teams', 'whatsapp', 'email'];
    }

    protected function calculate_ai_retry_wait_ms(int $base_wait_ms, int $status_code, int $attempt, $response): int {
        $base = max(0, min(60000, $base_wait_ms));
        if ($base === 0) {
            return 0;
        }

        $wait = $base;
        if (in_array($status_code, [429, 503], true) || $status_code <= 0) {
            $exp_factor = max(0, $attempt - 1);
            $wait = (int) min(120000, $base * (2 ** $exp_factor));

            $retry_after = $this->extract_retry_after_ms($response);
            if ($retry_after > 0) {
                $wait = max($wait, $retry_after);
            }
        }

        return max(0, min(120000, $wait));
    }

    protected function is_ai_retryable_http_status(int $status_code): bool {
        if ($status_code <= 0) {
            return true;
        }

        return in_array($status_code, [408, 425, 429, 500, 502, 503, 504], true);
    }

    protected function extract_retry_after_ms($response): int {
        if (!is_array($response)) {
            return 0;
        }

        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        if (!is_string($retry_after) || trim($retry_after) === '') {
            return 0;
        }

        $raw = trim($retry_after);
        if (ctype_digit($raw)) {
            return max(0, min(120000, ((int) $raw) * 1000));
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return 0;
        }

        $seconds = max(0, $ts - time());
        return max(0, min(120000, $seconds * 1000));
    }

    protected function maybe_generate_booking_reply(string $latest_message, array $conversation = []): string {
        if (!$this->is_booking_plugin_available()) {
            return '';
        }

        // Route through Dual-Session Router
        $router = new Restatify_Ai_Dual_Session_Router($this->get_options());
        $session_id = (string) ($conversation['id'] ?? wp_hash(time() . wp_rand()));
        $ip_address = $this->get_client_ip();

        $routing_result = $router->route_message(
            $latest_message,
            $conversation,
            $session_id,
            $ip_address
        );

        // If error or no action, return empty
        if ($routing_result['action'] === 'error' || empty($routing_result['user_facing_text'])) {
            return '';
        }

        // Handle session-level routing
        $session = $routing_result['session'] ?? 'session2';

        // Session 2 (general chat): delegate to LLM
        if ($session === 'session2' && $routing_result['delegate_to_session2_ai'] === true) {
            return ''; // Let normal AI generation handle it
        }

        // Session 1 (booking) or clarification: return structured response
        $user_text = $routing_result['user_facing_text'] ?? '';

        // If force_overlay required, emit booking token with prefill
        if ($routing_result['force_overlay'] === true) {
            $prefill = $routing_result['partial_prefill'] ?? [];
            return trim($user_text . ' [[RESTATIFY_BOOKING_OPEN]] [[RESTATIFY_BOOKING_PREFILL]] ' . wp_json_encode($prefill));
        }

        if ($session === 'session1') {
            return $user_text;
        }

        return $user_text;
    }

    protected function is_booking_intent_message(string $message): bool {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        $reject_pattern = '/\b(kein|keinen|keine|nicht|no|dont|don\'t|keinesfalls|auf\s+keinen\s+fall)\b.{0,40}\b(termin|appointment|slot|buchen|book|meeting)\b/i';
        if (preg_match($reject_pattern, $text) === 1) {
            return false;
        }

        return preg_match('/\b(termin|appointment|slot|verfuegbar|verfugbarkeit|frei|buchen|book|erstgespraech|meeting|kalender|calendar)\b/i', $text) === 1;
    }

    protected function resolve_conversation_language_code(array $conversation, string $latest_message): string {
        $conversation_id = (string) ($conversation['id'] ?? '');
        $state_machine = null;
        $state = [];

        if ($conversation_id !== '' && class_exists('Restatify_Ai_Dual_Session_State_Machine', false)) {
            $state_machine = new Restatify_Ai_Dual_Session_State_Machine();
            $state = $state_machine->get_session_state($conversation_id);
            if (!is_array($state)) {
                $state = [];
            }
        }

        $current = $this->normalize_simple_language_code((string) ($state['language_code'] ?? ''));
        $sample = $this->build_language_detection_sample($conversation, $latest_message);
        if ($sample === '') {
            return $current !== '' ? $current : 'de';
        }

        $sample_hash = md5($sample);
        if ((string) ($state['language_last_sample_hash'] ?? '') === $sample_hash && $current !== '') {
            return $current;
        }

        $options = $this->get_options(false);
        $debug_enabled = !empty($options['ai_debug_enabled']);
        $detected_payload = $this->detect_language_code_via_llm($sample, $current, $options);
        $detected = $this->normalize_simple_language_code((string) ($detected_payload['language'] ?? ''));
        $confidence = (float) ($detected_payload['confidence'] ?? 0.0);

        if ($detected === '') {
            $detected = $this->detect_language_code_fallback($sample, $current, $options);
            $confidence = 0.51;
        }

        $switch_reason = 'stable';

        if ($current === '') {
            $current = $detected !== '' ? $detected : 'de';
            $state['language_code'] = $current;
            $state['language_candidate'] = $current;
            $state['language_switch_votes'] = 0;
            $state['language_lock_until'] = time() + 120;
            $switch_reason = 'initial';
        } elseif ($detected === $current) {
            $state['language_candidate'] = $current;
            $state['language_switch_votes'] = 0;
            $switch_reason = 'same_language';
        } else {
            $candidate = $this->normalize_simple_language_code((string) ($state['language_candidate'] ?? ''));
            $votes = (int) ($state['language_switch_votes'] ?? 0);

            if ($candidate !== $detected) {
                $candidate = $detected;
                $votes = 1;
            } else {
                $votes++;
            }

            $force_switch = $this->should_force_language_switch_from_latest_message($latest_message, $current, $detected, $confidence);
            $needed_votes = $confidence >= 0.80 ? 1 : 2;
            $lock_until = (int) ($state['language_lock_until'] ?? 0);
            if ($force_switch || ($votes >= $needed_votes && time() >= $lock_until)) {
                $current = $detected;
                $state['language_code'] = $current;
                $state['language_candidate'] = $current;
                $state['language_switch_votes'] = 0;
                $state['language_lock_until'] = time() + 120;
                $switch_reason = $force_switch ? 'forced_by_latest_message' : 'vote_switch';
            } else {
                $state['language_candidate'] = $candidate;
                $state['language_switch_votes'] = $votes;
                $switch_reason = 'pending_votes';
            }
        }

        $state['language_last_detected'] = $detected;
        $state['language_last_confidence'] = $confidence;
        $state['language_switch_reason'] = $switch_reason;
        $state['language_last_sample_hash'] = $sample_hash;
        $state['language_last_detected_at'] = time();

        $this->log_ai_debug($debug_enabled, 'Language detection decision', [
            'current_language' => $current,
            'detected_language' => $detected,
            'confidence' => $confidence,
            'switch_reason' => $switch_reason,
            'candidate' => (string) ($state['language_candidate'] ?? ''),
            'switch_votes' => (int) ($state['language_switch_votes'] ?? 0),
        ]);

        if ($state_machine !== null) {
            $state_machine->update_session_state($conversation_id, $state);
        }

        return $current !== '' ? $current : 'de';
    }

    protected function should_force_language_switch_from_latest_message(string $latest_message, string $current, string $detected, float $confidence): bool {
        if ($detected === '' || $current === '' || $detected === $current) {
            return false;
        }

        $latest = trim($latest_message);
        if ($latest === '') {
            return false;
        }

        if ($confidence < 0.55) {
            return false;
        }

        if (function_exists('mb_strlen')) {
            $char_count = mb_strlen($latest);
        } else {
            $char_count = strlen($latest);
        }

        $word_count = preg_match_all('/\p{L}+/u', $latest, $matches);
        if ($word_count === false) {
            $word_count = 0;
        }

        return $char_count >= 80 || $word_count >= 12;
    }

    protected function normalize_simple_language_code(string $value): string {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[a-z]{2,3}/', $value, $m) !== 1) {
            return '';
        }

        return substr((string) $m[0], 0, 2);
    }

    protected function build_language_detection_sample(array $conversation, string $latest_message): string {
        $segments = [];
        $latest = trim((string) $latest_message);
        if ($latest !== '') {
            $segments[] = $latest;
        }

        $messages = (array) ($conversation['messages'] ?? []);
        $messages = array_reverse($messages);
        foreach ($messages as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sender = strtolower((string) ($item['sender'] ?? ''));
            if (!in_array($sender, ['visitor', 'user', 'human'], true)) {
                continue;
            }

            $text = trim((string) ($item['message'] ?? ''));
            if ($text === '') {
                continue;
            }

            $segments[] = $text;
            if (count($segments) >= 4) {
                break;
            }
        }

        return trim(implode("\n", array_reverse($segments)));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function detect_language_code_via_llm(string $sample, string $current, array $options): array {
        $api_key = trim((string) ($options['ai_api_key'] ?? ''));
        $endpoint = trim((string) ($options['ai_api_endpoint'] ?? Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT));
        $model = trim((string) ($options['ai_model'] ?? 'gpt-4o-mini'));

        if ($api_key === '' || $endpoint === '') {
            return [];
        }

        $provider = $this->detect_ai_provider($endpoint);
        $prompt = 'Classify the dominant user language from this multi-turn chat snippet. '
            . 'Return a 2-letter ISO language code in lowercase (examples: de, en, fr, es, it, tr, ar). '
            . 'Handle mixed language text (including Denglish) and choose the dominant language by syntax and intent, not by isolated borrowed words like hello/thanks/please. '
            . 'Return strict JSON only: {"language":"<iso2>","confidence":0.0-1.0,"mixed":true|false}. '
            . 'Current language lock: ' . ($current !== '' ? $current : 'none') . '. '
            . "Snippet:\n" . $sample;

        if ($provider === 'gemini') {
            $url = $this->normalize_gemini_endpoint($endpoint, $model !== '' ? $model : 'gemini-1.5-flash', $api_key);
            $body = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.1,
                    'maxOutputTokens' => 120,
                ],
            ];

            $response = wp_remote_post($url, [
                'timeout' => 10,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($body),
            ]);

            if (is_wp_error($response)) {
                return [];
            }

            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
            $text = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');
            return $this->extract_language_payload_from_text($text);
        }

        $request = $this->build_ai_request(
            $provider,
            $endpoint,
            $model !== '' ? $model : 'gpt-4o-mini',
            $api_key,
            [
                ['role' => 'system', 'content' => 'Return strict JSON only.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            ''
        );

        $response = wp_remote_post((string) ($request['endpoint'] ?? ''), [
            'timeout' => 10,
            'headers' => (array) ($request['headers'] ?? []),
            'body' => wp_json_encode((array) ($request['payload'] ?? [])),
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $text = $this->extract_ai_response_content($provider, is_array($decoded) ? $decoded : []);
        return $this->extract_language_payload_from_text($text);
    }

    /**
     * @return array<string,mixed>
     */
    protected function extract_language_payload_from_text(string $text): array {
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

        $language = $this->normalize_simple_language_code((string) ($decoded['language'] ?? ''));
        if ($language === '') {
            return [];
        }

        $confidence = (float) ($decoded['confidence'] ?? 0.0);
        if ($confidence < 0.0) {
            $confidence = 0.0;
        } elseif ($confidence > 1.0) {
            $confidence = 1.0;
        }

        return [
            'language' => $language,
            'confidence' => $confidence,
            'mixed' => !empty($decoded['mixed']),
        ];
    }

    protected function detect_language_code_fallback(string $sample, string $current, array $options = []): string {
        $text = mb_strtolower($sample);
        if ($text === '') {
            return $current !== '' ? $current : 'de';
        }

        $candidates = ['de', 'en'];
        if (class_exists('Restatify_Ai_Language_Keyword_Store', false)) {
            $candidates = Restatify_Ai_Language_Keyword_Store::known_language_codes();
        }
        if ($current !== '') {
            $candidates[] = $current;
        }

        $scores = [];
        foreach (array_values(array_unique($candidates)) as $code) {
            $lang = $this->normalize_simple_language_code((string) $code);
            if ($lang === '') {
                continue;
            }

            $set = [];
            if (class_exists('Restatify_Ai_Language_Keyword_Store', false)) {
                $set = Restatify_Ai_Language_Keyword_Store::keyword_set_for_language($lang, $options);
            }

            $scores[$lang] = $this->score_language_from_keyword_set($text, $set);
        }

        if (count($scores) === 0) {
            return $current !== '' ? $current : 'de';
        }

        arsort($scores);
        $best = (string) array_key_first($scores);
        $best_score = (float) ($scores[$best] ?? 0.0);

        if ($best_score <= 0.0) {
            return $current !== '' ? $current : 'de';
        }

        return $best !== '' ? $best : ($current !== '' ? $current : 'de');
    }

    /**
     * @param array<string,array<int,string>> $set
     */
    protected function score_language_from_keyword_set(string $sample_lower, array $set): float {
        $weights = [
            'booking' => 1.7,
            'affirmation' => 0.8,
            'rejection' => 0.8,
            'scheduling_context' => 1.8,
            'date' => 1.4,
            'time_of_day' => 1.2,
            'timing_question' => 1.6,
        ];

        $score = 0.0;
        foreach ($weights as $group => $weight) {
            $keywords = $set[$group] ?? [];
            if (!is_array($keywords) || count($keywords) === 0) {
                continue;
            }

            foreach ($keywords as $keyword) {
                $kw = mb_strtolower(trim((string) $keyword));
                if ($kw === '') {
                    continue;
                }

                if (mb_strpos($sample_lower, $kw) !== false) {
                    $score += $weight;
                }
            }
        }

        return $score;
    }

    protected function append_response_language_instruction(string $system_prompt, string $language_code): string {
        $lang = strtolower(trim($language_code));
        if ($lang === 'de') {
            return trim($system_prompt) . "\n\nLanguage policy (strict): Reply only in German. Do not mix languages unless the user explicitly asks to switch language.";
        }

        if ($lang === 'en') {
            return trim($system_prompt) . "\n\nLanguage policy (strict): Reply only in English. Do not mix languages unless the user explicitly asks to switch language.";
        }

        return trim($system_prompt) . "\n\nLanguage policy (strict): Reply only in the user's dominant language (ISO code: " . $lang . "). Do not mix with other languages unless the user explicitly asks to switch language.";
    }

    protected function is_booking_confirmation_message(string $message): bool {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        $has_time = preg_match('/\b([01]?\d|2[0-3])[:\.]([0-5]\d)\s*(uhr)?\b/i', $text) === 1;
        $has_date_hint = preg_match('/\b(morgen|uebermorgen|heute|montag|dienstag|mittwoch|donnerstag|freitag|samstag|sonntag|\d{1,2}\.\d{1,2}(?:\.\d{2,4})?)\b/i', $text) === 1;
        $has_confirmation = preg_match('/\b(ja|passt|passt\s+gut|einverstanden|ok|okay|nehme\s+ich|klingt\s+gut|gern|gerne)\b/i', $text) === 1;

        return ($has_time && $has_confirmation) || ($has_date_hint && $has_confirmation);
    }

    protected function is_booking_context_active(array $conversation): bool {
        $messages = (array) ($conversation['messages'] ?? []);
        if (count($messages) === 0) {
            return false;
        }

        $messages = array_slice($messages, -8);
        foreach ($messages as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            if ($sender === 'visitor') {
                continue;
            }

            $message = (string) ($item['message'] ?? '');
            if ($message === '') {
                continue;
            }

            if (strpos($message, '[[RESTATIFY_BOOKING_OPEN]]') !== false || $this->is_booking_intent_message($message)) {
                return true;
            }

            if (preg_match('/\b([01]?\d|2[0-3])[:\.]([0-5]\d)\s*(uhr)?\b/i', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function detect_ai_provider(string $endpoint): string {
        $host = strtolower((string) wp_parse_url($endpoint, PHP_URL_HOST));
        $path = strtolower((string) wp_parse_url($endpoint, PHP_URL_PATH));

        if (strpos($host, 'generativelanguage.googleapis.com') !== false || strpos($path, ':generatecontent') !== false) {
            return 'gemini';
        }

        if (strpos($host, 'mistral.ai') !== false) {
            return 'mistral';
        }

        if (strpos($host, 'deepseek.com') !== false) {
            return 'deepseek';
        }

        if (strpos($host, 'ollama') !== false || strpos($path, '/api/chat') !== false || strpos($path, '/api/generate') !== false || strpos($host, 'llama') !== false) {
            return 'llama';
        }

        return 'openai';
    }

    protected function is_local_ai_endpoint(string $endpoint): bool {
        $host = strtolower((string) wp_parse_url($endpoint, PHP_URL_HOST));
        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    protected function build_ai_messages(array $conversation, string $latest_message, string $system_prompt): array {
        $messages = [];
        $max_per_message_chars = 360;
        $max_total_chars = 5200;
        $total_chars = 0;

        $limit_text = static function (string $text, int $limit): string {
            $text = trim($text);
            if ($text === '') {
                return '';
            }

            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($text) <= $limit) {
                    return $text;
                }

                return mb_substr($text, 0, $limit);
            }

            if (strlen($text) <= $limit) {
                return $text;
            }

            return substr($text, 0, $limit);
        };

        if ($system_prompt !== '') {
            $system_prompt = $limit_text($system_prompt, 1000);
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];

            $total_chars += strlen($system_prompt);
        }

        $history = (array) ($conversation['messages'] ?? []);
        $history = array_slice($history, -30);

        $assistant_total = 0;
        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            if ($sender !== 'visitor') {
                $assistant_total++;
            }
        }

        $assistant_keep = min(6, $assistant_total);
        $assistant_skip = max(0, $assistant_total - $assistant_keep);
        $assistant_seen = 0;

        foreach ($history as $item) {
            if (!is_array($item) || empty($item['message'])) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            $role = $sender === 'visitor' ? 'user' : 'assistant';

            if ($role === 'assistant' && $assistant_seen++ < $assistant_skip) {
                continue;
            }

            $content = $limit_text((string) $item['message'], $role === 'user' ? $max_per_message_chars : 220);
            if ($content === '') {
                continue;
            }
            if (($total_chars + strlen($content)) > $max_total_chars) {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];

            $total_chars += strlen($content);
        }

        $latest_message = $limit_text($latest_message, 800);

        $messages[] = [
            'role' => 'user',
            'content' => $latest_message,
        ];

        return $messages;
    }

    protected function build_ai_request(string $provider, string $endpoint, string $model, string $api_key, array $messages, string $system_prompt): array {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($provider === 'gemini') {
            if ($api_key !== '') {
                $headers['x-goog-api-key'] = $api_key;
            }

            $gemini_contents = [];
            $gemini_system_prompt = '';
            foreach ($messages as $message) {
                if (!is_array($message) || empty($message['content'])) {
                    continue;
                }

                $role = (string) ($message['role'] ?? 'user');
                if ($role === 'system') {
                    if ($gemini_system_prompt === '') {
                        $gemini_system_prompt = (string) $message['content'];
                    }
                    continue;
                }

                $gemini_contents[] = [
                    'role' => $role === 'assistant' ? 'model' : 'user',
                    'parts' => [
                        ['text' => (string) $message['content']],
                    ],
                ];
            }

            if ($gemini_system_prompt !== '') {
                array_unshift($gemini_contents, [
                    'role' => 'user',
                    'parts' => [
                        ['text' => "System prompt:\n" . $gemini_system_prompt],
                    ],
                ]);
            }

            $payload = [
                'contents' => $gemini_contents,
                'generationConfig' => [
                    'temperature' => 0.4,
                ],
            ];

            return [
                'endpoint' => $this->normalize_gemini_endpoint($endpoint, $model, $api_key),
                'headers' => $headers,
                'payload' => $payload,
            ];
        }

        if ($provider === 'llama') {
            if ($api_key !== '') {
                $headers['Authorization'] = 'Bearer ' . $api_key;
            }

            $payload = [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
                'options' => [
                    'temperature' => 0.4,
                ],
            ];

            return [
                'endpoint' => $endpoint,
                'headers' => $headers,
                'payload' => $payload,
            ];
        }

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        return [
            'endpoint' => $endpoint,
            'headers' => $headers,
            'payload' => [
                'model' => $model,
                'messages' => $messages,
                'temperature' => 0.4,
            ],
        ];
    }

    protected function normalize_gemini_endpoint(string $endpoint, string $model, string $api_key = ''): string {
        $clean = rtrim($endpoint, '/');
        $clean_lower = strtolower($clean);

        if (strpos($clean_lower, ':generatecontent') !== false) {
            return $this->append_gemini_key_if_missing($clean, $api_key);
        }

        if (preg_match('#/models/[^/]+$#i', $clean) === 1) {
            return $this->append_gemini_key_if_missing($clean . ':generateContent', $api_key);
        }

        // Allow base endpoints like /v1 or /v1beta and derive the model path automatically.
        if (preg_match('#/v1(beta)?$#i', $clean) === 1) {
            return $this->append_gemini_key_if_missing($clean . '/models/' . rawurlencode($model) . ':generateContent', $api_key);
        }

        if (strpos($clean_lower, '/models/') !== false) {
            return $this->append_gemini_key_if_missing($clean . ':generateContent', $api_key);
        }

        return $this->append_gemini_key_if_missing($clean . '/models/' . rawurlencode($model) . ':generateContent', $api_key);
    }

    protected function append_gemini_key_if_missing(string $endpoint, string $api_key): string {
        if ($api_key === '' || stripos($endpoint, 'key=') !== false) {
            return $endpoint;
        }

        return add_query_arg('key', $api_key, $endpoint);
    }

    protected function extract_ai_response_content(string $provider, array $body): string {
        if ($provider === 'gemini') {
            $parts = (array) ($body['candidates'][0]['content']['parts'] ?? []);
            $text = '';
            foreach ($parts as $part) {
                if (is_array($part) && !empty($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }

            return trim($text);
        }

        if ($provider === 'llama') {
            $message_content = trim((string) ($body['message']['content'] ?? ''));
            if ($message_content !== '') {
                return $message_content;
            }

            return trim((string) ($body['response'] ?? ''));
        }

        return trim((string) ($body['choices'][0]['message']['content'] ?? ''));
    }

    protected function log_ai_debug(bool $enabled, string $message, array $context = []): void {
        if (!$enabled) {
            return;
        }

        if (isset($context['endpoint'])) {
            $context['endpoint'] = preg_replace('/([?&]key=)[^&]+/i', '$1***', (string) $context['endpoint']);
        }

        $line = '[Restatify MCO AI] ' . $message;
        if (!empty($context)) {
            $line .= ' | ' . wp_json_encode($context);
        }

        $this->store_ai_debug_line($line);
        error_log($line);
    }

    protected function store_ai_debug_line(string $line): void {
        $entries = get_option(Restatify_Ai_Multichat_Plugin::AI_DEBUG_LOG_KEY, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'time_gmt' => gmdate('c'),
            'line' => $line,
        ];

        $entries = array_slice($entries, -Restatify_Ai_Multichat_Plugin::AI_DEBUG_MAX_ENTRIES);
        update_option(Restatify_Ai_Multichat_Plugin::AI_DEBUG_LOG_KEY, $entries, false);
    }

    protected function get_recent_ai_debug_lines(int $limit = 40): array {
        $entries = get_option(Restatify_Ai_Multichat_Plugin::AI_DEBUG_LOG_KEY, []);
        if (!is_array($entries) || count($entries) === 0) {
            return [];
        }

        $entries = array_slice($entries, -max(1, $limit));
        $lines = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['line'])) {
                continue;
            }

            $stamp = !empty($entry['time_gmt']) ? '[' . (string) $entry['time_gmt'] . '] ' : '';
            $lines[] = $stamp . (string) $entry['line'];
        }

        return $lines;
    }

    protected function shorten_for_log(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 1200) {
            return $value;
        }

        return substr($value, 0, 1200) . '...';
    }

}

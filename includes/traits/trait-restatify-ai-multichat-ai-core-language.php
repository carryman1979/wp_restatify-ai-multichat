<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Language detection and response-language enforcement helpers.
 */
trait Restatify_Ai_Multichat_Ai_Core_Language_Trait {

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

        if ($this->is_ambiguous_short_language_probe($latest_message)) {
            $fallback = $current !== '' ? $current : 'de';
            $state['language_code'] = $fallback;
            $state['language_candidate'] = $fallback;
            $state['language_switch_votes'] = 0;
            $state['language_last_detected'] = $fallback;
            $state['language_last_confidence'] = 0.5;
            $state['language_switch_reason'] = 'short_probe_hold';
            $state['language_last_sample_hash'] = $sample_hash;
            $state['language_last_detected_at'] = time();

            $this->log_ai_debug($debug_enabled, 'Language detection decision', [
                'current_language' => $fallback,
                'detected_language' => $fallback,
                'confidence' => 0.5,
                'switch_reason' => 'short_probe_hold',
                'candidate' => $fallback,
                'switch_votes' => 0,
            ]);

            if ($state_machine !== null) {
                $state_machine->update_session_state($conversation_id, $state);
            }

            return $fallback;
        }

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

    protected function is_ambiguous_short_language_probe(string $latest_message): bool {
        $latest = trim($latest_message);
        if ($latest === '') {
            return false;
        }

        $normalized = mb_strtolower($latest);
        $normalized = preg_replace('/[\s\.!?,;:\-]+/u', ' ', $normalized);
        $normalized = trim((string) $normalized);

        if ($normalized === '') {
            return false;
        }

        $generic = [
            'hi', 'hello', 'hey', 'hallo', 'servus', 'moin',
            'yes', 'yeah', 'ja', 'ok', 'okay',
            'thanks', 'thank you', 'danke', 'merci',
        ];

        if (!in_array($normalized, $generic, true)) {
            return false;
        }

        if (function_exists('mb_strlen')) {
            return mb_strlen($normalized) <= 14;
        }

        return strlen($normalized) <= 14;
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
}

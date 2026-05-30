<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/traits/trait-restatify-ai-multichat-ai-core-booking.php';
require_once __DIR__ . '/traits/trait-restatify-ai-multichat-ai-core-language.php';

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
    use Restatify_Ai_Multichat_Ai_Core_Booking_Trait;
    use Restatify_Ai_Multichat_Ai_Core_Language_Trait;

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
        $options = $this->get_options();
        $contact_form_id = sanitize_key((string) ($options['contact_form_id'] ?? ''));
        if (!$this->is_booking_plugin_available() && $contact_form_id === '') {
            return '';
        }

        // Route through Dual-Session Router
        $router = new Restatify_Ai_Dual_Session_Router($options);
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
        $session = $routing_result['session'] ?? Restatify_Ai_Dual_Session_Router::SESSION_GENERAL_CHAT;

        // General-Chat: delegate to LLM
        if (($session === Restatify_Ai_Dual_Session_Router::SESSION_GENERAL_CHAT || $session === 'session2') && $routing_result['delegate_to_session2_ai'] === true) {
            return ''; // Let normal AI generation handle it
        }

        // Booking-Collector / Contact-Collector / clarification: return structured response
        $user_text = $routing_result['user_facing_text'] ?? '';

        // If force_overlay required, emit booking token with prefill
        if (($routing_result['force_overlay'] ?? false) === true) {
            $prefill = $routing_result['partial_prefill'] ?? [];
            return trim($user_text . ' [[RESTATIFY_BOOKING_OPEN]] [[RESTATIFY_BOOKING_PREFILL]] ' . wp_json_encode($prefill));
        }

        if (($routing_result['force_contact_form'] ?? false) === true) {
            $payload = $routing_result['contact_form_payload'] ?? [];
            if (!is_array($payload)) {
                $payload = [];
            }

            return trim($user_text . ' [[RESTATIFY_CONTACT_FORM_OPEN]] [[RESTATIFY_CONTACT_FORM_PAYLOAD]] ' . wp_json_encode($payload));
        }

        if ($session === Restatify_Ai_Dual_Session_Router::SESSION_BOOKING_COLLECTOR || $session === 'session1') {
            return $user_text;
        }

        return $user_text;
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

<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Restatify_MCO_AI_Trait {
    private function generate_ai_reply(array $options, array $conversation, string $latest_message): string {
        if (empty($options['ai_enabled'])) {
            return '';
        }

        $debug_enabled = !empty($options['ai_debug_enabled']);

        $endpoint = $this->sanitize_ai_endpoint((string) ($options['ai_api_endpoint'] ?? self::DEFAULT_AI_ENDPOINT));
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

        $system_prompt = trim((string) ($options['ai_system_prompt'] ?? ''));
        $messages = $this->build_ai_messages($conversation, $latest_message, $system_prompt);
        $request = $this->build_ai_request($provider, $endpoint, $model, $api_key, $messages, $system_prompt);

        $this->log_ai_debug($debug_enabled, 'AI request payload prepared', [
            'provider' => $provider,
            'endpoint' => (string) ($request['endpoint'] ?? ''),
            'message_count' => count($messages),
            'header_keys' => array_keys((array) ($request['headers'] ?? [])),
        ]);

        $response = wp_remote_post($request['endpoint'], [
            'timeout' => 18,
            'headers' => $request['headers'],
            'body' => wp_json_encode($request['payload']),
        ]);

        if (is_wp_error($response)) {
            $this->log_ai_debug($debug_enabled, 'AI HTTP error', [
                'provider' => $provider,
                'error' => $response->get_error_message(),
            ]);
            return '';
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $this->log_ai_debug($debug_enabled, 'AI non-2xx response', [
                'provider' => $provider,
                'status' => $status,
                'body' => $this->shorten_for_log((string) wp_remote_retrieve_body($response)),
            ]);
            return '';
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
            return '';
        }

        $this->log_ai_debug($debug_enabled, 'AI response parsed successfully', [
            'provider' => $provider,
            'content_length' => strlen($content),
        ]);

        if (function_exists('mb_substr')) {
            return mb_substr($content, 0, 1000);
        }

        return substr($content, 0, 1000);
    }

    private function detect_ai_provider(string $endpoint): string {
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

    private function is_local_ai_endpoint(string $endpoint): bool {
        $host = strtolower((string) wp_parse_url($endpoint, PHP_URL_HOST));
        return in_array($host, ['localhost', '127.0.0.1'], true);
    }

    private function build_ai_messages(array $conversation, string $latest_message, string $system_prompt): array {
        $messages = [];
        if ($system_prompt !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $system_prompt,
            ];
        }

        $history = (array) ($conversation['messages'] ?? []);
        $history = array_slice($history, -8);
        foreach ($history as $item) {
            if (!is_array($item) || empty($item['message'])) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            $role = $sender === 'visitor' ? 'user' : 'assistant';
            $messages[] = [
                'role' => $role,
                'content' => (string) $item['message'],
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $latest_message,
        ];

        return $messages;
    }

    private function build_ai_request(string $provider, string $endpoint, string $model, string $api_key, array $messages, string $system_prompt): array {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ($provider === 'gemini') {
            if ($api_key !== '') {
                $headers['x-goog-api-key'] = $api_key;
            }

            $gemini_contents = [];
            foreach ($messages as $message) {
                if (!is_array($message) || empty($message['content'])) {
                    continue;
                }

                $role = (string) ($message['role'] ?? 'user');
                if ($role === 'system') {
                    continue;
                }

                $gemini_contents[] = [
                    'role' => $role === 'assistant' ? 'model' : 'user',
                    'parts' => [
                        ['text' => (string) $message['content']],
                    ],
                ];
            }

            $payload = [
                'contents' => $gemini_contents,
                'generationConfig' => [
                    'temperature' => 0.4,
                ],
            ];

            if ($system_prompt !== '') {
                $payload['system_instruction'] = [
                    'parts' => [
                        ['text' => $system_prompt],
                    ],
                ];
            }

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

    private function normalize_gemini_endpoint(string $endpoint, string $model, string $api_key = ''): string {
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

    private function append_gemini_key_if_missing(string $endpoint, string $api_key): string {
        if ($api_key === '' || stripos($endpoint, 'key=') !== false) {
            return $endpoint;
        }

        return add_query_arg('key', $api_key, $endpoint);
    }

    private function extract_ai_response_content(string $provider, array $body): string {
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

    private function log_ai_debug(bool $enabled, string $message, array $context = []): void {
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

    private function store_ai_debug_line(string $line): void {
        $entries = get_option(self::AI_DEBUG_LOG_KEY, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'time_gmt' => gmdate('c'),
            'line' => $line,
        ];

        $entries = array_slice($entries, -self::AI_DEBUG_MAX_ENTRIES);
        update_option(self::AI_DEBUG_LOG_KEY, $entries, false);
    }

    private function get_recent_ai_debug_lines(int $limit = 40): array {
        $entries = get_option(self::AI_DEBUG_LOG_KEY, []);
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

    private function shorten_for_log(string $value): string {
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

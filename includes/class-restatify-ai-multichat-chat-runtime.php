<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles chat transport, AI replies and related runtime helpers.
 */
class Restatify_Ai_Multichat_Chat_Runtime extends Restatify_Ai_Multichat_Options_Runtime {
public function ajax_send_message(): void {
        $this->enforce_public_rate_limit('send');
        $this->verify_chat_nonce();

        $options = $this->get_options();
        if (empty($options['own_chat_enabled'])) {
            wp_send_json_error(['message' => __('Der Chat ist derzeit deaktiviert.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 403);
        }

        $honeypot = sanitize_text_field(wp_unslash($_POST['website'] ?? ''));
        if ($honeypot !== '') {
            wp_send_json_error(['message' => __('Nachricht konnte nicht gesendet werden. Bitte erneut versuchen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 400);
        }

        $message = $this->sanitize_chat_message_content((string) wp_unslash($_POST['message'] ?? ''));
        if ($message === '') {
            wp_send_json_error(['message' => __('Nachricht darf nicht leer sein.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 400);
        }

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $conversation_token = sanitize_text_field(wp_unslash($_POST['conversation_token'] ?? ''));
        $source_url = esc_url_raw(wp_unslash($_POST['source_url'] ?? home_url('/')));

        $store = $this->get_chat_store();
        $conversation = $this->resolve_or_create_conversation($store, $conversation_id, $conversation_token, $source_url);

        $conversation['messages'][] = $this->format_chat_message('visitor', $message);
        $conversation['updated_at_gmt'] = gmdate('c');

        $this->maybe_send_support_email($options, $conversation, $message);

        if (!empty($options['ai_enabled']) && $this->should_ai_reply_for_sender($conversation, 'visitor')) {
            $ai_reply = $this->generate_ai_reply($options, $conversation, $message);
            $ai_reply = $this->sanitize_chat_message_content($ai_reply);
            if ($ai_reply !== '') {
                $conversation['messages'][] = $this->format_chat_message('ai', $ai_reply);
                $conversation['updated_at_gmt'] = gmdate('c');
            }
        }

        $conversation['messages'] = array_slice($conversation['messages'], -Restatify_Ai_Multichat_Plugin::CHAT_MAX_MESSAGES);
        $store[$conversation['id']] = $conversation;
        $this->save_chat_store($store);

        wp_send_json_success([
            'conversation' => [
                'id' => $conversation['id'],
                'token' => $conversation['token'],
                'updated_at_gmt' => $conversation['updated_at_gmt'],
                'messages' => $conversation['messages'],
            ],
        ]);
    }

    public function ajax_fetch_chat(): void {
        $this->enforce_public_rate_limit('fetch');
        $this->verify_chat_nonce();

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $conversation_token = sanitize_text_field(wp_unslash($_POST['conversation_token'] ?? ''));
        if ($conversation_id === '' || $conversation_token === '') {
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 404);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id]) || !hash_equals((string) $store[$conversation_id]['token'], $conversation_token)) {
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 404);
        }

        wp_send_json_success([
            'conversation' => [
                'id' => $store[$conversation_id]['id'],
                'token' => $store[$conversation_id]['token'],
                'updated_at_gmt' => (string) ($store[$conversation_id]['updated_at_gmt'] ?? ''),
                'messages' => $store[$conversation_id]['messages'],
            ],
        ]);
    }

    public function ajax_booking_event(): void {
        $this->enforce_public_rate_limit('booking_event');
        $this->verify_chat_nonce();

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $conversation_token = sanitize_text_field(wp_unslash($_POST['conversation_token'] ?? ''));
        $event_type = sanitize_key(wp_unslash($_POST['event_type'] ?? ''));
        $start_iso = sanitize_text_field(wp_unslash($_POST['start_iso'] ?? ''));
        $end_iso = sanitize_text_field(wp_unslash($_POST['end_iso'] ?? ''));
        $reference = sanitize_text_field(wp_unslash($_POST['reference'] ?? ''));

        if ($conversation_id === '' || $conversation_token === '') {
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 404);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id]) || !hash_equals((string) $store[$conversation_id]['token'], $conversation_token)) {
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 404);
        }

        if (!in_array($event_type, ['confirmed', 'cancelled'], true)) {
            wp_send_json_error(['message' => __('Ungültiges Buchungsereignis.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 400);
        }

        if ($event_type === 'confirmed') {
            $message = RESTATIFY_BOOKING_CONFIRMED_TOKEN . ' ' . sprintf(
                __('Buchung vom Besucher bestätigt: %1$s bis %2$s (Referenz: %3$s).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                $start_iso !== '' ? $start_iso : '-',
                $end_iso !== '' ? $end_iso : '-',
                $reference !== '' ? $reference : '-'
            );
        } else {
            $message = RESTATIFY_BOOKING_CANCELLED_TOKEN . ' ' . (
                $start_iso !== ''
                    ? sprintf(__('Besucher hat den Buchungsablauf abgebrochen (ausgewählter Termin war %s).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN), $start_iso)
                    : __('Besucher hat den Buchungsablauf abgebrochen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)
            );
        }

        $store[$conversation_id]['messages'][] = $this->format_chat_message('system', $message);
        $store[$conversation_id]['updated_at_gmt'] = gmdate('c');
        $store[$conversation_id]['messages'] = array_slice($store[$conversation_id]['messages'], -Restatify_Ai_Multichat_Plugin::CHAT_MAX_MESSAGES);

        $this->save_chat_store($store);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'updated_at_gmt' => (string) ($store[$conversation_id]['updated_at_gmt'] ?? ''),
        ]);
    }

    public function ajax_support_reply(): void {
        $required_cap = apply_filters('restatify_mco_support_inbox_capability', Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY);
        if (!is_string($required_cap) || $required_cap === '') {
            $required_cap = Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY;
        }

        if (!current_user_can($required_cap)) {
            wp_send_json_error(['message' => __('Unzureichende Berechtigungen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 403);
        }

        check_ajax_referer('restatify_mco_chat_nonce', 'nonce');

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $message = $this->sanitize_chat_message_content((string) wp_unslash($_POST['message'] ?? ''));

        if ($conversation_id === '' || $message === '') {
            wp_send_json_error(['message' => __('Unterhaltung und Nachricht sind erforderlich.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 400);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id])) {
            wp_send_json_error(['message' => __('Unterhaltung existiert nicht.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 404);
        }

        $store[$conversation_id]['messages'][] = $this->format_chat_message('support', $message);
        $store[$conversation_id]['updated_at_gmt'] = gmdate('c');

        $options = $this->get_options();
        if (!empty($options['ai_enabled']) && $this->should_ai_reply_for_sender($store[$conversation_id], 'support')) {
            $ai_reply = $this->generate_ai_reply($options, $store[$conversation_id], $message);
            $ai_reply = $this->sanitize_chat_message_content($ai_reply);
            if ($ai_reply !== '') {
                $store[$conversation_id]['messages'][] = $this->format_chat_message('ai', $ai_reply);
                $store[$conversation_id]['updated_at_gmt'] = gmdate('c');
            }
        }

        $store[$conversation_id]['messages'] = array_slice($store[$conversation_id]['messages'], -Restatify_Ai_Multichat_Plugin::CHAT_MAX_MESSAGES);

        $this->save_chat_store($store);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'updated_at_gmt' => (string) ($store[$conversation_id]['updated_at_gmt'] ?? ''),
            'messages' => $store[$conversation_id]['messages'],
        ]);
    }

    public function ajax_delete_conversation(): void {
        if (!$this->can_manage_support_inbox()) {
            wp_send_json_error(['message' => __('Unzureichende Berechtigungen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 403);
        }

        check_ajax_referer('restatify_mco_chat_nonce', 'nonce');

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        if ($conversation_id === '') {
            wp_send_json_error(['message' => __('Unterhaltung ist erforderlich.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 400);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id])) {
            wp_send_json_success([
                'deleted' => true,
                'already_gone' => true,
            ]);
        }

        unset($store[$conversation_id]);
        $this->save_chat_store($store);

        wp_send_json_success([
            'deleted' => true,
            'already_gone' => false,
        ]);
    }

    public function ajax_set_ai_mode(): void {
        if (!$this->can_manage_support_inbox()) {
            wp_send_json_error(['message' => __('Insufficient permissions.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 403);
        }

        check_ajax_referer('restatify_mco_chat_nonce', 'nonce');

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $ai_mode = sanitize_key(wp_unslash($_POST['ai_mode'] ?? 'visitor'));
        if ($conversation_id === '') {
            wp_send_json_error(['message' => __('Conversation is required.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 400);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id])) {
            wp_send_json_error(['message' => __('Conversation does not exist.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 404);
        }

        $store[$conversation_id]['ai_mode'] = $this->normalize_ai_mode($ai_mode);
        $this->save_chat_store($store);

        wp_send_json_success(['ai_mode' => $store[$conversation_id]['ai_mode']]);
    }

    protected function verify_chat_nonce(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'restatify_mco_chat_nonce')) {
            wp_send_json_error(['message' => __('Invalid request token.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 403);
        }
    }

    protected function enforce_public_rate_limit(string $action): void {
        $options = $this->get_options(false);
        if (empty($options['chat_rate_limit_enabled'])) {
            return;
        }

        $window = max(10, min(3600, absint($options['chat_rate_limit_window_seconds'] ?? 60)));
        $max_send = max(1, min(120, absint($options['chat_rate_limit_max_send'] ?? 25)));
        $max_fetch = max(1, min(360, absint($options['chat_rate_limit_max_fetch'] ?? 120)));
        $max_booking_event = max(1, min(120, absint($options['chat_rate_limit_max_booking_event'] ?? 30)));

        $max_requests = $max_fetch;
        if ($action === 'send') {
            $max_requests = $max_send;
        } elseif ($action === 'booking_event') {
            $max_requests = $max_booking_event;
        }

        $ip = $this->get_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $fingerprint = md5($ip . '|' . $ua . '|' . $action);
        $key = 'restatify_mco_rl_' . $fingerprint;

        $bucket = get_transient($key);
        if (!is_array($bucket)) {
            $bucket = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $now = time();
        $start = (int) ($bucket['start'] ?? $now);
        if (($now - $start) >= $window) {
            $bucket = [
                'count' => 0,
                'start' => $now,
            ];
        }

        $bucket['count'] = (int) ($bucket['count'] ?? 0) + 1;

        if ($bucket['count'] > $max_requests) {
            set_transient($key, $bucket, $window);
            wp_send_json_error(['message' => __('Too many requests. Please wait a moment and try again.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 429);
        }

        set_transient($key, $bucket, $window);
    }

    protected function get_client_ip(): string {
        $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : '';
        if ($forwarded !== '') {
            $parts = array_map('trim', explode(',', $forwarded));
            foreach ($parts as $part) {
                if (filter_var($part, FILTER_VALIDATE_IP)) {
                    return $part;
                }
            }
        }

        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return 'unknown';
    }

    protected function get_chat_store(): array {
        $store = get_option(Restatify_Ai_Multichat_Plugin::CHAT_STORE_KEY, []);
        $store = is_array($store) ? $store : [];

        $options = $this->get_options(false);
        $max_age_minutes = max(0, (int) ($options['chat_reset_minutes'] ?? 0));
        $pruned = $this->prune_expired_conversations($store, $max_age_minutes);

        $normalized = [];
        foreach ($pruned as $id => $conversation) {
            if (!is_array($conversation)) {
                continue;
            }

            $conversation['ai_mode'] = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'visitor'));
            $normalized[$id] = $conversation;
        }

        if (count($normalized) !== count($store)) {
            update_option(Restatify_Ai_Multichat_Plugin::CHAT_STORE_KEY, $normalized, false);
        }

        return $normalized;
    }

    protected function save_chat_store(array $store): void {
        uasort($store, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at_gmt'] ?? ''), (string) ($a['updated_at_gmt'] ?? ''));
        });

        $store = array_slice($store, 0, Restatify_Ai_Multichat_Plugin::CHAT_MAX_CONVERSATIONS, true);
        update_option(Restatify_Ai_Multichat_Plugin::CHAT_STORE_KEY, $store, false);
    }

    protected function prune_expired_conversations(array $store, int $max_age_minutes): array {
        if ($max_age_minutes <= 0 || count($store) === 0) {
            return $store;
        }

        $now = time();
        $max_age_seconds = $max_age_minutes * MINUTE_IN_SECONDS;
        $filtered = [];

        foreach ($store as $id => $conversation) {
            if (!is_array($conversation)) {
                continue;
            }

            $updated_raw = (string) ($conversation['updated_at_gmt'] ?? '');
            $created_raw = (string) ($conversation['created_at_gmt'] ?? '');
            $updated_ts = $updated_raw !== '' ? strtotime($updated_raw) : false;
            $created_ts = $created_raw !== '' ? strtotime($created_raw) : false;
            $reference_ts = $updated_ts !== false ? (int) $updated_ts : ($created_ts !== false ? (int) $created_ts : 0);

            if ($reference_ts <= 0) {
                $filtered[$id] = $conversation;
                continue;
            }

            if (($now - $reference_ts) < $max_age_seconds) {
                $filtered[$id] = $conversation;
            }
        }

        return $filtered;
    }

    protected function resolve_or_create_conversation(array $store, string $conversation_id, string $conversation_token, string $source_url): array {
        // Reuse conversation only when token matches to prevent random id guessing.
        if ($conversation_id !== '' && isset($store[$conversation_id])) {
            $existing = $store[$conversation_id];
            $stored_token = (string) ($existing['token'] ?? '');
            if ($stored_token !== '' && $conversation_token !== '' && hash_equals($stored_token, $conversation_token)) {
                $existing['ai_mode'] = $this->normalize_ai_mode((string) ($existing['ai_mode'] ?? 'visitor'));
                return $existing;
            }
        }

        $id = wp_generate_password(20, false, false);
        $token = wp_generate_password(32, false, false);
        $now = gmdate('c');

        return [
            'id' => $id,
            'token' => $token,
            'status' => 'open',
            'source_url' => $source_url !== '' ? $source_url : home_url('/'),
            'created_at_gmt' => $now,
            'updated_at_gmt' => $now,
            'ai_mode' => 'visitor',
            'messages' => [],
        ];
    }

    protected function can_manage_support_inbox(): bool {
        $required_cap = apply_filters('restatify_mco_support_inbox_capability', Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY);
        if (!is_string($required_cap) || $required_cap === '') {
            $required_cap = Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY;
        }

        return current_user_can($required_cap);
    }

    protected function normalize_ai_mode(string $mode): string {
        $allowed = ['off', 'visitor', 'support', 'both'];
        return in_array($mode, $allowed, true) ? $mode : 'visitor';
    }

    protected function should_ai_reply_for_sender(array $conversation, string $sender): bool {
        $mode = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'visitor'));
        if ($mode === 'off') {
            return false;
        }

        if ($mode === 'both') {
            return true;
        }

        return $mode === $sender;
    }

    protected function get_ai_mode_options(): array {
        return [
            'off' => __('AI off (temporary)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'visitor' => __('AI replies to visitor only', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'support' => __('AI replies to support only', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'both' => __('AI replies to both sides', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
        ];
    }

    protected function format_chat_message(string $sender, string $message): array {
        $allowed_senders = ['visitor', 'support', 'ai', 'system'];
        if (!in_array($sender, $allowed_senders, true)) {
            $sender = 'visitor';
        }

        return [
            'sender' => $sender,
            'message' => $message,
            'time_gmt' => gmdate('c'),
        ];
    }

    protected function sanitize_chat_message_content(string $message): string {
        $clean = sanitize_textarea_field($message);
        $clean = wp_check_invalid_utf8($clean, true);
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $clean);
        $clean = str_replace(["\r\n", "\r"], "\n", (string) $clean);
        $clean = trim((string) $clean);

        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, 1000);
        }

        return substr($clean, 0, 1000);
    }

    protected function maybe_send_support_email(array $options, array $conversation, string $latest_message): void {
        if (empty($options['support_notify_on_message']) || empty($options['support_email']) || !is_email($options['support_email'])) {
            return;
        }

        $inbox_link = add_query_arg(
            [
                'page' => 'restatify-mco-support-inbox',
                'conversation' => $conversation['id'],
            ],
            admin_url('admin.php')
        );

        $subject = sprintf(
            __('[%s] New website chat message', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $source_url = (string) ($conversation['source_url'] ?? home_url('/'));
        $body = [];
        $body[] = __('A new visitor message has been received.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN);
        $body[] = '';
        $body[] = sprintf(__('Conversation ID: %s', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN), (string) $conversation['id']);
        $body[] = sprintf(__('Source URL: %s', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN), $source_url);
        $body[] = '';
        $body[] = __('Latest message:', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN);
        $body[] = $latest_message;
        $body[] = '';
        $body[] = __('Open chat in admin:', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN);
        $body[] = $inbox_link;

        wp_mail(
            $options['support_email'],
            $subject,
            implode("\n", $body),
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

protected function generate_ai_reply(array $options, array $conversation, string $latest_message): string {
        if (empty($options['ai_enabled'])) {
            return '';
        }

        $booking_reply = $this->maybe_generate_booking_reply($latest_message);
        if ($booking_reply !== '') {
            return $booking_reply;
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

    protected function maybe_generate_booking_reply(string $latest_message): string {
        if (!function_exists('restatify_booking_ai_handle_message')) {
            return '';
        }

        $intent_pattern = '/termin|appointment|slot|verfuegbar|verfugbarkeit|frei|buchen|book/i';
        if (!preg_match($intent_pattern, $latest_message)) {
            return '';
        }

        $reply = restatify_booking_ai_handle_message($latest_message);
        return is_string($reply) ? trim($reply) : '';
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

    protected function build_ai_request(string $provider, string $endpoint, string $model, string $api_key, array $messages, string $system_prompt): array {
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

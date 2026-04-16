<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RESTATIFY_BOOKING_OPEN_TOKEN')) {
    define('RESTATIFY_BOOKING_OPEN_TOKEN', '[[RESTATIFY_BOOKING_OPEN]]');
}

if (!defined('RESTATIFY_BOOKING_CONFIRMED_TOKEN')) {
    define('RESTATIFY_BOOKING_CONFIRMED_TOKEN', '[[RESTATIFY_BOOKING_CONFIRMED]]');
}

if (!defined('RESTATIFY_BOOKING_CANCELLED_TOKEN')) {
    define('RESTATIFY_BOOKING_CANCELLED_TOKEN', '[[RESTATIFY_BOOKING_CANCELLED]]');
}

trait Restatify_MCO_Chat_Trait {
    public function ajax_send_message(): void {
        $this->enforce_public_rate_limit('send');
        $this->verify_chat_nonce();

        $options = $this->get_options();
        if (empty($options['own_chat_enabled'])) {
            wp_send_json_error(['message' => __('Der Chat ist derzeit deaktiviert.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 403);
        }

        $honeypot = sanitize_text_field(wp_unslash($_POST['website'] ?? ''));
        if ($honeypot !== '') {
            wp_send_json_error(['message' => __('Nachricht konnte nicht gesendet werden. Bitte erneut versuchen.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 400);
        }

        $message = $this->sanitize_chat_message_content((string) wp_unslash($_POST['message'] ?? ''));
        if ($message === '') {
            wp_send_json_error(['message' => __('Nachricht darf nicht leer sein.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 400);
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

        $conversation['messages'] = array_slice($conversation['messages'], -Restatify_Multi_Chat_Overlay::CHAT_MAX_MESSAGES);
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
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 404);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id]) || !hash_equals((string) $store[$conversation_id]['token'], $conversation_token)) {
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 404);
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
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 404);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id]) || !hash_equals((string) $store[$conversation_id]['token'], $conversation_token)) {
            wp_send_json_error(['message' => __('Unterhaltung nicht gefunden.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 404);
        }

        if (!in_array($event_type, ['confirmed', 'cancelled'], true)) {
            wp_send_json_error(['message' => __('Ungültiges Buchungsereignis.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 400);
        }

        if ($event_type === 'confirmed') {
            $message = RESTATIFY_BOOKING_CONFIRMED_TOKEN . ' ' . sprintf(
                __('Buchung vom Besucher bestätigt: %1$s bis %2$s (Referenz: %3$s).', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
                $start_iso !== '' ? $start_iso : '-',
                $end_iso !== '' ? $end_iso : '-',
                $reference !== '' ? $reference : '-'
            );
        } else {
            $message = RESTATIFY_BOOKING_CANCELLED_TOKEN . ' ' . (
                $start_iso !== ''
                    ? sprintf(__('Besucher hat den Buchungsablauf abgebrochen (ausgewählter Termin war %s).', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN), $start_iso)
                    : __('Besucher hat den Buchungsablauf abgebrochen.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)
            );
        }

        $store[$conversation_id]['messages'][] = $this->format_chat_message('system', $message);
        $store[$conversation_id]['updated_at_gmt'] = gmdate('c');
        $store[$conversation_id]['messages'] = array_slice($store[$conversation_id]['messages'], -Restatify_Multi_Chat_Overlay::CHAT_MAX_MESSAGES);

        $this->save_chat_store($store);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'updated_at_gmt' => (string) ($store[$conversation_id]['updated_at_gmt'] ?? ''),
        ]);
    }

    public function ajax_support_reply(): void {
        $required_cap = apply_filters('restatify_mco_support_inbox_capability', Restatify_Multi_Chat_Overlay::SUPPORT_CAPABILITY);
        if (!is_string($required_cap) || $required_cap === '') {
            $required_cap = Restatify_Multi_Chat_Overlay::SUPPORT_CAPABILITY;
        }

        if (!current_user_can($required_cap)) {
            wp_send_json_error(['message' => __('Unzureichende Berechtigungen.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 403);
        }

        check_ajax_referer('restatify_mco_chat_nonce', 'nonce');

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $message = $this->sanitize_chat_message_content((string) wp_unslash($_POST['message'] ?? ''));

        if ($conversation_id === '' || $message === '') {
            wp_send_json_error(['message' => __('Unterhaltung und Nachricht sind erforderlich.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 400);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id])) {
            wp_send_json_error(['message' => __('Unterhaltung existiert nicht.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 404);
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

        $store[$conversation_id]['messages'] = array_slice($store[$conversation_id]['messages'], -Restatify_Multi_Chat_Overlay::CHAT_MAX_MESSAGES);

        $this->save_chat_store($store);

        wp_send_json_success([
            'conversation_id' => $conversation_id,
            'updated_at_gmt' => (string) ($store[$conversation_id]['updated_at_gmt'] ?? ''),
            'messages' => $store[$conversation_id]['messages'],
        ]);
    }

    public function ajax_delete_conversation(): void {
        if (!$this->can_manage_support_inbox()) {
            wp_send_json_error(['message' => __('Unzureichende Berechtigungen.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 403);
        }

        check_ajax_referer('restatify_mco_chat_nonce', 'nonce');

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        if ($conversation_id === '') {
            wp_send_json_error(['message' => __('Unterhaltung ist erforderlich.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 400);
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
            wp_send_json_error(['message' => __('Insufficient permissions.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 403);
        }

        check_ajax_referer('restatify_mco_chat_nonce', 'nonce');

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $ai_mode = sanitize_key(wp_unslash($_POST['ai_mode'] ?? 'visitor'));
        if ($conversation_id === '') {
            wp_send_json_error(['message' => __('Conversation is required.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 400);
        }

        $store = $this->get_chat_store();
        if (empty($store[$conversation_id])) {
            wp_send_json_error(['message' => __('Conversation does not exist.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 404);
        }

        $store[$conversation_id]['ai_mode'] = $this->normalize_ai_mode($ai_mode);
        $this->save_chat_store($store);

        wp_send_json_success(['ai_mode' => $store[$conversation_id]['ai_mode']]);
    }

    private function verify_chat_nonce(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'restatify_mco_chat_nonce')) {
            wp_send_json_error(['message' => __('Invalid request token.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 403);
        }
    }

    private function enforce_public_rate_limit(string $action): void {
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
            wp_send_json_error(['message' => __('Too many requests. Please wait a moment and try again.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN)], 429);
        }

        set_transient($key, $bucket, $window);
    }

    private function get_client_ip(): string {
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

    private function get_chat_store(): array {
        $store = get_option(Restatify_Multi_Chat_Overlay::CHAT_STORE_KEY, []);
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
            update_option(Restatify_Multi_Chat_Overlay::CHAT_STORE_KEY, $normalized, false);
        }

        return $normalized;
    }

    private function save_chat_store(array $store): void {
        uasort($store, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at_gmt'] ?? ''), (string) ($a['updated_at_gmt'] ?? ''));
        });

        $store = array_slice($store, 0, Restatify_Multi_Chat_Overlay::CHAT_MAX_CONVERSATIONS, true);
        update_option(Restatify_Multi_Chat_Overlay::CHAT_STORE_KEY, $store, false);
    }

    private function prune_expired_conversations(array $store, int $max_age_minutes): array {
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

    private function resolve_or_create_conversation(array $store, string $conversation_id, string $conversation_token, string $source_url): array {
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

    private function can_manage_support_inbox(): bool {
        $required_cap = apply_filters('restatify_mco_support_inbox_capability', Restatify_Multi_Chat_Overlay::SUPPORT_CAPABILITY);
        if (!is_string($required_cap) || $required_cap === '') {
            $required_cap = Restatify_Multi_Chat_Overlay::SUPPORT_CAPABILITY;
        }

        return current_user_can($required_cap);
    }

    private function normalize_ai_mode(string $mode): string {
        $allowed = ['off', 'visitor', 'support', 'both'];
        return in_array($mode, $allowed, true) ? $mode : 'visitor';
    }

    private function should_ai_reply_for_sender(array $conversation, string $sender): bool {
        $mode = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'visitor'));
        if ($mode === 'off') {
            return false;
        }

        if ($mode === 'both') {
            return true;
        }

        return $mode === $sender;
    }

    private function get_ai_mode_options(): array {
        return [
            'off' => __('AI off (temporary)', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'visitor' => __('AI replies to visitor only', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'support' => __('AI replies to support only', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'both' => __('AI replies to both sides', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
        ];
    }

    private function format_chat_message(string $sender, string $message): array {
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

    private function sanitize_chat_message_content(string $message): string {
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

    private function maybe_send_support_email(array $options, array $conversation, string $latest_message): void {
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
            __('[%s] New website chat message', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );

        $source_url = (string) ($conversation['source_url'] ?? home_url('/'));
        $body = [];
        $body[] = __('A new visitor message has been received.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN);
        $body[] = '';
        $body[] = sprintf(__('Conversation ID: %s', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN), (string) $conversation['id']);
        $body[] = sprintf(__('Source URL: %s', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN), $source_url);
        $body[] = '';
        $body[] = __('Latest message:', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN);
        $body[] = $latest_message;
        $body[] = '';
        $body[] = __('Open chat in admin:', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN);
        $body[] = $inbox_link;

        wp_mail(
            $options['support_email'],
            $subject,
            implode("\n", $body),
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }
}




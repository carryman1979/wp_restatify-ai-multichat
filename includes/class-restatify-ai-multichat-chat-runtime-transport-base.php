<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-restatify-ai-multichat-chat-runtime-ai-base.php';

/**
 * Transport/store helper layer for chat runtime.
 *
 * Provides nonce verification, public rate-limiting (via shared RateLimiter or
 * own transient bucket fallback), conversation store R/W helpers and WP remote
 * HTTP wrappers used by the AJAX handlers in Chat_Runtime.
 *
 * Inheritance order:
 *   Options_Runtime → Ai_Core_Base → Ai_Base → Transport_Base → Chat_Runtime → Admin_Runtime → Plugin
 */
abstract class Restatify_Ai_Multichat_Chat_Runtime_Transport_Base extends Restatify_Ai_Multichat_Chat_Runtime_Ai_Base {
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

        if (class_exists('\\Restatify\\Shared\\Runtime\\RateLimiter', false)) {
            $allowed = \Restatify\Shared\Runtime\RateLimiter::hit(
                'restatify_mco_rl_',
                $action,
                $window,
                $max_requests
            );

            if (!$allowed) {
                wp_send_json_error(['message' => __('Too many requests. Please wait a moment and try again.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 429);
            }

            return;
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

            $conversation['ai_mode'] = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'both'));
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
                $existing['ai_mode'] = $this->normalize_ai_mode((string) ($existing['ai_mode'] ?? 'both'));
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
            'ai_mode' => 'both',
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
        return in_array($mode, $allowed, true) ? $mode : 'both';
    }

    protected function should_ai_reply_for_sender(array $conversation, string $sender): bool {
        $mode = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'both'));
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

        return $this->truncate_chat_text($clean, 3000);
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

}

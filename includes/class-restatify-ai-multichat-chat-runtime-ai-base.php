<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-restatify-ai-multichat-chat-runtime-ai-core-base.php';

/**
 * AI lock/queue helper layer for chat runtime.
 *
 * Manages per-conversation exclusive generation locks (stored as WP options)
 * and the pending-message queue so concurrent requests do not produce duplicate
 * AI replies.  Extends Ai_Core_Base which owns the actual HTTP request loop.
 *
 * Inheritance order:
 *   Options_Runtime → Ai_Core_Base → Ai_Base → Transport_Base → Chat_Runtime → Admin_Runtime → Plugin
 */
abstract class Restatify_Ai_Multichat_Chat_Runtime_Ai_Base extends Restatify_Ai_Multichat_Chat_Runtime_Ai_Core_Base {
    protected function get_ai_lock_ttl_seconds(array $options): int {
        $max_attempts = max(1, min(10, absint($options['chat_send_retry_max_attempts'] ?? 3)));
        $timeout_ms = max(1000, min(120000, absint($options['chat_send_timeout_ms'] ?? 20000)));
        $retry_wait_ms = max(0, min(60000, absint($options['chat_send_retry_wait_ms'] ?? 500)));

        $ttl = (int) ceil((($max_attempts * $timeout_ms) + (max(0, $max_attempts - 1) * $retry_wait_ms)) / 1000) + 20;
        return max(30, min(300, $ttl));
    }

    protected function get_ai_lock_option_key(string $conversation_id): string {
        return 'restatify_mco_ai_lock_' . md5($conversation_id);
    }

    protected function acquire_ai_generation_lock(string $conversation_id, int $ttl_seconds): string {
        if ($conversation_id === '') {
            return '';
        }

        $key = $this->get_ai_lock_option_key($conversation_id);
        $now = time();
        $ttl = max(30, min(300, $ttl_seconds));
        $token = wp_generate_password(24, false, false);
        $payload = [
            'token' => $token,
            'expires_at' => $now + $ttl,
        ];

        if (add_option($key, $payload, '', false)) {
            return $token;
        }

        $existing = get_option($key, []);
        $expires_at = is_array($existing) ? (int) ($existing['expires_at'] ?? 0) : 0;
        if ($expires_at > $now) {
            return '';
        }

        update_option($key, $payload, false);
        return $token;
    }

    protected function release_ai_generation_lock(string $conversation_id, string $token): void {
        if ($conversation_id === '' || $token === '') {
            return;
        }

        $key = $this->get_ai_lock_option_key($conversation_id);
        $existing = get_option($key, []);
        if (!is_array($existing)) {
            return;
        }

        if ((string) ($existing['token'] ?? '') !== $token) {
            return;
        }

        delete_option($key);
    }

    protected function get_ai_pending_option_key(string $conversation_id): string {
        return 'restatify_mco_ai_pending_' . md5($conversation_id);
    }

    protected function enqueue_pending_ai_message(string $conversation_id, string $sender, string $message): void {
        if ($conversation_id === '' || $message === '') {
            return;
        }

        $key = $this->get_ai_pending_option_key($conversation_id);
        $queue = get_option($key, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        $sender_value = in_array($sender, ['visitor', 'support'], true) ? $sender : 'visitor';
        $queue[] = [
            'sender' => $sender_value,
            'message' => $this->sanitize_chat_message_content($message),
            'time_gmt' => gmdate('c'),
        ];

        $queue = array_slice($queue, -40);
        update_option($key, $queue, false);
    }

    protected function consume_pending_ai_messages(string $conversation_id): array {
        if ($conversation_id === '') {
            return [];
        }

        $key = $this->get_ai_pending_option_key($conversation_id);
        $queue = get_option($key, []);
        delete_option($key);

        if (!is_array($queue) || count($queue) === 0) {
            return [];
        }

        $normalized = [];
        foreach ($queue as $item) {
            if (!is_array($item)) {
                continue;
            }

            $msg = $this->sanitize_chat_message_content((string) ($item['message'] ?? ''));
            if ($msg === '') {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            if (!in_array($sender, ['visitor', 'support'], true)) {
                $sender = 'visitor';
            }

            $time_gmt = sanitize_text_field((string) ($item['time_gmt'] ?? ''));
            $normalized[] = [
                'sender' => $sender,
                'message' => $msg,
                'time_gmt' => $time_gmt !== '' ? $time_gmt : gmdate('c'),
            ];
        }

        return $normalized;
    }

    protected function process_pending_ai_messages_after_generation(array $options, array $conversation): array {
        $conversation_id = (string) ($conversation['id'] ?? '');
        if ($conversation_id === '') {
            return $conversation;
        }

        // Process at most two batches to avoid very long response times.
        for ($batch = 1; $batch <= 2; $batch++) {
            $pending = $this->consume_pending_ai_messages($conversation_id);
            if (count($pending) === 0) {
                break;
            }

            usort($pending, static function (array $a, array $b): int {
                return strcmp((string) ($b['time_gmt'] ?? ''), (string) ($a['time_gmt'] ?? ''));
            });

            $booking_reply = empty($options['ai_enabled']) ? $this->build_booking_reply_from_pending($pending, $conversation) : '';
            if ($booking_reply !== '') {
                $this->log_ai_debug(!empty($options['ai_debug_enabled']), 'AI booking intent detected from pending queue', [
                    'conversation_id' => $conversation_id,
                    'pending_count' => count($pending),
                ]);
                $conversation['messages'][] = $this->format_chat_message('ai', $booking_reply);
                $conversation['updated_at_gmt'] = gmdate('c');
                $conversation['messages'] = array_slice($conversation['messages'], -Restatify_Ai_Multichat_Plugin::CHAT_MAX_MESSAGES);
                continue;
            }

            $context_lines = [];
            foreach ($pending as $item) {
                $sender = (string) ($item['sender'] ?? 'visitor');
                $sender_label = $sender === 'support' ? 'Support' : 'Besucher';
                $context_lines[] = '- [' . (string) ($item['time_gmt'] ?? '') . '] ' . $sender_label . ': ' . (string) ($item['message'] ?? '');
            }

            $context_prompt = "Folgende Nachrichten sind zwischenzeitlich waehrend der Bearbeitung aufgelaufen. "
                . "Sie sind nach Zeitstempel sortiert (letzte Nachricht zuerst) und dienen dem besseren Kontextverstaendnis. "
                . "Generiere Fragen, Antwort oder eine Anmerkung, wenn erforderlich.\n\n"
                . implode("\n", $context_lines);

            $ai_reply = $this->generate_ai_reply($options, $conversation, $context_prompt);
            $ai_reply = $this->sanitize_chat_message_content($ai_reply);
            if ($ai_reply !== '') {
                $conversation['messages'][] = $this->format_chat_message('ai', $ai_reply);
                $conversation['updated_at_gmt'] = gmdate('c');
                $conversation['messages'] = array_slice($conversation['messages'], -Restatify_Ai_Multichat_Plugin::CHAT_MAX_MESSAGES);
            }
        }

        return $conversation;
    }

    protected function build_booking_reply_from_pending(array $pending, array $conversation = []): string {
        if (count($pending) === 0) {
            return '';
        }

        foreach ($pending as $item) {
            if (!is_array($item)) {
                continue;
            }

            $sender = (string) ($item['sender'] ?? 'visitor');
            if ($sender !== 'visitor') {
                continue;
            }

            $message = $this->sanitize_chat_message_content((string) ($item['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $reply = $this->maybe_generate_booking_reply($message, $conversation);
            if ($reply !== '') {
                return $reply;
            }
        }

        return '';
    }
}

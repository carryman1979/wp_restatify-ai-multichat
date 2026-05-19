<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-restatify-ai-multichat-chat-runtime-transport-base.php';

/**
 * Handles chat AJAX endpoints.
 */
class Restatify_Ai_Multichat_Chat_Runtime extends Restatify_Ai_Multichat_Chat_Runtime_Transport_Base {
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
        $booking_reply = $this->maybe_generate_booking_reply($message, $conversation);

        if (!empty($options['ai_enabled']) && $this->should_ai_reply_for_sender($conversation, 'visitor')) {
            if ($booking_reply !== '') {
                $conversation['messages'][] = $this->format_chat_message('ai', $this->sanitize_chat_message_content($booking_reply));
                $conversation['updated_at_gmt'] = gmdate('c');
            } else {
            $lock_ttl = $this->get_ai_lock_ttl_seconds($options);
            $lock_token = $this->acquire_ai_generation_lock((string) $conversation['id'], $lock_ttl);
            if ($lock_token === '') {
                $this->enqueue_pending_ai_message((string) $conversation['id'], 'visitor', $message);
                $this->log_ai_debug(!empty($options['ai_debug_enabled']), 'AI generation skipped due to active lock', [
                    'conversation_id' => (string) $conversation['id'],
                    'sender' => 'visitor',
                ]);
            } else {
                try {
                    $ai_reply = $this->generate_ai_reply($options, $conversation, $message);
                    $ai_reply = $this->sanitize_chat_message_content($ai_reply);

                    if ($ai_reply === '' || $this->is_ai_failure_notice_response($ai_reply, $options)) {
                        if ($booking_reply !== '') {
                            $ai_reply = $this->sanitize_chat_message_content($booking_reply);
                        }
                    }

                    if ($ai_reply !== '') {
                        $conversation['messages'][] = $this->format_chat_message('ai', $ai_reply);
                        $conversation['updated_at_gmt'] = gmdate('c');
                    }

                    $conversation = $this->process_pending_ai_messages_after_generation($options, $conversation);
                } finally {
                    $this->release_ai_generation_lock((string) $conversation['id'], $lock_token);
                }
            }
            }
        } else {
            if ($booking_reply !== '') {
                $conversation['messages'][] = $this->format_chat_message('ai', $this->sanitize_chat_message_content($booking_reply));
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

        $booking_confirmed_token = defined('RESTATIFY_BOOKING_CONFIRMED_TOKEN') ? (string) constant('RESTATIFY_BOOKING_CONFIRMED_TOKEN') : '[[RESTATIFY_BOOKING_CONFIRMED]]';
        $booking_cancelled_token = defined('RESTATIFY_BOOKING_CANCELLED_TOKEN') ? (string) constant('RESTATIFY_BOOKING_CANCELLED_TOKEN') : '[[RESTATIFY_BOOKING_CANCELLED]]';

        if ($event_type === 'confirmed') {
            $message = $booking_confirmed_token . ' ' . sprintf(
                __('Buchung vom Besucher bestätigt: %1$s bis %2$s (Referenz: %3$s).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                $start_iso !== '' ? $start_iso : '-',
                $end_iso !== '' ? $end_iso : '-',
                $reference !== '' ? $reference : '-'
            );
        } else {
            $message = $booking_cancelled_token . ' ' . (
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
            $lock_ttl = $this->get_ai_lock_ttl_seconds($options);
            $lock_token = $this->acquire_ai_generation_lock((string) $conversation_id, $lock_ttl);
            if ($lock_token === '') {
                $this->enqueue_pending_ai_message((string) $conversation_id, 'support', $message);
                $this->log_ai_debug(!empty($options['ai_debug_enabled']), 'AI generation skipped due to active lock', [
                    'conversation_id' => (string) $conversation_id,
                    'sender' => 'support',
                ]);
            } else {
                try {
                    $ai_reply = $this->generate_ai_reply($options, $store[$conversation_id], $message);
                    $ai_reply = $this->sanitize_chat_message_content($ai_reply);
                    if ($ai_reply !== '') {
                        $store[$conversation_id]['messages'][] = $this->format_chat_message('ai', $ai_reply);
                        $store[$conversation_id]['updated_at_gmt'] = gmdate('c');
                    }

                    $store[$conversation_id] = $this->process_pending_ai_messages_after_generation($options, $store[$conversation_id]);
                } finally {
                    $this->release_ai_generation_lock((string) $conversation_id, $lock_token);
                }
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
        delete_option($this->get_ai_pending_option_key($conversation_id));
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
        $ai_mode = sanitize_key(wp_unslash($_POST['ai_mode'] ?? 'both'));
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

}

<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-restatify-ai-multichat-chat-runtime-transport-base.php';

/**
 * Handles chat AJAX endpoints.
 */
class Restatify_Ai_Multichat_Chat_Runtime extends Restatify_Ai_Multichat_Chat_Runtime_Transport_Base {
public function register_support_bridge_rest_routes(): void {
        register_rest_route('restatify-support/v1', '/bridge', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_support_bridge_rest_request'],
            'permission_callback' => [$this, 'authorize_support_bridge_rest_request'],
        ]);
    }

    public function authorize_support_bridge_rest_request(WP_REST_Request $request) {
        if ($this->is_support_bridge_request_authorized($request)) {
            return true;
        }

        return new WP_Error(
            'restatify_support_bridge_forbidden',
            __('Unauthorized bridge request.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            ['status' => 403]
        );
    }

    public function handle_support_bridge_rest_request(WP_REST_Request $request): WP_REST_Response {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = $request->get_body_params();
        }

        if (!is_array($payload)) {
            $payload = [];
        }

        return rest_ensure_response($this->process_support_bridge_payload($payload));
    }

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

    protected function get_support_bridge_api_key(): string {
        if (defined('RESTATIFY_SUPPORT_BRIDGE_API_KEY')) {
            return trim((string) constant('RESTATIFY_SUPPORT_BRIDGE_API_KEY'));
        }

        return trim((string) get_option('restatify_support_bridge_api_key', ''));
    }

    protected function is_support_bridge_request_authorized(WP_REST_Request $request): bool {
        $configured_key = $this->get_support_bridge_api_key();
        if ($configured_key === '') {
            return false;
        }

        $provided_key = trim((string) $request->get_header('X-Restatify-Bridge-Key'));
        if ($provided_key === '') {
            return false;
        }

        return hash_equals($configured_key, $provided_key);
    }

    protected function process_support_bridge_payload(array $payload): array {
        $action = sanitize_key((string) ($payload['action'] ?? ''));
        if ($action === '') {
            return ['ok' => false, 'error' => 'action is required'];
        }

        $store = $this->get_chat_store();

        if ($action === 'load_store') {
            return ['ok' => true, 'store' => $store];
        }

        if ($action === 'append_support_message') {
            $conversation_id = sanitize_text_field((string) ($payload['conversation_id'] ?? ''));
            $message = $this->sanitize_chat_message_content((string) ($payload['message'] ?? ''));

            if ($conversation_id === '' || $message === '') {
                return ['ok' => false, 'error' => 'conversation_id and message are required'];
            }

            if (empty($store[$conversation_id]) || !is_array($store[$conversation_id])) {
                return ['ok' => false, 'error' => 'Conversation not found'];
            }

            $entry = $this->format_chat_message('support', $message);
            $store[$conversation_id]['messages'][] = $entry;
            $store[$conversation_id]['updated_at_gmt'] = gmdate('c');

            $options = $this->get_options();
            if (!empty($options['ai_enabled']) && $this->should_ai_reply_for_sender($store[$conversation_id], 'support')) {
                $lock_ttl = $this->get_ai_lock_ttl_seconds($options);
                $lock_token = $this->acquire_ai_generation_lock((string) $conversation_id, $lock_ttl);
                if ($lock_token === '') {
                    $this->enqueue_pending_ai_message((string) $conversation_id, 'support', $message);
                    $this->log_ai_debug(!empty($options['ai_debug_enabled']), 'AI generation skipped due to active lock (bridge rest)', [
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

            $ai_entry = null;
            $last_message = end($store[$conversation_id]['messages']);
            if (is_array($last_message) && (string) ($last_message['sender'] ?? '') === 'ai') {
                $ai_entry = [
                    'sender' => 'ai',
                    'message' => (string) ($last_message['message'] ?? ''),
                    'time_gmt' => (string) ($last_message['time_gmt'] ?? ''),
                ];
            }
            reset($store[$conversation_id]['messages']);

            return ['ok' => true, 'entry' => $entry, 'ai_entry' => $ai_entry];
        }

        if ($action === 'get_conversation_tools') {
            $conversation_id = sanitize_text_field((string) ($payload['conversation_id'] ?? ''));
            if ($conversation_id === '') {
                return ['ok' => false, 'error' => 'conversation_id is required'];
            }

            if (empty($store[$conversation_id]) || !is_array($store[$conversation_id])) {
                return ['ok' => false, 'error' => 'Conversation not found'];
            }

            return [
                'ok' => true,
                'ai_mode' => $this->normalize_ai_mode((string) ($store[$conversation_id]['ai_mode'] ?? 'both')),
                'booking_overlay_available' => function_exists('restatify_booking_ai_handle_message') || shortcode_exists('restatify_booking_popup'),
            ];
        }

        if ($action === 'set_conversation_ai_mode') {
            $conversation_id = sanitize_text_field((string) ($payload['conversation_id'] ?? ''));
            if ($conversation_id === '') {
                return ['ok' => false, 'error' => 'conversation_id is required'];
            }

            if (empty($store[$conversation_id]) || !is_array($store[$conversation_id])) {
                return ['ok' => false, 'error' => 'Conversation not found'];
            }

            $mode = $this->normalize_ai_mode(sanitize_key((string) ($payload['ai_mode'] ?? 'both')));
            $store[$conversation_id]['ai_mode'] = $mode;
            $store[$conversation_id]['updated_at_gmt'] = gmdate('c');
            $this->save_chat_store($store);

            return ['ok' => true, 'ai_mode' => $mode];
        }

        if ($action === 'delete_conversation') {
            $conversation_id = sanitize_text_field((string) ($payload['conversation_id'] ?? ''));
            if ($conversation_id === '') {
                return ['ok' => false, 'error' => 'conversation_id is required'];
            }

            if (empty($store[$conversation_id]) || !is_array($store[$conversation_id])) {
                return ['ok' => true, 'deleted' => true, 'already_gone' => true];
            }

            unset($store[$conversation_id]);
            delete_option($this->get_ai_pending_option_key($conversation_id));
            $this->save_chat_store($store);

            return ['ok' => true, 'deleted' => true, 'already_gone' => false];
        }

        if ($action === 'trigger_booking_overlay') {
            $conversation_id = sanitize_text_field((string) ($payload['conversation_id'] ?? ''));
            if ($conversation_id === '') {
                return ['ok' => false, 'error' => 'conversation_id is required'];
            }

            if (empty($store[$conversation_id]) || !is_array($store[$conversation_id])) {
                return ['ok' => false, 'error' => 'Conversation not found'];
            }

            if (!function_exists('restatify_booking_ai_handle_message') && !shortcode_exists('restatify_booking_popup')) {
                return ['ok' => false, 'error' => 'Booking overlay unavailable'];
            }

            $booking_open_token = defined('RESTATIFY_BOOKING_OPEN_TOKEN')
                ? (string) constant('RESTATIFY_BOOKING_OPEN_TOKEN')
                : '[[RESTATIFY_BOOKING_OPEN]]';
            $entry = $this->format_chat_message(
                'support',
                trim($booking_open_token . ' ' . 'Ich habe das Buchungstool fuer dich geoeffnet. Bitte waehle einen Termin und bestaetige deine Reservierung.')
            );

            $store[$conversation_id]['messages'][] = $entry;
            $store[$conversation_id]['updated_at_gmt'] = gmdate('c');
            $store[$conversation_id]['messages'] = array_slice($store[$conversation_id]['messages'], -Restatify_Ai_Multichat_Plugin::CHAT_MAX_MESSAGES);
            $this->save_chat_store($store);

            return ['ok' => true, 'entry' => $entry];
        }

        if ($action === 'validate_conversation_token') {
            $conversation_id = sanitize_text_field((string) ($payload['conversation_id'] ?? ''));
            $conversation_token = sanitize_text_field((string) ($payload['conversation_token'] ?? ''));
            if ($conversation_id === '' || $conversation_token === '') {
                return ['ok' => true, 'valid' => false];
            }

            if (empty($store[$conversation_id]) || !is_array($store[$conversation_id])) {
                return ['ok' => true, 'valid' => false];
            }

            $stored_token = (string) ($store[$conversation_id]['token'] ?? '');
            return ['ok' => true, 'valid' => $stored_token !== '' && hash_equals($stored_token, $conversation_token)];
        }

        if ($action === 'validate_credentials') {
            $username = sanitize_user((string) ($payload['username'] ?? ''));
            $password = (string) ($payload['password'] ?? '');
            if ($username === '' || $password === '') {
                return ['ok' => false, 'error' => 'username and password are required'];
            }

            $user = get_user_by('login', $username);
            if (!$user || !wp_check_password($password, $user->user_pass, $user->ID)) {
                return ['ok' => true, 'valid' => false];
            }

            return [
                'ok' => true,
                'valid' => true,
                'user_id' => $user->ID,
                'user_login' => $user->user_login,
                'has_capability' => user_can($user, Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY) || user_can($user, 'manage_options'),
            ];
        }

        if ($action === 'generate_api_key') {
            $user_id = (int) ($payload['user_id'] ?? 0);
            $user_login = sanitize_user((string) ($payload['user_login'] ?? ''));
            if ($user_id <= 0) {
                return ['ok' => false, 'error' => 'user_id is required'];
            }

            $keys_option = 'restatify_support_api_keys';
            $keys = get_option($keys_option, []);
            $keys = is_array($keys) ? $keys : [];
            $valid = array_values(array_filter($keys, static function ($entry) {
                return is_array($entry) && !empty($entry['key']);
            }));

            $same_user = static function (array $entry) use ($user_id, $user_login): bool {
                $entry_user_id = (int) ($entry['user_id'] ?? 0);
                $entry_user_login = sanitize_user((string) ($entry['user_login'] ?? ''));

                if ($entry_user_id > 0 && $entry_user_id === $user_id) {
                    return true;
                }

                return $user_login !== '' && $entry_user_login !== '' && hash_equals($entry_user_login, $user_login);
            };

            $existing_for_user = [];
            foreach ($valid as $entry) {
                if ($same_user($entry)) {
                    $existing_for_user[] = $entry;
                }
            }

            if (count($existing_for_user) > 0) {
                usort($existing_for_user, static function ($a, $b): int {
                    $a_created = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
                    $b_created = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
                    return $b_created <=> $a_created;
                });

                $selected = $existing_for_user[0];
                $selected_key = (string) ($selected['key'] ?? '');
                $deduped = [];
                $kept_for_user = false;
                foreach ($valid as $entry) {
                    if ($same_user($entry)) {
                        if (!$kept_for_user && hash_equals((string) ($entry['key'] ?? ''), $selected_key)) {
                            $deduped[] = $entry;
                            $kept_for_user = true;
                        }
                        continue;
                    }

                    $deduped[] = $entry;
                }

                if (count($deduped) !== count($keys)) {
                    update_option($keys_option, $deduped, false);
                }

                return ['ok' => true, 'api_key' => $selected_key];
            }

            $new_key = 'rsa-' . bin2hex(random_bytes(24));
            $valid[] = [
                'key' => $new_key,
                'user_id' => $user_id,
                'user_login' => $user_login,
                'created_at' => gmdate('c'),
            ];
            update_option($keys_option, $valid, false);

            return ['ok' => true, 'api_key' => $new_key];
        }

        if ($action === 'load_api_keys') {
            $keys = get_option('restatify_support_api_keys', []);
            $keys = is_array($keys) ? $keys : [];
            $valid = array_values(array_filter($keys, static function ($entry) {
                return is_array($entry) && !empty($entry['key']);
            }));

            usort($valid, static function ($a, $b): int {
                $a_created = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
                $b_created = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
                return $b_created <=> $a_created;
            });

            $deduped = [];
            $seen_users = [];
            foreach ($valid as $entry) {
                $entry_user_id = (int) ($entry['user_id'] ?? 0);
                $entry_user_login = sanitize_user((string) ($entry['user_login'] ?? ''));
                $user_key = $entry_user_id > 0 ? ('uid:' . $entry_user_id) : ('uln:' . $entry_user_login);

                if ($user_key === 'uln:') {
                    $deduped[] = $entry;
                    continue;
                }

                if (isset($seen_users[$user_key])) {
                    continue;
                }

                $seen_users[$user_key] = true;
                $deduped[] = $entry;
            }

            if (count($deduped) !== count($keys)) {
                update_option('restatify_support_api_keys', $deduped, false);
            }

            return ['ok' => true, 'keys' => $deduped];
        }

        return ['ok' => false, 'error' => 'Unsupported action'];
    }

}

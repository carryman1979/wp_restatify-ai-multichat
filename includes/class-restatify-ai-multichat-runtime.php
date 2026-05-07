<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runtime behavior container for Restatify AI Multichat.
 *
 * This class replaces the former trait-based composition and keeps
 * all runtime methods in one explicit class for clearer architecture.
 */
class Restatify_Ai_Multichat_Runtime {
    private bool $migration_checked = false;

    public function load_textdomain(): void {
        load_plugin_textdomain(
            Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN,
            false,
            dirname(plugin_basename(RESTATIFY_MCO_PLUGIN_FILE)) . '/languages'
        );
    }

    public function register_settings(): void {
        $this->ensure_legacy_options_migrated();

        register_setting(
            Restatify_Ai_Multichat_Plugin::SETTINGS_GROUP,
            Restatify_Ai_Multichat_Plugin::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => $this->get_default_options(),
            ]
        );
    }

    public function register_admin_page(): void {
        add_options_page(
            __('Multi Chat Overlay', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            __('Multi Chat Overlay', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'manage_options',
            Restatify_Ai_Multichat_Plugin::ADMIN_PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function register_polylang_strings(): void {
        if (!function_exists('pll_register_string')) {
            return;
        }

        $options = $this->get_raw_options();
        foreach (Restatify_Ai_Multichat_Plugin::TRANSLATABLE_OPTION_KEYS as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            pll_register_string(
                'restatify_mco_' . $key,
                $value,
                Restatify_Ai_Multichat_Plugin::POLYLANG_GROUP,
                true
            );
        }
    }

    public function sanitize_options($input): array {
        $input = is_array($input) ? $input : [];
        $defaults = $this->get_default_options();

        $output = [
            'enabled' => !empty($input['enabled']),
            'disable_during_maintenance' => !empty($input['disable_during_maintenance']),
            'require_cookie_consent' => !empty($input['require_cookie_consent']),
            'consent_cookie_names' => $this->sanitize_cookie_match_list((string) ($input['consent_cookie_names'] ?? $defaults['consent_cookie_names'])),
            'team_name' => sanitize_text_field($input['team_name'] ?? $defaults['team_name']),
            'message' => sanitize_text_field($input['message'] ?? $defaults['message']),
            'cta_label' => sanitize_text_field($input['cta_label'] ?? $defaults['cta_label']),
            'channels_more_label' => sanitize_text_field($input['channels_more_label'] ?? $defaults['channels_more_label']),
            'channels_less_label' => sanitize_text_field($input['channels_less_label'] ?? $defaults['channels_less_label']),
            'toggle_aria_label' => sanitize_text_field($input['toggle_aria_label'] ?? $defaults['toggle_aria_label']),
            'delay_seconds' => max(0, min(120, absint($input['delay_seconds'] ?? $defaults['delay_seconds']))),
            'own_chat_enabled' => !empty($input['own_chat_enabled']),
            'support_email' => sanitize_email($input['support_email'] ?? $defaults['support_email']),
            'support_notify_on_message' => !empty($input['support_notify_on_message']),
            'chat_title' => sanitize_text_field($input['chat_title'] ?? $defaults['chat_title']),
            'chat_placeholder' => sanitize_text_field($input['chat_placeholder'] ?? $defaults['chat_placeholder']),
            'chat_send_label' => sanitize_text_field($input['chat_send_label'] ?? $defaults['chat_send_label']),
            'chat_poll_seconds' => max(3, min(60, absint($input['chat_poll_seconds'] ?? $defaults['chat_poll_seconds']))),
            'chat_reset_minutes' => max(0, min(525600, absint($input['chat_reset_minutes'] ?? $defaults['chat_reset_minutes']))),
            'chat_rate_limit_enabled' => !empty($input['chat_rate_limit_enabled']),
            'chat_rate_limit_window_seconds' => max(10, min(3600, absint($input['chat_rate_limit_window_seconds'] ?? $defaults['chat_rate_limit_window_seconds']))),
            'chat_rate_limit_max_send' => max(1, min(120, absint($input['chat_rate_limit_max_send'] ?? $defaults['chat_rate_limit_max_send']))),
            'chat_rate_limit_max_fetch' => max(1, min(360, absint($input['chat_rate_limit_max_fetch'] ?? $defaults['chat_rate_limit_max_fetch']))),
            'chat_rate_limit_max_booking_event' => max(1, min(120, absint($input['chat_rate_limit_max_booking_event'] ?? $defaults['chat_rate_limit_max_booking_event']))),
            'ai_enabled' => !empty($input['ai_enabled']),
            'ai_debug_enabled' => !empty($input['ai_debug_enabled']),
            'ai_api_key' => sanitize_text_field($input['ai_api_key'] ?? $defaults['ai_api_key']),
            'ai_api_endpoint' => $this->sanitize_ai_endpoint((string) ($input['ai_api_endpoint'] ?? $defaults['ai_api_endpoint'])),
            'ai_model' => sanitize_text_field($input['ai_model'] ?? $defaults['ai_model']),
            'ai_system_prompt' => sanitize_textarea_field($input['ai_system_prompt'] ?? $defaults['ai_system_prompt']),
            'channels' => [],
        ];

        if (empty($output['support_email'])) {
            if (!empty($output['own_chat_enabled'])) {
                $output['support_email'] = sanitize_email((string) get_option('admin_email', ''));
                add_settings_error(
                    Restatify_Ai_Multichat_Plugin::OPTION_KEY,
                    'restatify_mco_support_email_required',
                    __('Die Support-E-Mail war leer und wurde auf die Admin-E-Mail der Website zurueckgesetzt.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                    'warning'
                );
            } else {
                $output['support_email'] = '';
            }
        }

        if (!empty($output['ai_enabled']) && trim((string) $output['ai_api_key']) === '') {
            $output['ai_enabled'] = false;
            add_settings_error(
                Restatify_Ai_Multichat_Plugin::OPTION_KEY,
                'restatify_mco_ai_key_required',
                __('Die KI-Autoantwort wurde deaktiviert, weil kein API-Schluessel hinterlegt ist.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'warning'
            );
        }

        $input_channels = isset($input['channels']) && is_array($input['channels']) ? $input['channels'] : [];

        foreach (Restatify_Ai_Multichat_Plugin::CHANNELS as $key => $meta) {
            $url = isset($input_channels[$key]) ? trim((string) $input_channels[$key]) : '';
            $output['channels'][$key] = $url !== '' ? $this->sanitize_channel_url($url) : '';
        }

        return $output;
    }

    private function get_active_channels(array $options): array {
        $active = [];
        foreach (Restatify_Ai_Multichat_Plugin::CHANNELS as $key => $meta) {
            $url = trim((string) ($options['channels'][$key] ?? ''));
            if ($url === '') {
                continue;
            }

            $active[] = [
                'key' => $key,
                'label' => $meta['label'],
                'icon' => $meta['icon'],
                'url' => $url,
            ];
        }

        return $active;
    }

    private function should_render(array $options): bool {
        if (empty($options['enabled'])) {
            return false;
        }

        if (!empty($options['disable_during_maintenance']) && $this->is_lightstart_available() && $this->is_lightstart_maintenance_active()) {
            return false;
        }

        return count($this->get_active_channels($options)) > 0 || !empty($options['own_chat_enabled']);
    }

    /**
     * Return true only when LightStart exists and maintenance status is active.
     */
    private function is_lightstart_maintenance_active(): bool {
        if (!$this->is_lightstart_available()) {
            return false;
        }

        $maintenance_options = get_option('wpmm_settings', []);
        if (!is_array($maintenance_options)) {
            return false;
        }

        return !empty($maintenance_options['general']['status']);
    }

    /**
     * Detect whether LightStart is installed and active (single-site or network).
     */
    private function is_lightstart_available(): bool {
        if (!file_exists(WP_PLUGIN_DIR . '/wp-maintenance-mode/wp-maintenance-mode.php')) {
            return false;
        }

        $active_plugins = (array) get_option('active_plugins', []);
        $network_plugins = is_multisite() ? (array) get_site_option('active_sitewide_plugins', []) : [];

        return in_array('wp-maintenance-mode/wp-maintenance-mode.php', $active_plugins, true)
            || isset($network_plugins['wp-maintenance-mode/wp-maintenance-mode.php']);
    }

    private function get_options(bool $apply_translations = true): array {
        $options = $this->get_raw_options();

        if (!$apply_translations || !function_exists('pll__')) {
            return $options;
        }

        // Keep saved base values language-neutral and only translate at runtime.
        foreach (Restatify_Ai_Multichat_Plugin::TRANSLATABLE_OPTION_KEYS as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            $translated = pll__($value);
            if (is_string($translated) && $translated !== '') {
                $options[$key] = $translated;
            }
        }

        return $options;
    }

    private function get_raw_options(): array {
        $this->ensure_legacy_options_migrated();

        $saved = get_option(Restatify_Ai_Multichat_Plugin::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return wp_parse_args($saved, $this->get_default_options());
    }

    private function get_default_options(): array {
        $defaults = [
            'enabled' => false,
            'disable_during_maintenance' => true,
            'require_cookie_consent' => true,
            'consent_cookie_names' => 'cookie_consent,cmplz_marketing,borlabs-cookie,CookieConsent',
            'team_name' => __('Restatify Service-Team', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'message' => __('Hallo. Wie können wir dir helfen?', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'cta_label' => __('Chat starten mit:', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'channels_more_label' => __('Weiter', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'channels_less_label' => __('Weniger', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'toggle_aria_label' => __('Chatfenster öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'delay_seconds' => 6,
            'own_chat_enabled' => false,
            'support_email' => get_option('admin_email', ''),
            'support_notify_on_message' => true,
            'chat_title' => __('Schreibe uns direkt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_placeholder' => __('Nachricht hier eingeben...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_send_label' => __('Senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_poll_seconds' => 8,
            'chat_reset_minutes' => 15,
            'chat_rate_limit_enabled' => true,
            'chat_rate_limit_window_seconds' => 60,
            'chat_rate_limit_max_send' => 25,
            'chat_rate_limit_max_fetch' => 120,
            'chat_rate_limit_max_booking_event' => 30,
            'ai_enabled' => false,
            'ai_debug_enabled' => false,
            'ai_api_key' => '',
            'ai_api_endpoint' => Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT,
            'ai_model' => 'gpt-4o-mini',
            'ai_system_prompt' => __('Du bist ein hilfreicher Support-Assistent für diese Website. Antworte kurz und freundlich in derselben Sprache wie der Nutzer.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'channels' => [],
        ];

        foreach (Restatify_Ai_Multichat_Plugin::CHANNELS as $key => $meta) {
            $defaults['channels'][$key] = '';
        }

        return $defaults;
    }

    private function sanitize_channel_url(string $url): string {
        return esc_url_raw($url, $this->get_allowed_url_protocols());
    }

    private function sanitize_ai_endpoint(string $endpoint): string {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT;
        }

        $sanitized = esc_url_raw($endpoint, ['https']);
        if ($sanitized === '') {
            return Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT;
        }

        $parts = wp_parse_url($sanitized);
        if (!is_array($parts) || empty($parts['scheme']) || strtolower((string) $parts['scheme']) !== 'https' || empty($parts['host'])) {
            return Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT;
        }

        return $sanitized;
    }

    private function get_allowed_url_protocols(): array {
        $protocols = wp_allowed_protocols();
        $extra = ['viber', 'tg', 'discord', 'signal', 'threema'];
        return array_values(array_unique(array_merge($protocols, $extra)));
    }

    private function get_contrast_text_color(string $hex): string {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $yiq >= 160 ? '#0b1221' : '#ffffff';
    }

    private function get_palette_colors(): array {
        $fallback = ['#ff6b00', '#00c2ff', '#a84700', '#0080a8', '#0b1221'];

        if (!function_exists('wp_get_global_settings')) {
            return $fallback;
        }

        $settings = wp_get_global_settings();
        if (!is_array($settings)) {
            return $fallback;
        }

        $palette_groups = $settings['color']['palette'] ?? [];
        $palette = [];

        if (isset($palette_groups['theme']) && is_array($palette_groups['theme'])) {
            $palette = $palette_groups['theme'];
        } elseif (is_array($palette_groups)) {
            $palette = $palette_groups;
        }

        $colors = [];
        foreach ($palette as $item) {
            if (!is_array($item) || empty($item['color'])) {
                continue;
            }

            $color = sanitize_hex_color($item['color']);
            if ($color) {
                $colors[] = $color;
            }
        }

        return count($colors) > 0 ? $colors : $fallback;
    }

    private function sanitize_cookie_match_list(string $value): string {
        $parts = preg_split('/[,\n\r\t ]+/', $value);
        if (!is_array($parts)) {
            return '';
        }

        $clean = [];
        foreach ($parts as $part) {
            $rule = trim((string) $part);
            if ($rule === '') {
                continue;
            }

            $segments = explode('=', $rule, 2);
            $name = preg_replace('/[^A-Za-z0-9_\-]/', '', trim((string) ($segments[0] ?? '')));
            if ($name === '') {
                continue;
            }

            if (count($segments) === 2) {
                $value_part = trim((string) $segments[1]);
                $value_part = preg_replace('/[^A-Za-z0-9_\-\.\|\[\]\{\}\:\/]/', '', $value_part);
                if ($value_part !== '') {
                    $clean[] = $name . '=' . $value_part;
                    continue;
                }
            }

            $clean[] = $name;
        }

        $clean = array_values(array_unique($clean));
        return implode(',', $clean);
    }

    private function ensure_legacy_options_migrated(): void {
        if ($this->migration_checked) {
            return;
        }
        $this->migration_checked = true;

        $current = get_option(Restatify_Ai_Multichat_Plugin::OPTION_KEY, null);
        if (is_array($current) && count($current) > 0) {
            return;
        }

        foreach (Restatify_Ai_Multichat_Plugin::LEGACY_OPTION_KEYS as $legacy_key) {
            $legacy = get_option((string) $legacy_key, null);
            if (!is_array($legacy) || count($legacy) === 0) {
                continue;
            }

            update_option(Restatify_Ai_Multichat_Plugin::OPTION_KEY, $legacy, false);
            update_option(
                Restatify_Ai_Multichat_Plugin::MIGRATION_STATE_OPTION,
                [
                    'completed' => true,
                    'show_notice' => true,
                    'source_option_key' => (string) $legacy_key,
                    'migrated_at' => time(),
                    'logs_history_migrated' => false,
                ],
                false
            );
            return;
        }
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

    private function verify_chat_nonce(): void {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'restatify_mco_chat_nonce')) {
            wp_send_json_error(['message' => __('Invalid request token.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 403);
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
            wp_send_json_error(['message' => __('Too many requests. Please wait a moment and try again.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)], 429);
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

    private function save_chat_store(array $store): void {
        uasort($store, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at_gmt'] ?? ''), (string) ($a['updated_at_gmt'] ?? ''));
        });

        $store = array_slice($store, 0, Restatify_Ai_Multichat_Plugin::CHAT_MAX_CONVERSATIONS, true);
        update_option(Restatify_Ai_Multichat_Plugin::CHAT_STORE_KEY, $store, false);
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
        $required_cap = apply_filters('restatify_mco_support_inbox_capability', Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY);
        if (!is_string($required_cap) || $required_cap === '') {
            $required_cap = Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY;
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
            'off' => __('AI off (temporary)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'visitor' => __('AI replies to visitor only', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'support' => __('AI replies to support only', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'both' => __('AI replies to both sides', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
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

private function generate_ai_reply(array $options, array $conversation, string $latest_message): string {
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

    private function maybe_generate_booking_reply(string $latest_message): string {
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

    private function get_recent_ai_debug_lines(int $limit = 40): array {
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

public function enqueue_support_inbox_assets(): void {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== 'restatify-mco-support-inbox') {
            return;
        }

        $base_url = RESTATIFY_MCO_PLUGIN_URL . 'assets/';
        $base_path = RESTATIFY_MCO_PLUGIN_DIR . 'assets/';

        wp_enqueue_script(
            'restatify-mco-support-inbox-admin',
            $base_url . 'support-inbox-admin.js',
            [],
            file_exists($base_path . 'support-inbox-admin.js') ? (string) filemtime($base_path . 'support-inbox-admin.js') : '1.0.0',
            true
        );

        wp_localize_script('restatify-mco-support-inbox-admin', 'restatifyMcoSupportInbox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('restatify_mco_chat_nonce'),
            'supportPageUrl' => add_query_arg(['page' => 'restatify-mco-support-inbox'], admin_url('admin.php')),
            'strings' => [
                'deleteConfirm' => __('Diese Unterhaltung dauerhaft löschen?', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'genericError' => __('Aktion fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'openBookingAtClient' => __('Ich habe das Buchungstool für dich geöffnet. Bitte wähle einen Termin und bestätige deine Reservierung.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            ],
        ]);
    }

    public function ensure_support_capability(): void {
        $role = get_role('administrator');
        if (!$role) {
            return;
        }

        if (!$role->has_cap(Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY)) {
            $role->add_cap(Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY);
        }
    }

    public function register_support_inbox_page(): void {
        add_menu_page(
            __('Support Chat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            __('Support Chat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            $this->get_support_inbox_capability(),
            'restatify-mco-support-inbox',
            [$this, 'render_support_inbox_page'],
            'dashicons-format-chat',
            58
        );
    }

    public function render_support_inbox_page(): void {
        if (!current_user_can($this->get_support_inbox_capability())) {
            wp_die(esc_html__('Unzureichende Berechtigungen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN));
        }

        $options = $this->get_options(false);
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Support Chat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h1>';

        if (empty($options['own_chat_enabled'])) {
            echo '<p>' . esc_html__('Der integrierte Website-Chat ist derzeit deaktiviert. Aktiviere ihn in den Multi-Chat-Overlay-Einstellungen, um hier Unterhaltungen zu empfangen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
            echo '</div>';
            return;
        }

        $this->render_support_inbox();
        echo '</div>';
    }

    public function register_ai_debug_dashboard_widget(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'restatify_mco_ai_debug_widget',
            __('Restatify AI Debug', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            [$this, 'render_ai_debug_dashboard_widget']
        );
    }

    public function render_ai_debug_dashboard_widget(): void {
        $lines = $this->get_recent_ai_debug_lines(20);
        $settings_link = add_query_arg(
            ['page' => Restatify_Ai_Multichat_Plugin::ADMIN_PAGE_SLUG],
            admin_url('options-general.php')
        );

        if (count($lines) === 0) {
            echo '<p>' . esc_html__('Noch keine KI-Debug-Zeilen vorhanden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
            echo '<p class="description">' . esc_html__('Aktiviere "KI-Debug-Protokollierung" in den Plugin-Einstellungen und sende eine Testnachricht, um dieses Widget zu befüllen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
            echo '<p><a class="button" href="' . esc_url($settings_link) . '">' . esc_html__('Plugin-Einstellungen öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</a></p>';
            return;
        }

        echo '<p class="description">' . esc_html__('Aktuelle pluginseitige KI-Diagnose (letzte 20 Eintraege).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
        echo '<textarea class="large-text code" rows="10" readonly>' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        echo '<p><a class="button" href="' . esc_url($settings_link) . '">' . esc_html__('Plugin-Einstellungen öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</a></p>';
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options(false);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Multi Chat Overlay', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h1>
            <p><?php esc_html_e('Konfiguriere zuerst das grundlegende Chat-Verhalten. Erweiterte Optionen sind unten in aufklappbaren Expertenbereichen gruppiert.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
            <?php settings_errors(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>

            <div class="notice notice-info" style="padding:12px 14px; margin: 12px 0 16px;">
                <p><strong><?php esc_html_e('Schnellhilfe', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></strong></p>
                <ol style="margin: 0 0 0 20px;">
                    <li><?php esc_html_e('Aktiviere das Overlay im ersten Abschnitt.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Hinterlege mindestens eine Kanal-URL oder aktiviere den integrierten Website-Chat.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Setze die Support-E-Mail, wenn du E-Mail-Benachrichtigungen für neue Nachrichten erhalten möchtest.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Oeffne den Support-Posteingang über den Link auf dieser Seite oder über Benachrichtigungs-E-Mails.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Optional kannst du die KI-Autoantwort aktivieren und API-Schluessel plus Modell hinterlegen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Bei mehrsprachigen Seiten mit Polylang: Übersetze Chat-Texte unter Sprachen > Übersetzungen in der Gruppe "Restatify Multi Chat Overlay".', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(Restatify_Ai_Multichat_Plugin::SETTINGS_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Overlay aktivieren', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Schwebendes Multi-Chat-Overlay im Frontend anzeigen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <?php if ($this->is_lightstart_available()) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Bei LightStart-Wartung ausblenden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[disable_during_maintenance]" value="1" <?php checked(!empty($options['disable_during_maintenance'])); ?>>
                                    <?php esc_html_e('Overlay nicht anzeigen, solange der Wartungsmodus (LightStart) aktiv ist.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cookie-Einwilligung voraussetzen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[require_cookie_consent]" value="1" <?php checked(!empty($options['require_cookie_consent'])); ?>>
                                <?php esc_html_e('Chat nur anzeigen, wenn Besucher-Einwilligung erkannt wurde', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Nutze die Cookie-Namen unten und/oder CMP-Signale (Cookiebot, OneTrust), um Einwilligungen zu erkennen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Regeln für Einwilligungs-Cookies', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text code" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[consent_cookie_names]" value="<?php echo esc_attr($options['consent_cookie_names']); ?>">
                            <p class="description"><?php esc_html_e('Kommagetrennte Regeln. Verwende cookie_name oder cookie_name=erwarteter_wert, z.B. cookie_notice_accepted=true,_cky-consent=accept.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Team-Titel', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[team_name]" value="<?php echo esc_attr($options['team_name']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Einleitungsnachricht', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[message]" value="<?php echo esc_attr($options['message']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Überschrift für Kanaele', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[cta_label]" value="<?php echo esc_attr($options['cta_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Beschriftung für weitere Kanaele', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[channels_more_label]" value="<?php echo esc_attr($options['channels_more_label']); ?>">
                            <p class="description"><?php esc_html_e('Buttontext zum Anzeigen zusätzlicher Kanal-Icons.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Beschriftung für weniger Kanaele', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[channels_less_label]" value="<?php echo esc_attr($options['channels_less_label']); ?>">
                            <p class="description"><?php esc_html_e('Buttontext zum Einklappen zusätzlicher Kanal-Icons.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Barrierefreiheits-Label für den Button', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[toggle_aria_label]" value="<?php echo esc_attr($options['toggle_aria_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Verzoegerung für automatisches Öffnen (Sekunden)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="0" max="120" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[delay_seconds]" value="<?php echo esc_attr((string) $options['delay_seconds']); ?>">
                            <p class="description"><?php esc_html_e('Nach dieser Verzoegerung oeffnet sich das Panel einmal automatisch. Wenn der Nutzer es schliesst, wird Auto-Open für 24 Stunden unterdrueckt.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Website-Chat + Support-Posteingang', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Der Support-Posteingang ist im separaten Admin-Menuepunkt "Support Chat" verfügbar.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Integrierten Website-Chat aktivieren', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[own_chat_enabled]" value="1" <?php checked(!empty($options['own_chat_enabled'])); ?>>
                                <?php esc_html_e('Ein natives Chatformular direkt im Overlay anzeigen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Support-E-Mail-Adresse', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="email" required placeholder="support@example.com" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[support_email]" value="<?php echo esc_attr($options['support_email']); ?>">
                            <p class="description"><?php esc_html_e('Neue Besuchernachrichten können an diese Adresse weitergeleitet werden - inklusive Direktlink zum offenen Chat im Admin.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('E-Mail bei neuer Nachricht senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[support_notify_on_message]" value="1" <?php checked(!empty($options['support_notify_on_message'])); ?>>
                                <?php esc_html_e('Fuer jede neue Besuchernachricht eine Support-Benachrichtigungs-E-Mail senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat-Titel', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_title]" value="<?php echo esc_attr($options['chat_title']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Platzhalter für Chat-Eingabe', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_placeholder]" value="<?php echo esc_attr($options['chat_placeholder']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Beschriftung Senden-Button', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_label]" value="<?php echo esc_attr($options['chat_send_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Aktualisierungsintervall (Sekunden)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="3" max="60" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_poll_seconds]" value="<?php echo esc_attr((string) $options['chat_poll_seconds']); ?>">
                            <p class="description"><?php esc_html_e('Wie oft der Chat nach neuen Support-Antworten sucht.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat zuruecksetzen nach (Minuten)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="0" max="525600" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_reset_minutes]" value="<?php echo esc_attr((string) $options['chat_reset_minutes']); ?>">
                            <p class="description"><?php esc_html_e('Bei 0 wird der Chatverlauf nie automatisch zurueckgesetzt. Andernfalls wird der Besucherchat nach dieser Inaktivitaetszeit zurueckgesetzt (empfohlen für Support: 15).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable public rate limiting', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_enabled]" value="1" <?php checked(!empty($options['chat_rate_limit_enabled'])); ?>>
                                <?php esc_html_e('Limit anonymous chat requests per visitor fingerprint (IP + user agent).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Rate limit window (seconds)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="10" max="3600" step="10" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_window_seconds]" value="<?php echo esc_attr((string) $options['chat_rate_limit_window_seconds']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Max send-message requests per window', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="1" max="120" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_max_send]" value="<?php echo esc_attr((string) $options['chat_rate_limit_max_send']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Max fetch-chat requests per window', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="1" max="360" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_max_fetch]" value="<?php echo esc_attr((string) $options['chat_rate_limit_max_fetch']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Max booking-event requests per window', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="1" max="120" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_max_booking_event]" value="<?php echo esc_attr((string) $options['chat_rate_limit_max_booking_event']); ?>">
                            <p class="description"><?php esc_html_e('Exceeding limits returns HTTP 429. Adjust fetch limit to match your polling interval and traffic.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <details style="margin:12px 0 16px;">
                    <summary><strong><?php esc_html_e('Experteneinstellungen: Optionale KI-Autoantwort', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></strong></summary>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('KI-Autoantwort aktivieren', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_enabled]" value="1" <?php checked(!empty($options['ai_enabled'])); ?>>
                                <?php esc_html_e('Automatische Erstantwort für eingehende Besuchernachrichten erzeugen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('KI-Debug-Protokollierung aktivieren', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_debug_enabled]" value="1" <?php checked(!empty($options['ai_debug_enabled'])); ?>>
                                <?php esc_html_e('Diagnose für Provider-Request/Response ins PHP-Error-Log schreiben (ohne vollständigen API-Schluessel).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API key', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_api_key]" value="<?php echo esc_attr($options['ai_api_key']); ?>" placeholder="sk-...">
                            <p class="description"><?php esc_html_e('Wird in den Plugin-Optionen gespeichert. Bitte nur einen eingeschraenkten Schluessel verwenden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API-Endpunkt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text code" type="url" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_api_endpoint]" value="<?php echo esc_attr($options['ai_api_endpoint']); ?>" placeholder="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT); ?>">
                            <p class="description"><?php esc_html_e('HTTPS-Endpunkt für KI-Anfragen. Der Anbieter wird aus der URL automatisch erkannt (OpenAI, Gemini, Mistral, DeepSeek, Llama/Ollama). Wenn leer oder ungültig, wird der OpenAI-Standardendpunkt verwendet.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Modell', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_model]" value="<?php echo esc_attr($options['ai_model']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('System-Prompt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea class="large-text" rows="4" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_system_prompt]"><?php echo esc_textarea($options['ai_system_prompt']); ?></textarea>
                        </td>
                    </tr>
                </table>
                </details>

                <?php
                $debug_lines = $this->get_recent_ai_debug_lines(40);
                if (count($debug_lines) > 0) :
                    ?>
                    <h3><?php esc_html_e('Aktuelle KI-Debug-Zeilen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h3>
                    <p class="description"><?php esc_html_e('Neueste Eintraege aus der pluginseitigen KI-Diagnose. Fuer vollständige Laufzeit-Logs bitte auch das PHP-Error-Log prüfen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                    <textarea class="large-text code" rows="10" readonly><?php echo esc_textarea(implode("\n", $debug_lines)); ?></textarea>
                <?php endif; ?>

                <details style="margin:12px 0 16px;">
                    <summary><strong><?php esc_html_e('Experteneinstellungen: Chat-Kanaele', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></strong></summary>
                <p><?php esc_html_e('Nur Kanaele mit URL werden im Overlay angezeigt.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                <table class="form-table" role="presentation">
                    <?php foreach (Restatify_Ai_Multichat_Plugin::CHANNELS as $key => $meta) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($meta['label']); ?></th>
                            <td>
                                <input
                                    class="regular-text code"
                                    type="url"
                                    name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[channels][<?php echo esc_attr($key); ?>]"
                                    value="<?php echo esc_attr($options['channels'][$key] ?? ''); ?>"
                                    placeholder="<?php echo esc_attr($meta['placeholder']); ?>"
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                </details>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets(): void {
        $options = $this->get_options();
        if (!$this->should_render($options)) {
            return;
        }

        $base_url = RESTATIFY_MCO_PLUGIN_URL . 'assets/';
        $base_path = RESTATIFY_MCO_PLUGIN_DIR . 'assets/';

        wp_enqueue_style(
            'restatify-multi-chat-overlay',
            $base_url . 'multi-chat-overlay.css',
            [],
            file_exists($base_path . 'multi-chat-overlay.css') ? (string) filemtime($base_path . 'multi-chat-overlay.css') : '1.0.0'
        );

        wp_enqueue_script(
            'restatify-multi-chat-overlay',
            $base_url . 'multi-chat-overlay.js',
            [],
            file_exists($base_path . 'multi-chat-overlay.js') ? (string) filemtime($base_path . 'multi-chat-overlay.js') : '1.0.0',
            true
        );

        wp_localize_script('restatify-multi-chat-overlay', 'restatifyMultiChatOverlay', [
            'dismissHours' => 24,
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('restatify_mco_chat_nonce'),
            'chatEnabled' => !empty($options['own_chat_enabled']),
            'requireConsent' => !empty($options['require_cookie_consent']),
            'consentCookieNames' => array_values(array_filter(array_map('trim', explode(',', (string) ($options['consent_cookie_names'] ?? ''))))),
            'pollSeconds' => max(3, (int) $options['chat_poll_seconds']),
            'chatResetMinutes' => max(0, (int) $options['chat_reset_minutes']),
            'strings' => [
                'sending' => __('Senden...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'sendFailed' => __('Nachricht konnte nicht gesendet werden. Bitte erneut versuchen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'emptyMessage' => __('Bitte gib zuerst eine Nachricht ein.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            ],
        ]);
    }

    public function render_overlay(): void {
        $options = $this->get_options();
        if (!$this->should_render($options)) {
            return;
        }

        $channels = $this->get_active_channels($options);
        $palette = $this->get_palette_colors();
        $delay_ms = max(0, (int) $options['delay_seconds']) * 1000;
        ?>
        <div
            class="restatify-mco"
            data-restatify-mco
            data-delay-ms="<?php echo esc_attr((string) $delay_ms); ?>"
            data-storage-key="restatify_mco_dismissed_at"
        >
            <button
                type="button"
                class="restatify-mco__fab"
                data-mco-toggle
                aria-expanded="false"
                aria-controls="restatify-mco-panel"
                aria-label="<?php echo esc_attr($options['toggle_aria_label']); ?>"
            >
                <span class="mobi-mbri-chat"></span>
            </button>

            <section id="restatify-mco-panel" class="restatify-mco__panel" data-mco-panel hidden>
                <header class="restatify-mco__header">
                    <p class="restatify-mco__team"><?php echo esc_html($options['team_name']); ?></p>
                    <div class="restatify-mco__header-actions">
                        <button
                            type="button"
                            class="restatify-mco__focus"
                            data-mco-focus
                            aria-pressed="false"
                            aria-label="<?php esc_attr_e('Chatfenster vergrößern', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>"
                            title="<?php esc_attr_e('Vergrößern', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>"
                        >
                            +
                        </button>
                        <button type="button" class="restatify-mco__close" data-mco-close aria-label="<?php esc_attr_e('Chatfenster schließen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>" title="<?php esc_attr_e('Schließen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>">&times;</button>
                    </div>
                </header>

                <div class="restatify-mco__body">
                    <p class="restatify-mco__message"><?php echo esc_html($options['message']); ?></p>
                    <?php if (count($channels) > 0) : ?>
                        <p class="restatify-mco__cta"><?php echo esc_html($options['cta_label']); ?></p>
                        <div class="restatify-mco__channels<?php echo count($channels) > 3 ? ' is-collapsed' : ''; ?>" data-mco-channels>
                            <?php foreach ($channels as $index => $channel) :
                                $channel_color = $palette[$index % count($palette)];
                                $channel_foreground = $this->get_contrast_text_color($channel_color);
                                $fallback_icon = strtoupper(substr((string) ($channel['label'] ?? ''), 0, 1));
                                if (function_exists('mb_substr')) {
                                    $fallback_icon = mb_strtoupper(mb_substr((string) ($channel['label'] ?? ''), 0, 1));
                                }
                                if ($fallback_icon === '') {
                                    $fallback_icon = '?';
                                }
                                ?>
                                <a
                                    class="restatify-mco__channel<?php echo $index >= 3 ? ' is-extra' : ''; ?>"
                                    href="<?php echo esc_url($channel['url'], $this->get_allowed_url_protocols()); ?>"
                                    target="_blank"
                                    rel="noopener"
                                    aria-label="<?php echo esc_attr($channel['label']); ?>"
                                    data-channel="<?php echo esc_attr($channel['key']); ?>"
                                    style="--mco-channel-color: <?php echo esc_attr($channel_color); ?>; --mco-channel-fg: <?php echo esc_attr($channel_foreground); ?>;"
                                >
                                    <span class="restatify-mco__channel-icon <?php echo esc_attr($channel['icon']); ?>" data-channel-fallback="<?php echo esc_attr($fallback_icon); ?>"></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <?php if (count($channels) > 3) : ?>
                            <button
                                type="button"
                                class="restatify-mco__channels-toggle"
                                data-mco-channels-toggle
                                data-label-more="<?php echo esc_attr($options['channels_more_label']); ?>"
                                data-label-less="<?php echo esc_attr($options['channels_less_label']); ?>"
                                aria-expanded="false"
                            >
                                <?php echo esc_html($options['channels_more_label']); ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($options['own_chat_enabled'])) : ?>
                        <div
                            class="restatify-mco__native-chat"
                            data-mco-native-chat
                            data-chat-title="<?php echo esc_attr($options['chat_title']); ?>"
                            data-chat-placeholder="<?php echo esc_attr($options['chat_placeholder']); ?>"
                            data-chat-send-label="<?php echo esc_attr($options['chat_send_label']); ?>"
                        >
                            <p class="restatify-mco__native-title"><?php echo esc_html($options['chat_title']); ?></p>
                            <div class="restatify-mco__native-messages" data-chat-messages aria-live="polite"></div>
                            <form class="restatify-mco__native-form" data-chat-form>
                                <label style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                                    <?php esc_html_e('Dieses Feld leer lassen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" data-chat-honeypot>
                                </label>
                                <textarea
                                    class="restatify-mco__native-input"
                                    data-chat-input
                                    rows="2"
                                    maxlength="1000"
                                    placeholder="<?php echo esc_attr($options['chat_placeholder']); ?>"
                                ></textarea>
                                <button type="submit" class="restatify-mco__native-send" data-chat-send><?php echo esc_html($options['chat_send_label']); ?></button>
                            </form>
                            <p class="restatify-mco__native-status" data-chat-status hidden></p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
        <?php
    }

    private function render_support_inbox(): void {
        $store = $this->get_chat_store();
        $selected_id = sanitize_text_field(wp_unslash($_GET['conversation'] ?? ''));
        $ai_mode_options = $this->get_ai_mode_options();

        echo '<h2>' . esc_html__('Support-Posteingang', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h2>';
        echo '<p>' . esc_html__('Offene Unterhaltungen von Website-Besuchern. Klicke auf eine Unterhaltung, um sie zu prüfen und zu antworten.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';

        echo '<div style="display:flex; gap:8px; align-items:center; margin:10px 0 14px;">';
        echo '<strong style="margin-right:4px;">' . esc_html__('Filter:', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</strong>';
        echo '<button type="button" class="button button-primary" data-mco-conversation-filter="all">' . esc_html__('Alle', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
        echo '<button type="button" class="button" data-mco-conversation-filter="confirmed">' . esc_html__('Buchung bestätigt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
        echo '<button type="button" class="button" data-mco-conversation-filter="cancelled">' . esc_html__('Buchung abgebrochen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
        echo '<button type="button" class="button" data-mco-conversation-filter="system">' . esc_html__('Systemereignisse', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
        echo '</div>';

        if (count($store) === 0) {
            echo '<p>' . esc_html__('Noch keine Unterhaltungen vorhanden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Unterhaltung', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Aktualisiert (UTC)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('KI-Modus', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Vorschau', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Aktion', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($store as $id => $conversation) {
            $messages = (array) ($conversation['messages'] ?? []);
            $last = end($messages);
            $preview = is_array($last) ? (string) ($last['message'] ?? '') : '';
            $preview = str_replace([
                RESTATIFY_BOOKING_OPEN_TOKEN,
                RESTATIFY_BOOKING_CONFIRMED_TOKEN,
                RESTATIFY_BOOKING_CANCELLED_TOKEN,
            ], '', $preview);
            if (function_exists('mb_substr')) {
                $preview = mb_substr($preview, 0, 100);
            } else {
                $preview = substr($preview, 0, 100);
            }

            $preview = trim($preview);

            $conversation_state = 'none';
            $conversation_badge = '';

            for ($idx = count($messages) - 1; $idx >= 0; $idx--) {
                $message_row = is_array($messages[$idx]) ? $messages[$idx] : [];
                $raw = (string) ($message_row['message'] ?? '');
                $sender = (string) ($message_row['sender'] ?? 'visitor');

                if ($raw !== '' && str_contains($raw, RESTATIFY_BOOKING_CONFIRMED_TOKEN)) {
                    $conversation_state = 'confirmed';
                    $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#e7f6ea; color:#116329; font-size:11px; font-weight:600;">' . esc_html__('Buchung bestätigt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
                    break;
                }

                if ($raw !== '' && str_contains($raw, RESTATIFY_BOOKING_CANCELLED_TOKEN)) {
                    $conversation_state = 'cancelled';
                    $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#fdecec; color:#8a1f1f; font-size:11px; font-weight:600;">' . esc_html__('Buchung abgebrochen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
                    break;
                }

                if ($sender === 'system') {
                    $conversation_state = 'system';
                    $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2f6; color:#344054; font-size:11px; font-weight:600;">' . esc_html__('Systemereignis', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
                    break;
                }
            }

            $is_selected = $selected_id !== '' && hash_equals($selected_id, (string) $id);
            $mode = $this->normalize_ai_mode((string) ($conversation['ai_mode'] ?? 'visitor'));
            $mode_label = (string) ($ai_mode_options[$mode] ?? $mode);
            $open_link = add_query_arg(
                [
                    'page' => 'restatify-mco-support-inbox',
                    'conversation' => (string) $id,
                ],
                admin_url('admin.php')
            );

            echo '<tr data-mco-conversation-row="' . esc_attr((string) $id) . '" data-mco-conversation-state="' . esc_attr($conversation_state) . '"' . ($is_selected ? ' style="background:#eef6ff"' : '') . '>';
            echo '<td><strong>' . esc_html((string) $id) . '</strong></td>';
            echo '<td>' . esc_html((string) ($conversation['updated_at_gmt'] ?? '')) . '</td>';
            echo '<td>' . esc_html($mode_label) . '</td>';
            echo '<td>' . $conversation_badge . ' ' . esc_html($preview) . '</td>';
            echo '<td>';
            echo '<a class="button" href="' . esc_url($open_link) . '">' . esc_html__('Öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</a> ';
            echo '<button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr((string) $id) . '">' . esc_html__('Loeschen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($selected_id !== '' && !empty($store[$selected_id])) {
            $selected = $store[$selected_id];
            $selected_ai_mode = $this->normalize_ai_mode((string) ($selected['ai_mode'] ?? 'visitor'));
            $booking_overlay_available = function_exists('restatify_booking_ai_handle_message') || shortcode_exists('restatify_booking_popup');
            echo '<div id="restatify-mco-conversation-detail" data-mco-conversation-detail="' . esc_attr($selected_id) . '">';
            echo '<h3 style="margin-top:20px;">' . esc_html__('Unterhaltungsdetails', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h3>';
            echo '<p><label for="restatify-mco-ai-mode"><strong>' . esc_html__('KI-Verhalten für diesen Chat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</strong></label></p>';
            echo '<p>';
            echo '<select id="restatify-mco-ai-mode" data-mco-ai-mode style="min-width: 280px;">';
            foreach ($ai_mode_options as $mode_key => $mode_label) {
                echo '<option value="' . esc_attr((string) $mode_key) . '"' . selected($selected_ai_mode, (string) $mode_key, false) . '>' . esc_html((string) $mode_label) . '</option>';
            }
            echo '</select> ';
            echo '<button type="button" class="button" data-mco-support-ai-save data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('KI-Modus speichern', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
            echo '</p>';
            echo '<div style="max-height:360px; overflow:auto; border:1px solid #ccd0d4; border-radius:6px; padding:12px; background:#fff;">';
            foreach ((array) ($selected['messages'] ?? []) as $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $sender = (string) ($msg['sender'] ?? 'visitor');
                $label = $sender === 'support'
                    ? __('Support', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)
                    : ($sender === 'ai'
                        ? __('AI', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)
                        : ($sender === 'system' ? __('System', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) : __('Besucher', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)));
                $raw_message = (string) ($msg['message'] ?? '');
                $is_booking_confirmed = str_contains($raw_message, RESTATIFY_BOOKING_CONFIRMED_TOKEN);
                $is_booking_cancelled = str_contains($raw_message, RESTATIFY_BOOKING_CANCELLED_TOKEN);
                $message_text = str_replace([
                    RESTATIFY_BOOKING_OPEN_TOKEN,
                    RESTATIFY_BOOKING_CONFIRMED_TOKEN,
                    RESTATIFY_BOOKING_CANCELLED_TOKEN,
                ], '', $raw_message);
                $message_text = trim($message_text);

                $badge_html = '';
                if ($is_booking_confirmed) {
                    $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#e7f6ea; color:#116329; font-size:11px; font-weight:600;">' . esc_html__('Buchung bestätigt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
                } elseif ($is_booking_cancelled) {
                    $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#fdecec; color:#8a1f1f; font-size:11px; font-weight:600;">' . esc_html__('Buchung abgebrochen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
                } elseif ($sender === 'system') {
                    $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#eef2f6; color:#344054; font-size:11px; font-weight:600;">' . esc_html__('Systemereignis', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</span>';
                }

                echo '<p style="margin:0 0 10px;">';
                echo '<strong>' . esc_html($label) . ':</strong> ';
                echo $badge_html;
                echo esc_html(trim($message_text));
                echo '<br><small>' . esc_html((string) ($msg['time_gmt'] ?? '')) . '</small>';
                echo '</p>';
            }
            echo '</div>';

            echo '<div style="margin-top:14px;">';
            if ($booking_overlay_available) {
                echo '<p><button type="button" class="button" data-mco-support-open-booking data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Buchungs-Overlay beim Besucher öffnen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button></p>';
            }
            echo '<textarea id="restatify-mco-support-reply" class="large-text" rows="3" placeholder="' . esc_attr__('Support-Antwort eingeben...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '"></textarea>';
            echo '<p><button type="button" class="button button-primary" data-mco-support-send data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Support-Antwort senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button></p>';
            echo '<p><button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Diese Unterhaltung löschen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button></p>';
            echo '</div>';
            echo '</div>';
        }

    }

    private function get_support_inbox_capability(): string {
        $capability = apply_filters('restatify_mco_support_inbox_capability', Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY);
        return is_string($capability) && $capability !== '' ? $capability : Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY;
    }
}

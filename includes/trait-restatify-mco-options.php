<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Restatify_MCO_Options_Trait {
    public function load_textdomain(): void {
        load_plugin_textdomain(
            Restatify_Multi_Chat_Overlay::TEXT_DOMAIN,
            false,
            dirname(plugin_basename(RESTATIFY_MCO_PLUGIN_FILE)) . '/languages'
        );
    }

    public function register_settings(): void {
        register_setting(
            'restatify_multi_chat_overlay',
            Restatify_Multi_Chat_Overlay::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_options'],
                'default' => $this->get_default_options(),
            ]
        );
    }

    public function register_admin_page(): void {
        add_options_page(
            __('Multi Chat Overlay', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            __('Multi Chat Overlay', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'manage_options',
            'restatify-multi-chat-overlay',
            [$this, 'render_admin_page']
        );
    }

    public function register_polylang_strings(): void {
        if (!function_exists('pll_register_string')) {
            return;
        }

        $options = $this->get_raw_options();
        foreach (Restatify_Multi_Chat_Overlay::TRANSLATABLE_OPTION_KEYS as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            pll_register_string(
                'restatify_mco_' . $key,
                $value,
                Restatify_Multi_Chat_Overlay::POLYLANG_GROUP,
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
                    Restatify_Multi_Chat_Overlay::OPTION_KEY,
                    'restatify_mco_support_email_required',
                    __('Die Support-E-Mail war leer und wurde auf die Admin-E-Mail der Website zurueckgesetzt.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
                    'warning'
                );
            } else {
                $output['support_email'] = '';
            }
        }

        if (!empty($output['ai_enabled']) && trim((string) $output['ai_api_key']) === '') {
            $output['ai_enabled'] = false;
            add_settings_error(
                Restatify_Multi_Chat_Overlay::OPTION_KEY,
                'restatify_mco_ai_key_required',
                __('Die KI-Autoantwort wurde deaktiviert, weil kein API-Schluessel hinterlegt ist.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
                'warning'
            );
        }

        $input_channels = isset($input['channels']) && is_array($input['channels']) ? $input['channels'] : [];

        foreach (Restatify_Multi_Chat_Overlay::CHANNELS as $key => $meta) {
            $url = isset($input_channels[$key]) ? trim((string) $input_channels[$key]) : '';
            $output['channels'][$key] = $url !== '' ? $this->sanitize_channel_url($url) : '';
        }

        return $output;
    }

    private function get_active_channels(array $options): array {
        $active = [];
        foreach (Restatify_Multi_Chat_Overlay::CHANNELS as $key => $meta) {
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
        foreach (Restatify_Multi_Chat_Overlay::TRANSLATABLE_OPTION_KEYS as $key) {
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
        $saved = get_option(Restatify_Multi_Chat_Overlay::OPTION_KEY, []);
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
            'team_name' => __('Restatify Service-Team', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'message' => __('Hallo. Wie können wir dir helfen?', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'cta_label' => __('Chat starten mit:', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'channels_more_label' => __('Weiter', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'channels_less_label' => __('Weniger', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'toggle_aria_label' => __('Chatfenster öffnen', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'delay_seconds' => 6,
            'own_chat_enabled' => false,
            'support_email' => get_option('admin_email', ''),
            'support_notify_on_message' => true,
            'chat_title' => __('Schreibe uns direkt', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'chat_placeholder' => __('Nachricht hier eingeben...', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'chat_send_label' => __('Senden', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
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
            'ai_api_endpoint' => Restatify_Multi_Chat_Overlay::DEFAULT_AI_ENDPOINT,
            'ai_model' => 'gpt-4o-mini',
            'ai_system_prompt' => __('Du bist ein hilfreicher Support-Assistent für diese Website. Antworte kurz und freundlich in derselben Sprache wie der Nutzer.', Restatify_Multi_Chat_Overlay::TEXT_DOMAIN),
            'channels' => [],
        ];

        foreach (Restatify_Multi_Chat_Overlay::CHANNELS as $key => $meta) {
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
            return Restatify_Multi_Chat_Overlay::DEFAULT_AI_ENDPOINT;
        }

        $sanitized = esc_url_raw($endpoint, ['https']);
        if ($sanitized === '') {
            return Restatify_Multi_Chat_Overlay::DEFAULT_AI_ENDPOINT;
        }

        $parts = wp_parse_url($sanitized);
        if (!is_array($parts) || empty($parts['scheme']) || strtolower((string) $parts['scheme']) !== 'https' || empty($parts['host'])) {
            return Restatify_Multi_Chat_Overlay::DEFAULT_AI_ENDPOINT;
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
}




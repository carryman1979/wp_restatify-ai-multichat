<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles configuration, defaults, sanitization and localization setup.
 */
class Restatify_Ai_Multichat_Options_Runtime {
    private $migration_checked = false;

    public function load_textdomain(): void {
        load_plugin_textdomain(
            Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN,
            false,
            dirname(plugin_basename(RESTATIFY_AI_MULTICHAT_PLUGIN_FILE)) . '/languages'
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
            __('AI Multichat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            __('AI Multichat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'manage_options',
            Restatify_Ai_Multichat_Plugin::ADMIN_PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function register_polylang_strings(): void {
        if (!function_exists('pll_register_string') && !class_exists('\\Restatify\\Shared\\I18n\\PolylangAdapter', false)) {
            return;
        }

        $options = $this->get_raw_options();
        foreach (Restatify_Ai_Multichat_Plugin::TRANSLATABLE_OPTION_KEYS as $key) {
            $value = trim((string) ($options[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if (class_exists('\\Restatify\\Shared\\I18n\\PolylangAdapter', false)) {
                \Restatify\Shared\I18n\PolylangAdapter::register(
                    'restatify_mco_' . $key,
                    $value,
                    Restatify_Ai_Multichat_Plugin::POLYLANG_GROUP,
                    true
                );
            } else {
                pll_register_string(
                    'restatify_mco_' . $key,
                    $value,
                    Restatify_Ai_Multichat_Plugin::POLYLANG_GROUP,
                    true
                );
            }
        }

        if (class_exists('\\Restatify\\Shared\\Util\\PrivacyLegalNotice', false)) {
            $privacy_legal_notice_class = '\\Restatify\\Shared\\Util\\PrivacyLegalNotice';
            $privacy_legal_notice_class::registerPolylangStrings();
        }

        if (class_exists('\\Restatify\\Shared\\I18n\\PolylangAdapter', false)) {
            \Restatify\Shared\I18n\PolylangAdapter::register(
                'restatify_mco_chat_ai_legal_notice',
                Restatify_Ai_Multichat_Plugin::CHAT_AI_LEGAL_NOTICE_TEXT,
                Restatify_Ai_Multichat_Plugin::POLYLANG_GROUP,
                false
            );
        } elseif (function_exists('pll_register_string')) {
            pll_register_string(
                'restatify_mco_chat_ai_legal_notice',
                Restatify_Ai_Multichat_Plugin::CHAT_AI_LEGAL_NOTICE_TEXT,
                Restatify_Ai_Multichat_Plugin::POLYLANG_GROUP,
                false
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
            'chat_ai_legal_notice' => sanitize_text_field($input['chat_ai_legal_notice'] ?? $defaults['chat_ai_legal_notice']),
            'cta_label' => sanitize_text_field($input['cta_label'] ?? $defaults['cta_label']),
            'channels_more_label' => sanitize_text_field($input['channels_more_label'] ?? $defaults['channels_more_label']),
            'channels_less_label' => sanitize_text_field($input['channels_less_label'] ?? $defaults['channels_less_label']),
            'toggle_aria_label' => sanitize_text_field($input['toggle_aria_label'] ?? $defaults['toggle_aria_label']),
            'privacy_policy_url' => esc_url_raw((string) ($input['privacy_policy_url'] ?? $defaults['privacy_policy_url'])),
            'delay_seconds' => max(0, min(120, absint($input['delay_seconds'] ?? $defaults['delay_seconds']))),
            'own_chat_enabled' => !empty($input['own_chat_enabled']),
            'support_email' => sanitize_email($input['support_email'] ?? $defaults['support_email']),
            'support_notify_on_message' => !empty($input['support_notify_on_message']),
            'chat_title' => sanitize_text_field($input['chat_title'] ?? $defaults['chat_title']),
            'chat_placeholder' => sanitize_text_field($input['chat_placeholder'] ?? $defaults['chat_placeholder']),
            'chat_send_label' => sanitize_text_field($input['chat_send_label'] ?? $defaults['chat_send_label']),
            'chat_send_failed_notice' => sanitize_text_field($input['chat_send_failed_notice'] ?? $defaults['chat_send_failed_notice']),
            'chat_send_overload_notice' => sanitize_text_field($input['chat_send_overload_notice'] ?? $defaults['chat_send_overload_notice']),
            'contact_form_id' => sanitize_key((string) ($input['contact_form_id'] ?? $defaults['contact_form_id'])),
            'chat_poll_seconds' => max(3, min(60, absint($input['chat_poll_seconds'] ?? $defaults['chat_poll_seconds']))),
            'chat_reset_minutes' => max(0, min(525600, absint($input['chat_reset_minutes'] ?? $defaults['chat_reset_minutes']))),
            'chat_send_retry_max_attempts' => max(1, min(10, absint($input['chat_send_retry_max_attempts'] ?? $defaults['chat_send_retry_max_attempts']))),
            'chat_send_retry_wait_ms' => max(0, min(60000, absint($input['chat_send_retry_wait_ms'] ?? $defaults['chat_send_retry_wait_ms']))),
            'chat_send_timeout_ms' => max(1000, min(120000, absint($input['chat_send_timeout_ms'] ?? $defaults['chat_send_timeout_ms']))),
            'chat_rate_limit_enabled' => !empty($input['chat_rate_limit_enabled']),
            'chat_rate_limit_window_seconds' => max(10, min(3600, absint($input['chat_rate_limit_window_seconds'] ?? $defaults['chat_rate_limit_window_seconds']))),
            'chat_rate_limit_max_send' => max(1, min(120, absint($input['chat_rate_limit_max_send'] ?? $defaults['chat_rate_limit_max_send']))),
            'chat_rate_limit_max_fetch' => max(1, min(360, absint($input['chat_rate_limit_max_fetch'] ?? $defaults['chat_rate_limit_max_fetch']))),
            'chat_rate_limit_max_booking_event' => max(1, min(120, absint($input['chat_rate_limit_max_booking_event'] ?? $defaults['chat_rate_limit_max_booking_event']))),
            'ai_enabled' => !empty($input['ai_enabled']),
            'eu_ai_act_enabled' => !empty($input['eu_ai_act_enabled']),
            'ai_debug_enabled' => !empty($input['ai_debug_enabled']),
            'live_debug_enabled' => !empty($input['live_debug_enabled']),
            'live_debug_public_enabled' => !empty($input['live_debug_public_enabled']),
            'ai_api_key' => sanitize_text_field($input['ai_api_key'] ?? $defaults['ai_api_key']),
            'ai_api_endpoint' => $this->sanitize_ai_endpoint((string) ($input['ai_api_endpoint'] ?? $defaults['ai_api_endpoint'])),
            'ai_model' => sanitize_text_field($input['ai_model'] ?? $defaults['ai_model']),
            'ai_system_prompt' => sanitize_textarea_field($input['ai_system_prompt'] ?? $defaults['ai_system_prompt']),
            'eu_ai_act_booking_question' => sanitize_textarea_field($input['eu_ai_act_booking_question'] ?? $defaults['eu_ai_act_booking_question']),
            'eu_ai_act_booking_trigger_answer' => sanitize_text_field($input['eu_ai_act_booking_trigger_answer'] ?? $defaults['eu_ai_act_booking_trigger_answer']),
            'eu_ai_act_booking_retry_prompt' => sanitize_textarea_field($input['eu_ai_act_booking_retry_prompt'] ?? $defaults['eu_ai_act_booking_retry_prompt']),
            'eu_ai_act_contact_question' => sanitize_textarea_field($input['eu_ai_act_contact_question'] ?? $defaults['eu_ai_act_contact_question']),
            'eu_ai_act_contact_trigger_answer' => sanitize_text_field($input['eu_ai_act_contact_trigger_answer'] ?? $defaults['eu_ai_act_contact_trigger_answer']),
            'eu_ai_act_contact_retry_prompt' => sanitize_textarea_field($input['eu_ai_act_contact_retry_prompt'] ?? $defaults['eu_ai_act_contact_retry_prompt']),
            'ai_max_response_chars' => max(
                Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_MIN,
                min(
                    Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_MAX,
                    absint($input['ai_max_response_chars'] ?? $defaults['ai_max_response_chars'])
                )
            ),
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

    protected function get_active_channels(array $options): array {
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

    protected function should_render(array $options): bool {
        if (empty($options['enabled'])) {
            return false;
        }

        if (!empty($options['disable_during_maintenance']) && $this->is_lightstart_available() && $this->is_lightstart_maintenance_active()) {
            return false;
        }

        return count($this->get_active_channels($options)) > 0 || !empty($options['own_chat_enabled']);
    }

    /**
     * @return array<int,array<string,string>>
     */
    protected function get_available_contact_forms(): array {
        $forms = get_option('restatify_forms_config', []);
        if (!is_array($forms)) {
            return [];
        }

        $items = [];
        foreach ($forms as $form) {
            if (!is_array($form)) {
                continue;
            }

            $id = sanitize_key((string) ($form['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $title = trim((string) ($form['title'] ?? ''));
            $trigger = trim((string) ($form['trigger'] ?? ''));
            if ($trigger === '') {
                $trigger = '#restatify-form-' . $id;
            }

            $items[] = [
                'id' => $id,
                'title' => $title !== '' ? $title : $id,
                'trigger' => $trigger,
            ];
        }

        return $items;
    }

    /**
     * Return true only when LightStart exists and maintenance status is active.
     */
    protected function is_lightstart_maintenance_active(): bool {
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
    protected function is_lightstart_available(): bool {
        if (class_exists('\\Restatify\\Shared\\Runtime\\PluginState', false)) {
            return \Restatify\Shared\Runtime\PluginState::isLightstartAvailable();
        }

        if (!file_exists(WP_PLUGIN_DIR . '/wp-maintenance-mode/wp-maintenance-mode.php')) {
            return false;
        }

        $active_plugins = (array) get_option('active_plugins', []);
        $network_plugins = is_multisite() ? (array) get_site_option('active_sitewide_plugins', []) : [];

        return in_array('wp-maintenance-mode/wp-maintenance-mode.php', $active_plugins, true)
            || isset($network_plugins['wp-maintenance-mode/wp-maintenance-mode.php']);
    }

    protected function get_options(bool $apply_translations = true): array {
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

            if (class_exists('\\Restatify\\Shared\\I18n\\PolylangAdapter', false)) {
                $translated = \Restatify\Shared\I18n\PolylangAdapter::translate($value);
            } elseif (function_exists('pll__')) {
                $translated = pll__($value);
            } else {
                $translated = $value;
            }
            if (is_string($translated) && $translated !== '') {
                $options[$key] = $translated;
            }
        }

        return $options;
    }

    protected function get_raw_options(): array {
        $this->ensure_legacy_options_migrated();

        $saved = get_option(Restatify_Ai_Multichat_Plugin::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        return wp_parse_args($saved, $this->get_default_options());
    }

    protected function get_default_options(): array {
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
            'privacy_policy_url' => function_exists('get_privacy_policy_url') ? (string) get_privacy_policy_url() : '',
            'delay_seconds' => 6,
            'own_chat_enabled' => false,
            'support_email' => get_option('admin_email', ''),
            'support_notify_on_message' => true,
            'chat_title' => __('Schreibe uns direkt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_ai_legal_notice' => Restatify_Ai_Multichat_Plugin::CHAT_AI_LEGAL_NOTICE_TEXT,
            'chat_placeholder' => __('Nachricht hier eingeben...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_send_label' => __('Senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_send_failed_notice' => __('Nora scheint verhindert zu sein. Wir haben einen Mitarbeiter zusaetzlich wegen Ihres Anliegens kontaktiert.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'chat_send_overload_notice' => __('Nora ist gerade stark ausgelastet. Bitte versuche es in wenigen Augenblicken erneut.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'contact_form_id' => '',
            'chat_poll_seconds' => 8,
            'chat_reset_minutes' => 15,
            'chat_send_retry_max_attempts' => 3,
            'chat_send_retry_wait_ms' => 500,
            'chat_send_timeout_ms' => 20000,
            'chat_rate_limit_enabled' => true,
            'chat_rate_limit_window_seconds' => 60,
            'chat_rate_limit_max_send' => 25,
            'chat_rate_limit_max_fetch' => 120,
            'chat_rate_limit_max_booking_event' => 30,
            'ai_enabled' => false,
            'eu_ai_act_enabled' => false,
            'ai_debug_enabled' => false,
            'live_debug_enabled' => false,
            'live_debug_public_enabled' => false,
            'ai_api_key' => '',
            'ai_api_endpoint' => Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT,
            'ai_model' => 'gpt-4o-mini',
            'ai_system_prompt' => __('Du bist ein hilfreicher Support-Assistent für diese Website. Antworte kurz und freundlich in derselben Sprache wie der Nutzer.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            'eu_ai_act_booking_question' => Restatify_Ai_Multichat_Plugin::EU_AI_ACT_BOOKING_QUESTION_TEXT,
            'eu_ai_act_booking_trigger_answer' => 'Ja',
            'eu_ai_act_booking_retry_prompt' => Restatify_Ai_Multichat_Plugin::EU_AI_ACT_RETRY_PROMPT_TEXT,
            'eu_ai_act_contact_question' => Restatify_Ai_Multichat_Plugin::EU_AI_ACT_CONTACT_QUESTION_TEXT,
            'eu_ai_act_contact_trigger_answer' => 'Ja',
            'eu_ai_act_contact_retry_prompt' => Restatify_Ai_Multichat_Plugin::EU_AI_ACT_RETRY_PROMPT_TEXT,
            'ai_max_response_chars' => Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_DEFAULT,
            'channels' => [],
        ];

        foreach (Restatify_Ai_Multichat_Plugin::CHANNELS as $key => $meta) {
            $defaults['channels'][$key] = '';
        }

        return $defaults;
    }

    protected function sanitize_channel_url(string $url): string {
        return esc_url_raw($url, $this->get_allowed_url_protocols());
    }

    protected function sanitize_ai_endpoint(string $endpoint): string {
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

    protected function get_allowed_url_protocols(): array {
        $protocols = wp_allowed_protocols();
        $extra = ['viber', 'tg', 'discord', 'signal', 'threema'];
        return array_values(array_unique(array_merge($protocols, $extra)));
    }

    protected function get_contrast_text_color(string $hex): string {
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

    protected function get_palette_colors(): array {
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

    protected function sanitize_cookie_match_list(string $value): string {
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

    protected function ensure_legacy_options_migrated(): void {
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
}

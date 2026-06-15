<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles admin UI, dashboard widgets and frontend rendering hooks.
 */
class Restatify_Ai_Multichat_Admin_Runtime extends Restatify_Ai_Multichat_Chat_Runtime {
public function enqueue_support_inbox_assets(): void {
        $page = sanitize_key(wp_unslash($_GET['page'] ?? ''));
        if ($page !== 'restatify-mco-support-inbox') {
            return;
        }

        $base_url = RESTATIFY_AI_MULTICHAT_PLUGIN_URL . 'assets/';
        $base_path = RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'assets/';

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

        // API-Konfiguration für Desktop-App
        $api_endpoint = get_option('restatify_support_api_endpoint', 'http://127.0.0.1:8089');
        $api_keys_raw = get_option('restatify_support_api_keys', []);
        $api_keys = is_array($api_keys_raw) ? $api_keys_raw : [];

        echo '<div style="background:#f0f6fc;border:1px solid #c2d8f0;border-radius:6px;padding:16px 20px;margin:16px 0 24px;">';
        echo '<h2 style="margin:0 0 10px;">' . esc_html__('Desktop-App Konfiguration', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h2>';
        echo '<p style="margin:0 0 12px;color:#3c434a;">' . esc_html__('Diese Zugangsdaten in der Support-Chat-App unter Einstellungen eintragen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
        echo '<table class="form-table" style="margin:0;">';
        echo '<tr><th style="padding:4px 20px 4px 0;white-space:nowrap;">' . esc_html__('API-Endpunkt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
        echo '<td><code style="font-size:13px;">' . esc_html($api_endpoint) . '</code></td></tr>';
        echo '</table>';

        if (count($api_keys) > 0) {
            echo '<p style="margin:12px 0 6px;font-weight:600;">' . esc_html__('Generierte API-Schlüssel', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
            echo '<table class="widefat" style="max-width:700px;">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Benutzer', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
            echo '<th>' . esc_html__('Erstellt am (UTC)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
            echo '<th>' . esc_html__('API-Schlüssel', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($api_keys as $entry) {
                if (!is_array($entry) || empty($entry['key'])) { continue; }
                echo '<tr>';
                echo '<td>' . esc_html((string)($entry['user_login'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string)($entry['created_at'] ?? '')) . '</td>';
                echo '<td><code style="font-size:11px;">' . esc_html((string)$entry['key']) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="margin:12px 0 0;color:#666;">' . esc_html__('Noch keine API-Schlüssel generiert. In der Desktop-App einloggen und "Zugangsdaten speichern" aktivieren.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
        }
        echo '</div>';

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
        require RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'templates/admin-page.inc.php';
    }

    /**
     * Enqueues frontend assets for the overlay and optional live debug panel.
     */
    public function enqueue_assets(): void {
        $options = $this->get_options();
        $can_load_live_debug = !empty($options['live_debug_enabled'])
            && (current_user_can('manage_options') || !empty($options['live_debug_public_enabled']));

        $base_url = RESTATIFY_AI_MULTICHAT_PLUGIN_URL . 'assets/';
        $base_path = RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'assets/';

        if ($can_load_live_debug) {
            wp_enqueue_style(
                'restatify-multi-chat-overlay-live-debug',
                $base_url . 'multi-chat-overlay-live-debug.css',
                [],
                file_exists($base_path . 'multi-chat-overlay-live-debug.css') ? (string) filemtime($base_path . 'multi-chat-overlay-live-debug.css') : '1.0.0'
            );

            wp_enqueue_script(
                'restatify-multi-chat-overlay-live-debug',
                $base_url . 'multi-chat-overlay-live-debug.js',
                [],
                file_exists($base_path . 'multi-chat-overlay-live-debug.js') ? (string) filemtime($base_path . 'multi-chat-overlay-live-debug.js') : '1.0.0',
                true
            );

            wp_localize_script('restatify-multi-chat-overlay-live-debug', 'restatifyMultiChatOverlayLiveDebug', [
                'enabled' => true,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('restatify_mco_chat_nonce'),
                'pollMs' => 2000,
                'strings' => [
                    'debugTitle' => __('Live Debug', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                    'session1Title' => __('Collector Router (Booking/Contact)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                    'session2Title' => __('General Chat Timeline', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                    'logTitle' => __('Aktives Log', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                    'debugWaiting' => __('Warte auf Konversationsdaten...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                ],
            ]);
        }

        if (!$this->should_render($options)) {
            return;
        }

        wp_enqueue_style(
            'restatify-multi-chat-overlay',
            $base_url . 'multi-chat-overlay.css',
            [],
            file_exists($base_path . 'multi-chat-overlay.css') ? (string) filemtime($base_path . 'multi-chat-overlay.css') : '1.0.0'
        );

        wp_enqueue_script(
            'restatify-multi-chat-overlay-markdown',
            $base_url . 'multi-chat-overlay-markdown.js',
            [],
            file_exists($base_path . 'multi-chat-overlay-markdown.js') ? (string) filemtime($base_path . 'multi-chat-overlay-markdown.js') : '1.0.0',
            true
        );

        wp_enqueue_script(
            'restatify-multi-chat-overlay',
            $base_url . 'multi-chat-overlay.js',
            ['restatify-multi-chat-overlay-markdown'],
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
            'chatSendRetryMaxAttempts' => max(1, (int) ($options['chat_send_retry_max_attempts'] ?? 3)),
            'chatSendRetryWaitMs' => max(0, (int) ($options['chat_send_retry_wait_ms'] ?? 500)),
            'chatSendTimeoutMs' => max(1000, (int) ($options['chat_send_timeout_ms'] ?? 20000)),
            'strings' => [
                'sending' => __('Senden...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'aiThinking' => __('Nora denkt...', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'sendFailed' => __('Nachricht konnte nicht gesendet werden. Bitte erneut versuchen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                'sendFailedNotice' => (string) ($options['chat_send_failed_notice'] ?? ''),
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
        require RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'templates/overlay.inc.php';
    }

    private function render_support_inbox(): void {
        $store = $this->get_chat_store();
        $selected_id = sanitize_text_field(wp_unslash($_GET['conversation'] ?? ''));
        $ai_mode_options = $this->get_ai_mode_options();
        require RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'templates/support-inbox.inc.php';

    }

    private function get_support_inbox_capability(): string {
        $capability = apply_filters('restatify_mco_support_inbox_capability', Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY);
        return is_string($capability) && $capability !== '' ? $capability : Restatify_Ai_Multichat_Plugin::SUPPORT_CAPABILITY;
    }
}
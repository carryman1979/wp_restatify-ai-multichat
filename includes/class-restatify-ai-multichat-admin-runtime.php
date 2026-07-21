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

        $api_key_notice = '';
        $api_key_notice_type = '';
        if (
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            && isset($_POST['restatify_mco_api_keys_action'])
        ) {
            $action = sanitize_key(wp_unslash($_POST['restatify_mco_api_keys_action']));
            if ($action === 'delete_all') {
                $nonce = sanitize_text_field(wp_unslash($_POST['restatify_mco_api_keys_nonce'] ?? ''));
                if (!wp_verify_nonce($nonce, 'restatify_mco_api_keys_action')) {
                    $api_key_notice_type = 'error';
                    $api_key_notice = __('Sicherheitsprüfung fehlgeschlagen. Seite neu laden und erneut versuchen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN);
                } else {
                    update_option('restatify_support_api_keys', [], false);
                    $api_key_notice_type = 'success';
                    $api_key_notice = __('Alle aktiven API-Schlüssel wurden gelöscht.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN);
                }
            }
        }

        $options = $this->get_options(false);
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Support Chat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</h1>';

        if ($api_key_notice !== '') {
            echo '<div class="notice notice-' . esc_attr($api_key_notice_type) . ' is-dismissible"><p>' . esc_html($api_key_notice) . '</p></div>';
        }

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
            echo '<details style="margin-top:14px;background:#fff;border:1px solid #d0d7de;border-radius:6px;padding:10px 12px;">';
            echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html(sprintf(
                /* translators: %d = number of active support API keys */
                __('Aktive API-Schlüssel (%d)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
                count($api_keys)
            )) . '</summary>';
            echo '<p style="margin:10px 0 12px;color:#3c434a;">' . esc_html__('Für Notfälle: Nach Passwortwechsel oder Verdacht auf Kompromittierung alle Schlüssel löschen und in den Apps neu anmelden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</p>';
            echo '<form method="post" style="margin:0 0 12px;">';
            echo '<input type="hidden" name="restatify_mco_api_keys_action" value="delete_all" />';
            wp_nonce_field('restatify_mco_api_keys_action', 'restatify_mco_api_keys_nonce');
            echo '<button type="submit" class="button button-secondary" onclick="return window.confirm(' . esc_attr(wp_json_encode(__('Wirklich alle aktiven API-Schlüssel löschen? Alle verbundenen Apps müssen sich danach neu anmelden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN))) . ');">' . esc_html__('Lösche alle', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN) . '</button>';
            echo '</form>';
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
            echo '</details>';
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

        $eu_ai_translation_notice = $this->handle_eu_ai_translation_update();

        $options = $this->get_options(false);
        $eu_ai_translation_page = max(1, absint($_GET['restatify_mco_eu_ai_page'] ?? 1));
        $eu_ai_translation_payload = class_exists('Restatify_Ai_Eu_Ai_Act_Translation_Store', false)
            ? Restatify_Ai_Eu_Ai_Act_Translation_Store::list_entries($eu_ai_translation_page, 10)
            : ['items' => [], 'total' => 0, 'total_pages' => 0, 'current_page' => 1];
        require RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'templates/admin-page.inc.php';
    }

    /**
     * @return array<string,string>
     */
    private function handle_eu_ai_translation_update(): array {
        $empty = ['type' => '', 'message' => ''];
        if (
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
            || !isset($_POST['restatify_mco_eu_ai_translation_action'])
            || !class_exists('Restatify_Ai_Eu_Ai_Act_Translation_Store', false)
        ) {
            return $empty;
        }

        $action = sanitize_key(wp_unslash($_POST['restatify_mco_eu_ai_translation_action'] ?? ''));
        if ($action !== 'update_translation') {
            return $empty;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['restatify_mco_eu_ai_translation_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'restatify_mco_eu_ai_translation_action')) {
            return [
                'type' => 'error',
                'message' => __('Sicherheitsprüfung für die Übersetzungsbearbeitung fehlgeschlagen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
            ];
        }

        $entry_id = absint($_POST['restatify_mco_translation_id'] ?? 0);
        $translated_text = sanitize_textarea_field(wp_unslash($_POST['restatify_mco_translation_value'] ?? ''));
        $ok = Restatify_Ai_Eu_Ai_Act_Translation_Store::update_translation($entry_id, $translated_text);

        return [
            'type' => $ok ? 'success' : 'error',
            'message' => $ok
                ? __('EU-AI-Act-Übersetzung gespeichert.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN)
                : __('EU-AI-Act-Übersetzung konnte nicht gespeichert werden.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN),
        ];
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
            'liveUpdatesWsUrl' => (string) apply_filters('restatify_mco_live_updates_ws_url', ''),
            'chatEnabled' => !empty($options['own_chat_enabled']),
            'euAiActEnabled' => !empty($options['eu_ai_act_enabled']),
            'euAiActBookingQuestion' => (string) ($options['eu_ai_act_booking_question'] ?? ''),
            'euAiActBookingTriggerAnswer' => (string) ($options['eu_ai_act_booking_trigger_answer'] ?? 'Ja'),
            'euAiActBookingRetryPrompt' => (string) ($options['eu_ai_act_booking_retry_prompt'] ?? ''),
            'euAiActContactQuestion' => (string) ($options['eu_ai_act_contact_question'] ?? ''),
            'euAiActContactTriggerAnswer' => (string) ($options['eu_ai_act_contact_trigger_answer'] ?? 'Ja'),
            'euAiActContactRetryPrompt' => (string) ($options['eu_ai_act_contact_retry_prompt'] ?? ''),
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
<?php

if (!defined('ABSPATH')) {
    exit;
}

trait Restatify_MCO_Render_Trait {
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
                'deleteConfirm' => __('Diese Unterhaltung dauerhaft loeschen?', self::TEXT_DOMAIN),
                'genericError' => __('Aktion fehlgeschlagen. Bitte Seite neu laden und erneut versuchen.', self::TEXT_DOMAIN),
                'openBookingAtClient' => __('Ich habe das Buchungstool fuer dich geoeffnet. Bitte waehle einen Termin und bestaetige deine Reservierung.', self::TEXT_DOMAIN),
            ],
        ]);
    }

    public function ensure_support_capability(): void {
        $role = get_role('administrator');
        if (!$role) {
            return;
        }

        if (!$role->has_cap(self::SUPPORT_CAPABILITY)) {
            $role->add_cap(self::SUPPORT_CAPABILITY);
        }
    }

    public function register_support_inbox_page(): void {
        add_menu_page(
            __('Support Chat', self::TEXT_DOMAIN),
            __('Support Chat', self::TEXT_DOMAIN),
            $this->get_support_inbox_capability(),
            'restatify-mco-support-inbox',
            [$this, 'render_support_inbox_page'],
            'dashicons-format-chat',
            58
        );
    }

    public function render_support_inbox_page(): void {
        if (!current_user_can($this->get_support_inbox_capability())) {
            wp_die(esc_html__('Unzureichende Berechtigungen.', self::TEXT_DOMAIN));
        }

        $options = $this->get_options(false);
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Support Chat', self::TEXT_DOMAIN) . '</h1>';

        if (empty($options['own_chat_enabled'])) {
            echo '<p>' . esc_html__('Der integrierte Website-Chat ist derzeit deaktiviert. Aktiviere ihn in den Multi-Chat-Overlay-Einstellungen, um hier Unterhaltungen zu empfangen.', self::TEXT_DOMAIN) . '</p>';
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
            __('Restatify AI Debug', self::TEXT_DOMAIN),
            [$this, 'render_ai_debug_dashboard_widget']
        );
    }

    public function render_ai_debug_dashboard_widget(): void {
        $lines = $this->get_recent_ai_debug_lines(20);
        $settings_link = add_query_arg(
            ['page' => 'restatify-multi-chat-overlay'],
            admin_url('options-general.php')
        );

        if (count($lines) === 0) {
            echo '<p>' . esc_html__('Noch keine KI-Debug-Zeilen vorhanden.', self::TEXT_DOMAIN) . '</p>';
            echo '<p class="description">' . esc_html__('Aktiviere "KI-Debug-Protokollierung" in den Plugin-Einstellungen und sende eine Testnachricht, um dieses Widget zu befuellen.', self::TEXT_DOMAIN) . '</p>';
            echo '<p><a class="button" href="' . esc_url($settings_link) . '">' . esc_html__('Plugin-Einstellungen oeffnen', self::TEXT_DOMAIN) . '</a></p>';
            return;
        }

        echo '<p class="description">' . esc_html__('Aktuelle pluginseitige KI-Diagnose (letzte 20 Eintraege).', self::TEXT_DOMAIN) . '</p>';
        echo '<textarea class="large-text code" rows="10" readonly>' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        echo '<p><a class="button" href="' . esc_url($settings_link) . '">' . esc_html__('Plugin-Einstellungen oeffnen', self::TEXT_DOMAIN) . '</a></p>';
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options(false);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Multi Chat Overlay', self::TEXT_DOMAIN); ?></h1>
            <p><?php esc_html_e('Konfiguriere zuerst das grundlegende Chat-Verhalten. Erweiterte Optionen sind unten in aufklappbaren Expertenbereichen gruppiert.', self::TEXT_DOMAIN); ?></p>
            <?php settings_errors(self::OPTION_KEY); ?>

            <div class="notice notice-info" style="padding:12px 14px; margin: 12px 0 16px;">
                <p><strong><?php esc_html_e('Schnellhilfe', self::TEXT_DOMAIN); ?></strong></p>
                <ol style="margin: 0 0 0 20px;">
                    <li><?php esc_html_e('Aktiviere das Overlay im ersten Abschnitt.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Hinterlege mindestens eine Kanal-URL oder aktiviere den integrierten Website-Chat.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Setze die Support-E-Mail, wenn du E-Mail-Benachrichtigungen fuer neue Nachrichten erhalten moechtest.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Oeffne den Support-Posteingang ueber den Link auf dieser Seite oder ueber Benachrichtigungs-E-Mails.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Optional kannst du die KI-Autoantwort aktivieren und API-Schluessel plus Modell hinterlegen.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Bei mehrsprachigen Seiten mit Polylang: Uebersetze Chat-Texte unter Sprachen > Uebersetzungen in der Gruppe "Restatify Multi Chat Overlay".', self::TEXT_DOMAIN); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('restatify_multi_chat_overlay'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Overlay aktivieren', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Schwebendes Multi-Chat-Overlay im Frontend anzeigen', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Cookie-Einwilligung voraussetzen', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_cookie_consent]" value="1" <?php checked(!empty($options['require_cookie_consent'])); ?>>
                                <?php esc_html_e('Chat nur anzeigen, wenn Besucher-Einwilligung erkannt wurde', self::TEXT_DOMAIN); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Nutze die Cookie-Namen unten und/oder CMP-Signale (Cookiebot, OneTrust), um Einwilligungen zu erkennen.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Regeln fuer Einwilligungs-Cookies', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text code" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[consent_cookie_names]" value="<?php echo esc_attr($options['consent_cookie_names']); ?>">
                            <p class="description"><?php esc_html_e('Kommagetrennte Regeln. Verwende cookie_name oder cookie_name=erwarteter_wert, z.B. cookie_notice_accepted=true,_cky-consent=accept.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Team-Titel', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[team_name]" value="<?php echo esc_attr($options['team_name']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Einleitungsnachricht', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[message]" value="<?php echo esc_attr($options['message']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Ueberschrift fuer Kanaele', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cta_label]" value="<?php echo esc_attr($options['cta_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Beschriftung fuer weitere Kanaele', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[channels_more_label]" value="<?php echo esc_attr($options['channels_more_label']); ?>">
                            <p class="description"><?php esc_html_e('Buttontext zum Anzeigen zusaetzlicher Kanal-Icons.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Beschriftung fuer weniger Kanaele', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[channels_less_label]" value="<?php echo esc_attr($options['channels_less_label']); ?>">
                            <p class="description"><?php esc_html_e('Buttontext zum Einklappen zusaetzlicher Kanal-Icons.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Barrierefreiheits-Label fuer den Button', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[toggle_aria_label]" value="<?php echo esc_attr($options['toggle_aria_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Verzoegerung fuer automatisches Oeffnen (Sekunden)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="0" max="120" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[delay_seconds]" value="<?php echo esc_attr((string) $options['delay_seconds']); ?>">
                            <p class="description"><?php esc_html_e('Nach dieser Verzoegerung oeffnet sich das Panel einmal automatisch. Wenn der Nutzer es schliesst, wird Auto-Open fuer 24 Stunden unterdrueckt.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Website-Chat + Support-Posteingang', self::TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Der Support-Posteingang ist im separaten Admin-Menuepunkt "Support Chat" verfuegbar.', self::TEXT_DOMAIN); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Integrierten Website-Chat aktivieren', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[own_chat_enabled]" value="1" <?php checked(!empty($options['own_chat_enabled'])); ?>>
                                <?php esc_html_e('Ein natives Chatformular direkt im Overlay anzeigen', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Support-E-Mail-Adresse', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="email" required placeholder="support@example.com" name="<?php echo esc_attr(self::OPTION_KEY); ?>[support_email]" value="<?php echo esc_attr($options['support_email']); ?>">
                            <p class="description"><?php esc_html_e('Neue Besuchernachrichten koennen an diese Adresse weitergeleitet werden - inklusive Direktlink zum offenen Chat im Admin.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('E-Mail bei neuer Nachricht senden', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[support_notify_on_message]" value="1" <?php checked(!empty($options['support_notify_on_message'])); ?>>
                                <?php esc_html_e('Fuer jede neue Besuchernachricht eine Support-Benachrichtigungs-E-Mail senden', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat-Titel', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_title]" value="<?php echo esc_attr($options['chat_title']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Platzhalter fuer Chat-Eingabe', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_placeholder]" value="<?php echo esc_attr($options['chat_placeholder']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Beschriftung Senden-Button', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_send_label]" value="<?php echo esc_attr($options['chat_send_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Aktualisierungsintervall (Sekunden)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="3" max="60" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_poll_seconds]" value="<?php echo esc_attr((string) $options['chat_poll_seconds']); ?>">
                            <p class="description"><?php esc_html_e('Wie oft der Chat nach neuen Support-Antworten sucht.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat zuruecksetzen nach (Minuten)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="0" max="525600" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_reset_minutes]" value="<?php echo esc_attr((string) $options['chat_reset_minutes']); ?>">
                            <p class="description"><?php esc_html_e('Bei 0 wird der Chatverlauf nie automatisch zurueckgesetzt. Andernfalls wird der Besucherchat nach dieser Inaktivitaetszeit zurueckgesetzt (empfohlen fuer Support: 15).', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <details style="margin:12px 0 16px;">
                    <summary><strong><?php esc_html_e('Experteneinstellungen: Optionale KI-Autoantwort', self::TEXT_DOMAIN); ?></strong></summary>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('KI-Autoantwort aktivieren', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_enabled]" value="1" <?php checked(!empty($options['ai_enabled'])); ?>>
                                <?php esc_html_e('Automatische Erstantwort fuer eingehende Besuchernachrichten erzeugen', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('KI-Debug-Protokollierung aktivieren', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_debug_enabled]" value="1" <?php checked(!empty($options['ai_debug_enabled'])); ?>>
                                <?php esc_html_e('Diagnose fuer Provider-Request/Response ins PHP-Error-Log schreiben (ohne vollstaendigen API-Schluessel).', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API key', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_api_key]" value="<?php echo esc_attr($options['ai_api_key']); ?>" placeholder="sk-...">
                            <p class="description"><?php esc_html_e('Wird in den Plugin-Optionen gespeichert. Bitte nur einen eingeschraenkten Schluessel verwenden.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API-Endpunkt', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text code" type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_api_endpoint]" value="<?php echo esc_attr($options['ai_api_endpoint']); ?>" placeholder="<?php echo esc_attr(self::DEFAULT_AI_ENDPOINT); ?>">
                            <p class="description"><?php esc_html_e('HTTPS-Endpunkt fuer KI-Anfragen. Der Anbieter wird aus der URL automatisch erkannt (OpenAI, Gemini, Mistral, DeepSeek, Llama/Ollama). Wenn leer oder ungueltig, wird der OpenAI-Standardendpunkt verwendet.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Modell', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_model]" value="<?php echo esc_attr($options['ai_model']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('System-Prompt', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_system_prompt]"><?php echo esc_textarea($options['ai_system_prompt']); ?></textarea>
                        </td>
                    </tr>
                </table>
                </details>

                <?php
                $debug_lines = $this->get_recent_ai_debug_lines(40);
                if (count($debug_lines) > 0) :
                    ?>
                    <h3><?php esc_html_e('Aktuelle KI-Debug-Zeilen', self::TEXT_DOMAIN); ?></h3>
                    <p class="description"><?php esc_html_e('Neueste Eintraege aus der pluginseitigen KI-Diagnose. Fuer vollstaendige Laufzeit-Logs bitte auch das PHP-Error-Log pruefen.', self::TEXT_DOMAIN); ?></p>
                    <textarea class="large-text code" rows="10" readonly><?php echo esc_textarea(implode("\n", $debug_lines)); ?></textarea>
                <?php endif; ?>

                <details style="margin:12px 0 16px;">
                    <summary><strong><?php esc_html_e('Experteneinstellungen: Chat-Kanaele', self::TEXT_DOMAIN); ?></strong></summary>
                <p><?php esc_html_e('Nur Kanaele mit URL werden im Overlay angezeigt.', self::TEXT_DOMAIN); ?></p>
                <table class="form-table" role="presentation">
                    <?php foreach (self::CHANNELS as $key => $meta) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($meta['label']); ?></th>
                            <td>
                                <input
                                    class="regular-text code"
                                    type="url"
                                    name="<?php echo esc_attr(self::OPTION_KEY); ?>[channels][<?php echo esc_attr($key); ?>]"
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
                'sending' => __('Senden...', self::TEXT_DOMAIN),
                'sendFailed' => __('Nachricht konnte nicht gesendet werden. Bitte erneut versuchen.', self::TEXT_DOMAIN),
                'emptyMessage' => __('Bitte gib zuerst eine Nachricht ein.', self::TEXT_DOMAIN),
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
                            aria-label="<?php esc_attr_e('Chatfenster vergroessern', self::TEXT_DOMAIN); ?>"
                            title="<?php esc_attr_e('Vergroessern', self::TEXT_DOMAIN); ?>"
                        >
                            +
                        </button>
                        <button type="button" class="restatify-mco__close" data-mco-close aria-label="<?php esc_attr_e('Chatfenster schliessen', self::TEXT_DOMAIN); ?>" title="<?php esc_attr_e('Schliessen', self::TEXT_DOMAIN); ?>">&times;</button>
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
                                    <?php esc_html_e('Dieses Feld leer lassen', self::TEXT_DOMAIN); ?>
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

        echo '<h2>' . esc_html__('Support-Posteingang', self::TEXT_DOMAIN) . '</h2>';
        echo '<p>' . esc_html__('Offene Unterhaltungen von Website-Besuchern. Klicke auf eine Unterhaltung, um sie zu pruefen und zu antworten.', self::TEXT_DOMAIN) . '</p>';

        echo '<div style="display:flex; gap:8px; align-items:center; margin:10px 0 14px;">';
        echo '<strong style="margin-right:4px;">' . esc_html__('Filter:', self::TEXT_DOMAIN) . '</strong>';
        echo '<button type="button" class="button button-primary" data-mco-conversation-filter="all">' . esc_html__('Alle', self::TEXT_DOMAIN) . '</button>';
        echo '<button type="button" class="button" data-mco-conversation-filter="confirmed">' . esc_html__('Buchung bestaetigt', self::TEXT_DOMAIN) . '</button>';
        echo '<button type="button" class="button" data-mco-conversation-filter="cancelled">' . esc_html__('Buchung abgebrochen', self::TEXT_DOMAIN) . '</button>';
        echo '<button type="button" class="button" data-mco-conversation-filter="system">' . esc_html__('Systemereignisse', self::TEXT_DOMAIN) . '</button>';
        echo '</div>';

        if (count($store) === 0) {
            echo '<p>' . esc_html__('Noch keine Unterhaltungen vorhanden.', self::TEXT_DOMAIN) . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Unterhaltung', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Aktualisiert (UTC)', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('KI-Modus', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Vorschau', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Aktion', self::TEXT_DOMAIN) . '</th>';
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
                    $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#e7f6ea; color:#116329; font-size:11px; font-weight:600;">' . esc_html__('Buchung bestaetigt', self::TEXT_DOMAIN) . '</span>';
                    break;
                }

                if ($raw !== '' && str_contains($raw, RESTATIFY_BOOKING_CANCELLED_TOKEN)) {
                    $conversation_state = 'cancelled';
                    $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#fdecec; color:#8a1f1f; font-size:11px; font-weight:600;">' . esc_html__('Buchung abgebrochen', self::TEXT_DOMAIN) . '</span>';
                    break;
                }

                if ($sender === 'system') {
                    $conversation_state = 'system';
                    $conversation_badge = '<span style="display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2f6; color:#344054; font-size:11px; font-weight:600;">' . esc_html__('Systemereignis', self::TEXT_DOMAIN) . '</span>';
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
            echo '<a class="button" href="' . esc_url($open_link) . '">' . esc_html__('Oeffnen', self::TEXT_DOMAIN) . '</a> ';
            echo '<button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr((string) $id) . '">' . esc_html__('Loeschen', self::TEXT_DOMAIN) . '</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($selected_id !== '' && !empty($store[$selected_id])) {
            $selected = $store[$selected_id];
            $selected_ai_mode = $this->normalize_ai_mode((string) ($selected['ai_mode'] ?? 'visitor'));
            $booking_overlay_available = function_exists('restatify_booking_ai_handle_message') || shortcode_exists('restatify_booking_popup');
            echo '<div id="restatify-mco-conversation-detail" data-mco-conversation-detail="' . esc_attr($selected_id) . '">';
            echo '<h3 style="margin-top:20px;">' . esc_html__('Unterhaltungsdetails', self::TEXT_DOMAIN) . '</h3>';
            echo '<p><label for="restatify-mco-ai-mode"><strong>' . esc_html__('KI-Verhalten fuer diesen Chat', self::TEXT_DOMAIN) . '</strong></label></p>';
            echo '<p>';
            echo '<select id="restatify-mco-ai-mode" data-mco-ai-mode style="min-width: 280px;">';
            foreach ($ai_mode_options as $mode_key => $mode_label) {
                echo '<option value="' . esc_attr((string) $mode_key) . '"' . selected($selected_ai_mode, (string) $mode_key, false) . '>' . esc_html((string) $mode_label) . '</option>';
            }
            echo '</select> ';
            echo '<button type="button" class="button" data-mco-support-ai-save data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('KI-Modus speichern', self::TEXT_DOMAIN) . '</button>';
            echo '</p>';
            echo '<div style="max-height:360px; overflow:auto; border:1px solid #ccd0d4; border-radius:6px; padding:12px; background:#fff;">';
            foreach ((array) ($selected['messages'] ?? []) as $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $sender = (string) ($msg['sender'] ?? 'visitor');
                $label = $sender === 'support'
                    ? __('Support', self::TEXT_DOMAIN)
                    : ($sender === 'ai'
                        ? __('AI', self::TEXT_DOMAIN)
                        : ($sender === 'system' ? __('System', self::TEXT_DOMAIN) : __('Besucher', self::TEXT_DOMAIN)));
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
                    $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#e7f6ea; color:#116329; font-size:11px; font-weight:600;">' . esc_html__('Buchung bestaetigt', self::TEXT_DOMAIN) . '</span>';
                } elseif ($is_booking_cancelled) {
                    $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#fdecec; color:#8a1f1f; font-size:11px; font-weight:600;">' . esc_html__('Buchung abgebrochen', self::TEXT_DOMAIN) . '</span>';
                } elseif ($sender === 'system') {
                    $badge_html = '<span style="display:inline-block; margin-right:8px; padding:2px 8px; border-radius:999px; background:#eef2f6; color:#344054; font-size:11px; font-weight:600;">' . esc_html__('Systemereignis', self::TEXT_DOMAIN) . '</span>';
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
                echo '<p><button type="button" class="button" data-mco-support-open-booking data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Buchungs-Overlay beim Besucher oeffnen', self::TEXT_DOMAIN) . '</button></p>';
            }
            echo '<textarea id="restatify-mco-support-reply" class="large-text" rows="3" placeholder="' . esc_attr__('Support-Antwort eingeben...', self::TEXT_DOMAIN) . '"></textarea>';
            echo '<p><button type="button" class="button button-primary" data-mco-support-send data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Support-Antwort senden', self::TEXT_DOMAIN) . '</button></p>';
            echo '<p><button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Diese Unterhaltung loeschen', self::TEXT_DOMAIN) . '</button></p>';
            echo '</div>';
            echo '</div>';
        }

    }

    private function get_support_inbox_capability(): string {
        $capability = apply_filters('restatify_mco_support_inbox_capability', self::SUPPORT_CAPABILITY);
        return is_string($capability) && $capability !== '' ? $capability : self::SUPPORT_CAPABILITY;
    }
}

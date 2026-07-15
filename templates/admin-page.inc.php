<?php
$options = isset($options) && is_array($options) ? $options : [];
$debug_lines = isset($debug_lines) && is_array($debug_lines) ? $debug_lines : [];
$available_contact_forms = $this->get_available_contact_forms();
?>
<div class="wrap">
    <h1><?php esc_html_e('AI Multichat', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h1>
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

        <h2><?php esc_html_e('Allgemeine Einstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h2>
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
            <tr>
                <th scope="row"><?php esc_html_e('Verzoegerung für automatisches Öffnen (Sekunden)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <input class="small-text" type="number" min="0" max="120" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[delay_seconds]" value="<?php echo esc_attr((string) $options['delay_seconds']); ?>">
                    <p class="description"><?php esc_html_e('Nach dieser Verzoegerung oeffnet sich das Panel einmal automatisch. Wenn der Nutzer es schliesst, wird Auto-Open für 24 Stunden unterdrueckt.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Team-Titel', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[team_name]" value="<?php echo esc_attr($options['team_name']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Einleitungsnachricht', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[message]" value="<?php echo esc_attr($options['message']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('URL zur Datenschutzerklärung', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <input class="regular-text code" type="url" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[privacy_policy_url]" value="<?php echo esc_attr((string) ($options['privacy_policy_url'] ?? '')); ?>" placeholder="https://example.com/datenschutz">
                    <p class="description"><?php esc_html_e('Wird im Overlay als Legal-Hinweis verlinkt.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Chat-Kanaele', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h2>
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

        <h2><?php esc_html_e('Website-Chat Einstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h2>
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
                <th scope="row"><?php esc_html_e('Chat-Titel', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_title]" value="<?php echo esc_attr($options['chat_title']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Zusatztext für Datenschutzhinweis', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_ai_legal_notice]" value="<?php echo esc_attr((string) ($options['chat_ai_legal_notice'] ?? '')); ?>">
                    <p class="description"><?php esc_html_e('Dieser Text wird bei aktiver KI direkt im bestehenden Datenschutzhinweis ergänzt. Mit Polylang in der Gruppe "Restatify Multi Chat Overlay" übersetzbar.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Platzhalter für Chat-Eingabe', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_placeholder]" value="<?php echo esc_attr($options['chat_placeholder']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Support-E-Mail-Adresse', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <input class="regular-text" type="email" required placeholder="yourmail@mail.com" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[support_email]" value="<?php echo esc_attr($options['support_email']); ?>">
                    <p class="description"><?php esc_html_e('Neue Besuchernachrichten können an diese Adresse weitergeleitet werden - inklusive Direktlink zum offenen Chat im Admin.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Kontaktformular fuer Nachrichtentyp', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <select name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[contact_form_id]">
                        <option value=""><?php esc_html_e('Kein Formular ausgewaehlt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></option>
                        <?php foreach ($available_contact_forms as $form_item) : ?>
                            <option value="<?php echo esc_attr((string) ($form_item['id'] ?? '')); ?>" <?php selected((string) ($options['contact_form_id'] ?? ''), (string) ($form_item['id'] ?? '')); ?>>
                                <?php echo esc_html((string) ($form_item['title'] ?? '')); ?>
                                <?php echo esc_html(' (' . (string) ($form_item['trigger'] ?? '') . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Wird verwendet, wenn der Chat mit hoher Sicherheit erkennt, dass der Besucher nur eine Nachricht hinterlassen moechte.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('KI-Assistenteneinstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h2>
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
                <th scope="row"><?php esc_html_e('Modell', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_model]" value="<?php echo esc_attr($options['ai_model']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('API-Endpunkt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <input class="regular-text code" type="url" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_api_endpoint]" value="<?php echo esc_attr($options['ai_api_endpoint']); ?>" placeholder="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT); ?>">
                    <p class="description"><?php esc_html_e('HTTPS-Endpunkt für KI-Anfragen. Der Anbieter wird aus der URL automatisch erkannt (OpenAI, Gemini, Mistral, DeepSeek, Llama/Ollama). Wenn leer oder ungültig, wird der OpenAI-Standardendpunkt verwendet.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
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
                <th scope="row"><?php esc_html_e('System-Prompt', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <textarea class="large-text" rows="4" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_system_prompt]"><?php echo esc_textarea($options['ai_system_prompt']); ?></textarea>
                    <p class="description"><?php esc_html_e('Der freie Prompt wird serverseitig um feste Systemanforderungen ergänzt (u.a. Antwortformat und max. Antwortlaenge).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Maximale KI-Antwortlaenge (Zeichen)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                <td>
                    <input class="small-text" type="number" min="<?php echo esc_attr((string) Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_MIN); ?>" max="<?php echo esc_attr((string) Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_MAX); ?>" step="50" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[ai_max_response_chars]" value="<?php echo esc_attr((string) ($options['ai_max_response_chars'] ?? Restatify_Ai_Multichat_Plugin::AI_MAX_RESPONSE_CHARS_DEFAULT)); ?>">
                    <p class="description"><?php esc_html_e('Serverseitig erzwungenes Limit fuer KI-Antworten. Standard: 3000 Zeichen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                </td>
            </tr>
        </table>

        <details style="margin:12px 0 16px;">
            <summary><strong><?php esc_html_e('Experteneinstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></strong></summary>

            <h3><?php esc_html_e('Allgemeine Einstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Überschrift für Kanaele', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[cta_label]" value="<?php echo esc_attr($options['cta_label']); ?>"></td>
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
                    <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[toggle_aria_label]" value="<?php echo esc_attr($options['toggle_aria_label']); ?>"></td>
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
            </table>

            <h3><?php esc_html_e('Website-Chat Einstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Beschriftung Senden-Button', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td><input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_label]" value="<?php echo esc_attr($options['chat_send_label']); ?>"></td>
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
                    <th scope="row"><?php esc_html_e('E-Mail bei neuer Nachricht senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[support_notify_on_message]" value="1" <?php checked(!empty($options['support_notify_on_message'])); ?>>
                            <?php esc_html_e('Fuer jede neue Besuchernachricht eine Support-Benachrichtigungs-E-Mail senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                        </label>
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
                    <td><input class="small-text" type="number" min="10" max="3600" step="10" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_window_seconds]" value="<?php echo esc_attr((string) $options['chat_rate_limit_window_seconds']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Max send-message requests per window', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td><input class="small-text" type="number" min="1" max="120" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_max_send]" value="<?php echo esc_attr((string) $options['chat_rate_limit_max_send']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Max fetch-chat requests per window', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td><input class="small-text" type="number" min="1" max="360" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_max_fetch]" value="<?php echo esc_attr((string) $options['chat_rate_limit_max_fetch']); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Max booking-event requests per window', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <input class="small-text" type="number" min="1" max="120" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_rate_limit_max_booking_event]" value="<?php echo esc_attr((string) $options['chat_rate_limit_max_booking_event']); ?>">
                        <p class="description"><?php esc_html_e('Exceeding limits returns HTTP 429. Adjust fetch limit to match your polling interval and traffic.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e('KI-Assistenteneinstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Hinweistext bei final fehlgeschlagenem Senden', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_failed_notice]" value="<?php echo esc_attr((string) ($options['chat_send_failed_notice'] ?? '')); ?>">
                        <p class="description"><?php esc_html_e('Wird direkt unter der nicht gesendeten Besuchernachricht eingeblendet, wenn alle Wiederholungen fehlschlagen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Hinweistext bei Provider-Ueberlastung (429/503)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <input class="regular-text" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_overload_notice]" value="<?php echo esc_attr((string) ($options['chat_send_overload_notice'] ?? '')); ?>">
                        <p class="description"><?php esc_html_e('Wird angezeigt, wenn der KI-Provider Ueberlast meldet. Beispiel: Modell derzeit stark ausgelastet.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Maximale Sendeversuche', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <input class="small-text" type="number" min="1" max="10" step="1" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_retry_max_attempts]" value="<?php echo esc_attr((string) ($options['chat_send_retry_max_attempts'] ?? 3)); ?>">
                        <p class="description"><?php esc_html_e('Anzahl aller Versuche inklusive Erstversuch (z.B. 3 = Erstversuch + 2 Wiederholungen).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Wartezeit zwischen Versuchen (ms)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td><input class="small-text" type="number" min="0" max="60000" step="50" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_retry_wait_ms]" value="<?php echo esc_attr((string) ($options['chat_send_retry_wait_ms'] ?? 500)); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Request-Timeout pro Versuch (ms)', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td><input class="small-text" type="number" min="1000" max="120000" step="500" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[chat_send_timeout_ms]" value="<?php echo esc_attr((string) ($options['chat_send_timeout_ms'] ?? 20000)); ?>"></td>
                </tr>
            </table>
        </details>

        <details style="margin:12px 0 16px;">
            <summary><strong><?php esc_html_e('Entwicklereinstellungen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></strong></summary>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Regeln für Einwilligungs-Cookies', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <input class="regular-text code" type="text" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[consent_cookie_names]" value="<?php echo esc_attr($options['consent_cookie_names']); ?>">
                        <p class="description"><?php esc_html_e('Kommagetrennte Regeln. Verwende cookie_name oder cookie_name=erwarteter_wert, z.B. cookie_notice_accepted=true,_cky-consent=accept.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
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
                    <th scope="row"><?php esc_html_e('Live-Debug-Overlay aktivieren', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[live_debug_enabled]" value="1" <?php checked(!empty($options['live_debug_enabled'])); ?>>
                            <?php esc_html_e('Zeigt im Frontend links oben ein transparentes Live-Debug-Overlay mit Session-Status und aktivem Log.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(Restatify_Ai_Multichat_Plugin::OPTION_KEY); ?>[live_debug_public_enabled]" value="1" <?php checked(!empty($options['live_debug_public_enabled'])); ?>>
                            <?php esc_html_e('Live-Debug auch für nicht eingeloggte Besucher anzeigen (nur kurzfristig auf Testumgebungen aktivieren).', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <?php $debug_lines = $this->get_recent_ai_debug_lines(40); ?>
            <h3><?php esc_html_e('Aktuelle KI-Debug-Zeilen', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></h3>
            <p class="description"><?php esc_html_e('Neueste Eintraege aus der pluginseitigen KI-Diagnose. Fuer vollständige Laufzeit-Logs bitte auch das PHP-Error-Log prüfen.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></p>
            <textarea class="large-text code" rows="10" readonly><?php echo esc_textarea(implode("\n", $debug_lines)); ?></textarea>
        </details>

        <?php submit_button(); ?>
    </form>
</div>
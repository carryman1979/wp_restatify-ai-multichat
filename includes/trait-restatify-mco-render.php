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
                'deleteConfirm' => __('Delete this conversation permanently?', self::TEXT_DOMAIN),
                'genericError' => __('Action failed. Please refresh and try again.', self::TEXT_DOMAIN),
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
            wp_die(esc_html__('Insufficient permissions.', self::TEXT_DOMAIN));
        }

        $options = $this->get_options(false);
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Support Chat', self::TEXT_DOMAIN) . '</h1>';

        if (empty($options['own_chat_enabled'])) {
            echo '<p>' . esc_html__('Built-in website chat is currently disabled. Enable it in Multi Chat Overlay settings to receive conversations here.', self::TEXT_DOMAIN) . '</p>';
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
            echo '<p>' . esc_html__('No AI debug lines yet.', self::TEXT_DOMAIN) . '</p>';
            echo '<p class="description">' . esc_html__('Enable "AI debug logging" in plugin settings and send a test message to populate this widget.', self::TEXT_DOMAIN) . '</p>';
            echo '<p><a class="button" href="' . esc_url($settings_link) . '">' . esc_html__('Open plugin settings', self::TEXT_DOMAIN) . '</a></p>';
            return;
        }

        echo '<p class="description">' . esc_html__('Recent plugin-side AI diagnostics (latest 20 entries).', self::TEXT_DOMAIN) . '</p>';
        echo '<textarea class="large-text code" rows="10" readonly>' . esc_textarea(implode("\n", $lines)) . '</textarea>';
        echo '<p><a class="button" href="' . esc_url($settings_link) . '">' . esc_html__('Open plugin settings', self::TEXT_DOMAIN) . '</a></p>';
    }

    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->get_options(false);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Multi Chat Overlay', self::TEXT_DOMAIN); ?></h1>
            <p><?php esc_html_e('Configure chat channels, popup delay, and behavior of the floating chat widget.', self::TEXT_DOMAIN); ?></p>

            <div class="notice notice-info" style="padding:12px 14px; margin: 12px 0 16px;">
                <p><strong><?php esc_html_e('Quick help', self::TEXT_DOMAIN); ?></strong></p>
                <ol style="margin: 0 0 0 20px;">
                    <li><?php esc_html_e('Enable the overlay in the first section.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Add at least one channel URL or enable built-in website chat.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Set the support email if you want email notifications for new messages.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Open the support inbox via the link in this page or through notification emails.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('Optionally enable AI auto reply and provide API key + model.', self::TEXT_DOMAIN); ?></li>
                    <li><?php esc_html_e('For multilingual sites with Polylang, translate chat texts under Languages > Translations in group "Restatify Multi Chat Overlay".', self::TEXT_DOMAIN); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('restatify_multi_chat_overlay'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable overlay', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Show floating multi chat overlay on frontend', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Require cookie consent', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[require_cookie_consent]" value="1" <?php checked(!empty($options['require_cookie_consent'])); ?>>
                                <?php esc_html_e('Only show chat when visitor consent is detected', self::TEXT_DOMAIN); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Use cookie names below and/or your CMP signals (Cookiebot, OneTrust) to detect consent.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Consent cookie rules', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text code" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[consent_cookie_names]" value="<?php echo esc_attr($options['consent_cookie_names']); ?>">
                            <p class="description"><?php esc_html_e('Comma-separated rules. Use cookie_name or cookie_name=expected_value, for example: cookie_notice_accepted=true,_cky-consent=accept.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Team title', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[team_name]" value="<?php echo esc_attr($options['team_name']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Intro message', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[message]" value="<?php echo esc_attr($options['message']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Channel heading', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[cta_label]" value="<?php echo esc_attr($options['cta_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('More channels label', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[channels_more_label]" value="<?php echo esc_attr($options['channels_more_label']); ?>">
                            <p class="description"><?php esc_html_e('Button text used to show additional channel icons.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Fewer channels label', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[channels_less_label]" value="<?php echo esc_attr($options['channels_less_label']); ?>">
                            <p class="description"><?php esc_html_e('Button text used to collapse additional channel icons.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Button accessibility label', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[toggle_aria_label]" value="<?php echo esc_attr($options['toggle_aria_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-open delay (seconds)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="0" max="120" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[delay_seconds]" value="<?php echo esc_attr((string) $options['delay_seconds']); ?>">
                            <p class="description"><?php esc_html_e('After this delay, the panel opens automatically once. If user closes it, auto-open is suppressed for 24 hours.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Website chat + support inbox', self::TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Support inbox is available in the separate admin menu item "Support Chat".', self::TEXT_DOMAIN); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable built-in website chat', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[own_chat_enabled]" value="1" <?php checked(!empty($options['own_chat_enabled'])); ?>>
                                <?php esc_html_e('Show a native chat form directly inside the overlay', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Support email address', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="email" name="<?php echo esc_attr(self::OPTION_KEY); ?>[support_email]" value="<?php echo esc_attr($options['support_email']); ?>">
                            <p class="description"><?php esc_html_e('New visitor messages can be forwarded to this address with a direct link to the open chat in admin.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Send email on new message', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[support_notify_on_message]" value="1" <?php checked(!empty($options['support_notify_on_message'])); ?>>
                                <?php esc_html_e('Send a support notification email for each new visitor message', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat title', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_title]" value="<?php echo esc_attr($options['chat_title']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat input placeholder', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_placeholder]" value="<?php echo esc_attr($options['chat_placeholder']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Send button label', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_send_label]" value="<?php echo esc_attr($options['chat_send_label']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Refresh interval (seconds)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="3" max="60" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_poll_seconds]" value="<?php echo esc_attr((string) $options['chat_poll_seconds']); ?>">
                            <p class="description"><?php esc_html_e('How often the chat checks for new support replies.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Reset chat after (minutes)', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="small-text" type="number" min="0" max="525600" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[chat_reset_minutes]" value="<?php echo esc_attr((string) $options['chat_reset_minutes']); ?>">
                            <p class="description"><?php esc_html_e('If set to 0, chat history never auto-resets. Otherwise visitor chat is reset after this idle time (recommended for support: 15).', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Optional AI auto reply', self::TEXT_DOMAIN); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable AI auto reply', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_enabled]" value="1" <?php checked(!empty($options['ai_enabled'])); ?>>
                                <?php esc_html_e('Generate an automatic first-level response for incoming visitor messages', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable AI debug logging', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_debug_enabled]" value="1" <?php checked(!empty($options['ai_debug_enabled'])); ?>>
                                <?php esc_html_e('Write provider request/response diagnostics to PHP error log (without exposing full API key).', self::TEXT_DOMAIN); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API key', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="password" autocomplete="off" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_api_key]" value="<?php echo esc_attr($options['ai_api_key']); ?>">
                            <p class="description"><?php esc_html_e('Stored in plugin options. Use a restricted key only.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API endpoint', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text code" type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_api_endpoint]" value="<?php echo esc_attr($options['ai_api_endpoint']); ?>" placeholder="<?php echo esc_attr(self::DEFAULT_AI_ENDPOINT); ?>">
                            <p class="description"><?php esc_html_e('HTTPS endpoint for AI requests. Provider is auto-detected from URL (OpenAI, Gemini, Mistral, DeepSeek, Llama/Ollama). If empty or invalid, the default OpenAI endpoint is used.', self::TEXT_DOMAIN); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Model', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_model]" value="<?php echo esc_attr($options['ai_model']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('System prompt', self::TEXT_DOMAIN); ?></th>
                        <td>
                            <textarea class="large-text" rows="4" name="<?php echo esc_attr(self::OPTION_KEY); ?>[ai_system_prompt]"><?php echo esc_textarea($options['ai_system_prompt']); ?></textarea>
                        </td>
                    </tr>
                </table>

                <?php
                $debug_lines = $this->get_recent_ai_debug_lines(40);
                if (count($debug_lines) > 0) :
                    ?>
                    <h3><?php esc_html_e('Recent AI debug lines', self::TEXT_DOMAIN); ?></h3>
                    <p class="description"><?php esc_html_e('Newest entries from plugin-side AI diagnostics. For full runtime logs, also check your PHP error log.', self::TEXT_DOMAIN); ?></p>
                    <textarea class="large-text code" rows="10" readonly><?php echo esc_textarea(implode("\n", $debug_lines)); ?></textarea>
                <?php endif; ?>

                <h2><?php esc_html_e('Chat channels', self::TEXT_DOMAIN); ?></h2>
                <p><?php esc_html_e('Only channels with a URL are shown in the overlay.', self::TEXT_DOMAIN); ?></p>
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
                'sending' => __('Sending...', self::TEXT_DOMAIN),
                'sendFailed' => __('Message could not be sent. Please try again.', self::TEXT_DOMAIN),
                'emptyMessage' => __('Please enter a message first.', self::TEXT_DOMAIN),
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
                            aria-label="<?php esc_attr_e('Expand chat panel', self::TEXT_DOMAIN); ?>"
                            title="<?php esc_attr_e('Expand', self::TEXT_DOMAIN); ?>"
                        >
                            +
                        </button>
                        <button type="button" class="restatify-mco__close" data-mco-close aria-label="<?php esc_attr_e('Close chat panel', self::TEXT_DOMAIN); ?>" title="<?php esc_attr_e('Close', self::TEXT_DOMAIN); ?>">&times;</button>
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
                                    <?php esc_html_e('Leave this field empty', self::TEXT_DOMAIN); ?>
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

        echo '<h2>' . esc_html__('Support inbox', self::TEXT_DOMAIN) . '</h2>';
        echo '<p>' . esc_html__('Open conversations from website visitors. Click a conversation to inspect and reply.', self::TEXT_DOMAIN) . '</p>';

        if (count($store) === 0) {
            echo '<p>' . esc_html__('No conversations yet.', self::TEXT_DOMAIN) . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Conversation', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Updated (UTC)', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('AI mode', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Preview', self::TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Action', self::TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($store as $id => $conversation) {
            $messages = (array) ($conversation['messages'] ?? []);
            $last = end($messages);
            $preview = is_array($last) ? (string) ($last['message'] ?? '') : '';
            if (function_exists('mb_substr')) {
                $preview = mb_substr($preview, 0, 100);
            } else {
                $preview = substr($preview, 0, 100);
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

            echo '<tr data-mco-conversation-row="' . esc_attr((string) $id) . '"' . ($is_selected ? ' style="background:#eef6ff"' : '') . '>';
            echo '<td><strong>' . esc_html((string) $id) . '</strong></td>';
            echo '<td>' . esc_html((string) ($conversation['updated_at_gmt'] ?? '')) . '</td>';
            echo '<td>' . esc_html($mode_label) . '</td>';
            echo '<td>' . esc_html($preview) . '</td>';
            echo '<td>';
            echo '<a class="button" href="' . esc_url($open_link) . '">' . esc_html__('Open', self::TEXT_DOMAIN) . '</a> ';
            echo '<button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr((string) $id) . '">' . esc_html__('Delete', self::TEXT_DOMAIN) . '</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($selected_id !== '' && !empty($store[$selected_id])) {
            $selected = $store[$selected_id];
            $selected_ai_mode = $this->normalize_ai_mode((string) ($selected['ai_mode'] ?? 'visitor'));
            echo '<div id="restatify-mco-conversation-detail" data-mco-conversation-detail="' . esc_attr($selected_id) . '">';
            echo '<h3 style="margin-top:20px;">' . esc_html__('Conversation detail', self::TEXT_DOMAIN) . '</h3>';
            echo '<p><label for="restatify-mco-ai-mode"><strong>' . esc_html__('AI behavior for this chat', self::TEXT_DOMAIN) . '</strong></label></p>';
            echo '<p>';
            echo '<select id="restatify-mco-ai-mode" data-mco-ai-mode style="min-width: 280px;">';
            foreach ($ai_mode_options as $mode_key => $mode_label) {
                echo '<option value="' . esc_attr((string) $mode_key) . '"' . selected($selected_ai_mode, (string) $mode_key, false) . '>' . esc_html((string) $mode_label) . '</option>';
            }
            echo '</select> ';
            echo '<button type="button" class="button" data-mco-support-ai-save data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Save AI mode', self::TEXT_DOMAIN) . '</button>';
            echo '</p>';
            echo '<div style="max-height:360px; overflow:auto; border:1px solid #ccd0d4; border-radius:6px; padding:12px; background:#fff;">';
            foreach ((array) ($selected['messages'] ?? []) as $msg) {
                if (!is_array($msg)) {
                    continue;
                }

                $sender = (string) ($msg['sender'] ?? 'visitor');
                $label = $sender === 'support' ? __('Support', self::TEXT_DOMAIN) : ($sender === 'ai' ? __('AI', self::TEXT_DOMAIN) : __('Visitor', self::TEXT_DOMAIN));
                echo '<p style="margin:0 0 10px;">';
                echo '<strong>' . esc_html($label) . ':</strong> ';
                echo esc_html((string) ($msg['message'] ?? ''));
                echo '<br><small>' . esc_html((string) ($msg['time_gmt'] ?? '')) . '</small>';
                echo '</p>';
            }
            echo '</div>';

            echo '<div style="margin-top:14px;">';
            echo '<textarea id="restatify-mco-support-reply" class="large-text" rows="3" placeholder="' . esc_attr__('Type support reply...', self::TEXT_DOMAIN) . '"></textarea>';
            echo '<p><button type="button" class="button button-primary" data-mco-support-send data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Send support reply', self::TEXT_DOMAIN) . '</button></p>';
            echo '<p><button type="button" class="button button-link-delete" data-mco-support-delete data-conversation-id="' . esc_attr($selected_id) . '">' . esc_html__('Delete this conversation', self::TEXT_DOMAIN) . '</button></p>';
            echo '</div>';
            echo '</div>';
        }

    }

    private function get_support_inbox_capability(): string {
        $capability = apply_filters('restatify_mco_support_inbox_capability', self::SUPPORT_CAPABILITY);
        return is_string($capability) && $capability !== '' ? $capability : self::SUPPORT_CAPABILITY;
    }
}

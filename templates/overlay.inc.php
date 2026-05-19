<?php
$options = isset($options) && is_array($options) ? $options : [];
$channels = isset($channels) && is_array($channels) ? $channels : [];
$palette = isset($palette) && is_array($palette) && count($palette) > 0 ? $palette : ['#ff6b00'];
$delay_ms = isset($delay_ms) ? (int) $delay_ms : 0;
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

                    <?php if (!empty($options['privacy_policy_url'])) : ?>
                        <p class="restatify-mco__legal-notice">
                            <?php esc_html_e('Mit der Nutzung dieses Tools stimmst du unseren Datenschutzbestimmungen zu.', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?>
                            <a href="<?php echo esc_url((string) $options['privacy_policy_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Datenschutzerklärung', Restatify_Ai_Multichat_Plugin::TEXT_DOMAIN); ?></a>.
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        </div>
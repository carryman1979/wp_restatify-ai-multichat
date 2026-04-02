<?php
/**
 * Plugin Name: Restatify Multi Chat Overlay
 * Description: Floating multi-channel chat overlay with configurable links, integrated website chat, support inbox and optional AI replies.
 * Version: 1.4.0
 * Author: Restatify
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RESTATIFY_MCO_PLUGIN_FILE')) {
    define('RESTATIFY_MCO_PLUGIN_FILE', __FILE__);
}

if (!defined('RESTATIFY_MCO_PLUGIN_DIR')) {
    define('RESTATIFY_MCO_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('RESTATIFY_MCO_PLUGIN_URL')) {
    define('RESTATIFY_MCO_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once RESTATIFY_MCO_PLUGIN_DIR . 'includes/trait-restatify-mco-options.php';
require_once RESTATIFY_MCO_PLUGIN_DIR . 'includes/trait-restatify-mco-chat.php';
require_once RESTATIFY_MCO_PLUGIN_DIR . 'includes/trait-restatify-mco-ai.php';
require_once RESTATIFY_MCO_PLUGIN_DIR . 'includes/trait-restatify-mco-render.php';

final class Restatify_Multi_Chat_Overlay {
    use Restatify_MCO_Options_Trait;
    use Restatify_MCO_Chat_Trait;
    use Restatify_MCO_AI_Trait;
    use Restatify_MCO_Render_Trait;

    private const OPTION_KEY = 'restatify_multi_chat_overlay_options';
    private const CHAT_STORE_KEY = 'restatify_multi_chat_overlay_conversations';
    private const AI_DEBUG_LOG_KEY = 'restatify_multi_chat_overlay_ai_debug_log';
    private const CHAT_MAX_CONVERSATIONS = 200;
    private const CHAT_MAX_MESSAGES = 80;
    private const AI_DEBUG_MAX_ENTRIES = 120;
    private const DEFAULT_AI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    private const SUPPORT_CAPABILITY = 'restatify_mco_support_chat';
    private const TEXT_DOMAIN = 'restatify-multi-chat-overlay';
    private const POLYLANG_GROUP = 'Restatify Multi Chat Overlay';
    private const TRANSLATABLE_OPTION_KEYS = [
        'team_name',
        'message',
        'cta_label',
        'channels_more_label',
        'channels_less_label',
        'toggle_aria_label',
        'chat_title',
        'chat_placeholder',
        'chat_send_label',
        'ai_system_prompt',
    ];

    private const CHANNELS = [
        'whatsapp' => [
            'label' => 'WhatsApp',
            'icon' => 'socicon-whatsapp',
            'placeholder' => 'https://wa.me/49123456789',
        ],
        'telegram' => [
            'label' => 'Telegram',
            'icon' => 'socicon-telegram',
            'placeholder' => 'https://t.me/yourusername',
        ],
        'messenger' => [
            'label' => 'Messenger',
            'icon' => 'socicon-messenger',
            'placeholder' => 'https://m.me/yourpage',
        ],
        'discord' => [
            'label' => 'Discord',
            'icon' => 'socicon-discord',
            'placeholder' => 'https://discord.gg/yourserver',
        ],
        'signal' => [
            'label' => 'Signal',
            'icon' => 'mobi-mbri-chat',
            'placeholder' => 'https://signal.me/#p/+49123456789',
        ],
        'viber' => [
            'label' => 'Viber',
            'icon' => 'socicon-viber',
            'placeholder' => 'viber://chat?number=%2B49123456789',
        ],
        'threema' => [
            'label' => 'Threema',
            'icon' => 'socicon-threema',
            'placeholder' => 'threema://compose?recipient=ECHOECHO',
        ],
        'wechat' => [
            'label' => 'WeChat',
            'icon' => 'socicon-wechat',
            'placeholder' => 'https://weixin.qq.com/',
        ],
    ];

    public function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'register_polylang_strings']);
        add_action('admin_init', [$this, 'ensure_support_capability']);
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_menu', [$this, 'register_support_inbox_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_support_inbox_assets']);
        add_action('wp_dashboard_setup', [$this, 'register_ai_debug_dashboard_widget']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_overlay'], 120);

        add_action('wp_ajax_restatify_mco_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_nopriv_restatify_mco_send_message', [$this, 'ajax_send_message']);
        add_action('wp_ajax_restatify_mco_fetch_chat', [$this, 'ajax_fetch_chat']);
        add_action('wp_ajax_nopriv_restatify_mco_fetch_chat', [$this, 'ajax_fetch_chat']);
        add_action('wp_ajax_restatify_mco_booking_event', [$this, 'ajax_booking_event']);
        add_action('wp_ajax_nopriv_restatify_mco_booking_event', [$this, 'ajax_booking_event']);
        add_action('wp_ajax_restatify_mco_support_reply', [$this, 'ajax_support_reply']);
        add_action('wp_ajax_restatify_mco_delete_conversation', [$this, 'ajax_delete_conversation']);
        add_action('wp_ajax_restatify_mco_set_ai_mode', [$this, 'ajax_set_ai_mode']);
    }
}

new Restatify_Multi_Chat_Overlay();

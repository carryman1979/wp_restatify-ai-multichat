<?php
/**
 * Plugin Name: Restatify AI Multichat
 * Description: Floating multi-channel chat overlay with configurable links, integrated website chat, support inbox and optional AI replies.
 * Version: 2.0.6
 * Author: Restatify
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RESTATIFY_AI_MULTICHAT_PLUGIN_FILE')) {
    define('RESTATIFY_AI_MULTICHAT_PLUGIN_FILE', __FILE__);
}

if (!defined('RESTATIFY_AI_MULTICHAT_PLUGIN_DIR')) {
    define('RESTATIFY_AI_MULTICHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('RESTATIFY_AI_MULTICHAT_PLUGIN_URL')) {
    define('RESTATIFY_AI_MULTICHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('RESTATIFY_AI_MULTICHAT_SHARED_VERSION')) {
    define('RESTATIFY_AI_MULTICHAT_SHARED_VERSION', '1.0.0');
}

$restatify_multichat_require_first = static function (array $paths): bool {
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && file_exists($path)) {
            require_once $path;
            return true;
        }
    }

    return false;
};

$restatify_multichat_require_first([
    dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/SharedRegistry.php',
    dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/Contracts/BookingChatTokens.php',
    dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/Runtime/PluginState.php',
    dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/Runtime/BootstrapGuard.php',
    dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/Runtime/RateLimiter.php',
    dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/I18n/PolylangAdapter.php',
]);

if (class_exists('\\Restatify\\Shared\\Contracts\\BookingChatTokens', false)) {
    \Restatify\Shared\Contracts\BookingChatTokens::defineGlobalConstants();
}

$restatify_legacy_plugin_basename = 'wp_restatify-multi-chat-overlay/restatify-multi-chat-overlay.php';
$restatify_skip_bootstrap_for_request = false;
if (class_exists('\\Restatify\\Shared\\Runtime\\BootstrapGuard', false)) {
    $restatify_skip_bootstrap_for_request = \Restatify\Shared\Runtime\BootstrapGuard::deactivateLegacyAndMaybeNotify(
        [$restatify_legacy_plugin_basename],
        'restatify_ai_multichat_admin_notice',
        'Legacy plugin wurde automatisch deaktiviert, um Klassenkonflikte mit Restatify AI Multichat zu vermeiden.',
        'restatify-multi-chat-overlay'
    );
}

if ($restatify_skip_bootstrap_for_request) {
    return;
}

$restatify_multichat_shared_component = 'migration_notice_manager';
$restatify_multichat_shared_manager_class = null;

if (class_exists('\\Restatify\\Shared\\SharedRegistry', false)) {
    $registered_payload = \Restatify\Shared\SharedRegistry::get(
        $restatify_multichat_shared_component,
        RESTATIFY_AI_MULTICHAT_SHARED_VERSION
    );

    if (is_array($registered_payload)) {
        $registered_class = (string) ($registered_payload['class'] ?? '');
        if ($registered_class !== '' && class_exists($registered_class, false)) {
            $restatify_multichat_shared_manager_class = $registered_class;
        }
    }

    if ($restatify_multichat_shared_manager_class === null) {
        $restatify_multichat_require_first([
            dirname(__DIR__, 3) . '/wp_restatify-shared/src/php/Migration/MigrationNoticeManager.php',
        ]);

        if (class_exists('\\Restatify\\Shared\\Migration\\MigrationNoticeManager', false)) {
            $restatify_multichat_shared_manager_class = '\\Restatify\\Shared\\Migration\\MigrationNoticeManager';
            \Restatify\Shared\SharedRegistry::register(
                $restatify_multichat_shared_component,
                RESTATIFY_AI_MULTICHAT_SHARED_VERSION,
                ['class' => $restatify_multichat_shared_manager_class]
            );
        }
    }
}

if (
    is_string($restatify_multichat_shared_manager_class)
    && $restatify_multichat_shared_manager_class !== ''
    && class_exists($restatify_multichat_shared_manager_class, false)
    && !class_exists('Restatify_Shared_Migration_Notice_Manager', false)
) {
    class_alias($restatify_multichat_shared_manager_class, 'Restatify_Shared_Migration_Notice_Manager');
}

if (!class_exists('Restatify_Shared_Migration_Notice_Manager', false)) {
    /**
     * Fail-safe: keep plugin activation functional even if optional migration helper
     * file is missing in a partial/corrupted deployment.
     */
    final class Restatify_Shared_Migration_Notice_Manager {
        public static function register(array $config): void {
            unset($config);
        }
    }
}

require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-multichat-options-runtime.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-multichat-chat-runtime.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-multichat-admin-runtime.php';

final class Restatify_Ai_Multichat_Plugin extends Restatify_Ai_Multichat_Admin_Runtime {

    public const SETTINGS_GROUP = 'restatify_ai_multichat';
    public const OPTION_KEY = 'restatify_ai_multichat_options';
    public const LEGACY_OPTION_KEYS = [
        'restatify_multi_chat_overlay_options',
    ];
    public const MIGRATION_STATE_OPTION = 'restatify_ai_multichat_migration_state';
    public const ADMIN_NOTICE_TRANSIENT = 'restatify_ai_multichat_admin_notice';
    public const ADMIN_PAGE_SLUG = 'restatify-ai-multichat';
    public const CHAT_STORE_KEY = 'restatify_ai_multichat_conversations';
    public const AI_DEBUG_LOG_KEY = 'restatify_ai_multichat_debug_log';
    public const CHAT_MAX_CONVERSATIONS = 200;
    public const CHAT_MAX_MESSAGES = 80;
    public const AI_DEBUG_MAX_ENTRIES = 120;
    public const DEFAULT_AI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    public const SUPPORT_CAPABILITY = 'restatify_mco_support_chat';
    public const TEXT_DOMAIN = 'restatify-multi-chat-overlay';
    public const POLYLANG_GROUP = 'Restatify Multi Chat Overlay';
    public const TRANSLATABLE_OPTION_KEYS = [
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

    public const CHANNELS = [
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

        Restatify_Shared_Migration_Notice_Manager::register([
            'state_option_key' => self::MIGRATION_STATE_OPTION,
            'state_show_key' => 'show_notice',
            'page_slug' => self::ADMIN_PAGE_SLUG,
            'legacy_option_keys' => self::LEGACY_OPTION_KEYS,
            'notice_transient_key' => self::ADMIN_NOTICE_TRANSIENT,
            'action_query_arg' => 'restatify_ai_multichat_migration_notice_action',
            'nonce_query_arg' => 'restatify_ai_multichat_migration_notice_nonce',
            'nonce_action' => 'restatify_ai_multichat_migration_notice',
            'title_de' => 'Restatify AI Multi-Chat 2.0: Migration abgeschlossen',
            'title_en' => 'Restatify AI Multi-Chat 2.0: Migration completed',
            'body_de' => 'Ihre Einstellungen wurden aus der Legacy-Konfiguration uebernommen. Standard ist: Legacy-Einstellungen vorerst behalten.',
            'body_en' => 'Your settings were migrated from the legacy configuration. Default is to keep legacy settings for now.',
            'warning_de' => 'Hinweis: Chatverlauf und Debug-Logs wurden bewusst nicht migriert.',
            'warning_en' => 'Note: chat history and debug logs were intentionally not migrated.',
            'keep_label_de' => 'Legacy-Einstellungen behalten (Standard)',
            'keep_label_en' => 'Keep legacy settings (default)',
            'remove_label_de' => 'Legacy-Einstellungen entfernen',
            'remove_label_en' => 'Remove legacy settings',
            'success_keep_de' => 'Legacy-Einstellungen wurden zur Sicherheit beibehalten. Sie koennen diese spaeter entfernen.',
            'success_keep_en' => 'Legacy settings were kept for safety. You can remove them later.',
            'success_remove_de' => 'Legacy-Einstellungen wurden entfernt. Die aktuellen Restatify AI Multi-Chat Einstellungen bleiben aktiv.',
            'success_remove_en' => 'Legacy settings were removed. Current Restatify AI Multi-Chat settings stay active.',
        ]);

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

if (!class_exists('Restatify_Multi_Chat_Overlay', false)) {
    class_alias('Restatify_Ai_Multichat_Plugin', 'Restatify_Multi_Chat_Overlay');
}

new Restatify_Ai_Multichat_Plugin();

<?php
/**
 * Plugin Name: Restatify AI Multichat
 * Description: Floating multi-channel chat overlay with configurable links, integrated website chat, support inbox and optional AI replies.
 * Version: 2.0.11
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
    define('RESTATIFY_AI_MULTICHAT_SHARED_VERSION', '1.0.2');
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

$restatify_multichat_local_shared_root = dirname(__DIR__, 3) . '/wp_restatify-shared';
$restatify_multichat_use_local_latest_shared = is_dir($restatify_multichat_local_shared_root . '/src/php');
$restatify_multichat_versioned_shared_roots = [];

$restatify_multichat_plugin_shared_root = '';
if (defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR) && WP_PLUGIN_DIR !== '') {
    $restatify_multichat_plugin_shared_root = WP_PLUGIN_DIR . '/wp_restatify-shared';
}

$restatify_multichat_local_versioned_shared_path = rtrim($restatify_multichat_local_shared_root, '/') . '/versions/' . RESTATIFY_AI_MULTICHAT_SHARED_VERSION . '/src/php';
$restatify_multichat_plugin_versioned_shared_path = $restatify_multichat_plugin_shared_root !== ''
    ? rtrim($restatify_multichat_plugin_shared_root, '/') . '/versions/' . RESTATIFY_AI_MULTICHAT_SHARED_VERSION . '/src/php'
    : '';

$restatify_multichat_duplicate_shared_roots = is_dir($restatify_multichat_local_versioned_shared_path)
    && $restatify_multichat_plugin_versioned_shared_path !== ''
    && is_dir($restatify_multichat_plugin_versioned_shared_path);
if (!$restatify_multichat_use_local_latest_shared) {
    if (defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR) && WP_PLUGIN_DIR !== '') {
        $restatify_multichat_versioned_shared_roots[] = WP_PLUGIN_DIR . '/wp_restatify-shared';
    }
    if (defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) && WPMU_PLUGIN_DIR !== '') {
        $restatify_multichat_versioned_shared_roots[] = WPMU_PLUGIN_DIR . '/wp_restatify-shared';
    }
    $restatify_multichat_versioned_shared_roots = array_values(array_unique($restatify_multichat_versioned_shared_roots));
}

$restatify_multichat_shared_candidates = static function (string $relativePath) use (
    $restatify_multichat_use_local_latest_shared,
    $restatify_multichat_local_shared_root,
    $restatify_multichat_versioned_shared_roots
): array {
    $relativePath = ltrim($relativePath, '/');

    if ($restatify_multichat_use_local_latest_shared) {
        return [rtrim($restatify_multichat_local_shared_root, '/') . '/' . $relativePath];
    }

    $paths = [];
    foreach ($restatify_multichat_versioned_shared_roots as $root) {
        $paths[] = rtrim($root, '/') . '/versions/' . RESTATIFY_AI_MULTICHAT_SHARED_VERSION . '/' . $relativePath;
    }

    return array_values(array_unique($paths));
};

$restatify_multichat_require_shared = static function (string $relativePath, string $className = '') use (
    $restatify_multichat_require_first,
    $restatify_multichat_shared_candidates,
    $restatify_multichat_duplicate_shared_roots
): bool {
    $symbolExists = static function (string $symbol): bool {
        if ($symbol === '') {
            return false;
        }

        return class_exists($symbol, false)
            || interface_exists($symbol, false)
            || trait_exists($symbol, false);
    };

    if ($className !== '' && $symbolExists($className)) {
        return true;
    }

    if ($restatify_multichat_duplicate_shared_roots) {
        return $className !== '' ? $symbolExists($className) : false;
    }

    $required = $restatify_multichat_require_first($restatify_multichat_shared_candidates($relativePath));

    if ($className !== '') {
        return $symbolExists($className);
    }

    return $required;
};

$restatify_multichat_require_shared('src/php/SharedRegistry.php', '\\Restatify\\Shared\\SharedRegistry');
$restatify_multichat_require_shared('src/php/Contracts/BookingChatTokens.php', '\\Restatify\\Shared\\Contracts\\BookingChatTokens');
$restatify_multichat_require_shared('src/php/Contracts/BookingPrefillSchema.php', '\\Restatify\\Shared\\Contracts\\BookingPrefillSchema');
if (
    !$restatify_multichat_duplicate_shared_roots
    && !$restatify_multichat_require_shared('src/php/Util/BookingContactMethodsResolver.php', '\\Restatify\\Shared\\Util\\BookingContactMethodsResolver')
) {
    throw new RuntimeException('Missing required shared dependency: wp_restatify-shared/src/php/Util/BookingContactMethodsResolver.php');
}
$restatify_multichat_require_shared('src/php/Util/BookingContactChannelProfiles.php', '\\Restatify\\Shared\\Util\\BookingContactChannelProfiles');
$restatify_multichat_require_shared('src/php/Util/BookingContactChannels.php', '\\Restatify\\Shared\\Util\\BookingContactChannels');
$restatify_multichat_require_shared('src/php/Runtime/PluginState.php', '\\Restatify\\Shared\\Runtime\\PluginState');
$restatify_multichat_require_shared('src/php/Runtime/BootstrapGuard.php', '\\Restatify\\Shared\\Runtime\\BootstrapGuard');
$restatify_multichat_require_shared('src/php/Runtime/RateLimiter.php', '\\Restatify\\Shared\\Runtime\\RateLimiter');
$restatify_multichat_require_shared('src/php/I18n/PolylangAdapter.php', '\\Restatify\\Shared\\I18n\\PolylangAdapter');

if (
    !$restatify_multichat_duplicate_shared_roots
    && !$restatify_multichat_require_shared('src/php/Util/PrivacyLegalNotice.php', '\\Restatify\\Shared\\Util\\PrivacyLegalNotice')
) {
    throw new RuntimeException('Missing required shared dependency: wp_restatify-shared/src/php/Util/PrivacyLegalNotice.php');
}

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
        $restatify_multichat_require_shared('src/php/Migration/MigrationNoticeManager.php', '\\Restatify\\Shared\\Migration\\MigrationNoticeManager');

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
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-language-keyword-store.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-ui-string-store.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-dual-session-router.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-dual-session-state-machine.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-dual-session-slot-manager.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-dual-session-prompts.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-dual-session-cooldown-manager.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-router-debug-logger.php';
require_once RESTATIFY_AI_MULTICHAT_PLUGIN_DIR . 'includes/class-restatify-ai-session1-debug-logger.php';

if (class_exists('Restatify_Ai_Multichat_Plugin', false)) {
    return;
}

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
    public const AI_MAX_RESPONSE_CHARS_MIN = 600;
    public const AI_MAX_RESPONSE_CHARS_MAX = 12000;
    public const AI_MAX_RESPONSE_CHARS_DEFAULT = 3000;
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
        'chat_send_failed_notice',
        'chat_send_overload_notice',
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
        add_action('init', [$this, 'maybe_install_runtime_schema'], 1);
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_polylang_strings'], 20);
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
        add_action('wp_ajax_restatify_ai_run_router_smoke_test', [$this, 'ajax_run_router_smoke_test']);
        add_action('wp_ajax_restatify_ai_run_router_tests', [$this, 'ajax_run_router_tests']);
        add_action('wp_ajax_restatify_ai_get_router_debug_log', [$this, 'ajax_get_router_debug_log']);
        add_action('wp_ajax_restatify_mco_live_debug_status', [$this, 'ajax_live_debug_status']);
        add_action('wp_ajax_nopriv_restatify_mco_live_debug_status', [$this, 'ajax_live_debug_status']);
        add_action('wp_ajax_restatify_mco_booking_event', [$this, 'ajax_booking_event']);
        add_action('wp_ajax_nopriv_restatify_mco_booking_event', [$this, 'ajax_booking_event']);
        add_action('wp_ajax_restatify_mco_support_reply', [$this, 'ajax_support_reply']);
        add_action('wp_ajax_restatify_mco_delete_conversation', [$this, 'ajax_delete_conversation']);
        add_action('wp_ajax_restatify_mco_set_ai_mode', [$this, 'ajax_set_ai_mode']);
    }

    public function maybe_install_runtime_schema(): void {
        if (class_exists('Restatify_Ai_Language_Keyword_Store', false)) {
            Restatify_Ai_Language_Keyword_Store::maybe_install_table();
        }
        if (class_exists('Restatify_Ai_Ui_String_Store', false)) {
            Restatify_Ai_Ui_String_Store::maybe_install_table();
        }
    }

    /**
     * AJAX handler for Router smoke test (quick validation).
     * Admin-only; returns component health status.
     */
    public function ajax_run_router_smoke_test(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $smoke_results = Restatify_Ai_Router_Integration_Test::run_smoke_test();
        wp_send_json_success(['smoke_test' => $smoke_results]);
    }

    /**
     * AJAX handler for running Router end-to-end tests.
     * Admin-only; returns detailed test results with routing decisions.
     */
    public function ajax_run_router_tests(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // Run all test scenarios
        $test_results = Restatify_Ai_Router_Test_Scenarios::run_all_tests();

        // Send response with results
        wp_send_json_success($test_results);
    }

    /**
     * AJAX handler for retrieving Router debug log.
     * Admin-only; returns recent router events and decisions.
     */
    public function ajax_get_router_debug_log(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $type = sanitize_text_field(wp_unslash($_GET['type'] ?? ''));
        $session = sanitize_text_field(wp_unslash($_GET['session'] ?? ''));
        $limit = (int) $_GET['limit'] ?? 100;

        $entries = [];
        if ($session !== '') {
            $entries = Restatify_Ai_Router_Debug_Logger::get_session_log($session, $limit);
        } elseif ($type !== '') {
            $entries = Restatify_Ai_Router_Debug_Logger::get_entries_by_type($type, $limit);
        } else {
            $entries = Restatify_Ai_Router_Debug_Logger::get_recent_entries($limit);
        }

        wp_send_json_success(['entries' => $entries, 'count' => count($entries)]);
    }

    /**
     * AJAX endpoint for frontend live debug overlay data.
     * Available for admins; optionally for anonymous users when explicitly enabled.
     */
    public function ajax_live_debug_status(): void {
        $this->verify_chat_nonce();

        $options = $this->get_options(false);
        if (empty($options['live_debug_enabled'])) {
            wp_send_json_error(['message' => 'Live debug disabled'], 403);
        }

        $has_admin_access = current_user_can('manage_options');
        $has_public_access = !empty($options['live_debug_public_enabled']);
        if (!$has_admin_access && !$has_public_access) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $conversation_id = sanitize_text_field(wp_unslash($_POST['conversation_id'] ?? ''));
        $conversation = null;
        if ($conversation_id !== '') {
            $store = $this->get_chat_store();
            if (!empty($store[$conversation_id]) && is_array($store[$conversation_id])) {
                $conversation = $store[$conversation_id];
            }
        }

        $state_machine = new Restatify_Ai_Dual_Session_State_Machine();
        $state = $conversation_id !== '' ? $state_machine->get_session_state($conversation_id) : [];
        if (!is_array($state)) {
            $state = [];
        }

        $confidence = max(0.0, min(1.0, floatval($state['confidence'] ?? 0.0)));
        $level_percent = (int) round($confidence * 100);

        $current_session = (string) ($state['current_session'] ?? Restatify_Ai_Dual_Session_Router::SESSION_GENERAL_CHAT);

        if (!empty($state['booking_flow_active']) && in_array($current_session, [Restatify_Ai_Dual_Session_Router::SESSION_BOOKING_COLLECTOR, 'session1'], true)) {
            $session1_status = sprintf('Booking-Collector aktiv. Sammle Buchungsdaten. Level %d%%', $level_percent);
        } elseif (!empty($state['contact_flow_active']) && in_array($current_session, [Restatify_Ai_Dual_Session_Router::SESSION_CONTACT_COLLECTOR, 'contact'], true)) {
            $session1_status = sprintf('Contact-Collector aktiv. Sammle Kontaktdaten. Level %d%%', $level_percent);
        } elseif ($confidence >= Restatify_Ai_Dual_Session_Router::CONFIDENCE_CLARIFY_MIN) {
            $session1_status = sprintf('Intent unklar. Stelle Rueckfrage. Level %d%%', $level_percent);
        } else {
            $session1_status = sprintf('General-Chat aktiv. Kein Collector erforderlich. Level %d%%', $level_percent);
        }

        $session1_last_request_at = '-';
        if (is_array($conversation) && !empty($conversation['messages']) && is_array($conversation['messages'])) {
            $messages_for_last_request = array_reverse($conversation['messages']);
            foreach ($messages_for_last_request as $message) {
                if (!is_array($message)) {
                    continue;
                }

                if ((string) ($message['sender'] ?? '') !== 'visitor') {
                    continue;
                }

                $timestamp = trim((string) ($message['time_gmt'] ?? ''));
                $session1_last_request_at = $timestamp !== '' ? $timestamp : '-';
                break;
            }
        }

        // Get collector debug logs (session-scoped when conversation id is available).
        if ($conversation_id !== '') {
            $session1_logs = Restatify_Ai_Session1_Debug_Logger::format_session_log_lines($conversation_id, 20);
        } else {
            $session1_logs = Restatify_Ai_Session1_Debug_Logger::format_as_log_lines(20);
        }
        if (empty($session1_logs)) {
            $session1_logs = ['(keine Booking-Collector Aktivitaet im aktuellen Gespraech)'];
        }

        $language_lock_until = (int) ($state['language_lock_until'] ?? 0);
        $language_last_detected_at = (int) ($state['language_last_detected_at'] ?? 0);
        $language_debug = [
            'code' => (string) ($state['language_code'] ?? ''),
            'candidate' => (string) ($state['language_candidate'] ?? ''),
            'last_detected' => (string) ($state['language_last_detected'] ?? ''),
            'last_confidence' => max(0.0, min(1.0, (float) ($state['language_last_confidence'] ?? 0.0))),
            'switch_votes' => (int) ($state['language_switch_votes'] ?? 0),
            'switch_reason' => (string) ($state['language_switch_reason'] ?? ''),
            'lock_until_gmt' => $language_lock_until > 0 ? gmdate('Y-m-d H:i:s', $language_lock_until) : '-',
            'last_detected_at_gmt' => $language_last_detected_at > 0 ? gmdate('Y-m-d H:i:s', $language_last_detected_at) : '-',
        ];

        $recognized_booking_data = [
            'collected_fields' => is_array($state['collected_fields'] ?? null) ? (array) $state['collected_fields'] : [],
            'partial_prefill' => is_array($state['partial_prefill'] ?? null) ? (array) $state['partial_prefill'] : [],
        ];

        $contact_form_id = sanitize_key((string) ($options['contact_form_id'] ?? ''));
        $contact_collected_fields = is_array($state['contact_collected_fields'] ?? null) ? (array) $state['contact_collected_fields'] : [];
        $recognized_contact_data = [
            'collected_fields' => $contact_collected_fields,
            'partial_prefill' => $contact_collected_fields,
            'contact_form_payload_preview' => $contact_form_id !== '' ? [
                'form_id' => $contact_form_id,
                'trigger' => '#restatify-form-' . $contact_form_id,
                'prefill' => $contact_collected_fields,
            ] : null,
        ];

        $session2_timeline = [];
        if (is_array($conversation) && !empty($conversation['messages']) && is_array($conversation['messages'])) {
            $messages = array_slice($conversation['messages'], -20);
            foreach ($messages as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $sender = (string) ($message['sender'] ?? '');
                $text = trim((string) ($message['message'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $timestamp = (string) ($message['time_gmt'] ?? '');
                if (function_exists('mb_substr')) {
                    $preview = mb_substr($text, 0, 120);
                } else {
                    $preview = substr($text, 0, 120);
                }
                if ((function_exists('mb_strlen') ? mb_strlen($text) : strlen($text)) > 120) {
                    $preview .= '...';
                }

                if ($sender === 'visitor') {
                    $session2_timeline[] = sprintf('%s Anfrage erhalten: %s', $timestamp, $preview);
                } elseif (in_array($sender, ['ai', 'support', 'system'], true)) {
                    $session2_timeline[] = sprintf('%s Antwort gegeben: %s', $timestamp, $preview);
                }
            }
        }
        $session2_timeline = array_slice($session2_timeline, -10);

        if ($conversation_id !== '') {
            $router_entries = Restatify_Ai_Router_Debug_Logger::get_session_log($conversation_id, 20);
        } else {
            $router_entries = Restatify_Ai_Router_Debug_Logger::get_recent_entries(20);
        }
        $last_router_action = '-';
        $last_router_type = '-';
        if (!empty($router_entries)) {
            $last_router_entry = end($router_entries);
            if (is_array($last_router_entry)) {
                $last_router_action = (string) ($last_router_entry['action'] ?? ($last_router_entry['decision'] ?? '-'));
                $last_router_type = (string) ($last_router_entry['type'] ?? 'router');
            }
            reset($router_entries);
        }

        $log_lines = [];
        if (!empty($options['ai_debug_enabled'])) {
            $log_lines = $this->get_recent_ai_debug_lines(30);
        }

        if (count($log_lines) === 0) {
            foreach ($router_entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $time = (string) ($entry['timestamp'] ?? '');
                $type = (string) ($entry['type'] ?? 'router');
                $action = (string) ($entry['action'] ?? ($entry['decision'] ?? ''));
                $msg = (string) ($entry['message_preview'] ?? ($entry['message'] ?? ''));
                $line = trim($time . ' [' . $type . '] ' . $action . ' ' . $msg);
                if ($line !== '') {
                    $log_lines[] = $line;
                }
            }
        }

        wp_send_json_success([
            'session1' => [
                'status_line' => $session1_status,
                'confidence' => $confidence,
                'confidence_percent' => $level_percent,
                'current_session' => $current_session,
                'booking_flow_active' => !empty($state['booking_flow_active']),
                'contact_flow_active' => !empty($state['contact_flow_active']),
                'booking_confidence' => max(0.0, min(1.0, (float) ($state['booking_confidence'] ?? 0.0))),
                'contact_confidence' => max(0.0, min(1.0, (float) ($state['contact_confidence'] ?? 0.0))),
                'general_confidence' => max(0.0, min(1.0, (float) ($state['general_confidence'] ?? 0.0))),
                'clarification_attempts' => (int) ($state['clarification_attempts'] ?? 0),
                'contact_attempt_count' => (int) ($state['contact_attempt_count'] ?? 0),
                'last_contact_field' => (string) ($state['last_contact_field'] ?? ''),
                'last_contact_question' => (string) ($state['last_contact_question'] ?? ''),
                'last_request_at' => $session1_last_request_at,
                'last_router_action' => $last_router_action,
                'last_router_type' => $last_router_type,
                'language' => $language_debug,
                'recognized_booking_data' => $recognized_booking_data,
                'recognized_contact_data' => $recognized_contact_data,
                'debug_logs' => $session1_logs,
            ],
            'session2' => [
                'timeline' => $session2_timeline,
            ],
            'log' => [
                'enabled' => !empty($options['ai_debug_enabled']) || count($log_lines) > 0,
                'lines' => array_slice($log_lines, -30),
            ],
        ]);
    }
}

if (!class_exists('Restatify_Multi_Chat_Overlay', false)) {
    class_alias('Restatify_Ai_Multichat_Plugin', 'Restatify_Multi_Chat_Overlay');
}

new Restatify_Ai_Multichat_Plugin();



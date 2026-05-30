<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('Restatify_Ai_Multichat_Plugin')) {
    final class Restatify_Ai_Multichat_Plugin {
        public const OPTION_KEY = 'restatify_ai_multichat_options';
        public const LEGACY_OPTION_KEYS = [];
        public const DEFAULT_AI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
        public const AI_MAX_RESPONSE_CHARS_MIN = 300;
        public const AI_MAX_RESPONSE_CHARS_MAX = 6000;
        public const AI_MAX_RESPONSE_CHARS_DEFAULT = 3000;
        public const CHAT_MAX_MESSAGES = 100;
        public const AI_DEBUG_LOG_KEY = 'restatify_mco_ai_debug_log';
        public const AI_DEBUG_MAX_ENTRIES = 200;
        public const TEXT_DOMAIN = 'restatify-multi-chat-overlay';
        public const CHANNELS = [
            'whatsapp' => ['label' => 'WhatsApp'],
        ];
    }
}

if (!class_exists('Restatify_Booking_Assistant_Constants')) {
    final class Restatify_Booking_Assistant_Constants {
        public const OPTION_KEY = 'restatify_booking_options';
    }
}

$GLOBALS['restatify_test_options'] = [];
$GLOBALS['restatify_test_transients'] = [];

if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string {
        return $text;
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string {
        return trim($text);
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $text): string {
        return trim($text);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return $value;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, $value) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $tag, ...$args): void {
        return;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql', bool $gmt = false) {
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s');
        }

        return time();
    }
}

if (!function_exists('wp_hash')) {
    function wp_hash(string $data): string {
        return sha1($data);
    }
}

if (!function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = 2147483647): int {
        return random_int($min, $max);
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false) {
        return $GLOBALS['restatify_test_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value, bool $autoload = false): bool {
        $GLOBALS['restatify_test_options'][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool {
        unset($GLOBALS['restatify_test_options'][$key]);
        return true;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool {
        $expires_at = $expiration > 0 ? time() + $expiration : null;
        $GLOBALS['restatify_test_transients'][$key] = [
            'value' => $value,
            'expires_at' => $expires_at,
        ];
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key) {
        if (!isset($GLOBALS['restatify_test_transients'][$key])) {
            return false;
        }

        $entry = $GLOBALS['restatify_test_transients'][$key];
        $expires_at = $entry['expires_at'] ?? null;
        if ($expires_at !== null && $expires_at < time()) {
            unset($GLOBALS['restatify_test_transients'][$key]);
            return false;
        }

        return $entry['value'] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool {
        unset($GLOBALS['restatify_test_transients'][$key]);
        return true;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(string $key, string $value, string $url): string {
        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url, array $allowed_protocols = []): string {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!empty($allowed_protocols) && !in_array($scheme, $allowed_protocols, true)) {
            return '';
        }

        return $url;
    }
}

if (!function_exists('wp_allowed_protocols')) {
    function wp_allowed_protocols(): array {
        return ['http', 'https'];
    }
}

if (!function_exists('restatify_booking_ai_handle_message')) {
    function restatify_booking_ai_handle_message(): void {
        return;
    }
}

$restatify_multichat_test_require_first = static function (array $paths): bool {
    foreach ($paths as $path) {
        if (is_string($path) && $path !== '' && file_exists($path)) {
            require_once $path;
            return true;
        }
    }

    return false;
};

$restatify_multichat_test_shared_version = '1.0.2';
$restatify_multichat_test_root_shared = dirname(__DIR__, 4) . '/wp_restatify-shared';
$restatify_multichat_test_packaged_shared = dirname(__DIR__)
    . '/shared-install/wp_restatify-shared/versions/'
    . $restatify_multichat_test_shared_version;

$restatify_multichat_test_require_first([
    $restatify_multichat_test_root_shared . '/versions/' . $restatify_multichat_test_shared_version . '/src/php/Util/BookingContactMethodsResolver.php',
    $restatify_multichat_test_root_shared . '/src/php/Util/BookingContactMethodsResolver.php',
    $restatify_multichat_test_packaged_shared . '/src/php/Util/BookingContactMethodsResolver.php',
]);

if (!class_exists('\\Restatify\\Shared\\Util\\BookingContactMethodsResolver', false)) {
    final class Restatify_Multichat_Test_BookingContactMethodsResolver {
        public static function methodsFromOptions(array $options): array {
            $channels = isset($options['contact_channels']) && is_array($options['contact_channels'])
                ? $options['contact_channels']
                : [];

            $methods = [];
            foreach ($channels as $channel) {
                if (!is_array($channel)) {
                    continue;
                }

                $key = isset($channel['key']) ? trim((string) $channel['key']) : '';
                if ($key !== '') {
                    $methods[] = $key;
                }
            }

            return array_values(array_unique($methods));
        }
    }

    class_alias('Restatify_Multichat_Test_BookingContactMethodsResolver', '\\Restatify\\Shared\\Util\\BookingContactMethodsResolver');
}

require_once dirname(__DIR__) . '/includes/class-restatify-ai-multichat-options-runtime.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-multichat-chat-runtime.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-dual-session-state-machine.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-dual-session-slot-manager.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-dual-session-cooldown-manager.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-router-debug-logger.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-dual-session-router.php';

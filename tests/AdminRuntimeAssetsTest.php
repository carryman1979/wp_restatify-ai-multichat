<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string {
        unset($action);
        return 'nonce';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool {
        unset($capability);
        return true;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], $ver = false, string $media = 'all'): void {
        $GLOBALS['restatify_test_enqueued_styles'][$handle] = [
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
            'media' => $media,
        ];
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], $ver = false, bool $in_footer = false): void {
        $GLOBALS['restatify_test_enqueued_scripts'][$handle] = [
            'src' => $src,
            'deps' => $deps,
            'ver' => $ver,
            'in_footer' => $in_footer,
        ];
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): void {
        $GLOBALS['restatify_test_localized_scripts'][$handle] = [
            'object_name' => $object_name,
            'l10n' => $l10n,
        ];
    }
}

require_once __DIR__ . '/bootstrap.php';

if (!defined('RESTATIFY_AI_MULTICHAT_PLUGIN_DIR')) {
    define('RESTATIFY_AI_MULTICHAT_PLUGIN_DIR', dirname(__DIR__) . '/');
}

if (!defined('RESTATIFY_AI_MULTICHAT_PLUGIN_URL')) {
    define('RESTATIFY_AI_MULTICHAT_PLUGIN_URL', 'https://example.test/wp-content/plugins/wp_restatify-ai-multichat/');
}

require_once dirname(__DIR__) . '/includes/class-restatify-ai-multichat-admin-runtime.php';

final class AdminRuntimeAssetsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['restatify_test_enqueued_styles'] = [];
        $GLOBALS['restatify_test_enqueued_scripts'] = [];
        $GLOBALS['restatify_test_localized_scripts'] = [];
    }

    public function testEnqueueAssetsAddsMarkdownDependencyForOverlayScript(): void {
        $runtime = new class () extends Restatify_Ai_Multichat_Admin_Runtime {
            protected function get_options(bool $force_reload = false): array {
                return [
                    'live_debug_enabled' => 0,
                    'live_debug_public_enabled' => 0,
                    'own_chat_enabled' => 1,
                    'require_cookie_consent' => 0,
                    'consent_cookie_names' => '',
                    'chat_poll_seconds' => 8,
                    'chat_reset_minutes' => 0,
                    'chat_send_retry_max_attempts' => 3,
                    'chat_send_retry_wait_ms' => 500,
                    'chat_send_timeout_ms' => 20000,
                ];
            }

            protected function should_render(array $options): bool {
                unset($options);
                return true;
            }
        };

        $runtime->enqueue_assets();

        self::assertArrayHasKey('restatify-multi-chat-overlay-markdown', $GLOBALS['restatify_test_enqueued_scripts']);
        self::assertArrayHasKey('restatify-multi-chat-overlay', $GLOBALS['restatify_test_enqueued_scripts']);
        self::assertContains(
            'restatify-multi-chat-overlay-markdown',
            $GLOBALS['restatify_test_enqueued_scripts']['restatify-multi-chat-overlay']['deps']
        );
    }
}

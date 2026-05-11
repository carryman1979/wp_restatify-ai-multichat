<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

if (!class_exists('Restatify_Ai_Multichat_Plugin')) {
    final class Restatify_Ai_Multichat_Plugin {
        public const DEFAULT_AI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
        public const CHANNELS = [
            'whatsapp' => ['label' => 'WhatsApp'],
        ];
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

require_once dirname(__DIR__) . '/includes/class-restatify-ai-multichat-options-runtime.php';
require_once dirname(__DIR__) . '/includes/class-restatify-ai-multichat-chat-runtime.php';

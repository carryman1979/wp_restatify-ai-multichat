<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Debug Logging Service for Dual-Session Router
 * 
 * Logs routing decisions, confidence calculations, state transitions,
 * and key decisions for troubleshooting and monitoring.
 */
class Restatify_Ai_Router_Debug_Logger {

    const DEBUG_LOG_KEY = 'restatify_router_debug_log';
    const DEBUG_LOG_MAX_ENTRIES = 500;

    /**
     * Log a routing decision.
     */
    public static function log_routing_decision(
        string $session_id,
        string $ip_address,
        string $message,
        string $action,
        array $context = []
    ): void {
        $entry = [
            'timestamp' => current_time('mysql', true),
            'timestamp_unix' => time(),
            'session_id' => substr($session_id, 0, 12),
            'ip_address' => $ip_address,
            'message' => $message,
            'action' => $action,
            'context' => $context,
        ];

        self::append_log_entry($entry);
    }

    /**
     * Log confidence calculation.
     */
    public static function log_confidence_calculation(
        string $session_id,
        string $user_message,
        float $confidence,
        string $decision
    ): void {
        $entry = [
            'timestamp' => current_time('mysql', true),
            'type' => 'confidence',
            'session_id' => substr($session_id, 0, 12),
            'message_preview' => substr($user_message, 0, 80),
            'confidence' => round($confidence * 100, 1),
            'decision' => $decision,
        ];

        self::append_log_entry($entry);
    }

    /**
     * Log state transition.
     */
    public static function log_state_transition(
        string $session_id,
        string $from_state,
        string $to_state,
        array $state_data = []
    ): void {
        $entry = [
            'timestamp' => current_time('mysql', true),
            'type' => 'state_transition',
            'session_id' => substr($session_id, 0, 12),
            'from' => $from_state,
            'to' => $to_state,
            'customer_turns' => $state_data['customer_turns'] ?? null,
            'booking_attempts' => $state_data['booking_attempts'] ?? null,
        ];

        self::append_log_entry($entry);
    }

    /**
     * Log IP cooldown event.
     */
    public static function log_cooldown_event(string $ip_address, bool $triggered): void {
        $entry = [
            'timestamp' => current_time('mysql', true),
            'type' => 'cooldown',
            'ip_address' => $ip_address,
            'triggered' => $triggered ? 'yes' : 'no',
        ];

        self::append_log_entry($entry);
    }

    /**
     * Append entry to debug log (rotating).
     */
    private static function append_log_entry(array $entry): void {
        if (!apply_filters('restatify_router_debug_enabled', true)) {
            return;
        }

        $log = get_option(self::DEBUG_LOG_KEY, []);
        if (!is_array($log)) {
            $log = [];
        }

        // Keep log size manageable
        if (count($log) >= self::DEBUG_LOG_MAX_ENTRIES) {
            $log = array_slice($log, -floor(self::DEBUG_LOG_MAX_ENTRIES * 0.8));
        }

        $log[] = $entry;
        update_option(self::DEBUG_LOG_KEY, $log);
    }

    /**
     * Get recent debug log entries.
     */
    public static function get_recent_entries(int $limit = 50): array {
        $log = get_option(self::DEBUG_LOG_KEY, []);
        if (!is_array($log)) {
            return [];
        }

        return array_slice($log, -$limit);
    }

    /**
     * Get debug log entries filtered by type.
     */
    public static function get_entries_by_type(string $type, int $limit = 50): array {
        $entries = self::get_recent_entries($limit * 2);

        return array_filter($entries, function ($entry) use ($type) {
            return ($entry['type'] ?? '') === $type;
        });
    }

    /**
     * Get debug log entries for a specific session.
     */
    public static function get_session_log(string $session_id, int $limit = 100): array {
        $session_short = substr($session_id, 0, 12);
        $entries = self::get_recent_entries($limit * 2);

        return array_filter($entries, function ($entry) use ($session_short) {
            return ($entry['session_id'] ?? '') === $session_short;
        });
    }

    /**
     * Clear debug log.
     */
    public static function clear_log(): void {
        delete_option(self::DEBUG_LOG_KEY);
    }

    /**
     * Format debug log for display.
     */
    public static function format_log_display(array $entries): string {
        if (empty($entries)) {
            return "No log entries found.\n";
        }

        $output = "\n=== Restatify Router Debug Log ===\n\n";

        foreach (array_reverse($entries) as $entry) {
            $timestamp = $entry['timestamp'] ?? 'N/A';
            $type = $entry['type'] ?? 'routing';

            $output .= "[$timestamp] [$type]\n";

            if ($type === 'routing') {
                $output .= "  Action: {$entry['action']}\n";
                $output .= "  Message Preview: {$entry['message']}\n";
                $output .= "  Session: {$entry['session_id']}\n";
            } elseif ($type === 'confidence') {
                $output .= "  Confidence: {$entry['confidence']}%\n";
                $output .= "  Decision: {$entry['decision']}\n";
            } elseif ($type === 'state_transition') {
                $output .= "  {$entry['from']} → {$entry['to']}\n";
            } elseif ($type === 'cooldown') {
                $output .= "  IP: {$entry['ip_address']}\n";
                $output .= "  Triggered: {$entry['triggered']}\n";
            }

            $output .= "\n";
        }

        return $output;
    }
}

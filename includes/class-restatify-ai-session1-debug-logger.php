<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Session 1 Debug Logger — Dedicated logging for booking workflow activation and progression
 * 
 * Tracks Session 1 entry points, user inputs, and extraction results with timestamps.
 * Provides clear visibility into when/why booking workflow is triggered.
 */
class Restatify_Ai_Session1_Debug_Logger {

    const LOG_KEY = 'restatify_session1_debug_log';
    const MAX_ENTRIES = 200;

    /**
     * Log Session 1 entry trigger (fast-track, confidence, etc.)
     */
    public static function log_session1_entry(
        string $session_id,
        string $user_message,
        string $trigger_reason,
        array $context = []
    ): void {
        if (!apply_filters('restatify_router_debug_enabled', true)) {
            return;
        }

        $entry = [
            'timestamp' => current_time('mysql', true),
            'timestamp_unix' => time(),
            'timestamp_short' => self::format_time_short(current_time('mysql', true)),
            'session_id' => substr($session_id, 0, 12),
            'type' => 'entry',
            'user_message' => substr($user_message, 0, 200),
            'trigger_reason' => $trigger_reason, // 'fast_track', 'confidence_98', 'forced_conversion', etc.
            'confidence' => (float) ($context['confidence'] ?? 0.0),
            'context' => $context,
        ];

        self::append_entry($entry);
    }

    /**
     * Log Session 1 data extraction results
     */
    public static function log_session1_extraction(
        string $session_id,
        string $user_message,
        array $extracted_data,
        array $missing_fields = []
    ): void {
        if (!apply_filters('restatify_router_debug_enabled', true)) {
            return;
        }

        $entry = [
            'timestamp' => current_time('mysql', true),
            'timestamp_short' => self::format_time_short(current_time('mysql', true)),
            'session_id' => substr($session_id, 0, 12),
            'type' => 'extraction',
            'user_message_preview' => substr($user_message, 0, 100),
            'extracted' => [
                'name' => !empty($extracted_data['name']) ? '✓' : '✗',
                'email' => !empty($extracted_data['email']) ? '✓ ' . self::mask_email($extracted_data['email']) : '✗',
                'phone' => !empty($extracted_data['phone']) ? '✓ ' . self::mask_phone($extracted_data['phone']) : '✗',
                'date' => !empty($extracted_data['date']) ? '✓ ' . $extracted_data['date'] : '✗',
                'time' => !empty($extracted_data['time']) ? '✓ ' . $extracted_data['time'] : '✗',
            ],
            'missing_fields' => $missing_fields,
            'missing_count' => count($missing_fields),
        ];

        self::append_entry($entry);
    }

    /**
     * Log Session 1 data collection question
     */
    public static function log_session1_question(
        string $session_id,
        string $field_requested,
        string $question_text
    ): void {
        if (!apply_filters('restatify_router_debug_enabled', true)) {
            return;
        }

        $entry = [
            'timestamp' => current_time('mysql', true),
            'timestamp_short' => self::format_time_short(current_time('mysql', true)),
            'session_id' => substr($session_id, 0, 12),
            'type' => 'question',
            'field_requested' => $field_requested,
            'question_preview' => substr($question_text, 0, 150),
        ];

        self::append_entry($entry);
    }

    /**
     * Log Session 1 booking confirmation/trigger
     */
    public static function log_session1_confirmation(
        string $session_id,
        array $booking_data
    ): void {
        if (!apply_filters('restatify_router_debug_enabled', true)) {
            return;
        }

        $entry = [
            'timestamp' => current_time('mysql', true),
            'timestamp_short' => self::format_time_short(current_time('mysql', true)),
            'session_id' => substr($session_id, 0, 12),
            'type' => 'confirmation',
            'booking_data' => [
                'name' => !empty($booking_data['name']) ? 'provided' : 'missing',
                'contact_method' => (string) ($booking_data['contact_method'] ?? 'unknown'),
                'contact_masked' => self::mask_contact($booking_data['contact_value'] ?? '', $booking_data['contact_method'] ?? 'email'),
                'date' => (string) ($booking_data['date'] ?? 'TBD'),
                'time' => (string) ($booking_data['time'] ?? 'TBD'),
            ],
            'trigger_generated' => 'BOOKING_TRIGGER [[RESTATIFY_BOOKING_PREFILL]] sent',
        ];

        self::append_entry($entry);
    }

    /**
     * Log Session 1 state (helper for debugging)
     */
    public static function log_session1_state(
        string $session_id,
        array $state
    ): void {
        if (!apply_filters('restatify_router_debug_enabled', true)) {
            return;
        }

        $entry = [
            'timestamp' => current_time('mysql', true),
            'timestamp_short' => self::format_time_short(current_time('mysql', true)),
            'session_id' => substr($session_id, 0, 12),
            'type' => 'state',
            'booking_flow_active' => !empty($state['booking_flow_active']) ? 'true' : 'false',
            'booking_attempt_count' => (int) ($state['booking_attempt_count'] ?? 0),
            'collected_fields' => is_array($state['collected_fields']) ? count($state['collected_fields']) : 0,
            'customer_turns' => (int) ($state['customer_turns'] ?? 0),
        ];

        self::append_entry($entry);
    }

    /**
     * Get recent Session 1 entries
     */
    public static function get_recent_entries(int $limit = 50): array {
        $log = get_option(self::LOG_KEY, []);
        if (!is_array($log)) {
            return [];
        }

        return array_slice($log, -$limit);
    }

    /**
     * Get Session 1 entries for specific session
     */
    public static function get_session_entries(string $session_id, int $limit = 100): array {
        $session_short = substr($session_id, 0, 12);
        $entries = self::get_recent_entries($limit * 2);

        return array_filter($entries, function ($entry) use ($session_short) {
            return ($entry['session_id'] ?? '') === $session_short;
        });
    }

    /**
     * Format recent entries as human-readable log lines
     */
    public static function format_as_log_lines(int $limit = 30): array {
        $entries = self::get_recent_entries($limit);
        $lines = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = (string) ($entry['timestamp_short'] ?? '');
            $type = (string) ($entry['type'] ?? '');
            $session = (string) ($entry['session_id'] ?? '');

            switch ($type) {
                case 'entry':
                    $reason = (string) ($entry['trigger_reason'] ?? 'unknown');
                    $msg = (string) ($entry['user_message'] ?? '');
                    $confidence = isset($entry['confidence']) ? round($entry['confidence'] * 100, 0) . '%' : '-';
                    $line = "$timestamp [S1-ENTRY] reason=$reason conf=$confidence msg=\"{$msg}\"";
                    break;

                case 'extraction':
                    $extracted = $entry['extracted'] ?? [];
                    $missing = $entry['missing_count'] ?? 0;
                    $extracted_str = implode(', ', (array) $extracted);
                    $line = "$timestamp [S1-EXTRACT] $extracted_str | Missing: $missing";
                    break;

                case 'question':
                    $field = (string) ($entry['field_requested'] ?? '');
                    $question = (string) ($entry['question_preview'] ?? '');
                    $line = "$timestamp [S1-QUESTION] Asking for '$field': \"{$question}\"";
                    break;

                case 'confirmation':
                    $booking = $entry['booking_data'] ?? [];
                    $contact = (string) ($booking['contact_masked'] ?? 'N/A');
                    $date = (string) ($booking['date'] ?? 'TBD');
                    $time = (string) ($booking['time'] ?? 'TBD');
                    $line = "$timestamp [S1-CONFIRM] Contact: $contact | Date: $date @ $time | ✓ BOOKING TRIGGER";
                    break;

                case 'state':
                    $attempts = (int) ($entry['booking_attempt_count'] ?? 0);
                    $active = (string) ($entry['booking_flow_active'] ?? 'false');
                    $line = "$timestamp [S1-STATE] Active: $active | Attempts: $attempts";
                    break;

                default:
                    $line = "$timestamp [S1-$type] " . json_encode($entry);
            }

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Clear log for specific session or all
     */
    public static function clear_log(string $session_id = ''): void {
        if ($session_id === '') {
            delete_option(self::LOG_KEY);
            return;
        }

        $log = get_option(self::LOG_KEY, []);
        if (!is_array($log)) {
            return;
        }

        $session_short = substr($session_id, 0, 12);
        $log = array_filter($log, function ($entry) use ($session_short) {
            return ($entry['session_id'] ?? '') !== $session_short;
        });

        update_option(self::LOG_KEY, $log);
    }

    // ===== Helper methods =====

    private static function append_entry(array $entry): void {
        $log = get_option(self::LOG_KEY, []);
        if (!is_array($log)) {
            $log = [];
        }

        // Keep log size manageable
        if (count($log) >= self::MAX_ENTRIES) {
            $log = array_slice($log, -floor(self::MAX_ENTRIES * 0.8));
        }

        $log[] = $entry;
        update_option(self::LOG_KEY, $log);
    }

    private static function format_time_short(string $timestamp): string {
        // Convert "2026-05-19 11:14:14" to "11:14:14"
        $parts = explode(' ', $timestamp);
        return $parts[1] ?? $timestamp;
    }

    private static function mask_email(string $email): string {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '(invalid)';
        }

        $parts = explode('@', $email);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';

        $local_masked = strlen($local) > 2 ? substr($local, 0, 1) . '***' . substr($local, -1) : $local;
        return "$local_masked@$domain";
    }

    private static function mask_phone(string $phone): string {
        // Show last 4 digits only
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($clean) < 4) {
            return '(invalid)';
        }

        return '***' . substr($clean, -4);
    }

    private static function mask_contact(string $contact_value, string $contact_method): string {
        if ($contact_method === 'email') {
            return self::mask_email($contact_value);
        } else {
            return self::mask_phone($contact_value);
        }
    }
}

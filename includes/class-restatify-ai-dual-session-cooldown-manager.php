<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IP-based Cooldown Manager for Router.
 * 
 * Tracks and enforces cooldowns after Session 1 conversion.
 * Spec: After conversion to Session 1, IP gets cooldown (~15 minutes)
 * with user-facing chat notice.
 */
class Restatify_Ai_Dual_Session_Cooldown_Manager {

    const COOLDOWN_DURATION = 900; // 15 minutes
    const OPTION_PREFIX = 'restatify_router_cooldown_';

    /**
     * Check if IP is currently on cooldown.
     */
    public static function is_ip_on_cooldown(string $ip_address): bool {
        $option_key = self::OPTION_PREFIX . md5($ip_address);
        $cooldown_until = get_transient($option_key);

        if ($cooldown_until === false) {
            return false;
        }

        return time() < intval($cooldown_until);
    }

    /**
     * Get remaining cooldown time in seconds.
     */
    public static function get_remaining_cooldown_seconds(string $ip_address): int {
        if (!self::is_ip_on_cooldown($ip_address)) {
            return 0;
        }

        $option_key = self::OPTION_PREFIX . md5($ip_address);
        $cooldown_until = intval(get_transient($option_key));

        return max(0, $cooldown_until - time());
    }

    /**
     * Get remaining cooldown time in human-readable format.
     */
    public static function get_remaining_cooldown_display(string $ip_address): string {
        $seconds = self::get_remaining_cooldown_seconds($ip_address);

        if ($seconds === 0) {
            return '';
        }

        $minutes = ceil($seconds / 60);

        if ($minutes === 1) {
            return 'etwa 1 Minute';
        }

        return "etwa $minutes Minuten";
    }

    /**
     * Record cooldown for IP (set it on cooldown).
     */
    public static function record_cooldown(string $ip_address): void {
        $option_key = self::OPTION_PREFIX . md5($ip_address);
        $cooldown_until = time() + self::COOLDOWN_DURATION;

        set_transient($option_key, $cooldown_until, self::COOLDOWN_DURATION);

        // Log cooldown event for audit
        do_action('restatify_cooldown_recorded', [
            'ip_address' => $ip_address,
            'cooldown_until' => $cooldown_until,
            'duration_seconds' => self::COOLDOWN_DURATION,
            'timestamp' => current_time('mysql', true),
        ]);
    }

    /**
     * Clear cooldown for IP (remove from cooldown).
     */
    public static function clear_cooldown(string $ip_address): void {
        $option_key = self::OPTION_PREFIX . md5($ip_address);
        delete_transient($option_key);
    }

    /**
     * Get cooldown notice message.
     */
    public static function get_cooldown_notice(string $ip_address): string {
        $remaining = self::get_remaining_cooldown_display($ip_address);

        if ($remaining === '') {
            return '';
        }

        return "Sie können ab in $remaining eine neue Sitzung starten.";
    }
}

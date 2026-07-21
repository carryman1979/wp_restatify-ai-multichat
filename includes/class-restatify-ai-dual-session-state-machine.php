<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * State Machine for Dual-Session Router.
 * 
 * Manages session state including:
 * - Current session (booking_collector/contact_collector/general_chat)
 * - Booking/Contact collector flow flags
 * - Confidence levels
 * - Attempt counters
 * - Collected booking/contact fields
 * - Clarification attempts
 * - Turn counts
 */
class Restatify_Ai_Dual_Session_State_Machine {

    const STATE_OPTION_PREFIX = 'restatify_router_state_';
    const STATE_TTL_SECONDS = 3600; // 1 hour

    /**
     * Get current session state by session ID.
     */
    public function get_session_state(string $session_id): array {
        if ($session_id === '') {
            return $this->get_default_state();
        }

        $option_key = self::STATE_OPTION_PREFIX . md5($session_id);
        $state = get_transient($option_key);

        if ($state === false) {
            return $this->get_default_state();
        }

        if (!is_array($state)) {
            return $this->get_default_state();
        }

        return $this->migrate_state($state);
    }

    /**
     * Update session state.
     */
    public function update_session_state(string $session_id, array $state): void {
        if ($session_id === '') {
            return;
        }

        $option_key = self::STATE_OPTION_PREFIX . md5($session_id);
        set_transient($option_key, $state, self::STATE_TTL_SECONDS);
    }

    /**
     * Clear session state (e.g., after booking completion or timeout).
     */
    public function clear_session_state(string $session_id): void {
        if ($session_id === '') {
            return;
        }

        $option_key = self::STATE_OPTION_PREFIX . md5($session_id);
        delete_transient($option_key);
    }

    /**
     * Get default state for new session.
     */
    private function get_default_state(): array {
        return [
            'current_session' => 'general_chat', // Default to general chat
            'booking_flow_active' => false,
            'contact_flow_active' => false,
            'confidence' => 0.0,
            'clarification_attempts' => 0,
            'booking_attempt_count' => 0,
            'contact_attempt_count' => 0,
            'booking_field_retry_counts' => [],
            'booking_skipped_fields' => [],
            'contact_field_retry_counts' => [],
            'contact_skipped_fields' => [],
            'customer_turns' => 0,
            'support_turns' => 0,
            'collected_fields' => [],
            'contact_collected_fields' => [],
            'preloaded_slots' => [],
            'slot_cache_time' => 0,
            'reask_attempts' => 0,
            'forced_conversion' => false,
            'force_overlay' => false,
            'partial_prefill' => [],
            'pending_confirmation_action' => '',
            'pending_confirmation_payload' => [],
            'pending_confirmation_retry_count' => 0,
            'pending_confirmation_language_code' => 'de',
            'pending_abort_followup' => false,
            'pending_abort_source' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function migrate_state(array $state): array {
        $current = (string) ($state['current_session'] ?? '');

        if ($current === 'session1') {
            $state['current_session'] = 'booking_collector';
        } elseif ($current === 'session2') {
            $state['current_session'] = 'general_chat';
        } elseif ($current === 'contact') {
            $state['current_session'] = 'contact_collector';
        }

        if (empty($state['current_session'])) {
            $state['current_session'] = 'general_chat';
        }

        $defaults = $this->get_default_state();
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }

        return $state;
    }

    /**
     * Increment booking attempt counter.
     */
    public function increment_booking_attempts(string $session_id): int {
        $state = $this->get_session_state($session_id);
        $state['booking_attempt_count'] = ($state['booking_attempt_count'] ?? 0) + 1;
        $this->update_session_state($session_id, $state);
        return $state['booking_attempt_count'];
    }

    /**
    * Increment customer turn counter (General-Chat only, support turns excluded).
     */
    public function increment_customer_turns(string $session_id): int {
        $state = $this->get_session_state($session_id);
        $state['customer_turns'] = ($state['customer_turns'] ?? 0) + 1;
        $this->update_session_state($session_id, $state);
        return $state['customer_turns'];
    }

    /**
     * Increment support turn counter.
     */
    public function increment_support_turns(string $session_id): int {
        $state = $this->get_session_state($session_id);
        $state['support_turns'] = ($state['support_turns'] ?? 0) + 1;
        $this->update_session_state($session_id, $state);
        return $state['support_turns'];
    }

    /**
     * Update collected booking fields.
     */
    public function update_collected_fields(string $session_id, array $fields): void {
        $state = $this->get_session_state($session_id);
        $state['collected_fields'] = array_merge($state['collected_fields'] ?? [], $fields);
        $this->update_session_state($session_id, $state);
    }

    /**
     * Get collected booking fields.
     */
    public function get_collected_fields(string $session_id): array {
        $state = $this->get_session_state($session_id);
        return $state['collected_fields'] ?? [];
    }

    /**
     * Mark booking as completed (clears state).
     */
    public function mark_booking_completed(string $session_id): void {
        // Log booking completion (audit trail)
        $this->log_booking_event($session_id, 'booking_completed');

        // Clear session state
        $this->clear_session_state($session_id);
    }

    /**
     * Mark workflow as limit-hit (clears state).
     */
    public function mark_workflow_limit_hit(string $session_id): void {
        $this->log_booking_event($session_id, 'workflow_limit_hit');
        $this->clear_session_state($session_id);
    }

    /**
    * Mark session as ended (clears state and collector work data).
     */
    public function mark_session_ended(string $session_id): void {
        $state = $this->get_session_state($session_id);
        $this->log_booking_event($session_id, 'session_ended', $state);
        $this->clear_session_state($session_id);
    }

    /**
    * Delete uncertainty cache (when falling back from clarification to General-Chat).
     */
    public function clear_uncertainty_cache(string $session_id): void {
        $state = $this->get_session_state($session_id);
        $state['confidence'] = 0.0;
        $state['clarification_attempts'] = 0;
        $this->update_session_state($session_id, $state);
    }

    /**
     * Log booking event for audit trail.
     */
    private function log_booking_event(string $session_id, string $event, array $context = []): void {
        $log_entry = [
            'session_id' => $session_id,
            'event' => $event,
            'timestamp' => current_time('mysql', true),
            'context' => $context,
        ];

        // Store in audit log (always retained per spec)
        // In production: write to dedicated audit log table or file
        do_action('restatify_router_audit_log', $log_entry);
    }
}

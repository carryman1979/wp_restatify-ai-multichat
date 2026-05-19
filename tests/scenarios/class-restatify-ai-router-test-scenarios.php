<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * End-to-End Router Test Scenarios
 * 
 * Comprehensive test suite for Dual-Session Router covering:
 * - Session 2 (General Chat) routing
 * - Session 1 (Booking Intent) routing with confidence levels
 * - Clarification flows
 * - Forced conversion after 20 turns
 * - IP cooldown enforcement
 * - 10-attempt limit & force overlay
 */
class Restatify_Ai_Router_Test_Scenarios {

    /**
     * Build a unique session id to avoid cross-test transient leakage.
     */
    private static function build_session_id(string $prefix): string {
        return wp_hash($prefix . '_' . microtime(true) . '_' . wp_rand());
    }

    /**
     * Reset state/cooldown for deterministic test behavior.
     */
    private static function reset_test_context(string $session_id, string $ip_address): void {
        $state_machine = new Restatify_Ai_Dual_Session_State_Machine();
        $state_machine->clear_session_state($session_id);
        Restatify_Ai_Dual_Session_Cooldown_Manager::clear_cooldown($ip_address);
    }

    /**
     * Run all test scenarios and return results.
     */
    public static function run_all_tests(): array {
        $results = [];

        $results['scenario_1_general_chat'] = self::test_general_chat_routing();
        $results['scenario_2_booking_high_confidence'] = self::test_booking_high_confidence();
        $results['scenario_3_booking_low_confidence'] = self::test_booking_low_confidence();
        $results['scenario_4_clarification_flow'] = self::test_clarification_flow();
        $results['scenario_5_20_turns_forced_conversion'] = self::test_forced_conversion_after_20_turns();
        $results['scenario_6_ip_cooldown'] = self::test_ip_cooldown_enforcement();
        $results['scenario_7_10_booking_attempts'] = self::test_10_booking_attempts();
        $results['scenario_8_implicit_booking_when_genau'] = self::test_implicit_booking_when_genau();
        $results['scenario_9_implicit_booking_morgen'] = self::test_implicit_booking_morgen();

        return [
            'timestamp' => current_time('mysql', true),
            'total_scenarios' => count($results),
            'results' => $results,
        ];
    }

    // ...restlicher Szenariencode wie im Original...
}

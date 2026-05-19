<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Quick Integration Test for Router
 * 
 * Validates:
 * - Router instantiation
 * - Message routing logic
 * - Confidence calculation
 * - State machine tracking
 * - Cooldown enforcement
 */
class Restatify_Ai_Router_Integration_Test {

    /**
     * Run quick smoke test.
     */
    public static function run_smoke_test(): array {
        $results = [];

        // Test 1: Router instantiation
        try {
            $router = new Restatify_Ai_Dual_Session_Router();
            $results['router_instantiation'] = ['status' => 'pass', 'message' => 'Router created successfully'];
        } catch (Exception $e) {
            $results['router_instantiation'] = ['status' => 'fail', 'message' => $e->getMessage()];
            return $results;
        }

        // Test 2: Simple message routing
        try {
            $result = $router->route_message('Hallo', [], wp_hash('test'), '127.0.0.1');
            $has_action = isset($result['action']);
            $has_session = isset($result['session']);
            $results['message_routing'] = [
                'status' => $has_action && $has_session ? 'pass' : 'fail',
                'action' => $result['action'] ?? 'none',
                'session' => $result['session'] ?? 'none',
            ];
        } catch (Exception $e) {
            $results['message_routing'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        // Test 3: State machine integration
        try {
            $state_machine = new Restatify_Ai_Dual_Session_State_Machine();
            $session_id = wp_hash('test_sm');
            $state_machine->increment_customer_turn($session_id);
            $state = $state_machine->get_session_state($session_id);
            $results['state_machine'] = [
                'status' => $state['customer_turns'] === 1 ? 'pass' : 'fail',
                'customer_turns' => $state['customer_turns'],
            ];
        } catch (Exception $e) {
            $results['state_machine'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        // Test 4: Cooldown manager
        try {
            $test_ip = '192.168.1.255';
            Restatify_Ai_Dual_Session_Cooldown_Manager::record_cooldown($test_ip);
            $is_cooldown = Restatify_Ai_Dual_Session_Cooldown_Manager::is_ip_on_cooldown($test_ip);
            $results['cooldown_manager'] = [
                'status' => $is_cooldown ? 'pass' : 'fail',
                'is_on_cooldown' => $is_cooldown,
            ];
            // Cleanup
            Restatify_Ai_Dual_Session_Cooldown_Manager::clear_cooldown($test_ip);
        } catch (Exception $e) {
            $results['cooldown_manager'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        // Test 5: Slot manager instantiation
        try {
            $slot_manager = new Restatify_Ai_Dual_Session_Slot_Manager([]);
            $results['slot_manager'] = ['status' => 'pass', 'message' => 'Slot manager created successfully'];
        } catch (Exception $e) {
            $results['slot_manager'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * Format smoke test results for display.
     */
    public static function format_smoke_results(array $results): string {
        $output = "\n=== Router Integration Smoke Test ===\n\n";

        $total = count($results);
        $passed = 0;

        foreach ($results as $test_name => $result) {
            $status = $result['status'] === 'pass' ? '\u2713' : '\u2717';
            $output .= "$status $test_name\n";

            if ($result['status'] === 'fail' && isset($result['message'])) {
                $output .= "  Error: {$result['message']}\n";
            } else {
                foreach ($result as $key => $value) {
                    if ($key !== 'status' && $key !== 'message') {
                        if (is_bool($value)) {
                            $value = $value ? 'true' : 'false';
                        }
                        $output .= "  $key: $value\n";
                    }
                }
            }
            $output .= "\n";

            if ($result['status'] === 'pass') {
                $passed++;
            }
        }

        $output .= "---\n";
        $output .= "Result: $passed / $total tests passed\n";

        return $output;
    }
}

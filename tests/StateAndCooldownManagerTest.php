<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StateAndCooldownManagerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['restatify_test_transients'] = [];
        $GLOBALS['restatify_test_options'] = [];
    }

    public function testStateMachineStartsWithExpectedDefaults(): void {
        $stateMachine = new Restatify_Ai_Dual_Session_State_Machine();
        $state = $stateMachine->get_session_state('state_defaults_test');

        self::assertSame(Restatify_Ai_Dual_Session_Router::SESSION_GENERAL_CHAT, $state['current_session']);
        self::assertFalse((bool) $state['booking_flow_active']);
        self::assertSame(0, (int) $state['customer_turns']);
    }

    public function testStateMachineIncrementCustomerTurnsPersistsValue(): void {
        $stateMachine = new Restatify_Ai_Dual_Session_State_Machine();
        $sessionId = 'state_increment_test';

        $first = $stateMachine->increment_customer_turns($sessionId);
        $second = $stateMachine->increment_customer_turns($sessionId);

        self::assertSame(1, $first);
        self::assertSame(2, $second);

        $state = $stateMachine->get_session_state($sessionId);
        self::assertSame(2, (int) $state['customer_turns']);
    }

    public function testCooldownManagerRecordsAndClearsCooldown(): void {
        $ip = '203.0.113.42';

        Restatify_Ai_Dual_Session_Cooldown_Manager::record_cooldown($ip);
        self::assertTrue(Restatify_Ai_Dual_Session_Cooldown_Manager::is_ip_on_cooldown($ip));
        self::assertGreaterThan(0, Restatify_Ai_Dual_Session_Cooldown_Manager::get_remaining_cooldown_seconds($ip));

        Restatify_Ai_Dual_Session_Cooldown_Manager::clear_cooldown($ip);
        self::assertFalse(Restatify_Ai_Dual_Session_Cooldown_Manager::is_ip_on_cooldown($ip));
    }
}

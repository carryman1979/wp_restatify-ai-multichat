<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RouterDebugLoggerUnitTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['restatify_test_transients'] = [];
        $GLOBALS['restatify_test_options'] = [];
    }

    public function testRoutingDecisionLogEntryIsPersistedAndSessionIdIsShortened(): void {
        Restatify_Ai_Router_Debug_Logger::log_routing_decision(
            'session-very-long-identifier-12345',
            '198.51.100.10',
            'Ich moechte einen Termin vereinbaren',
            'switch_to_session1',
            ['confidence' => 0.99]
        );

        $entries = Restatify_Ai_Router_Debug_Logger::get_recent_entries(10);

        self::assertCount(1, $entries);
        self::assertSame('switch_to_session1', $entries[0]['action'] ?? null);
        self::assertSame('session-very', $entries[0]['session_id'] ?? null);
    }

    public function testGetEntriesByTypeReturnsOnlyRequestedType(): void {
        Restatify_Ai_Router_Debug_Logger::log_confidence_calculation(
            'session-abc-123456',
            'Termin?',
            0.91,
            'clarify'
        );

        Restatify_Ai_Router_Debug_Logger::log_state_transition(
            'session-abc-123456',
            'session2',
            'session1',
            ['customer_turns' => 5, 'booking_attempts' => 1]
        );

        $confidenceEntries = Restatify_Ai_Router_Debug_Logger::get_entries_by_type('confidence', 10);

        self::assertNotEmpty($confidenceEntries);
        foreach ($confidenceEntries as $entry) {
            self::assertSame('confidence', $entry['type'] ?? null);
        }
    }

    public function testClearLogRemovesStoredEntries(): void {
        Restatify_Ai_Router_Debug_Logger::log_cooldown_event('203.0.113.7', true);
        self::assertNotEmpty(Restatify_Ai_Router_Debug_Logger::get_recent_entries(10));

        Restatify_Ai_Router_Debug_Logger::clear_log();

        self::assertSame([], Restatify_Ai_Router_Debug_Logger::get_recent_entries(10));
    }
}

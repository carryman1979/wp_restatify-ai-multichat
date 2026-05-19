<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SlotManagerUnitTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['restatify_test_transients'] = [];
        $GLOBALS['restatify_test_options'] = [];
    }

    public function testMatchSlotsReturnsExactWhenWithinTolerance(): void {
        $manager = new Restatify_Ai_Dual_Session_Slot_Manager();

        $slots = [
            ['start_iso' => '2026-05-20T13:05:00Z'],
            ['start_iso' => '2026-05-20T15:00:00Z'],
        ];

        $result = $manager->match_slots($slots, '2026-05-20', '13:00');

        self::assertSame('exact', $result['match_type'] ?? null);
        self::assertSame('2026-05-20T13:05:00Z', $result['exact_match']['start_iso'] ?? null);
        self::assertSame([], $result['alternatives'] ?? null);
    }

    public function testMatchSlotsReturnsAlternativesWhenNoExactHit(): void {
        $manager = new Restatify_Ai_Dual_Session_Slot_Manager();

        $slots = [
            ['start_iso' => '2026-05-21T12:00:00Z'],
            ['start_iso' => '2026-05-21T14:30:00Z'],
            ['start_iso' => '2026-05-22T12:10:00Z'],
            ['start_iso' => '2026-05-21T13:20:00Z'],
        ];

        $result = $manager->match_slots($slots, '2026-05-21', '13:00');

        self::assertSame('alternatives', $result['match_type'] ?? null);
        self::assertCount(3, $result['alternatives'] ?? []);
        self::assertSame('2026-05-21T13:20:00Z', $result['alternatives'][0]['start_iso'] ?? null);
    }

    public function testFormatSlotForDisplayUsesLabelOrFallback(): void {
        $manager = new Restatify_Ai_Dual_Session_Slot_Manager();

        $withLabel = ['start_iso' => '2026-05-21T13:00:00Z', 'label' => 'Mittwoch 13:00'];
        $withoutLabel = ['start_iso' => '2026-05-21T13:00:00Z'];

        self::assertSame('Mittwoch 13:00', $manager->format_slot_for_display($withLabel));

        $fallback = $manager->format_slot_for_display($withoutLabel);
        self::assertStringContainsString('21.05.2026', $fallback);
        self::assertStringContainsString('Uhr', $fallback);
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DualSessionRouterUnitTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['restatify_test_transients'] = [];
        $GLOBALS['restatify_test_options'] = [];
    }

    public function testShortAffirmationAfterAiBookingOfferRoutesToSession1(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Klingt gut. Wie geht es weiter?'],
            ['sender' => 'ai', 'message' => 'Sollen wir einen kurzen Termin oder Call in den naechsten Tagen vereinbaren?'],
        ];

        $result = $router->route_message('Gerne', $conversation, 'test_router_affirmation', '127.0.0.11');

        self::assertSame('session1', $result['session'] ?? null);
        self::assertGreaterThanOrEqual(Restatify_Ai_Dual_Session_Router::CONFIDENCE_AUTO_BOOKING, (float) ($result['confidence'] ?? 0.0));
    }

    public function testForcedConversionStillTriggersAfterTwentyTurns(): void {
        $router = new Restatify_Ai_Dual_Session_Router();
        $sessionId = 'test_router_forced_conversion';

        $result = [];
        for ($turn = 1; $turn <= 20; $turn++) {
            $result = $router->route_message(
                'Allgemeine Frage ohne Terminbezug ' . $turn,
                [],
                $sessionId,
                '127.0.0.12'
            );
        }

        self::assertSame('forced_conversion', $result['action'] ?? null);
        self::assertSame('session1', $result['session'] ?? null);
    }

    public function testEnglishSchedulingSignalsTriggerClarificationOrSession1(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Sounds nice. How can we proceed?'],
            ['sender' => 'ai', 'message' => 'Would you like to schedule a short call in the next days?'],
            ['sender' => 'visitor', 'message' => 'Tomorrow would be nice'],
            ['sender' => 'ai', 'message' => 'Great. What time works best for you?'],
        ];

        $result = $router->route_message('Round about 2 o\'clock p.m.?', $conversation, 'test_router_english', '127.0.0.31');

        self::assertGreaterThan(0.0, (float) ($result['confidence'] ?? 0.0));
        self::assertContains($result['session'] ?? '', ['clarification', 'session1']);
    }

    public function testExplicitBookingAcceptanceHelperUsesLastAiOfferContext(): void {
        $reflection = new ReflectionClass(Restatify_Ai_Dual_Session_Router::class);
        $router = $reflection->newInstanceWithoutConstructor();

        $method = $reflection->getMethod('is_explicit_booking_acceptance');
        $method->setAccessible(true);

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Okay'],
            ['sender' => 'ai', 'message' => 'Moechten Sie direkt einen Termin fuer ein Erstgespraech vereinbaren?'],
        ];

        $accepted = $method->invoke($router, 'Ja, gerne', $conversation, 'de');

        self::assertTrue($accepted);
    }

    public function testDirectAppointmentQuestionDoesNotFallBackToLowIntent(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $result = $router->route_message(
            'Können wir gleich einen Termin ausmachen?',
            [],
            'test_router_direct_appointment_question',
            '127.0.0.13'
        );

        self::assertSame('session1', $result['session'] ?? null);
        self::assertGreaterThanOrEqual(
            Restatify_Ai_Dual_Session_Router::CONFIDENCE_CLARIFY_MIN,
            (float) ($result['confidence'] ?? 0.0)
        );
    }

    public function testExplicitBookingRejectionRoutesDirectlyToSession2(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $conversation = [
            ['sender' => 'ai', 'message' => 'Moechten Sie gleich einen Termin vereinbaren?'],
        ];

        $result = $router->route_message(
            'Nein, ich moechte keinen Termin.',
            $conversation,
            'test_router_explicit_rejection',
            '127.0.0.14'
        );

        self::assertSame('session2', $result['session'] ?? null);
        self::assertSame('routing_session2', $result['action'] ?? null);
    }

    public function testRejectionSuppressesImmediateClarificationLoop(): void {
        $router = new Restatify_Ai_Dual_Session_Router();
        $sessionId = 'test_router_rejection_suppression';
        $ip = '127.0.0.15';

        $conversation = [
            ['sender' => 'ai', 'message' => 'Moechten Sie einen Termin ausmachen?'],
        ];

        $first = $router->route_message('Nein, nicht jetzt.', $conversation, $sessionId, $ip);
        self::assertSame('session2', $first['session'] ?? null);

        $second = $router->route_message('Termin?', [], $sessionId, $ip);
        self::assertSame('session2', $second['session'] ?? null);
        self::assertSame('routing_session2', $second['action'] ?? null);
    }

    public function testFastTrackJaAfterDirectBookingQuestion(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Klingt sinnvoll.'],
            ['sender' => 'ai', 'message' => 'Möchten Sie gerne einen Termin mit unserem Team vereinbaren?'],
        ];

        $result = $router->route_message('Ja', $conversation, 'test_fast_track_ja', '127.0.0.20');

        self::assertSame('session1', $result['session'] ?? null, 'Fast-track should route "Ja" to Session1 immediately');
        self::assertSame(true, $result['user_facing_text'] !== '', 'Session1 should return a response');
    }

    public function testFastTrackContactInfoAfterBookingQuestion(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $conversation = [
            ['sender' => 'ai', 'message' => 'Möchten Sie gerne einen Termin mit unserem Team vereinbaren?'],
        ];

        $result = $router->route_message('info@example.test und +49987654321', $conversation, 'test_fast_track_contact', '127.0.0.21');

        self::assertSame('session1', $result['session'] ?? null, 'Fast-track should detect contact info as booking intent');
    }

    public function testFastTrackTimeReferenceAfterBookingQuestion(): void {
        $router = new Restatify_Ai_Dual_Session_Router();

        $conversation = [
            ['sender' => 'ai', 'message' => 'Möchten Sie gerne einen Termin mit unserem Team vereinbaren?'],
        ];

        $result = $router->route_message('Nachmittags morgen würde gut passen', $conversation, 'test_fast_track_time', '127.0.0.22');

        self::assertSame('session1', $result['session'] ?? null, 'Fast-track should detect time reference as booking intent');
    }

    public function testSession1ExtractBookingDataWithContactInfo(): void {
        $router = $this->createRouterWithMockLlm([
            'name' => 'Unicorn Team',
            'email' => 'info@unicornfin.test',
            'phone' => '+49987654321',
            'contact_method' => 'email',
            'contact_value' => 'info@unicornfin.test',
            'subject' => 'Anfrage Erstgespraech',
            'note' => 'Kontaktaufnahme fuer ein Erstgespraech.',
        ], 'Wie lautet Ihr Name, damit wir den Termin korrekt zuordnen koennen?');

        $conversation = [
            ['sender' => 'ai', 'message' => 'Möchten Sie einen Termin vereinbaren?'],
        ];

        $result = $router->route_message(
            'Nachmittags auf jedenfall. Vormittags sind wir voll. info@unicornfin.test und +49987654321 sind die gewünschten Kontaktdaten.',
            $conversation,
            'test_session1_extraction',
            '127.0.0.23'
        );

        self::assertSame('session1', $result['session'] ?? null);
        // Session1 should either confirm booking (if all data present) or ask for missing data
        self::assertNotEmpty($result['user_facing_text'] ?? null, 'Session1 should return a response');
        // Should not still be a generic clarification
        self::assertNotSame('clarification', $result['session'] ?? null);
    }

    public function testSession1CollectsMissingFieldsBeforeOpeningOverlay(): void {
        $router = $this->createRouterWithMockLlm([
            'name' => 'Max',
            'subject' => 'Digitalisierung der Filialprozesse',
            'note' => 'Kunde moechte Erstgespraech zur Digitalisierung.',
        ], 'Welche E-Mail-Adresse duerfen wir fuer die Terminabstimmung verwenden?');

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Hallo, mein Name ist Max und ich will meine Firma digitalisieren.'],
            ['sender' => 'visitor', 'message' => 'Ich habe 3 Getränkemarktfilialen in 2 Städten.'],
            ['sender' => 'visitor', 'message' => 'Jede Filiale macht ihr eigenes Ding und mein Neffe soll das später übernehmen.'],
            ['sender' => 'ai', 'message' => 'Wäre es für Sie in Ordnung, wenn wir einen Termin für ein kurzes Gespräch vereinbaren?'],
        ];

        $result = $router->route_message('Ja könn ma scho machn', $conversation, 'test_session1_collect_first', '127.0.0.24');

        self::assertSame('session1', $result['session'] ?? null);
        self::assertSame(false, $result['force_overlay'] ?? true);
        self::assertStringContainsString('E-Mail-Adresse', (string) ($result['user_facing_text'] ?? ''));
    }

    public function testSession1OpensWithDerivedSubjectAndNoteWhenDataIsComplete(): void {
        $router = $this->createRouterWithMockLlm([
            'name' => 'Max',
            'email' => 'max@example.test',
            'contact_method' => 'email',
            'contact_value' => 'max@example.test',
            'subject' => 'Digitalisierung der Getraenkemarkt-Filialen',
            'note' => 'Kunde betreibt 3 Getraenkemarktfilialen und sucht Beratung zur Digitalisierung.',
        ]);

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Hallo, mein Name ist Max und ich will meine Firma digitalisieren.'],
            ['sender' => 'visitor', 'message' => 'Ich habe 3 Getränkemarktfilialen in 2 Städten.'],
            ['sender' => 'visitor', 'message' => 'Jede Filiale macht ihr eigenes Ding und mein Neffe soll das übernehmen.'],
            ['sender' => 'ai', 'message' => 'Wollen wir dafür einen Termin vereinbaren?'],
        ];

        $result = $router->route_message('Ja, gerne. Meine Mail ist max@example.test', $conversation, 'test_session1_open_ready', '127.0.0.25');

        self::assertSame('session1', $result['session'] ?? null);
        self::assertSame(true, $result['force_overlay'] ?? false);
        self::assertSame('max@example.test', $result['partial_prefill']['email'] ?? null);
        self::assertSame('email', $result['partial_prefill']['contact_method'] ?? null);
        self::assertSame('max@example.test', $result['partial_prefill']['contact_value'] ?? null);
        self::assertStringContainsString('Getraenkemarkt', (string) ($result['partial_prefill']['subject'] ?? ''));
        self::assertStringContainsString('3 Getraenkemarktfilialen', (string) ($result['partial_prefill']['note'] ?? ''));
    }

    public function testSession1OpensPartialPrefillAfterTenQuestions(): void {
        $router = $this->createRouterWithMockLlm([]);
        $sessionId = 'test_session1_partial_after_limit';

        $state = $router->state_machine->get_session_state($sessionId);
        $state['current_session'] = 'session1';
        $state['booking_flow_active'] = true;
        $state['booking_attempt_count'] = 10;
        $state['collected_fields'] = ['name' => 'Max'];
        $router->state_machine->update_session_state($sessionId, $state);

        $conversation = [
            ['sender' => 'visitor', 'message' => 'Hallo, mein Name ist Max und ich will meine Firma digitalisieren.'],
            ['sender' => 'ai', 'message' => 'Lassen Sie uns dafür einen Termin vereinbaren.'],
        ];

        $result = $router->route_message('Ja', $conversation, $sessionId, '127.0.0.26');

        self::assertSame('session1', $result['session'] ?? null);
        self::assertSame(true, $result['force_overlay'] ?? false);
        self::assertStringContainsString('bisher bekannten Angaben', (string) ($result['user_facing_text'] ?? ''));
        self::assertSame('Max', $result['partial_prefill']['name'] ?? null);
    }

    /**
     * @param array<string,mixed> $extraction
     */
    private function createRouterWithMockLlm(array $extraction, string $question = ''): Restatify_Ai_Dual_Session_Router {
        return new Restatify_Ai_Dual_Session_Router([
            'session1_llm_json_callback' => static function (string $prompt, array $options = []) use ($extraction, $question): array {
                if (strpos($prompt, 'Return strict JSON only: {"question":"..."}') !== false) {
                    if ($question !== '') {
                        return ['question' => $question];
                    }
                    return ['question' => 'Welche Angabe fehlt noch fuer die Terminabstimmung?'];
                }

                return $extraction;
            },
        ]);
    }
}

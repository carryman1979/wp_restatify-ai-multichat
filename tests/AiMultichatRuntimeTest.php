<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value): string {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

final class AiMultichatRuntimeTest extends TestCase {
    private Restatify_Ai_Multichat_Chat_Runtime $runtime;

    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['restatify_test_transients'] = [];
        $GLOBALS['restatify_test_options'] = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->runtime = new class () extends Restatify_Ai_Multichat_Chat_Runtime {
            protected function get_options(bool $force_reload = false): array {
                return [];
            }

            protected function get_client_ip(): string {
                return '127.0.0.1';
            }

            protected function is_booking_plugin_available(): bool {
                return true;
            }
        };
    }

    public function testDetectAiProviderSupportsGeminiAndLlama(): void {
        self::assertSame(
            'gemini',
            $this->invokeProtected('detect_ai_provider', ['https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro'])
        );

        self::assertSame(
            'llama',
            $this->invokeProtected('detect_ai_provider', ['http://localhost:11434/api/chat'])
        );
    }

    public function testNormalizeGeminiEndpointBuildsGenerateContentUrlAndAppendsKey(): void {
        $normalized = $this->invokeProtected('normalize_gemini_endpoint', [
            'https://generativelanguage.googleapis.com/v1beta',
            'gemini-1.5-flash',
            'secret-key',
        ]);

        self::assertSame(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=secret-key',
            $normalized
        );
    }

    public function testSanitizeAiEndpointFallsBackToDefaultForInvalidOrInsecureUrls(): void {
        self::assertSame(
            Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT,
            $this->invokeProtected('sanitize_ai_endpoint', [''])
        );

        self::assertSame(
            Restatify_Ai_Multichat_Plugin::DEFAULT_AI_ENDPOINT,
            $this->invokeProtected('sanitize_ai_endpoint', ['http://insecure.local/api'])
        );
    }

    public function testBuildEffectiveSystemPromptContainsAntiOverpromisingGuardrails(): void {
        $prompt = $this->invokeProtected('build_effective_system_prompt', [
            'Du bist Nora.',
            1200,
            true,
            ['email', 'phone'],
        ]);

        self::assertStringContainsString('Erfinde keine internen Schritte oder Aktionen', $prompt);
        self::assertStringContainsString('keine rechtlichen Garantien als Fakt', $prompt);
        self::assertStringContainsString('WIEDERHOLUNGSVERBOT', $prompt);
        self::assertStringContainsString('BUCHUNGSMODUS', $prompt);
        self::assertStringContainsString('KURZANTWORT-REGEL', $prompt);
    }

    public function testBuildAiMessagesKeepsUserFactsFromLongHistory(): void {
        $conversation = ['messages' => []];

        for ($i = 1; $i <= 25; $i++) {
            $conversation['messages'][] = ['sender' => 'ai', 'message' => 'Antwortblock ' . $i . ' mit vielen Details und Erklaerungen.'];
            $userMessage = 'Rueckfrage ' . $i;
            if ($i === 24) {
                $userMessage .= ' und wir nutzen Ilogu mit CRM Modul.';
            }

            $conversation['messages'][] = ['sender' => 'visitor', 'message' => $userMessage];
        }

        $messages = $this->invokeProtected('build_ai_messages', [
            $conversation,
            'Wie machen wir weiter?',
            'System prompt',
        ]);

        $asJoined = json_encode($messages, JSON_UNESCAPED_UNICODE);
        self::assertIsString($asJoined);
        self::assertStringContainsString('Ilogu mit CRM Modul', (string) $asJoined);
    }

    public function testMaybeGenerateBookingReplyKeepsSession1InChatWhileDataIsMissing(): void {
        $this->runtime = $this->createRuntimeWithMockLlm([
            'name' => 'Max',
            'subject' => 'Digitalisierung der Filialprozesse',
            'note' => 'Kunde moechte Erstgespraech zur Digitalisierung.',
        ], 'Welche E-Mail-Adresse duerfen wir fuer die Terminabstimmung verwenden?');

        $conversation = [
            'id' => 'runtime_session1_collect',
            'messages' => [
                ['sender' => 'visitor', 'message' => 'Hallo, mein Name ist Max und ich will meine Firma digitalisieren.'],
                ['sender' => 'visitor', 'message' => 'Ich habe 3 Getränkemarktfilialen in 2 Städten.'],
                ['sender' => 'ai', 'message' => 'Wäre es in Ordnung, wenn wir dafür einen Termin vereinbaren?'],
            ],
        ];

        $reply = $this->invokeProtected('maybe_generate_booking_reply', [
            'Ja könn ma scho machn',
            $conversation,
        ]);

        self::assertStringContainsString('E-Mail-Adresse', $reply);
        self::assertStringNotContainsString('[[RESTATIFY_BOOKING_OPEN]]', $reply);
    }

    public function testMaybeGenerateBookingReplyOpensOverlayWhenEnoughPrefillExists(): void {
        $this->runtime = $this->createRuntimeWithMockLlm([
            'name' => 'Max',
            'email' => 'max@example.test',
            'contact_method' => 'email',
            'contact_value' => 'max@example.test',
            'subject' => 'Digitalisierung der Getraenkemarkt-Filialen',
            'note' => 'Kunde betreibt 3 Getraenkemarktfilialen und sucht Beratung zur Digitalisierung.',
        ]);

        $conversation = [
            'id' => 'runtime_session1_open_ready',
            'messages' => [
                ['sender' => 'visitor', 'message' => 'Hallo, mein Name ist Max und ich will meine Firma digitalisieren.'],
                ['sender' => 'visitor', 'message' => 'Ich habe 3 Getränkemarktfilialen in 2 Städten.'],
                ['sender' => 'visitor', 'message' => 'Jede Filiale macht ihr eigenes Ding und mein Neffe soll das übernehmen.'],
                ['sender' => 'ai', 'message' => 'Wollen wir dafür einen Termin vereinbaren?'],
            ],
        ];

        $reply = $this->invokeProtected('maybe_generate_booking_reply', [
            'Ja, gerne. Meine Mail ist max@example.test',
            $conversation,
        ]);

        self::assertStringContainsString('[[RESTATIFY_BOOKING_OPEN]]', $reply);
        self::assertStringContainsString('"subject":"Digitalisierung der Getraenkemarkt-Filialen"', $reply);
        self::assertStringContainsString('"note":', $reply);
    }

    private function invokeProtected(string $method, array $args) {
        $reflection = new ReflectionMethod($this->runtime, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->runtime, $args);
    }

    /**
     * @param array<string,mixed> $extraction
     */
    private function createRuntimeWithMockLlm(array $extraction, string $question = ''): Restatify_Ai_Multichat_Chat_Runtime {
        return new class ($extraction, $question) extends Restatify_Ai_Multichat_Chat_Runtime {
            /** @var array<string,mixed> */
            private array $extraction;
            private string $question;

            /**
             * @param array<string,mixed> $extraction
             */
            public function __construct(array $extraction, string $question) {
                $this->extraction = $extraction;
                $this->question = $question;
            }

            protected function get_options(bool $force_reload = false): array {
                $extraction = $this->extraction;
                $question = $this->question;

                return [
                    'session1_llm_json_callback' => static function (string $prompt, array $options = []) use ($extraction, $question): array {
                        if (strpos($prompt, 'Return strict JSON only: {"question":"..."}') !== false) {
                            if ($question !== '') {
                                return ['question' => $question];
                            }

                            return ['question' => 'Welche Angabe fehlt noch fuer die Terminabstimmung?'];
                        }

                        return $extraction;
                    },
                ];
            }

            protected function get_client_ip(): string {
                return '127.0.0.1';
            }

            protected function is_booking_plugin_available(): bool {
                return true;
            }
        };
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/class-restatify-ai-dual-session-prompts.php';

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
                return [
                    'session_router_llm_json_callback' => static function (string $prompt, array $options = []): array {
                        return [
                            'intent' => 'booking',
                            'booking_confidence' => 0.99,
                            'contact_confidence' => 0.01,
                            'general_confidence' => 0.00,
                        ];
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
        self::assertStringContainsString('Kontaktformular', $prompt);
        self::assertStringContainsString('Terminbuchungstool', $prompt);
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

    public function testGetBookingContactMethodsUsesSharedResolver(): void {
        $GLOBALS['restatify_test_options'][Restatify_Booking_Assistant_Constants::OPTION_KEY] = [
            'contact_channels' => [
                ['key' => 'email'],
                ['key' => 'phone'],
                ['key' => 'email'],
            ],
        ];

        $methods = $this->invokeProtected('get_booking_contact_methods', []);

        self::assertSame(['email', 'phone'], $methods);
    }

    public function testMaybeGenerateBookingReplyKeepsSession1InChatWhileDataIsMissing(): void {
        $this->runtime = $this->createRuntimeWithMockLlm([
            'name' => 'Max',
            'subject' => 'Digitalisierung der Filialprozesse',
            'note' => 'Kunde moechte Erstgespraech zur Digitalisierung.',
        ], 'Welcher Tag oder welche Uhrzeit waere fuer das Erstgespraech fuer Sie passend?');

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

        self::assertStringContainsString('Uhrzeit', $reply);
        self::assertStringNotContainsString('[[RESTATIFY_BOOKING_OPEN]]', $reply);
    }

    public function testMaybeGenerateBookingReplyOpensOverlayWhenEnoughPrefillExists(): void {
        $this->runtime = $this->createRuntimeWithMockLlm([
            'name' => 'Max',
            'email' => 'max@example.test',
            'contact_method' => 'email',
            'contact_value' => 'max@example.test',
            'date' => '2026-08-14',
            'time' => '10:30',
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

    public function testMaybeGenerateBookingReplyEmitsContactFormTokens(): void {
        $GLOBALS['restatify_test_options']['restatify_forms_config'] = [
            [
                'id' => 'kontaktformular',
                'title' => 'Kontaktformular',
                'trigger' => '#restatify-form-kontaktformular',
                'fields' => [],
            ],
        ];

        $this->runtime = new class () extends Restatify_Ai_Multichat_Chat_Runtime {
            protected function get_options(bool $force_reload = false): array {
                return [
                    'contact_form_id' => 'kontaktformular',
                    'session_router_llm_json_callback' => static function (string $prompt, array $options = []): array {
                        return [
                            'intent' => 'contact',
                            'booking_confidence' => 0.01,
                            'contact_confidence' => 0.99,
                            'general_confidence' => 0.00,
                        ];
                    },
                    'session_contact_llm_json_callback' => static function (string $prompt, array $options = []): array {
                        if (strpos($prompt, 'Return strict JSON only: {"question":"..."}') !== false) {
                            return ['question' => 'Welche E-Mail-Adresse duerfen wir fuer die Rueckmeldung verwenden?'];
                        }

                        return [];
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

        $conversation = [
            'id' => 'runtime_contact_open',
            'messages' => [
                ['sender' => 'visitor', 'message' => 'Hallo'],
            ],
        ];

        $reply = $this->invokeProtected('maybe_generate_booking_reply', [
            'Ich moechte nur eine Nachricht hinterlassen, kein Termin. Mein Name ist Max und meine Mail ist max@example.test.',
            $conversation,
        ]);

        self::assertStringContainsString('[[RESTATIFY_CONTACT_FORM_OPEN]]', $reply);
        self::assertStringContainsString('[[RESTATIFY_CONTACT_FORM_PAYLOAD]]', $reply);
        self::assertStringContainsString('"form_id":"kontaktformular"', $reply);
    }

    public function testResolveConversationLanguageKeepsGermanForEmailOnlyReplies(): void {
        $runtime = new class () extends Restatify_Ai_Multichat_Chat_Runtime {
            protected function get_options(bool $force_reload = false): array {
                return [
                    'ai_debug_enabled' => false,
                    'language_detection_llm_json_callback' => static function (string $prompt, array $options = []): array {
                        return [
                            'language' => 'en',
                            'confidence' => 0.99,
                        ];
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

        $conversation = [
            'id' => 'runtime_language_email_only',
            'messages' => [
                ['sender' => 'visitor', 'message' => 'Hallo, ich brauche Hilfe.'],
            ],
        ];

        $reflection = new ReflectionMethod($runtime, 'resolve_conversation_language_code');
        $reflection->setAccessible(true);

        $language = $reflection->invoke($runtime, $conversation, 'Meine Mail ist max@example.test');

        self::assertSame('de', $language);
    }

    public function testSession2PromptForbidsChatBasedMessageDropoff(): void {
        $prompt = Restatify_Ai_Dual_Session_Prompts::get_session2_system_prompt_wrapper('Basis-Prompt');
        $prompt_lower = mb_strtolower($prompt);

        self::assertStringContainsString('Kontaktformular', $prompt);
        self::assertStringContainsString('Terminbuchungstool', $prompt);
        self::assertStringContainsString('antworte niemals mit "schreiben sie die nachricht hier in den chat"', $prompt_lower);
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
                    'session_router_llm_json_callback' => static function (string $prompt, array $options = []): array {
                        return [
                            'intent' => 'booking',
                            'booking_confidence' => 0.99,
                            'contact_confidence' => 0.01,
                            'general_confidence' => 0.00,
                        ];
                    },
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

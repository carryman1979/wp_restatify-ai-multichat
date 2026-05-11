<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiMultichatRuntimeTest extends TestCase {
    private Restatify_Ai_Multichat_Chat_Runtime $runtime;

    protected function setUp(): void {
        parent::setUp();

        $reflection = new ReflectionClass(Restatify_Ai_Multichat_Chat_Runtime::class);
        $this->runtime = $reflection->newInstanceWithoutConstructor();
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

    private function invokeProtected(string $method, array $args) {
        $reflection = new ReflectionMethod($this->runtime, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($this->runtime, $args);
    }
}

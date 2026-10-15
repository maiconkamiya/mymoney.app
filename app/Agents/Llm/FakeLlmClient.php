<?php

namespace App\Agents\Llm;

use Closure;

/**
 * Deterministic client for tests and for running the app without an API key.
 * By default every call answers "hold" with zero confidence.
 */
class FakeLlmClient implements LlmClient
{
    /** @var list<array{system: string, prompt: string}> */
    public array $calls = [];

    /**
     * @param  (Closure(string $system, string $prompt): array<string, mixed>)|null  $responder
     */
    public function __construct(private ?Closure $responder = null) {}

    /**
     * @param  Closure(string $system, string $prompt): array<string, mixed>  $responder
     */
    public function respondUsing(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    public function json(string $model, string $system, string $prompt, array $schema): LlmResponse
    {
        $this->calls[] = ['system' => $system, 'prompt' => $prompt];

        $data = $this->responder !== null
            ? ($this->responder)($system, $prompt)
            : ['action' => 'hold', 'confidence' => 0, 'veto' => false, 'reasons' => ['Cliente simulado.'], 'evidence_ids' => [], 'stop' => null, 'target' => null];

        return new LlmResponse($data, $model);
    }
}

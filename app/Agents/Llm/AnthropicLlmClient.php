<?php

namespace App\Agents\Llm;

use Anthropic\Client;

/**
 * Calls Claude through the official SDK and constrains the reply to a JSON
 * schema with structured outputs. Server-side refusal fallbacks are enabled so
 * a declined request is retried on the model's default fallback.
 */
class AnthropicLlmClient implements LlmClient
{
    public function __construct(private readonly Client $client) {}

    public function json(string $model, string $system, string $prompt, array $schema): LlmResponse
    {
        $message = $this->client->beta->messages->create(
            model: $model,
            maxTokens: 16000,
            system: $system,
            messages: [['role' => 'user', 'content' => $prompt]],
            outputConfig: [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
        );

        if ($message->stopReason === 'refusal') {
            throw new LlmException('O modelo recusou a solicitação.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                if (! is_array($data)) {
                    throw new LlmException('Resposta do modelo não é JSON válido.');
                }

                return new LlmResponse(
                    data: $data,
                    model: $message->model,
                    inputTokens: $message->usage->inputTokens,
                    outputTokens: $message->usage->outputTokens,
                );
            }
        }

        throw new LlmException('Resposta do modelo sem bloco de texto.');
    }
}

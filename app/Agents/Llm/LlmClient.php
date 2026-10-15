<?php

namespace App\Agents\Llm;

interface LlmClient
{
    /**
     * Ask a model for a JSON object that matches the given schema.
     *
     * @param  array<string, mixed>  $schema
     */
    public function json(string $model, string $system, string $prompt, array $schema): LlmResponse;
}

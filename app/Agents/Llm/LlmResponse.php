<?php

namespace App\Agents\Llm;

final readonly class LlmResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * Cost in US dollars using the per-million-token prices in config/agents.php.
     */
    public function cost(): float
    {
        $price = config('agents.pricing.'.$this->model, ['input' => 0, 'output' => 0]);

        return ($this->inputTokens * $price['input'] + $this->outputTokens * $price['output']) / 1_000_000;
    }
}

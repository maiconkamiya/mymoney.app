<?php

namespace App\Agents;

use App\Agents\Data\Scenario;
use App\Agents\Data\Vote;
use App\Agents\Llm\LlmClient;
use App\Enums\AgentRole;
use App\Enums\VoteAction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One voting specialist. Its behaviour comes from the role prompt in
 * config/agents.php; the scenario and the answer format are the same for all.
 */
class LlmAgent
{
    public function __construct(
        public readonly AgentRole $role,
        private readonly LlmClient $llm,
    ) {}

    /**
     * @param  list<Vote>  $priorVotes  First-round votes, shown to the skeptic and in the revision round.
     * @param  string|null  $objection  The skeptic's objection; when given, the agent may revise its vote once.
     */
    public function vote(Scenario $scenario, array $priorVotes = [], ?string $objection = null): Vote
    {
        if (($reason = $this->missingInput($scenario)) !== null) {
            return Vote::abstain($this->role, $reason);
        }

        $config = config('agents.roles.'.$this->role->value);
        $model = config('agents.models.'.$config['model']);

        $system = $config['prompt']."\n\n"
            .'Responda somente com o JSON pedido. confidence vai de 0 a 1. '
            .'Cite em evidence_ids os ids das notícias ou candles que sustentam o voto. '
            .'Preços de stop e alvo na moeda do ativo, ou null.';

        $prompt = "Cenário:\n".json_encode($scenario->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($priorVotes !== []) {
            $prompt .= "\n\nVotos da primeira rodada:\n".json_encode(array_map(fn (Vote $v) => [
                'agent' => $v->agent->value, 'action' => $v->action->value, 'confidence' => $v->confidence, 'reasons' => $v->reasons,
            ], $priorVotes), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if ($objection !== null) {
            $prompt .= "\n\nObjeção do cético:\n".$objection."\n\nMantenha ou revise seu voto considerando a objeção.";
        }

        try {
            $response = $this->llm->json($model, $system, $prompt, Vote::schema());
        } catch (Throwable $e) {
            Log::warning('Agent vote failed', ['role' => $this->role->value, 'error' => $e->getMessage()]);

            return Vote::abstain($this->role, 'Falha ao consultar o modelo: '.$e->getMessage());
        }

        $data = $response->data;

        return new Vote(
            agent: $this->role,
            action: VoteAction::tryFrom($data['action'] ?? '') ?? VoteAction::Hold,
            confidence: max(0, min(1, (float) ($data['confidence'] ?? 0))),
            veto: $this->role->canVeto() && (bool) ($data['veto'] ?? false),
            reasons: array_values(array_map('strval', $data['reasons'] ?? [])),
            evidenceIds: array_values(array_map('strval', $data['evidence_ids'] ?? [])),
            stop: isset($data['stop']) ? (float) $data['stop'] : null,
            target: isset($data['target']) ? (float) $data['target'] : null,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            cost: $response->cost(),
        );
    }

    /**
     * Why this agent has nothing to judge, or null when it has its input.
     * Checked before calling the model, so an agent without data neither costs
     * tokens nor drags the score toward zero.
     */
    private function missingInput(Scenario $scenario): ?string
    {
        return match ($this->role) {
            AgentRole::Quant => $scenario->forecast === null ? 'Previsão indisponível.' : null,
            AgentRole::News => $scenario->news === [] && $scenario->similarNews === [] ? 'Nenhuma notícia relevante no cenário.' : null,
            AgentRole::Fundamentals => $scenario->fundamentals === null ? 'Sem dados fundamentalistas para este ativo.' : null,
            default => null,
        };
    }
}

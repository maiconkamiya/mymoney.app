<?php

namespace App\Agents;

use App\Agents\Chart\ChartAnalyzer;
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

        $prompt = "Cenário:\n".json_encode($scenario->forRole($this->role), self::jsonFlags());

        if ($priorVotes !== []) {
            $prompt .= "\n\nVotos da primeira rodada:\n".json_encode(array_map(fn (Vote $v) => [
                'agent' => $v->agent->value, 'action' => $v->action->value, 'confidence' => $v->confidence, 'reasons' => $v->reasons,
            ], $priorVotes), self::jsonFlags());
        }

        if ($objection !== null) {
            $prompt .= "\n\nObjeção do cético:\n".$objection."\n\nMantenha ou revise seu voto considerando a objeção.";
        }

        try {
            $response = $this->llm->json($model, $system, $prompt, Vote::schema($this->role));
        } catch (Throwable $e) {
            Log::warning('Agent vote failed', ['role' => $this->role->value, 'error' => $e->getMessage()]);

            return Vote::abstain($this->role, 'Falha ao consultar o modelo: '.$e->getMessage());
        }

        $data = $response->data;
        $analysis = $this->role === AgentRole::Technical ? $this->analysis($data, $scenario) : null;
        [$stop, $target] = $this->levels($data, $scenario);

        return new Vote(
            agent: $this->role,
            action: VoteAction::tryFrom($data['action'] ?? '') ?? VoteAction::Hold,
            confidence: max(0, min(1, (float) ($data['confidence'] ?? 0))),
            veto: $this->role->canVeto() && (bool) ($data['veto'] ?? false),
            reasons: array_values(array_map('strval', $data['reasons'] ?? [])),
            evidenceIds: array_values(array_map('strval', $data['evidence_ids'] ?? [])),
            stop: $stop,
            target: $target,
            model: $response->model,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            cost: $response->cost(),
            analysis: $analysis,
        );
    }

    /**
     * The chart reading the Technical agent reports. Levels are kept only when
     * the agent named them as numbers; the scenario only when it is a known one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function analysis(array $data, Scenario $scenario): array
    {
        $numbers = fn (mixed $list) => array_values(array_map('floatval', array_filter((array) $list, 'is_numeric')));

        return [
            'scenario' => in_array($data['chart_scenario'] ?? null, Vote::CHART_SCENARIOS, true) ? $data['chart_scenario'] : null,
            'supports' => $numbers($data['supports'] ?? []),
            'resistances' => $numbers($data['resistances'] ?? []),
            'timeframes' => array_values(array_map('strval', (array) ($data['timeframes'] ?? []))),
            'omitted_timeframes' => array_keys($scenario->chart['omitted'] ?? []),
        ];
    }

    /**
     * Stop and target as the model gave them, dropped when they sit on the
     * wrong side of the price for the action (the guard and the consensus never
     * see a stop above a long entry).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: float|null, 1: float|null}
     */
    private function levels(array $data, Scenario $scenario): array
    {
        $stop = isset($data['stop']) ? (float) $data['stop'] : null;
        $target = isset($data['target']) ? (float) $data['target'] : null;
        $direction = VoteAction::tryFrom($data['action'] ?? '')?->direction() ?? 0;

        if ($this->role !== AgentRole::Technical || $direction === 0) {
            return [$stop, $target];
        }

        if ($stop !== null && ($stop - $scenario->price) * $direction >= 0) {
            $stop = null;
        }

        if ($target !== null && ($target - $scenario->price) * $direction <= 0) {
            $target = null;
        }

        return [$stop, $target];
    }

    /**
     * How scenarios are written for the model: compact when the cost switch is on
     * (same data, fewer input tokens), indented otherwise.
     */
    public static function jsonFlags(): int
    {
        return config('agents.compact_json') ? JSON_UNESCAPED_UNICODE : JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
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
            AgentRole::Technical => $scenario->chart === null || ! $scenario->chart['usable']
                ? 'Sem candles suficientes para ler o gráfico: nenhum tempo gráfico tem '.ChartAnalyzer::MIN_BARS.' barras.'
                : null,
            default => null,
        };
    }
}

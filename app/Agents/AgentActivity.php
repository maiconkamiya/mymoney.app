<?php

namespace App\Agents;

use App\Enums\AgentRole;
use App\Enums\SignalStage;
use App\Enums\VoteAction;
use App\Models\AgentVote;
use App\Models\Portfolio;
use App\Models\Signal;
use Illuminate\Support\Collection;

/**
 * Turns what the swarm recorded for a portfolio (signals, votes per round,
 * veto, stage and orders) into the picture the agents card draws: what each
 * agent is doing and which messages passed between them. It only reads;
 * everything shown comes from the stored cycle.
 */
class AgentActivity
{
    /** A cycle counts as "ao vivo" for this long after its last change. */
    public const LIVE_WINDOW_SECONDS = 120;

    /**
     * @return array{
     *     status: 'live'|'idle'|'empty',
     *     signal: array<string, mixed>|null,
     *     agents: list<array<string, mixed>>,
     *     messages: list<array<string, mixed>>,
     *     recent: list<array<string, mixed>>,
     *     generated_at: string,
     * }
     */
    public function for(Portfolio $portfolio): array
    {
        /** @var Signal|null $signal */
        $signal = $portfolio->signals()->with(['asset', 'votes', 'orders'])->latest('updated_at')->latest('id')->first();

        $recent = array_values($portfolio->signals()->with('asset')->latest('updated_at')->latest('id')->limit(6)->get()
            ->map(fn (Signal $item) => [
                'id' => $item->id,
                'symbol' => $item->asset->symbol,
                'stage' => $item->stage->value,
                'side' => $item->side?->value,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ])->all());

        if ($signal === null) {
            return [
                'status' => 'empty',
                'signal' => null,
                'agents' => $this->agents(null, collect()),
                'messages' => [],
                'recent' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $votes = $signal->votes->sortBy([['round', 'asc'], ['id', 'asc']])->values();
        $inFlight = in_array($signal->stage, [SignalStage::Scanned, SignalStage::Analyzed], true);
        $fresh = $signal->updated_at !== null && $signal->updated_at->diffInSeconds(now(), true) <= self::LIVE_WINDOW_SECONDS;

        return [
            'status' => $inFlight || $fresh ? 'live' : 'idle',
            'signal' => [
                'id' => $signal->id,
                'symbol' => $signal->asset->symbol,
                'stage' => $signal->stage->value,
                'side' => $signal->side?->value,
                'score' => $signal->score,
                'reason' => $signal->decision_reason,
                'updated_at' => $signal->updated_at?->toIso8601String(),
                'version' => $signal->id.':'.$signal->stage->value.':'.$votes->count().':'.$signal->updated_at?->getTimestamp(),
            ],
            'agents' => $this->agents($signal, $votes),
            'messages' => $this->messages($signal, $votes),
            'recent' => $recent,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, AgentVote>  $votes
     * @return list<array<string, mixed>>
     */
    private function agents(?Signal $signal, Collection $votes): array
    {
        $final = $votes->groupBy(fn (AgentVote $vote) => $vote->agent->value)->map(fn (Collection $group) => $group->last());
        $stage = $signal?->stage;
        $collecting = $stage === SignalStage::Scanned;

        $agents = [];

        foreach (AgentRole::cases() as $role) {
            $agents[] = match ($role) {
                AgentRole::Coordinator => $this->coordinator($stage),
                AgentRole::Executor => $this->executor($signal),
                default => $this->voter($role, $signal, $final->get($role->value), $collecting),
            };
        }

        return $agents;
    }

    /**
     * @return array<string, mixed>
     */
    private function coordinator(?SignalStage $stage): array
    {
        [$state, $detail] = match ($stage) {
            null => ['idle', 'Sem ciclos ainda nesta carteira.'],
            SignalStage::Scanned => ['analyzing', 'Montando o cenário e coletando os votos.'],
            SignalStage::Analyzed => ['analyzing', 'Calculando o consenso.'],
            SignalStage::Rejected => ['rejected', 'Consenso não atingido, sinal rejeitado.'],
            default => ['approved', 'Consenso atingido, sinal aprovado.'],
        };

        return ['role' => AgentRole::Coordinator->value, 'state' => $state, 'detail' => $detail, 'action' => null, 'confidence' => null, 'round' => null, 'veto' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function executor(?Signal $signal): array
    {
        [$state, $detail] = match ($signal?->stage) {
            SignalStage::Approved => ['waiting', 'Aguardando a confirmação para enviar a ordem.'],
            SignalStage::Confirmed => ['analyzing', 'Enviando a ordem.'],
            SignalStage::Executed, SignalStage::Closed => ['approved', 'Ordem executada.'],
            default => ['idle', 'Sem ordem para enviar.'],
        };

        return ['role' => AgentRole::Executor->value, 'state' => $state, 'detail' => $detail, 'action' => null, 'confidence' => null, 'round' => null, 'veto' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function voter(AgentRole $role, ?Signal $signal, ?AgentVote $vote, bool $collecting): array
    {
        if ($vote === null) {
            return [
                'role' => $role->value,
                'state' => $collecting ? 'analyzing' : 'idle',
                'detail' => $collecting ? 'Analisando o cenário.' : 'Sem voto neste ciclo.',
                'action' => null,
                'confidence' => null,
                'round' => null,
                'veto' => false,
            ];
        }

        $state = $this->stance($signal, $vote);

        $verbo = match ($state) {
            'veto' => 'Vetou',
            'abstained' => 'Absteve-se',
            'neutral' => 'Ficou neutro',
            'approved' => 'Aprovou',
            default => 'Rejeitou',
        };
        $detail = $vote->abstained
            ? 'Absteve-se, fora do score.'
            : sprintf('%s: %s com %d%% de confiança.', $verbo, strtolower($this->actionLabel($vote->action)), round($vote->confidence * 100));

        if (($vote->reasons[0] ?? null) !== null) {
            $detail .= ' '.$vote->reasons[0];
        }

        return [
            'role' => $role->value,
            'state' => $state,
            'detail' => $detail,
            'action' => $vote->action->value,
            'confidence' => $vote->confidence,
            'round' => $vote->round,
            'veto' => $vote->veto,
            'abstained' => $vote->abstained,
        ];
    }

    private function stance(?Signal $signal, AgentVote $vote): string
    {
        return match (true) {
            $vote->veto => 'veto',
            $vote->abstained => 'abstained',
            $vote->action === VoteAction::Hold => 'neutral',
            $signal?->side === null || $vote->action->value === $signal->side->value => 'approved',
            default => 'rejected',
        };
    }

    /**
     * Who said what to whom, in the order it happened.
     *
     * @param  Collection<int, AgentVote>  $votes
     * @return list<array<string, mixed>>
     */
    private function messages(Signal $signal, Collection $votes): array
    {
        $messages = [];

        foreach ($votes as $vote) {
            if ($vote->agent === AgentRole::Skeptic) {
                $messages[] = [
                    'from' => AgentRole::Skeptic->value,
                    'to' => 'voters',
                    'kind' => 'challenge',
                    'state' => 'challenge',
                    'round' => $vote->round,
                    'text' => $vote->reasons[0] ?? 'Contestou as conclusões da primeira rodada.',
                ];
            }

            $messages[] = [
                'from' => $vote->agent->value,
                'to' => AgentRole::Coordinator->value,
                'kind' => $vote->veto ? 'veto' : ($vote->round > 1 ? 'revision' : 'vote'),
                'state' => $this->stance($signal, $vote),
                'round' => $vote->round,
                'text' => $vote->abstained
                    ? 'Absteve-se: '.($vote->reasons[0] ?? 'sem dados para opinar.')
                    : sprintf('%s com %d%% de confiança.', $this->actionLabel($vote->action), round($vote->confidence * 100)),
            ];
        }

        if (in_array($signal->stage, [SignalStage::Approved, SignalStage::Confirmed, SignalStage::Executed, SignalStage::Closed], true)) {
            $messages[] = [
                'from' => AgentRole::Coordinator->value,
                'to' => AgentRole::Executor->value,
                'kind' => 'decision',
                'state' => 'approved',
                'round' => null,
                'text' => 'Sinal aprovado'.($signal->side !== null ? ' ('.($signal->side->value === 'buy' ? 'compra' : 'venda').')' : '').'.',
            ];
        }

        return $messages;
    }

    private function actionLabel(VoteAction $action): string
    {
        return match ($action) {
            VoteAction::Buy => 'Compra',
            VoteAction::Sell => 'Venda',
            VoteAction::Hold => 'Aguardar',
        };
    }
}

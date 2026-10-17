import type {
    AgentActivity,
    AgentActivityAgent,
    AgentActivityMessage,
    AgentLiveEvent,
} from '@/types';

const ACTION_TEXT = { buy: 'Compra', sell: 'Venda', hold: 'Aguardar' } as const;

function patchAgent(
    activity: AgentActivity,
    role: string,
    patch: Partial<AgentActivityAgent>,
): AgentActivityAgent[] {
    return activity.agents.map((agent) =>
        agent.role === role ? { ...agent, ...patch } : agent,
    );
}

function voteState(event: {
    veto: boolean;
    abstained: boolean;
    action: 'buy' | 'sell' | 'hold';
}): AgentActivityAgent['state'] {
    if (event.veto) return 'veto';
    if (event.abstained) return 'abstained';

    return event.action === 'hold' ? 'neutral' : event.action;
}

/**
 * Folds one real agent event into the activity the card shows. The server
 * sends the full picture when a cycle is decided; in between, the events
 * alone say who is doing what.
 */
export function applyAgentEvent(
    activity: AgentActivity,
    event: AgentLiveEvent,
): AgentActivity {
    switch (event.type) {
        case 'cycle.started':
            return {
                ...activity,
                status: 'live',
                signal: {
                    id: event.signal.id,
                    symbol: event.signal.symbol,
                    stage: event.signal.stage,
                    side: null,
                    score: null,
                    reason: null,
                    updated_at: event.at,
                    version: `${event.signal.id}:live`,
                },
                messages: [],
                agents: activity.agents.map((agent) => ({
                    ...agent,
                    state: agent.role === 'coordinator' ? 'analyzing' : 'idle',
                    detail:
                        agent.role === 'coordinator'
                            ? 'Montando o cenário e coletando os votos.'
                            : 'Aguardando a vez.',
                    action: null,
                    confidence: null,
                    round: null,
                    veto: false,
                })),
            };

        case 'agent.started':
            return {
                ...activity,
                status: 'live',
                agents: patchAgent(activity, event.role, {
                    state: 'analyzing',
                    detail: 'Consultando o modelo e lendo os dados.',
                }),
            };

        case 'agent.voted': {
            const message: AgentActivityMessage = {
                from: event.role,
                to: 'coordinator',
                kind: event.veto
                    ? 'veto'
                    : event.round > 1
                      ? 'revision'
                      : 'vote',
                state: voteState(event),
                round: event.round,
                text: event.abstained
                    ? `Absteve-se: ${event.text ?? 'sem dados para opinar.'}`
                    : `${ACTION_TEXT[event.action]} com ${Math.round(event.confidence * 100)}% de confiança.`,
            };
            const messages = [...activity.messages];

            if (event.role === 'skeptic') {
                messages.push({
                    from: 'skeptic',
                    to: 'voters',
                    kind: 'challenge',
                    state: 'challenge',
                    round: event.round,
                    text: event.text ?? 'Contestou a primeira rodada.',
                });
            }

            messages.push(message);

            return {
                ...activity,
                messages,
                agents: patchAgent(activity, event.role, {
                    state: voteState(event),
                    detail: event.abstained
                        ? `Absteve-se, fora do score.${event.text ? ` ${event.text}` : ''}`
                        : `Votou ${ACTION_TEXT[event.action].toLowerCase()} com ${Math.round(event.confidence * 100)}% de confiança.${event.text ? ` ${event.text}` : ''}`,
                    action: event.action,
                    confidence: event.confidence,
                    round: event.round,
                    veto: event.veto,
                }),
            };
        }

        case 'order.started':
            return {
                ...activity,
                agents: patchAgent(activity, 'executor', {
                    state: 'analyzing',
                    detail: 'Enviando a ordem.',
                }),
            };

        case 'consensus.decided':
        case 'order.finished':
            return event.snapshot;
    }
}

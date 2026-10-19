<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';
import PortfolioExplorerFollowController from '@/actions/App/Http/Controllers/PortfolioExplorerFollowController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import type { ExplorerFollow } from '@/types';

const props = defineProps<{
    portfolioId: number;
    follow: ExplorerFollow;
    trackedCount: number;
    agentsEnabled: boolean;
}>();

const hora = (iso: string) =>
    new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });

const lastScan = computed(() =>
    props.follow.last_scan_at ? hora(props.follow.last_scan_at) : null,
);
</script>

<template>
    <section
        class="space-y-4 rounded-2xl border bg-card p-4"
        data-test="explorer-follow"
    >
        <div class="space-y-1">
            <div class="flex items-center justify-between gap-3">
                <h2 class="font-medium">Seguir o explorador</h2>
                <span
                    class="rounded-full px-2 py-0.5 text-xs"
                    :class="
                        follow.enabled
                            ? 'bg-emerald-500/15 text-emerald-400'
                            : 'bg-muted text-muted-foreground'
                    "
                >
                    {{ follow.enabled ? 'Ligado' : 'Desligado' }}
                </span>
            </div>
            <p class="text-sm text-muted-foreground">
                A cada varredura, os ativos acompanhados viram o top
                {{ follow.size }} do explorador: entra quem subiu no ranking,
                sai quem caiu dele. Sair da lista nunca vende nada: com posição,
                ciclo micro, ordem ou sinal em andamento, o ativo fica até
                terminar.
            </p>
        </div>

        <Form
            v-bind="PortfolioExplorerFollowController.update.form(portfolioId)"
            v-slot="{ errors, processing }"
            :options="{
                preserveScroll: true,
                only: ['explorer_follow', 'tracked_asset_ids', 'available_assets', 'micro'],
            }"
            class="space-y-3"
        >
            <label class="flex items-start gap-2 text-sm">
                <input type="hidden" name="enabled" value="0" />
                <input
                    type="checkbox"
                    name="enabled"
                    value="1"
                    class="mt-0.5"
                    :checked="follow.enabled"
                    data-test="explorer-follow-enabled"
                />
                <span>Manter os acompanhados no ranking do explorador</span>
            </label>

            <div class="grid grid-cols-2 gap-3">
                <label class="space-y-1 text-sm">
                    <span class="text-muted-foreground">Quantos ativos</span>
                    <input
                        type="number"
                        name="size"
                        min="1"
                        :max="follow.max_size"
                        :value="follow.size"
                        class="w-full rounded-lg border bg-transparent px-2 py-1.5 font-mono tabular-nums"
                    />
                    <InputError :message="errors.size" />
                </label>
                <label class="space-y-1 text-sm">
                    <span class="text-muted-foreground"
                        >Permanência mínima (h)</span
                    >
                    <input
                        type="number"
                        name="min_hours"
                        min="0"
                        max="72"
                        :value="follow.min_hours"
                        class="w-full rounded-lg border bg-transparent px-2 py-1.5 font-mono tabular-nums"
                    />
                    <InputError :message="errors.min_hours" />
                </label>
            </div>
            <InputError :message="errors.enabled" />

            <div class="flex items-center justify-between gap-3">
                <p class="text-xs text-muted-foreground">
                    <template v-if="!follow.explorer_enabled">
                        O explorador está desligado: nada muda até ele voltar.
                    </template>
                    <template v-else-if="lastScan">
                        Última varredura {{ lastScan }}, a cada
                        {{ follow.interval_minutes }} min.
                    </template>
                    <template v-else>Nenhuma varredura ainda.</template>
                </p>
                <Button
                    :disabled="processing"
                    variant="outline"
                    size="sm"
                    data-test="explorer-follow-save"
                >
                    Salvar
                </Button>
            </div>
        </Form>

        <p
            v-if="agentsEnabled"
            class="rounded-xl bg-amber-500/10 p-3 text-xs text-amber-400"
        >
            O explorador não usa IA por carteira. Com os agentes em ação
            ligados, porém, cada ativo acompanhado entra na varredura do enxame:
            hoje são {{ trackedCount }}, seguindo o explorador serão até
            {{ follow.size }}, e o gasto de IA cresce na mesma proporção.
        </p>

        <div v-if="follow.history.length" class="space-y-2">
            <h3 class="text-sm font-medium">Últimas mudanças</h3>
            <ul class="space-y-1.5 text-sm" data-test="explorer-follow-history">
                <li
                    v-for="(change, i) in follow.history"
                    :key="`${change.at}-${change.symbol}-${i}`"
                    class="flex items-baseline gap-2"
                >
                    <span
                        class="w-12 shrink-0 text-xs"
                        :class="
                            change.kind === 'added'
                                ? 'text-emerald-400'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ change.kind === 'added' ? 'Entrou' : 'Saiu' }}
                    </span>
                    <span class="font-mono">{{ change.symbol }}</span>
                    <span class="truncate text-xs text-muted-foreground">
                        {{ change.reason }}
                    </span>
                    <span
                        class="ml-auto shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ hora(change.at) }}
                    </span>
                </li>
            </ul>
        </div>
    </section>
</template>

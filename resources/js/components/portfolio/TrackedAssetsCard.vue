<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { update } from '@/actions/App/Http/Controllers/PortfolioAssetsController';
import { Button } from '@/components/ui/button';
import { avisoFimNegociacao, dataCurta } from '@/lib/fimNegociacao';
import { index as assetsIndex } from '@/routes/assets';
import type { AvailableAsset } from '@/types';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    portfolioId: number;
    available: AvailableAsset[];
    tracked: number[];
    // Seguindo o explorador: a lista é dele, só leitura aqui.
    locked?: boolean;
}>();

const selected = ref<number[]>([...props.tracked]);
const saving = ref(false);

// Mudou em outra aba ou pelo servidor: acompanha, a menos que haja uma edição por salvar aqui.
watch(
    () => props.tracked,
    (now, before) => {
        if ([...selected.value].sort().join() === [...before].sort().join()) {
            selected.value = [...now];
        }
    },
);

// Ativo removido do cadastro (tela Ativos, explorador) sai da marcação aqui também.
watch(
    () => props.available.map((asset) => asset.id),
    (ids) => {
        selected.value = selected.value.filter((id) => ids.includes(id));
    },
);

const dirty = computed(
    () =>
        [...selected.value].sort().join() !== [...props.tracked].sort().join(),
);

function save() {
    saving.value = true;

    router.put(
        update.url(props.portfolioId),
        { asset_ids: selected.value },
        {
            preserveScroll: true,
            only: ['tracked_asset_ids', 'micro'],
            onFinish: () => (saving.value = false),
        },
    );
}
</script>

<template>
    <section class="space-y-3 rounded-2xl border bg-card p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-medium">Ativos acompanhados</h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        locked
                            ? `${selected.length} marcado(s) pelo explorador. Desligue "Seguir o explorador" para escolher à mão.`
                            : selected.length === 0
                              ? 'Nenhum marcado: a carteira acompanha todos os ativos cadastrados do mercado.'
                              : `${selected.length} marcado(s): só esses entram nos sinais desta carteira.`
                    }}
                    <Link
                        :href="assetsIndex()"
                        class="underline underline-offset-4"
                    >
                        Cadastrar mais ativos
                    </Link>
                </p>
            </div>
            <div v-if="!locked" class="flex gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    :disabled="selected.length === 0"
                    @click="selected = []"
                >
                    Limpar
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!dirty || saving"
                    @click="save"
                >
                    Salvar
                </Button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-2 @sm:grid-cols-3 @3xl:grid-cols-6">
            <label
                v-for="asset in available"
                :key="asset.id"
                class="flex items-center gap-2 rounded-lg border px-2 py-1.5 text-sm"
                :class="[
                    selected.includes(asset.id) ? 'border-primary' : '',
                    locked ? 'cursor-default' : 'cursor-pointer',
                ]"
            >
                <input
                    v-model="selected"
                    type="checkbox"
                    :value="asset.id"
                    :disabled="locked"
                />
                {{ asset.symbol }}
                <span
                    v-if="asset.trading_ends_on"
                    class="ml-auto text-xs text-amber-400"
                    :title="
                        avisoFimNegociacao(
                            asset.trading_ends_on,
                            asset.trading_ended,
                        ) ?? undefined
                    "
                    data-test="tracked-trading-end"
                >
                    {{
                        asset.trading_ended
                            ? 'Encerrado'
                            : `Até ${dataCurta(asset.trading_ends_on).slice(0, 5)}`
                    }}
                </span>
            </label>
        </div>
    </section>
</template>

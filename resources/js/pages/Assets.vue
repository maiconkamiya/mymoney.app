<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { marketLabels } from '@/lib/trading';
import { destroy, index, search, store } from '@/routes/assets';
import type { AssetRow, AssetSearchResult, Market } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ativos', href: index() }],
    },
});

const props = defineProps<{
    assets: AssetRow[];
}>();

const market = ref<Market>('crypto');
const query = ref('');
const results = ref<AssetSearchResult[]>([]);
const searching = ref(false);
const searchError = ref<string | null>(null);
const errors = ref<Record<string, string>>({});
const adding = ref<string | null>(null);

let timer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | undefined;

const placeholders: Record<Market, string> = {
    crypto: 'Pesquise um par em USDT, como SOL ou DOGE',
    b3: 'Pesquise um ticker, como PETR4 ou MGLU3',
};

async function runSearch() {
    controller?.abort();
    searchError.value = null;

    if (query.value.trim().length < 2) {
        results.value = [];
        searching.value = false;

        return;
    }

    controller = new AbortController();
    searching.value = true;

    try {
        const response = await fetch(
            search.url({
                query: { market: market.value, q: query.value.trim() },
            }),
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        const body = await response.json();

        if (!response.ok) {
            results.value = [];
            searchError.value =
                body.message ?? 'Não foi possível pesquisar agora.';
        } else {
            results.value = body.results;
        }
    } catch (e) {
        if ((e as Error).name !== 'AbortError') {
            searchError.value = 'Não foi possível pesquisar agora.';
        }
    } finally {
        searching.value = false;
    }
}

watch([query, market], () => {
    clearTimeout(timer);
    timer = setTimeout(runSearch, 350);
});

watch(market, () => {
    results.value = [];
    errors.value = {};
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

function add(symbol: string) {
    adding.value = symbol;
    errors.value = {};

    router.post(
        store.url(),
        { market: market.value, symbol },
        {
            preserveScroll: true,
            onError: (e) => (errors.value = e),
            onSuccess: () => {
                results.value = results.value.map((r) =>
                    r.symbol === symbol ? { ...r, registered: true } : r,
                );
            },
            onFinish: () => (adding.value = null),
        },
    );
}

function remove(asset: AssetRow) {
    if (!confirm(`Remover ${asset.symbol}?`)) {
        return;
    }

    errors.value = {};
    router.delete(destroy.url(asset.id), {
        preserveScroll: true,
        onError: (e) => (errors.value = e),
    });
}

const typed = computed(() => query.value.trim().toUpperCase());
const canAddTyped = computed(
    () =>
        typed.value.length >= 2 &&
        results.value.length === 0 &&
        !props.assets.some(
            (a) => a.market === market.value && a.symbol === typed.value,
        ),
);

const grouped = computed(() =>
    (['crypto', 'b3'] as Market[]).map((m) => ({
        market: m,
        rows: props.assets.filter((a) => a.market === m),
    })),
);
</script>

<template>
    <Head title="Ativos" />

    <div
        class="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8"
    >
        <Heading
            title="Ativos"
            description="Cadastre criptos da Binance e papéis da B3. O coletor e o scanner passam a cobri-los sozinhos; cada carteira escolhe quais acompanha."
        />

        <section class="space-y-4 rounded-2xl border bg-card p-4">
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-for="m in ['crypto', 'b3'] as Market[]"
                    :key="m"
                    type="button"
                    size="sm"
                    :variant="market === m ? 'default' : 'outline'"
                    @click="market = m"
                >
                    {{ marketLabels[m] }}
                </Button>
            </div>

            <Input
                v-model="query"
                type="search"
                autocomplete="off"
                :placeholder="placeholders[market]"
                aria-label="Pesquisar ativo"
            />

            <p v-if="searching" class="text-sm text-muted-foreground">
                Pesquisando...
            </p>
            <p v-else-if="searchError" class="text-sm text-destructive">
                {{ searchError }}
            </p>
            <p
                v-else-if="query.trim().length >= 2 && results.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nada encontrado para "{{ query.trim() }}".
                {{
                    market === 'crypto'
                        ? 'Só aparecem pares spot em USDT negociáveis na Binance.'
                        : 'Se você sabe o ticker, pode adicionar direto: ele é conferido antes de salvar.'
                }}
            </p>

            <ul v-if="results.length" class="divide-y rounded-xl border">
                <li
                    v-for="row in results"
                    :key="row.symbol"
                    class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                >
                    <span>
                        <span class="font-medium">{{ row.symbol }}</span>
                        <span
                            v-if="row.name"
                            class="ml-2 text-muted-foreground"
                        >
                            {{ row.name }}
                        </span>
                    </span>
                    <Badge v-if="row.registered" variant="secondary">
                        Cadastrado
                    </Badge>
                    <Button
                        v-else
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="adding !== null"
                        @click="add(row.symbol)"
                    >
                        {{
                            adding === row.symbol
                                ? 'Adicionando...'
                                : 'Adicionar'
                        }}
                    </Button>
                </li>
            </ul>

            <Button
                v-if="canAddTyped && !searching"
                type="button"
                variant="outline"
                size="sm"
                :disabled="adding !== null"
                @click="add(typed)"
            >
                Adicionar {{ typed }}
            </Button>

            <InputError :message="errors.symbol ?? errors.market" />
            <InputError :message="errors.asset" />
        </section>

        <section v-for="group in grouped" :key="group.market" class="space-y-3">
            <Heading
                variant="small"
                :title="`${marketLabels[group.market]} (${group.rows.length})`"
            />

            <div
                v-if="group.rows.length === 0"
                class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nenhum ativo cadastrado neste mercado.
            </div>

            <div v-else class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Ativo</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Lote
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Variação mínima
                            </th>
                            <th
                                v-if="group.market === 'crypto'"
                                class="px-4 py-2 text-right font-medium"
                            >
                                Ordem mínima
                            </th>
                            <th class="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="asset in group.rows"
                            :key="asset.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <span class="font-medium">{{
                                    asset.symbol
                                }}</span>
                                <span
                                    v-if="asset.name"
                                    class="ml-2 text-muted-foreground"
                                >
                                    {{ asset.name }}
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ Number(asset.lot_size) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ Number(asset.tick_size) }}
                            </td>
                            <td
                                v-if="group.market === 'crypto'"
                                class="px-4 py-2 text-right tabular-nums"
                            >
                                {{
                                    asset.min_notional
                                        ? `${Number(asset.min_notional)} USDT`
                                        : '-'
                                }}
                            </td>
                            <td class="px-4 py-2 text-right">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    :disabled="asset.blockers.length > 0"
                                    :title="asset.blockers.join(' ')"
                                    @click="remove(asset)"
                                >
                                    Remover
                                </Button>
                                <p
                                    v-if="asset.blockers.length"
                                    class="mt-1 max-w-xs text-xs text-muted-foreground"
                                >
                                    {{ asset.blockers.join(' ') }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>

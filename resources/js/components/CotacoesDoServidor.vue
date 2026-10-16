<script setup lang="ts">
import { echoIsConfigured, useEchoPublic } from '@laravel/echo-vue';
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';

type Cotacao = { symbol: string; price: number; recebidaEm: number };

const SIMBOLOS = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT'];

const realtime = echoIsConfigured();
const cotacoes = reactive<Record<string, Cotacao | undefined>>({});
const agora = ref(Date.now());
let relogio: number | undefined;

if (realtime) {
    useEchoPublic<{ symbol: string; price: number }>(
        'mercado',
        '.cotacao.atualizada',
        (event) => {
            cotacoes[event.symbol] = {
                symbol: event.symbol,
                price: event.price,
                recebidaEm: Date.now(),
            };
        },
    );
}

onMounted(() => {
    relogio = window.setInterval(() => (agora.value = Date.now()), 1000);
});
onBeforeUnmount(() => window.clearInterval(relogio));

const preco = (v: number) =>
    v.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

function atraso(c: Cotacao | undefined): string {
    if (!c) {
        return 'aguardando';
    }

    const s = Math.max(0, Math.round((agora.value - c.recebidaEm) / 1000));

    return s < 5 ? 'agora' : `há ${s} s`;
}
</script>

<template>
    <section v-if="realtime" aria-label="Cotações gravadas pelo servidor">
        <h3 class="text-sm font-medium">Cotações do coletor (USDT)</h3>
        <p class="mt-1 text-xs text-muted-foreground">
            O que o servidor está gravando para os agentes, atualizado a cada
            segundo. Sem atualização, o coletor não está rodando.
        </p>
        <dl class="mt-3 grid grid-cols-3 gap-4">
            <div v-for="simbolo in SIMBOLOS" :key="simbolo">
                <dt class="text-xs text-muted-foreground">
                    {{ simbolo.replace('USDT', '') }}
                    <span class="ml-1">{{ atraso(cotacoes[simbolo]) }}</span>
                </dt>
                <dd class="num text-base font-semibold">
                    {{
                        cotacoes[simbolo]
                            ? preco(cotacoes[simbolo]!.price)
                            : '...'
                    }}
                </dd>
            </div>
        </dl>
    </section>
</template>

<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import Reveal from './Reveal.vue';

const faq = [
    {
        q: 'O MyMoney opera com dinheiro de verdade?',
        a: 'Não por padrão. Toda carteira começa simulada. Operar de verdade exige uma carteira real, credencial da corretora sem permissão de saque e sua aprovação explícita.',
    },
    {
        q: 'De onde vêm as cotações?',
        a: 'Da API pública da Binance, nos pares em real (BTC/BRL, ETH/BRL e SOL/BRL). Na simulação, o app nunca usa preço posterior ao momento simulado.',
    },
    {
        q: 'Quem decide se uma operação acontece?',
        a: 'Seis agentes votam e o consenso precisa de nota mínima e maioria. Depois disso, a guarda de risco ainda pode barrar a ordem, e ela tem a palavra final.',
    },
    {
        q: 'Consigo ver por que uma operação foi recusada?',
        a: 'Sim. Cada rodada guarda os votos, a confiança de cada agente e o motivo da recusa, seja nota baixa, falta de maioria ou veto de risco.',
    },
    {
        q: 'O app também acompanha ações?',
        a: 'Sim, ações da B3 com o horário do pregão. Esta página mostra só a parte de cripto, que funciona 24 horas.',
    },
];
</script>

<template>
    <section
        id="duvidas"
        class="mx-auto max-w-3xl px-4 py-20 sm:px-6 md:py-24 lg:px-8"
    >
        <Reveal>
            <h2 class="text-3xl font-semibold tracking-tight md:text-4xl">
                Dúvidas comuns
            </h2>
        </Reveal>
        <div class="mt-10 grid gap-3">
            <details
                v-for="(f, i) in faq"
                :key="i"
                class="group rounded-2xl bg-card px-5 open:pb-5"
            >
                <summary
                    class="press flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-medium [&::-webkit-details-marker]:hidden"
                >
                    {{ f.q }}
                    <ChevronDown
                        :size="18"
                        class="shrink-0 text-muted-foreground transition-transform duration-300 group-open:rotate-180"
                    />
                </summary>
                <p class="max-w-[62ch] leading-relaxed text-muted-foreground">
                    {{ f.a }}
                </p>
            </details>
        </div>
    </section>
</template>

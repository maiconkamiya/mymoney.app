<script setup lang="ts">
import { Hand, Ban, Clock, TrendingDown } from '@lucide/vue';
import Reveal from './Reveal.vue';
import riscoImg from '../../../images/landing/risco.webp';

// Regras reais da guarda de risco do app (app/Trading/Risk/RiskGuard.php).
const rules = [
    {
        icon: Hand,
        title: 'Parada geral',
        text: 'Um botão interrompe todas as ordens, de todos os agentes, na hora.',
    },
    {
        icon: Ban,
        title: 'Toda compra tem stop',
        text: 'Sem preço de saída definido, a ordem nem chega à corretora.',
    },
    {
        icon: Clock,
        title: 'Cotação atrasada bloqueia',
        text: 'Se o preço chegou velho, ninguém opera em cima dele.',
    },
    {
        icon: TrendingDown,
        title: 'Limite de perda diária',
        text: 'Bateu a perda máxima do dia, novas compras param até amanhã.',
    },
];
</script>

<template>
    <section
        id="risco"
        class="mx-auto max-w-[1400px] px-4 py-20 sm:px-6 md:py-24 lg:px-8"
    >
        <Reveal>
            <h2
                class="max-w-[24ch] text-3xl font-semibold tracking-tight md:text-4xl"
            >
                Os agentes sugerem. A guarda de risco decide.
            </h2>
            <p class="mt-3 max-w-[62ch] text-muted-foreground">
                Regras fixas, sem modelo de IA, aplicadas a toda ordem simulada
                ou real. Valem mesmo se todos os agentes errarem.
            </p>
        </Reveal>
        <Reveal>
            <img
                :src="riscoImg"
                alt="Barraca montada na neve ao pé de uma montanha"
                width="1600"
                height="900"
                class="mt-10 aspect-[16/9] w-full max-w-full rounded-2xl object-cover md:aspect-[21/8]"
            />
        </Reveal>
        <div class="mt-12 grid gap-x-12 gap-y-10 md:grid-cols-2">
            <Reveal v-for="(r, i) in rules" :key="r.title" :delay="0.06 * i">
                <div class="flex gap-4">
                    <component
                        :is="r.icon"
                        :size="28"
                        class="shrink-0 text-primary"
                    />
                    <div>
                        <h3 class="text-lg font-semibold">{{ r.title }}</h3>
                        <p class="mt-1 max-w-[46ch] text-muted-foreground">
                            {{ r.text }}
                        </p>
                    </div>
                </div>
            </Reveal>
        </div>
    </section>
</template>

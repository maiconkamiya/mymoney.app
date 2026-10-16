<script setup lang="ts">
import { onMounted, onBeforeUnmount } from 'vue';
import { motion, AnimatePresence } from 'motion-v';
import { CircleCheck, CircleX } from '@lucide/vue';
import { rounds, nextRound } from '@/lib/consensoExemplo';
import { brl, hora } from '@/lib/mercado';

let timer = 0;
onMounted(() => {
    // A primeira rodada sai na hora, para a seção abrir com conteúdo.
    nextRound();
    timer = window.setInterval(nextRound, 7000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const chip = (a: string) =>
    a === 'compra'
        ? 'bg-up/15 text-up'
        : a === 'venda'
          ? 'bg-down/15 text-down'
          : 'bg-muted text-muted-foreground';
</script>

<template>
    <section id="consenso" class="border-y border-border bg-card/60">
        <div
            class="mx-auto grid max-w-[1400px] gap-12 px-4 py-20 sm:px-6 md:py-24 lg:grid-cols-12 lg:px-8"
        >
            <div class="lg:col-span-5">
                <div class="lg:sticky lg:top-28">
                    <h2
                        class="text-3xl font-semibold tracking-tight md:text-4xl"
                    >
                        O consenso acontece na sua frente
                    </h2>
                    <p class="mt-4 max-w-[52ch] text-muted-foreground">
                        A cada rodada, seis votos viram uma nota entre -1 e 1. A
                        operação passa com nota de pelo menos 0,35 e quatro
                        votos na mesma direção.
                    </p>
                    <p class="mt-4 max-w-[52ch] text-sm text-muted-foreground">
                        Exemplo calculado no navegador sobre os negócios reais
                        acima, com a mesma regra do app. Nenhum modelo de IA é
                        chamado nesta página.
                    </p>
                </div>
            </div>

            <div class="min-w-0 lg:col-span-7">
                <ol
                    class="grid gap-4"
                    aria-live="polite"
                    aria-relevant="additions"
                >
                    <AnimatePresence :initial="false">
                        <motion.li
                            v-for="r in rounds"
                            :key="r.id"
                            layout
                            :initial="{ opacity: 0, y: -16 }"
                            :animate="{ opacity: 1, y: 0 }"
                            :exit="{ opacity: 0, scale: 0.98 }"
                            :transition="{
                                type: 'spring',
                                stiffness: 100,
                                damping: 20,
                            }"
                            class="rounded-2xl border border-border bg-background p-5"
                        >
                            <header
                                class="flex flex-wrap items-baseline justify-between gap-2"
                            >
                                <p class="font-medium">
                                    {{ r.pair }}
                                    <span
                                        class="num ml-2 text-muted-foreground"
                                        >{{ brl(r.price) }}</span
                                    >
                                </p>
                                <p class="num text-sm text-muted-foreground">
                                    {{ hora(r.at) }}
                                </p>
                            </header>
                            <ul
                                class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3"
                            >
                                <motion.li
                                    v-for="(v, i) in r.votes"
                                    :key="v.agent"
                                    :title="v.reason"
                                    class="flex min-w-0 items-center justify-between gap-2 rounded-lg bg-card px-3 py-2 text-sm"
                                    :initial="{ opacity: 0.2, y: 6 }"
                                    :animate="{ opacity: 1, y: 0 }"
                                    :transition="{
                                        type: 'spring',
                                        stiffness: 100,
                                        damping: 20,
                                        delay: 0.12 * i,
                                    }"
                                >
                                    <span class="truncate">{{ v.agent }}</span>
                                    <span
                                        class="num shrink-0 rounded-full px-2 py-0.5 text-xs"
                                        :class="
                                            v.veto
                                                ? 'bg-down/15 text-down'
                                                : chip(v.action)
                                        "
                                    >
                                        {{ v.veto ? 'veto' : v.action }}
                                    </span>
                                </motion.li>
                            </ul>
                            <p
                                class="mt-4 flex items-start gap-2 text-sm"
                                :class="
                                    r.approved
                                        ? 'text-up'
                                        : 'text-muted-foreground'
                                "
                            >
                                <CircleCheck
                                    v-if="r.approved"
                                    :size="18"
                                    class="mt-0.5 shrink-0"
                                />
                                <CircleX
                                    v-else
                                    :size="18"
                                    class="mt-0.5 shrink-0"
                                />
                                <span>{{ r.verdict }}</span>
                            </p>
                        </motion.li>
                    </AnimatePresence>
                </ol>
            </div>
        </div>
    </section>
</template>

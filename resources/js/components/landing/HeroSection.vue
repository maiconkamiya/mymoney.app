<script setup lang="ts">
import { motion } from 'motion-v';
import { ArrowRight } from '@lucide/vue';
import LiveQuotes from './LiveQuotes.vue';
import heroImg from '../../../images/landing/hero.webp';

const spring = (delay: number) => ({
    type: 'spring' as const,
    stiffness: 100,
    damping: 20,
    delay,
});
</script>

<template>
    <section
        id="topo"
        class="mx-auto max-w-[1400px] px-4 pt-10 pb-16 sm:px-6 md:pt-16 lg:px-8 lg:pb-24"
    >
        <div class="grid gap-10 lg:grid-cols-12 lg:items-start lg:gap-8">
            <div class="lg:col-span-6 lg:pt-16 xl:col-span-5">
                <motion.h1
                    class="text-4xl leading-[1.05] font-semibold tracking-tighter md:text-5xl lg:text-6xl"
                    :initial="{ opacity: 0.4, y: 14 }"
                    :animate="{ opacity: 1, y: 0 }"
                    :transition="spring(0)"
                >
                    Seis agentes votam. Você vê cada voto.
                </motion.h1>
                <motion.p
                    class="mt-6 max-w-[46ch] text-lg leading-relaxed text-muted-foreground"
                    :initial="{ opacity: 0.4, y: 14 }"
                    :animate="{ opacity: 1, y: 0 }"
                    :transition="spring(0.08)"
                >
                    O MyMoney analisa Bitcoin, Ethereum e Solana em reais,
                    decide por consenso e simula antes de arriscar seu dinheiro.
                </motion.p>
                <motion.div
                    class="mt-8 flex flex-wrap items-center gap-3"
                    :initial="{ opacity: 0.4, y: 14 }"
                    :animate="{ opacity: 1, y: 0 }"
                    :transition="spring(0.16)"
                >
                    <a
                        href="#simulador"
                        class="press inline-flex items-center gap-2 rounded-full bg-primary px-6 py-3 font-medium whitespace-nowrap text-primary-foreground hover:opacity-90"
                    >
                        Simular carteira
                        <ArrowRight :size="18" />
                    </a>
                    <a
                        href="#consenso"
                        class="press inline-flex rounded-full border border-border px-6 py-3 font-medium whitespace-nowrap hover:bg-muted"
                    >
                        Ver o consenso
                    </a>
                </motion.div>
            </div>

            <div class="relative lg:col-span-6 xl:col-span-7">
                <motion.img
                    :src="heroImg"
                    alt="Vista aérea em preto e branco de uma ponte pênsil sobre o mar"
                    width="900"
                    height="1100"
                    fetchpriority="high"
                    class="aspect-[4/3] w-full max-w-full rounded-2xl object-cover md:aspect-[16/11] lg:ml-auto lg:aspect-[5/6] lg:max-h-[600px] lg:w-[78%]"
                    :initial="{ opacity: 0.4, scale: 1.02 }"
                    :animate="{ opacity: 1, scale: 1 }"
                    :transition="{ type: 'spring', stiffness: 60, damping: 20 }"
                />
                <div
                    class="relative mx-4 -mt-16 sm:mx-auto sm:max-w-sm lg:absolute lg:bottom-10 lg:left-0 lg:mx-0 lg:mt-0 lg:w-[340px]"
                >
                    <LiveQuotes />
                </div>
            </div>
        </div>
    </section>
</template>

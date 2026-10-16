<script setup lang="ts">
import {
    ChartLine,
    Newspaper,
    Waves,
    SquareFunction,
    Search,
    ShieldCheck,
} from '@lucide/vue';
import Reveal from './Reveal.vue';
import imgTecnico from '../../../images/landing/agente-tecnico.webp';
import imgCetico from '../../../images/landing/agente-cetico.webp';
import imgQuant from '../../../images/landing/agente-quant.webp';
</script>

<template>
    <section
        id="agentes"
        class="mx-auto max-w-[1400px] px-4 py-20 sm:px-6 md:py-24 lg:px-8"
    >
        <Reveal>
            <h2
                class="max-w-[22ch] text-3xl font-semibold tracking-tight md:text-4xl"
            >
                Cada agente olha para uma coisa só
            </h2>
            <p class="mt-3 max-w-[60ch] text-muted-foreground">
                Especialistas discordam de propósito. A operação só segue quando
                a maioria aponta para o mesmo lado.
            </p>
        </Reveal>

        <!-- 6 agentes, 6 células. Abaixo de 768px vira uma coluna. -->
        <div
            class="mt-12 grid auto-rows-[minmax(200px,auto)] gap-4 md:grid-cols-2 lg:grid-cols-4"
        >
            <Reveal class="md:col-span-2 lg:row-span-2">
                <article
                    class="relative flex h-full min-h-[320px] flex-col justify-end overflow-hidden rounded-2xl"
                >
                    <img
                        :src="imgTecnico"
                        alt="Pessoa caminhando sozinha num deserto de dunas claras"
                        class="absolute inset-0 size-full object-cover"
                    />
                    <div
                        class="absolute inset-0 bg-gradient-to-t from-background via-background/50 to-transparent"
                    />
                    <div class="relative p-6">
                        <ChartLine :size="28" class="text-primary" />
                        <h3 class="mt-3 text-xl font-semibold">Técnico</h3>
                        <p class="mt-2 max-w-[42ch] text-muted-foreground">
                            Lê tendência, suportes, volume e volatilidade nos
                            candles. Ignora manchetes e propõe stop e alvo.
                        </p>
                    </div>
                </article>
            </Reveal>

            <Reveal :delay="0.05">
                <article class="flex h-full flex-col rounded-2xl bg-muted p-6">
                    <Newspaper :size="28" class="text-primary" />
                    <h3 class="mt-3 text-xl font-semibold">Notícias</h3>
                    <p class="mt-2 text-muted-foreground">
                        Separa o fato novo do que o preço já absorveu. Sem
                        notícia relevante, fica de fora.
                    </p>
                </article>
            </Reveal>

            <Reveal :delay="0.1" class="lg:row-span-2">
                <article
                    class="relative flex h-full min-h-[320px] flex-col justify-end overflow-hidden rounded-2xl"
                >
                    <img
                        :src="imgCetico"
                        alt="Folhas secas em close sobre fundo escuro"
                        class="absolute inset-0 size-full object-cover"
                    />
                    <div
                        class="absolute inset-0 bg-gradient-to-t from-background via-background/60 to-transparent"
                    />
                    <div class="relative p-6">
                        <Search :size="28" class="text-primary" />
                        <h3 class="mt-3 text-xl font-semibold">Cético</h3>
                        <p class="mt-2 text-muted-foreground">
                            Procura o melhor argumento contra a operação. Só
                            vota a favor quando não acha objeção.
                        </p>
                    </div>
                </article>
            </Reveal>

            <Reveal :delay="0.15">
                <article
                    class="flex h-full flex-col rounded-2xl border border-border p-6"
                >
                    <Waves :size="28" class="text-primary" />
                    <h3 class="mt-3 text-xl font-semibold">Fluxo</h3>
                    <p class="mt-2 text-muted-foreground">
                        Mede quem está agredindo o livro: compradores ou
                        vendedores, e com que força.
                    </p>
                </article>
            </Reveal>

            <Reveal class="md:col-span-2">
                <article
                    class="relative flex h-full min-h-[220px] items-end overflow-hidden rounded-2xl"
                >
                    <img
                        :src="imgQuant"
                        alt="Estrada entre ciprestes numa colina ao entardecer"
                        class="absolute inset-0 size-full object-cover"
                    />
                    <div
                        class="absolute inset-0 bg-gradient-to-r from-background via-background/70 to-transparent"
                    />
                    <div class="relative max-w-[40ch] p-6">
                        <SquareFunction :size="28" class="text-primary" />
                        <h3 class="mt-3 text-xl font-semibold">Quant</h3>
                        <p class="mt-2 text-muted-foreground">
                            Traduz a previsão do modelo estatístico em voto. Sem
                            previsão, a confiança é zero.
                        </p>
                    </div>
                </article>
            </Reveal>

            <Reveal :delay="0.05" class="md:col-span-2">
                <article
                    class="flex h-full flex-col justify-end rounded-2xl p-6"
                    style="
                        background: linear-gradient(
                            135deg,
                            color-mix(in oklab, var(--primary) 26%, var(--card))
                                0%,
                            var(--card) 70%
                        );
                    "
                >
                    <ShieldCheck :size="28" class="text-primary" />
                    <h3 class="mt-3 text-xl font-semibold">Risco</h3>
                    <p class="mt-2 max-w-[48ch] text-muted-foreground">
                        Confere tamanho, exposição e stop. É o único agente com
                        poder de veto.
                    </p>
                </article>
            </Reveal>
        </div>
    </section>
</template>

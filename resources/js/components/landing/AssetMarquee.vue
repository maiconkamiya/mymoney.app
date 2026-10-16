<script setup lang="ts">
import {
    siBitcoin,
    siEthereum,
    siSolana,
    siBinance,
    siCardano,
    siChainlink,
    siXrp,
    siLitecoin,
    siPolygon,
    siDogecoin,
} from 'simple-icons';

// Logos reais (Simple Icons) dos ativos e da corretora acompanhados. Só logo, sem rótulo.
const icons = [
    siBitcoin,
    siEthereum,
    siSolana,
    siBinance,
    siCardano,
    siChainlink,
    siXrp,
    siLitecoin,
    siPolygon,
    siDogecoin,
];
</script>

<template>
    <section
        aria-label="Ativos e corretora acompanhados"
        class="overflow-hidden border-y border-border py-8"
    >
        <div
            class="marquee-track flex w-max items-center gap-16 pr-16 text-muted-foreground"
        >
            <template v-for="round in 2" :key="round">
                <svg
                    v-for="icon in icons"
                    :key="round + icon.slug"
                    role="img"
                    :aria-label="icon.title"
                    :aria-hidden="round === 2 ? 'true' : undefined"
                    viewBox="0 0 24 24"
                    class="size-8 shrink-0"
                    fill="currentColor"
                >
                    <path :d="icon.path" />
                </svg>
            </template>
        </div>
    </section>
</template>

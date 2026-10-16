<script setup lang="ts">
import BrandMark from './BrandMark.vue';
</script>

<template>
    <footer class="border-t border-border">
        <div
            class="mx-auto grid max-w-[1400px] gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1fr_auto] md:items-end lg:px-8"
        >
            <div>
                <p class="flex items-center gap-2.5 font-semibold">
                    <BrandMark /> MyMoney
                </p>
                <p class="mt-3 max-w-[60ch] text-sm text-muted-foreground">
                    Ferramenta de análise e simulação. Nada nesta página é
                    recomendação de investimento. Cotações da API pública da
                    Binance.
                </p>
            </div>
            <ul class="flex flex-wrap gap-6 text-sm text-muted-foreground">
                <li>
                    <a href="#mercado" class="hover:text-foreground">Mercado</a>
                </li>
                <li>
                    <a href="#agentes" class="hover:text-foreground">Agentes</a>
                </li>
                <li>
                    <a href="#risco" class="hover:text-foreground">Risco</a>
                </li>
                <li>
                    <a href="#duvidas" class="hover:text-foreground">Dúvidas</a>
                </li>
            </ul>
        </div>
    </footer>
</template>

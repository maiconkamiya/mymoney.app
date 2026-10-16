<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Versão e commit do build no rodapé do menu lateral. Discreto de propósito: o suporte lê o
 * número aqui, o resto do tempo ele não atrapalha. Clicar abre "Novidades".
 */
const page = usePage();
const sistema = computed(() => page.props.sistema);
</script>

<template>
    <Link
        v-if="sistema"
        href="/novidades"
        data-testid="versao-do-sistema"
        class="mt-1 block truncate rounded-md px-2 py-1 font-mono text-[11px] text-muted-foreground/80 transition-colors group-data-[collapsible=icon]:hidden hover:text-foreground"
        title="Ver o que mudou nesta versão"
    >
        v{{ sistema.versao }} · {{ sistema.commit }}
    </Link>
</template>

<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { onMounted, watch } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Aviso discreto "Nova versão X": depois de um deploy, cada usuário vê UMA vez. O servidor diz se
 * há novidade não vista (`sistema.aviso_novidade`); aqui mostramos o aviso e, no mesmo instante,
 * gravamos que a versão foi vista, para um recarregamento não repetir o aviso. Abrir "Novidades"
 * também grava (no servidor).
 *
 * Não renderiza nada: o aviso é um toast do `<Toaster />` do layout.
 */
const page = usePage();

/** Versão cujo aviso já foi aberto nesta aba: a prop só zera na próxima resposta do servidor. */
let versaoJaAvisada: string | null = null;

function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((linha) => linha.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}

async function registrarComoVista(): Promise<void> {
    try {
        await fetch('/novidades/vista', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });
    } catch {
        // Sem rede agora: o aviso reaparece na próxima visita, o que é melhor que perdê-lo.
    }
}

function avisarSeHouverNovidade(): void {
    const sistema = page.props.sistema;

    if (!sistema?.aviso_novidade || versaoJaAvisada === sistema.versao) {
        return;
    }

    versaoJaAvisada = sistema.versao;

    toast(`Nova versão ${sistema.versao}`, {
        description: 'O sistema foi atualizado.',
        duration: 12000,
        action: {
            label: 'Ver novidades',
            onClick: () => router.visit('/novidades'),
        },
    });

    void registrarComoVista();
}

// Depois de montar: o `<Toaster />` do layout já está de pé.
onMounted(avisarSeHouverNovidade);
watch(() => page.props.sistema, avisarSeHouverNovidade);
</script>

<template>
    <span class="hidden" aria-hidden="true" />
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { RealtimeStatus } from '@/composables/usePortfolioRealtime';

const props = defineProps<{
    status: RealtimeStatus;
    lastUpdate: Date | null;
}>();

const states: Record<
    RealtimeStatus,
    { label: string; dot: string; hint: string }
> = {
    'ao-vivo': {
        label: 'Tempo real',
        dot: 'bg-up',
        hint: 'Conectado ao servidor: saldo, posições, ordens e micro operações atualizam sozinhos.',
    },
    conectando: {
        label: 'Conectando',
        dot: 'bg-muted-foreground animate-pulse',
        hint: 'Abrindo a conexão em tempo real com o servidor.',
    },
    'sem-conexao': {
        label: 'Sem tempo real',
        dot: 'bg-amber-500',
        hint: 'A conexão com o servidor de tempo real caiu. Enquanto ela não volta, a tela se atualiza a cada 30 s; ao voltar, recarrega tudo.',
    },
    'sem-permissao': {
        label: 'Canal recusado',
        dot: 'bg-down',
        hint: 'O servidor recusou o canal desta carteira (sessão expirada?). A tela se atualiza a cada 30 s; entre de novo para voltar ao tempo real.',
    },
    desligado: {
        label: 'Tempo real desligado',
        dot: 'bg-muted-foreground/50',
        hint: 'Este build saiu sem as variáveis VITE_REVERB_*. Rode npm run build com elas no .env.',
    },
};

const state = computed(() => states[props.status]);

const title = computed(() =>
    props.lastUpdate
        ? `${state.value.hint} Última atualização às ${props.lastUpdate.toLocaleTimeString('pt-BR')}.`
        : state.value.hint,
);
</script>

<template>
    <span
        class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs text-muted-foreground"
        :title="title"
        :data-status="status"
        data-test="realtime-indicator"
        role="status"
    >
        <span class="size-1.5 rounded-full" :class="state.dot" />
        {{ state.label }}
    </span>
</template>

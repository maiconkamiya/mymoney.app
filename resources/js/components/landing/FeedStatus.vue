<script setup lang="ts">
import { mode, modeLabel, replayDate } from '@/lib/mercado';

defineProps<{ compact?: boolean }>();
const replayHint = `gravados em ${replayDate.toLocaleDateString('pt-BR')} às ${replayDate.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`;
</script>

<template>
    <p
        class="flex items-center gap-2 text-sm text-muted-foreground"
        role="status"
        aria-live="polite"
    >
        <span class="relative inline-flex size-2">
            <span
                v-if="mode !== 'conectando'"
                class="live-ring absolute inset-0 rounded-full"
                :class="mode === 'ao-vivo' ? 'bg-up' : 'bg-primary'"
            />
            <span
                class="relative inline-flex size-2 rounded-full"
                :class="
                    mode === 'ao-vivo'
                        ? 'bg-up'
                        : mode === 'reproducao'
                          ? 'bg-primary'
                          : 'bg-muted'
                "
            />
        </span>
        <span>{{ modeLabel }}</span>
        <span
            v-if="mode === 'reproducao' && !compact"
            class="hidden sm:inline"
            >{{ replayHint }}</span
        >
    </p>
</template>

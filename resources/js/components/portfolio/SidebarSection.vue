<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { useId } from 'vue';

defineProps<{
    title: string;
    hint?: string;
    open: boolean;
    tone?: 'default' | 'danger';
}>();

defineEmits<{ toggle: [] }>();

const panelId = useId();
</script>

<template>
    <div
        class="rounded-2xl border bg-card"
        :class="tone === 'danger' ? 'border-destructive/40' : ''"
    >
        <h2>
            <button
                type="button"
                class="press flex w-full items-center justify-between gap-3 rounded-2xl px-4 py-3 text-left"
                :aria-expanded="open"
                :aria-controls="panelId"
                @click="$emit('toggle')"
            >
                <span class="min-w-0">
                    <span class="block font-medium">{{ title }}</span>
                    <span
                        v-if="hint"
                        class="block truncate text-xs text-muted-foreground"
                    >
                        {{ hint }}
                    </span>
                </span>
                <ChevronDown
                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-300"
                    :class="open ? 'rotate-180' : ''"
                />
            </button>
        </h2>
        <!-- Fechada a seção continua montada: os cards seguem recebendo o tempo real. -->
        <div
            :id="panelId"
            class="grid transition-[grid-template-rows] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] motion-reduce:transition-none"
            :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'"
            :inert="!open"
        >
            <div class="min-h-0 overflow-hidden">
                <div
                    class="@container px-4 pb-4 [&>section]:rounded-none [&>section]:border-0 [&>section]:bg-transparent [&>section]:p-0"
                >
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Info } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { ref } from 'vue';

defineProps<{ label: string }>();

// Abre no hover (mouse) e no toque (celular): o Tooltip do reka não abre por toque.
const open = ref(false);
let closeTimer: ReturnType<typeof setTimeout> | undefined;

function show(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        clearTimeout(closeTimer);
        open.value = true;
    }
}

function hide(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        closeTimer = setTimeout(() => (open.value = false), 150);
    }
}
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger
            type="button"
            class="inline-flex size-5 shrink-0 items-center justify-center rounded-full align-middle text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
            :aria-label="label"
            @pointerenter="show"
            @pointerleave="hide"
        >
            <Info class="size-3.5" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                side="top"
                :side-offset="6"
                :collision-padding="16"
                class="z-50 max-h-80 w-80 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-xl border bg-popover p-3 text-xs text-popover-foreground shadow-lg outline-none"
                @pointerenter="show"
                @pointerleave="hide"
            >
                <slot />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>

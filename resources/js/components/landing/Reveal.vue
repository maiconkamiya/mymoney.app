<script setup lang="ts">
import { motion } from 'motion-v';

// Entrada ao rolar: só desloca o bloco, sem esconder; a página fica completa
// mesmo antes de qualquer animação.
withDefaults(defineProps<{ delay?: number; as?: string }>(), {
    delay: 0,
    as: 'div',
});
</script>

<template>
    <motion.div
        :initial="{ y: 18 }"
        :while-in-view="{ y: 0 }"
        :in-view-options="{ once: true, amount: 0.2 }"
        :transition="{ type: 'spring', stiffness: 100, damping: 20, delay }"
    >
        <slot />
    </motion.div>
</template>

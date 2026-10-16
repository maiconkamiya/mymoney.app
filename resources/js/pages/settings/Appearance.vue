<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Aparência',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Aparência" />

    <h1 class="sr-only">Aparência</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Aparência"
            description="Escolha o tema claro, escuro ou o do sistema."
        />
        <AppearanceTabs />
    </div>
</template>

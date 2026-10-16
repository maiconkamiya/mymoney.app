<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Confirme seu e-mail',
        description: 'Clique no link que acabamos de enviar para o seu e-mail.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Confirmar e-mail" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        Enviamos um novo link de confirmação para o e-mail do cadastro.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Reenviar e-mail de confirmação
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Sair
        </TextLink>
    </Form>
</template>

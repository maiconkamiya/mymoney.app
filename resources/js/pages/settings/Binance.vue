<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import BinanceController from '@/actions/App/Http/Controllers/Settings/BinanceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/binance';
import { show } from '@/routes/portfolios';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Binance', href: edit() }],
    },
});

defineProps<{
    credential: {
        label: string;
        configured_at: string | null;
        verified: boolean;
    } | null;
    portfolio_id: number | null;
    testnet: boolean;
    live_enabled: boolean;
}>();

const date = (iso: string | null) =>
    iso ? new Date(iso).toLocaleDateString('pt-BR') : '';
</script>

<template>
    <Head title="Binance" />

    <h1 class="sr-only">Binance</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Conta da Binance"
            description="Sua chave fica cifrada, é só sua e nunca volta para a tela. Crie a chave na Binance com leitura e negociação spot, sem saque, e restrinja ao IP do servidor."
        />

        <p
            v-if="testnet"
            class="rounded-xl border border-dashed p-3 text-sm text-muted-foreground"
        >
            Modo de teste: as chamadas vão para o testnet da Binance, sem
            dinheiro real.
        </p>

        <div v-if="credential" class="space-y-3 rounded-2xl border p-4">
            <p class="font-medium">
                Chave configurada em {{ date(credential.configured_at) }}
                <span class="text-muted-foreground">
                    ({{ credential.label }})
                </span>
            </p>
            <p class="text-sm text-muted-foreground">
                {{
                    live_enabled
                        ? 'Ordens reais ligadas: cada ordem ainda exige a sua aprovação e respeita os limites.'
                        : 'Ordens reais desligadas: os agentes leem a conta, mas nenhuma ordem é enviada.'
                }}
            </p>
            <div class="flex flex-wrap items-center gap-3">
                <Link
                    v-if="portfolio_id"
                    :href="show(portfolio_id)"
                    class="text-sm underline underline-offset-4"
                >
                    Abrir a carteira Conta Binance
                </Link>
                <Form
                    v-bind="BinanceController.sync.form()"
                    v-slot="{ errors, processing }"
                >
                    <Button variant="outline" :disabled="processing">
                        Sincronizar agora
                    </Button>
                    <InputError :message="errors.sync" />
                </Form>
                <Form v-bind="BinanceController.destroy.form()">
                    <Button variant="destructive">Remover chave</Button>
                </Form>
            </div>
        </div>

        <Form
            v-bind="BinanceController.update.form()"
            class="max-w-xl space-y-4"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <Heading
                variant="small"
                :title="credential ? 'Trocar a chave' : 'Cadastrar a chave'"
                description="A chave é conferida na Binance antes de ser guardada."
            />
            <div class="grid gap-2">
                <Label for="label">Apelido (opcional)</Label>
                <Input id="label" name="label" placeholder="Binance" />
                <InputError :message="errors.label" />
            </div>
            <div class="grid gap-2">
                <Label for="api_key">API Key</Label>
                <Input
                    id="api_key"
                    name="api_key"
                    autocomplete="off"
                    required
                />
                <InputError :message="errors.api_key" />
            </div>
            <div class="grid gap-2">
                <Label for="api_secret">API Secret</Label>
                <PasswordInput
                    id="api_secret"
                    name="api_secret"
                    autocomplete="off"
                    required
                />
                <InputError :message="errors.api_secret" />
            </div>
            <Button :disabled="processing">Verificar e guardar</Button>
        </Form>
    </div>
</template>

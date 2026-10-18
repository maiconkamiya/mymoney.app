<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';
import PortfolioCloneController from '@/actions/App/Http/Controllers/PortfolioCloneController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { money } from '@/lib/trading';

const props = defineProps<{
    portfolio: { id: number; name: string; mode: string };
    equity: number;
}>();

const isLive = computed(() => props.portfolio.mode === 'live');
const cloneName = computed(
    () => `${props.portfolio.name} ${isLive.value ? '(simulada)' : '(cópia)'}`,
);
</script>

<template>
    <section class="space-y-3 rounded-2xl border p-4">
        <div>
            <p class="font-medium">Clonar como simulada</p>
            <p class="text-sm text-muted-foreground">
                Uma carteira simulada com os mesmos ativos acompanhados e o
                mesmo card Micro operações, para testar mudanças sem dinheiro.
            </p>
        </div>

        <Dialog>
            <DialogTrigger as-child>
                <Button variant="outline" data-test="clone-portfolio-button">
                    Clonar como simulada
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="PortfolioCloneController.form(portfolio.id)"
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Criar {{ cloneName }}?</DialogTitle>
                        <DialogDescription>
                            A simulada começa com
                            <strong class="text-foreground"
                                >{{
                                    money(
                                        Math.round(equity * 100) / 100,
                                        'crypto',
                                    )
                                }}
                                USDT</strong
                            >
                            em caixa, o patrimônio de agora desta carteira.
                        </DialogDescription>
                    </DialogHeader>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="rounded-xl bg-muted/40 p-3">
                            <dt class="font-medium">Vai junto</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Ativos acompanhados, aporte, operações por dia,
                                margem, meta e perda do dia, regra de entrada.
                                Micro operações já ligadas.
                            </dd>
                        </div>
                        <div class="rounded-xl bg-muted/40 p-3">
                            <dt class="font-medium">Fica para trás</dt>
                            <dd class="mt-1 text-muted-foreground">
                                Posições, ordens, sinais, histórico, credencial
                                da Binance e a execução real. Nenhuma ordem vai
                                à corretora.
                            </dd>
                        </div>
                    </dl>

                    <InputError :message="errors.clone" />

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Cancelar</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="processing"
                            data-test="confirm-clone-portfolio-button"
                        >
                            {{ processing ? 'Clonando...' : 'Criar simulada' }}
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </section>
</template>

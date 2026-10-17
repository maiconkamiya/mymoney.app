<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InitialCashController from '@/actions/App/Http/Controllers/PortfolioInitialCashController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/trading';

defineProps<{
    portfolio: {
        id: number;
        mode: string;
        market: string;
        initial_cash: number;
    };
}>();
</script>

<template>
    <Dialog v-if="portfolio.mode === 'paper'">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                data-test="edit-initial-cash-button"
            >
                Alterar saldo inicial
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="InitialCashController.form(portfolio.id)"
                class="space-y-6"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Alterar o saldo inicial</DialogTitle>
                    <DialogDescription>
                        Hoje é
                        {{ money(portfolio.initial_cash, portfolio.market) }}. O
                        caixa muda pela diferença e o histórico de patrimônio
                        acompanha, como se o capital sempre tivesse sido o novo.
                        Posições, ordens e sinais não são apagados, e o retorno
                        passa a ser calculado sobre o novo valor.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="initial_cash">Novo saldo inicial</Label>
                    <Input
                        id="initial_cash"
                        name="initial_cash"
                        type="number"
                        min="100"
                        step="0.01"
                        :default-value="portfolio.initial_cash"
                        required
                    />
                    <InputError :message="errors.initial_cash" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-test="confirm-initial-cash-button"
                    >
                        Confirmar alteração
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

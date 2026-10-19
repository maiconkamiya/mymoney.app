<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';
import DepositController from '@/actions/App/Http/Controllers/PortfolioDepositController';
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
import type { PortfolioDeposit } from '@/types/trading';

defineProps<{
    portfolio: {
        id: number;
        market: string;
        initial_cash: number;
        deposits?: PortfolioDeposit[];
    };
}>();

const open = ref(false);

function quando(iso: string): string {
    return new Date(iso).toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="outline" size="sm" data-test="new-deposit-button">
                Novo aporte
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="DepositController.store.form(portfolio.id)"
                class="space-y-6"
                :options="{
                    preserveScroll: true,
                    only: ['portfolio', 'performance', 'micro'],
                }"
                reset-on-success
                @success="open = false"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Novo aporte</DialogTitle>
                    <DialogDescription>
                        O valor entra no caixa agora e soma ao total investido,
                        hoje
                        {{
                            money(
                                Math.round(portfolio.initial_cash * 100) / 100,
                                portfolio.market,
                            )
                        }}. O histórico anterior fica como está, e o aporte não
                        conta como lucro nem como perda na guarda de risco nem
                        na meta do dia.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="deposit_amount">Valor do aporte</Label>
                    <Input
                        id="deposit_amount"
                        name="amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        inputmode="decimal"
                        placeholder="0,00"
                        required
                    />
                    <InputError :message="errors.amount" />
                </div>

                <div
                    v-if="portfolio.deposits?.length"
                    class="grid gap-2"
                    data-test="deposit-history"
                >
                    <p class="text-sm text-muted-foreground">
                        Aportes anteriores
                    </p>
                    <ul
                        class="max-h-40 divide-y overflow-y-auto rounded-xl border text-sm"
                    >
                        <li
                            v-for="deposit in portfolio.deposits"
                            :key="deposit.id"
                            class="flex items-center justify-between gap-4 px-3 py-2"
                        >
                            <span class="text-muted-foreground tabular-nums">{{
                                quando(deposit.deposited_at)
                            }}</span>
                            <span class="font-medium tabular-nums">{{
                                money(deposit.amount, portfolio.market)
                            }}</span>
                        </li>
                    </ul>
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-test="confirm-deposit-button"
                    >
                        Confirmar aporte
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

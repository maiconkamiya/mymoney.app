<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import PortfolioController from '@/actions/App/Http/Controllers/PortfolioController';
import TradingHaltController from '@/actions/App/Http/Controllers/TradingHaltController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DeletePortfolio from '@/components/portfolio/DeletePortfolio.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { marketLabels, money, percent, statusLabels } from '@/lib/trading';
import { index, show } from '@/routes/portfolios';
import type { PortfolioSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Carteiras', href: index() }],
    },
});

defineProps<{
    portfolios: PortfolioSummary[];
    halted: boolean;
}>();

const today = new Date().toISOString().slice(0, 10);
const inThirtyDays = new Date(Date.now() + 30 * 86_400_000)
    .toISOString()
    .slice(0, 10);
</script>

<template>
    <Head title="Carteiras" />

    <div
        class="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border p-4"
            :class="
                halted
                    ? 'border-destructive/50 bg-destructive/10'
                    : 'border-sidebar-border/70'
            "
        >
            <div>
                <p class="font-medium">
                    {{
                        halted ? 'Parada geral acionada' : 'Negociação liberada'
                    }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        halted
                            ? 'Nenhuma ordem é enviada até você retomar.'
                            : 'A parada geral bloqueia todas as ordens, simuladas e reais.'
                    }}
                </p>
            </div>
            <Form v-if="halted" v-bind="TradingHaltController.destroy.form()">
                <Button variant="outline">Retomar negociação</Button>
            </Form>
            <Form v-else v-bind="TradingHaltController.store.form()">
                <Button variant="destructive">Parada geral</Button>
            </Form>
        </div>

        <section>
            <Heading
                title="Carteiras"
                description="Cada carteira simula um valor inicial durante um período, com os mesmos agentes e regras de risco da operação real."
            />

            <div
                v-if="portfolios.length === 0"
                class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nenhuma carteira ainda. Crie a primeira simulação abaixo.
            </div>

            <div v-else class="overflow-x-auto rounded-2xl border bg-card">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Nome</th>
                            <th class="px-4 py-2 font-medium">Mercado</th>
                            <th class="px-4 py-2 font-medium">Período</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Patrimônio
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Retorno
                            </th>
                            <th class="px-4 py-2 font-medium">Status</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="portfolio in portfolios"
                            :key="portfolio.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2">
                                <Link
                                    :href="show(portfolio.id)"
                                    class="font-medium underline-offset-4 hover:underline"
                                >
                                    {{ portfolio.name }}
                                </Link>
                            </td>
                            <td class="px-4 py-2">
                                {{ marketLabels[portfolio.market] }}
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ portfolio.starts_at }} a
                                {{ portfolio.ends_at }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ money(portfolio.equity, portfolio.market) }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{
                                    percent(
                                        portfolio.equity /
                                            portfolio.initial_cash -
                                            1,
                                    )
                                }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge variant="secondary">
                                    {{
                                        portfolio.mode === 'paper'
                                            ? 'Simulada'
                                            : 'Real'
                                    }}
                                    · {{ statusLabels[portfolio.status] }}
                                </Badge>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <DeletePortfolio
                                    :portfolio="portfolio"
                                    size="sm"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="max-w-xl">
            <Heading
                variant="small"
                title="Nova simulação"
                description="A carteira começa a receber sinais assim que o período começa."
            />

            <Form
                v-bind="PortfolioController.store.form()"
                class="mt-4 space-y-4"
                reset-on-success
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="name">Nome</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        placeholder="Consenso completo, cripto"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="market">Mercado</Label>
                        <select
                            id="market"
                            name="market"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                        >
                            <option value="crypto">Cripto</option>
                            <option value="b3">B3</option>
                        </select>
                        <InputError :message="errors.market" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="initial_cash">Valor inicial (X)</Label>
                        <Input
                            id="initial_cash"
                            name="initial_cash"
                            type="number"
                            min="100"
                            step="0.01"
                            :default-value="10000"
                            required
                        />
                        <InputError :message="errors.initial_cash" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="starts_at">Início (Y)</Label>
                        <Input
                            id="starts_at"
                            name="starts_at"
                            type="date"
                            :default-value="today"
                            required
                        />
                        <InputError :message="errors.starts_at" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ends_at">Fim (Y)</Label>
                        <Input
                            id="ends_at"
                            name="ends_at"
                            type="date"
                            :default-value="inThirtyDays"
                            required
                        />
                        <InputError :message="errors.ends_at" />
                    </div>
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="auto_execute" value="0" />
                    <input
                        type="checkbox"
                        name="auto_execute"
                        value="1"
                        class="mt-0.5"
                    />
                    <span>
                        Executar sinais aprovados sem confirmação manual
                        <span class="block text-muted-foreground">
                            Desligado por padrão. A guarda de risco continua
                            valendo.
                        </span>
                    </span>
                </label>

                <Button :disabled="processing">Criar carteira</Button>
            </Form>
        </section>
    </div>
</template>

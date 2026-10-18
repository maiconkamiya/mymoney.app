<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { panic, release, update } from '@/routes/portfolios';
import type { LiveInfo, PortfolioSummary } from '@/types';

const props = defineProps<{
    portfolio: PortfolioSummary;
    live: LiveInfo;
}>();

const typed = ref('');
const confirmed = computed(() => typed.value === 'CONFIRMO');
const blocked = computed(() => props.portfolio.orders_blocked === true);
const cur = (value: number) => money(value, props.portfolio.market);

// Botões da carteira real: mantêm a rolagem e recarregam só os blocos que mudam.
const liveFormOptions = {
    preserveScroll: true,
    only: ['portfolio', 'live', 'micro'],
};
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border bg-card p-4"
        :class="blocked ? 'border-destructive/60' : ''"
        data-test="live-execution-card"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <h3 class="font-medium">
                    Execução automática com dinheiro real
                </h3>
                <Badge v-if="blocked" variant="destructive">Bloqueada</Badge>
                <Badge v-else-if="portfolio.auto_execute">Ligada</Badge>
                <Badge v-else variant="secondary">Desligada</Badge>
                <Badge v-if="live.testnet" variant="secondary">Testnet</Badge>
            </div>

            <Form
                v-bind="panic.form(portfolio.id)"
                v-slot="{ processing }"
                :options="liveFormOptions"
            >
                <Button
                    variant="destructive"
                    :disabled="processing"
                    data-test="panic-button"
                >
                    Parar tudo
                </Button>
            </Form>
        </div>

        <p class="text-sm text-muted-foreground">
            Ligada, cada sinal aprovado pelos agentes vira uma ordem real na
            Binance sem esperar a sua confirmação. Valem sempre: máximo de
            {{ cur(live.max_order_value) }} por ordem, teto de
            {{ cur(live.max_daily_value) }} por dia (restam
            {{ cur(live.remaining_today) }} hoje), sem venda a descoberto, só
            ativos de cripto, saldo suficiente e cotação recente. "Parar tudo"
            desliga a execução automática, cancela as ordens abertas e bloqueia
            novas ordens; as posições abertas ficam como estão.
        </p>

        <div
            v-if="!live.enabled"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
        >
            A execução real está desligada no servidor
            (<code>TRADING_LIVE_ENABLED=false</code>). Ligue no
            <code>.env</code> e reinicie os serviços para poder ligar aqui.
        </div>

        <div
            v-if="live.halted"
            class="rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm"
        >
            Há uma parada geral acionada: nenhuma ordem sai até a negociação ser
            retomada.
        </div>

        <div
            v-if="blocked"
            class="flex flex-col gap-3 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm @lg:flex-row @lg:items-center @lg:justify-between"
        >
            <span>
                Novas ordens bloqueadas: {{ portfolio.orders_blocked_reason }}
                Confira as ordens na Binance antes de liberar.
            </span>
            <Form
                v-bind="release.form(portfolio.id)"
                v-slot="{ processing }"
                :options="liveFormOptions"
            >
                <Button
                    variant="outline"
                    :disabled="processing"
                    data-test="release-button"
                >
                    Liberar ordens
                </Button>
            </Form>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <Form
                v-if="portfolio.auto_execute"
                v-bind="update.form(portfolio.id)"
                v-slot="{ processing }"
                :options="liveFormOptions"
            >
                <input type="hidden" name="auto_execute" value="0" />
                <Button variant="outline" :disabled="processing">
                    Desligar execução automática
                </Button>
            </Form>

            <Dialog v-else @update:open="typed = ''">
                <DialogTrigger as-child>
                    <Button
                        :disabled="!live.enabled || live.halted"
                        data-test="live-auto-on-button"
                    >
                        Ligar execução automática
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <Form
                        v-bind="update.form(portfolio.id)"
                        v-slot="{ errors, processing }"
                        :options="liveFormOptions"
                        class="space-y-6"
                    >
                        <DialogHeader class="space-y-3">
                            <DialogTitle>
                                Enviar ordens reais sem a sua aprovação?
                            </DialogTitle>
                            <DialogDescription>
                                Com isto ligado, ordens REAIS serão enviadas à
                                Binance, com o seu dinheiro, sem pedir
                                aprovação. Os agentes podem errar e você pode
                                perder o valor investido. Os limites ({{
                                    cur(live.max_order_value)
                                }}
                                por ordem e {{ cur(live.max_daily_value) }} por
                                dia) e o botão "Parar tudo" continuam valendo.
                                Digite CONFIRMO para continuar.
                            </DialogDescription>
                        </DialogHeader>

                        <input type="hidden" name="auto_execute" value="1" />
                        <div class="grid gap-2">
                            <Label for="confirmacao" class="sr-only">
                                Confirmação
                            </Label>
                            <Input
                                id="confirmacao"
                                v-model="typed"
                                name="confirmacao"
                                autocomplete="off"
                                placeholder="CONFIRMO"
                                data-test="confirmacao-input"
                            />
                            <InputError :message="errors.confirmacao" />
                            <InputError :message="errors.auto_execute" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary" type="button">
                                    Cancelar
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                :disabled="!confirmed || processing"
                                data-test="live-auto-confirm-button"
                            >
                                Ligar com dinheiro real
                            </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>

            <Form
                v-bind="update.form(portfolio.id)"
                :options="liveFormOptions"
                class="flex items-center gap-2 text-sm"
            >
                <input
                    type="hidden"
                    name="auto_execute"
                    :value="portfolio.auto_execute ? 1 : 0"
                />
                <input type="hidden" name="notify_live_email" value="0" />
                <label class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="notify_live_email"
                        value="1"
                        :checked="portfolio.notify_live_email"
                        @change="
                            (
                                $event.target as HTMLInputElement
                            ).form?.requestSubmit()
                        "
                    />
                    Avisar por e-mail quando uma ordem real sair ou falhar
                </label>
            </Form>
        </div>
    </section>
</template>

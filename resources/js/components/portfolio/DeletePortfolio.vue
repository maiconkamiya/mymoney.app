<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PortfolioController from '@/actions/App/Http/Controllers/PortfolioController';
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

const props = defineProps<{
    portfolio: { id: number; name: string; mode: string };
    size?: 'sm' | 'default';
}>();

const isPaper = computed(() => props.portfolio.mode === 'paper');
const confirmation = ref('');
const matches = computed(() => confirmation.value === props.portfolio.name);
</script>

<template>
    <p
        v-if="!isPaper"
        class="text-sm text-muted-foreground"
        data-test="delete-portfolio-blocked"
    >
        Carteira real não pode ser excluída por aqui.
    </p>

    <Dialog v-else @update:open="confirmation = ''">
        <DialogTrigger as-child>
            <Button
                variant="outline"
                :size="size ?? 'default'"
                class="text-destructive"
                data-test="delete-portfolio-button"
            >
                Excluir carteira
            </Button>
        </DialogTrigger>
        <DialogContent>
            <Form
                v-bind="PortfolioController.destroy.form(portfolio.id)"
                class="space-y-6"
                :options="{ preserveScroll: true }"
                v-slot="{ errors, processing }"
            >
                <DialogHeader class="space-y-3">
                    <DialogTitle>Excluir a carteira simulada?</DialogTitle>
                    <DialogDescription>
                        Sinais, votos dos agentes, ordens, posições e histórico
                        desta carteira são apagados para sempre, mesmo que ela
                        ainda esteja em execução. Digite
                        <strong class="text-foreground">{{
                            portfolio.name
                        }}</strong>
                        para confirmar.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="confirmation" class="sr-only">
                        Nome da carteira
                    </Label>
                    <Input
                        id="confirmation"
                        v-model="confirmation"
                        name="confirmation"
                        autocomplete="off"
                        placeholder="Nome da carteira"
                    />
                    <InputError :message="errors.confirmation" />
                    <InputError :message="errors.portfolio" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancelar</Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="!matches || processing"
                        data-test="confirm-delete-portfolio-button"
                    >
                        Excluir carteira
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>

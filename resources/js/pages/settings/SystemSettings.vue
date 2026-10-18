<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import SystemSettingsController from '@/actions/App/Http/Controllers/Settings/SystemSettingsController';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/system-settings';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Custos e serviços', href: edit() }],
    },
});

type Value = boolean | string | number;

const props = defineProps<{
    values: Record<string, Value>;
    can_edit: boolean;
}>();

type Item = {
    key: string;
    title: string;
    help: string;
    options?: { value: string | number; label: string }[];
};

const groups: { title: string; description: string; items: Item[] }[] = [
    {
        title: 'Agentes de IA',
        description:
            'O enxame é quase todo o gasto com IA. As economias são estimativas sobre esse gasto.',
        items: [
            {
                key: 'agents__models__skeptic',
                title: 'Modelo do Cético',
                help: 'Sonnet custa metade do Opus por token: cerca de 30% a menos no gasto de IA. O Cético procura objeções num cenário pronto, tarefa que o Sonnet faz bem.',
                options: [
                    { value: 'claude-opus-5-5', label: 'Opus' },
                    { value: 'claude-sonnet-5-5', label: 'Sonnet' },
                ],
            },
            {
                key: 'trading__scan_interval_minutes',
                title: 'Intervalo da varredura',
                help: 'De quanto em quanto tempo o enxame olha cada par. 30 min corta cerca de 45%; o gatilho de 1% em 5 min continua chamando o enxame na hora em movimento forte.',
                options: [
                    { value: 15, label: '15 min' },
                    { value: 30, label: '30 min' },
                    { value: 60, label: '60 min' },
                ],
            },
            {
                key: 'agents__risk_needs_direction',
                title: 'Risco só com direção',
                help: 'Sem compra ou venda na primeira rodada, o Risco não é consultado: nada seria aprovado mesmo. Cerca de 15% a menos. O veto continua em todo sinal com direção.',
            },
            {
                key: 'agents__skip_skeptic_on_late_entry',
                title: 'Pular o Cético em entrada tardia',
                help: 'Se a regra de entrada tardia já barraria a compra, o sinal é rejeitado sem chamar o Cético. O resultado é o mesmo, sem o custo do modelo mais caro.',
            },
            {
                key: 'agents__compact_json',
                title: 'Cenário compacto',
                help: 'Envia os mesmos dados sem espaços de formatação: cerca de 15% menos tokens de entrada. O modelo lê igual.',
            },
            {
                key: 'explorer__ai__enabled',
                title: 'Frase do explorador',
                help: 'Uma frase do Haiku por rodada do explorador explicando cada par. Custa cerca de US$ 1 por mês.',
            },
        ],
    },
    {
        title: 'Serviços',
        description:
            'Rotinas que não gastam IA, mas ocupam a máquina quando não estão em uso.',
        items: [
            {
                key: 'explorer__enabled',
                title: 'Explorador de oportunidades',
                help: 'Procura os melhores pares USDT a cada 15 min na tela Ativos.',
            },
            {
                key: 'forecast__enabled',
                title: 'Serviço de previsão',
                help: 'Treino de domingo e consulta do modelo próprio. Enquanto ele não valida, o Quantitativo vota "aguardar" sem custo de qualquer jeito.',
            },
            {
                key: 'trading__b3_sync',
                title: 'Cotações da B3',
                help: 'Sincronização por minuto e fundamentos das 07:00. Sem uso enquanto só cripto é operada.',
            },
            {
                key: 'news__b3_sources',
                title: 'Notícias da B3',
                help: 'InfoMoney e fatos relevantes na coleta. Desligadas, deixam de passar pela triagem do Haiku.',
            },
        ],
    },
];

const values = ref<Record<string, Value>>({ ...props.values });
const saving = ref<string | null>(null);

watch(
    () => props.values,
    (fresh) => (values.value = { ...fresh }),
);

function save(key: string, value: Value) {
    if (!props.can_edit || values.value[key] === value) {
        return;
    }

    const before = values.value[key];
    values.value[key] = value;
    saving.value = key;

    router.put(
        SystemSettingsController.update.url(),
        { [key]: value },
        {
            preserveScroll: true,
            preserveState: true,
            onError: () => (values.value[key] = before),
            onFinish: () => (saving.value = null),
        },
    );
}
</script>

<template>
    <Head title="Custos e serviços" />

    <h1 class="sr-only">Custos e serviços</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Custos e serviços"
            description="Liga e desliga o que gasta IA e as rotinas sem uso. Vale para o sistema inteiro e passa a valer na próxima execução, sem deploy. A guarda de risco, os limites de dinheiro real e o Parar tudo não mudam aqui."
        />

        <p
            v-if="!can_edit"
            class="rounded-xl border border-dashed p-3 text-sm text-muted-foreground"
        >
            Só o administrador do sistema muda estes ajustes.
        </p>

        <section v-for="group in groups" :key="group.title" class="space-y-3">
            <div>
                <h2 class="text-sm font-medium">{{ group.title }}</h2>
                <p class="text-sm text-muted-foreground">
                    {{ group.description }}
                </p>
            </div>

            <ul class="divide-y rounded-2xl border">
                <li
                    v-for="item in group.items"
                    :key="item.key"
                    class="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium">{{ item.title }}</p>
                        <p class="text-sm text-muted-foreground">
                            {{ item.help }}
                        </p>
                    </div>

                    <div
                        v-if="item.options"
                        class="inline-flex shrink-0 rounded-lg border p-0.5"
                        role="radiogroup"
                        :aria-label="item.title"
                    >
                        <button
                            v-for="option in item.options"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="values[item.key] === option.value"
                            :disabled="!can_edit || saving === item.key"
                            class="rounded-md px-3 py-1 text-sm transition-colors disabled:cursor-not-allowed"
                            :class="
                                values[item.key] === option.value
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="save(item.key, option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <button
                        v-else
                        type="button"
                        role="switch"
                        :aria-checked="values[item.key] === true"
                        :aria-label="item.title"
                        :disabled="!can_edit || saving === item.key"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                        :class="values[item.key] ? 'bg-primary' : 'bg-muted'"
                        @click="save(item.key, !values[item.key])"
                    >
                        <span
                            class="inline-block size-5 rounded-full bg-background shadow transition-transform"
                            :class="
                                values[item.key]
                                    ? 'translate-x-5.5'
                                    : 'translate-x-0.5'
                            "
                        />
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>

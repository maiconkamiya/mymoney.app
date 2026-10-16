<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { dividirNegrito, formatarDataDaVersao } from '@/lib/novidadesTexto';

/**
 * Página "Novidades": as últimas versões do CHANGELOG, escritas para quem usa o sistema. O
 * servidor entrega texto puro; aqui só o `**negrito**` vira `<strong>`, por trechos (sem `v-html`).
 */
type VersaoDoChangelog = {
    versao: string;
    data: string | null;
    resumo: string | null;
    secoes: { titulo: string; itens: string[] }[];
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Novidades', href: '/novidades' }],
    },
});

defineProps<{
    versoes: VersaoDoChangelog[];
}>();

const page = usePage();
const versaoAtual = computed(() => page.props.sistema?.versao ?? null);
</script>

<template>
    <Head title="Novidades" />

    <div class="flex flex-1 flex-col gap-4 p-4">
        <Heading
            title="Novidades"
            description="O que mudou no MyMoney.App, da versão mais recente para a mais antiga"
        />

        <p
            v-if="versoes.length === 0"
            class="rounded-xl border bg-card p-5 text-sm text-muted-foreground"
        >
            Ainda não há novidades registradas.
        </p>

        <article
            v-for="versao in versoes"
            :key="versao.versao"
            class="rounded-xl border bg-card p-5"
            :data-testid="`novidades-versao-${versao.versao}`"
        >
            <header class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold tracking-tight">
                    Versão {{ versao.versao }}
                </h2>
                <Badge v-if="versao.versao === versaoAtual" variant="secondary">
                    Versão atual
                </Badge>
                <span v-if="versao.data" class="text-xs text-muted-foreground">
                    {{ formatarDataDaVersao(versao.data) }}
                </span>
            </header>

            <p v-if="versao.resumo" class="mt-1.5 text-sm text-muted-foreground">
                {{ versao.resumo }}
            </p>

            <section
                v-for="secao in versao.secoes"
                :key="secao.titulo"
                class="mt-4"
            >
                <h3
                    class="font-mono text-[11px] tracking-[0.14em] text-muted-foreground uppercase"
                >
                    {{ secao.titulo }}
                </h3>
                <ul class="mt-2 space-y-2 text-sm leading-relaxed">
                    <li
                        v-for="(item, indice) in secao.itens"
                        :key="indice"
                        class="flex gap-2"
                    >
                        <span
                            class="mt-2 size-1 shrink-0 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                        <span>
                            <template
                                v-for="(trecho, n) in dividirNegrito(item)"
                                :key="n"
                            >
                                <strong
                                    v-if="trecho.negrito"
                                    class="font-semibold text-foreground"
                                    >{{ trecho.texto }}</strong
                                >
                                <template v-else>{{ trecho.texto }}</template>
                            </template>
                        </span>
                    </li>
                </ul>
            </section>
        </article>
    </div>
</template>

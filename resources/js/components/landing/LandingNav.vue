<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Moon, Sun } from '@lucide/vue';
import { computed } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import { dashboard, login, register } from '@/routes';
import BrandMark from './BrandMark.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const { resolvedAppearance, updateAppearance } = useAppearance();
const dark = computed(() => resolvedAppearance.value === 'dark');

const links = [
    { href: '#mercado', label: 'Mercado' },
    { href: '#agentes', label: 'Agentes' },
    { href: '#risco', label: 'Risco' },
    { href: '#simulador', label: 'Simulador' },
    { href: '#duvidas', label: 'Dúvidas' },
];
</script>

<template>
    <header
        class="sticky top-0 z-20 border-b border-border/70 bg-background/85 backdrop-blur-md"
    >
        <nav
            class="mx-auto flex h-16 max-w-[1400px] items-center gap-6 px-4 sm:px-6 lg:px-8"
            aria-label="Principal"
        >
            <a
                href="#topo"
                class="flex items-center gap-2.5 font-semibold tracking-tight"
            >
                <BrandMark />
                <span>MyMoney</span>
            </a>
            <ul
                class="ml-4 hidden items-center gap-6 text-sm text-muted-foreground lg:flex"
            >
                <li v-for="l in links" :key="l.href">
                    <a
                        :href="l.href"
                        class="press whitespace-nowrap hover:text-foreground"
                        >{{ l.label }}</a
                    >
                </li>
            </ul>
            <div class="ml-auto flex items-center gap-2">
                <button
                    type="button"
                    class="press grid size-10 place-items-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                    :aria-label="dark ? 'Usar tema claro' : 'Usar tema escuro'"
                    @click="updateAppearance(dark ? 'light' : 'dark')"
                >
                    <Sun v-if="dark" :size="20" />
                    <Moon v-else :size="20" />
                </button>
                <Link
                    v-if="user"
                    :href="dashboard()"
                    class="press rounded-full bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground hover:opacity-90"
                >
                    Abrir o painel
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="press hidden rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap hover:bg-muted sm:inline-flex"
                    >
                        Entrar
                    </Link>
                    <Link
                        :href="register()"
                        class="press rounded-full bg-primary px-4 py-2 text-sm font-medium whitespace-nowrap text-primary-foreground hover:opacity-90"
                    >
                        Criar conta
                    </Link>
                </template>
            </div>
        </nav>
    </header>
</template>

<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { computed } from 'vue';
import { dashboard, register } from '@/routes';
import Reveal from './Reveal.vue';
import ctaImg from '../../../images/landing/cta.webp';
const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <section class="relative isolate overflow-hidden">
        <img
            :src="ctaImg"
            alt=""
            class="absolute inset-0 -z-10 size-full object-cover"
        />
        <div
            class="absolute inset-0 -z-10"
            style="
                background: linear-gradient(
                    90deg,
                    var(--background) 0%,
                    color-mix(in oklab, var(--background) 72%, transparent) 55%,
                    transparent 100%
                );
            "
        />
        <div class="mx-auto max-w-[1400px] px-4 py-24 sm:px-6 md:py-32 lg:px-8">
            <Reveal class="max-w-xl">
                <h2 class="text-3xl font-semibold tracking-tight md:text-5xl">
                    Comece com dinheiro simulado.
                </h2>
                <p class="mt-4 max-w-[44ch] text-lg text-muted-foreground">
                    Defina valor e prazo, deixe os agentes trabalharem e só
                    depois decida se vale arriscar.
                </p>
                <Link
                    :href="user ? dashboard() : register()"
                    class="press mt-8 inline-flex items-center gap-2 rounded-full bg-primary px-6 py-3 font-medium whitespace-nowrap text-primary-foreground hover:opacity-90"
                >
                    {{ user ? 'Abrir o painel' : 'Criar conta' }}
                    <ArrowRight :size="18" />
                </Link>
            </Reveal>
        </div>
    </section>
</template>

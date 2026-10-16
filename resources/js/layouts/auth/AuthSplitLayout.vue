<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import heroImg from '../../../images/landing/hero.webp';
import BrandMark from '@/components/landing/BrandMark.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="grid min-h-dvh lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <div class="flex flex-col px-6 py-8 sm:px-10">
            <Link
                :href="home()"
                class="flex items-center gap-2 text-base font-semibold"
            >
                <BrandMark />
                {{ name }}
            </Link>
            <div class="flex flex-1 items-center justify-center py-12">
                <div class="flex w-full max-w-sm flex-col gap-8">
                    <div class="flex flex-col gap-2">
                        <h1
                            v-if="title"
                            class="text-2xl font-semibold tracking-tight"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </div>
                    <slot />
                </div>
            </div>
        </div>
        <div class="relative hidden p-3 lg:block">
            <div class="relative h-full overflow-hidden rounded-2xl">
                <img
                    :src="heroImg"
                    alt=""
                    class="absolute inset-0 size-full object-cover grayscale"
                />
                <div
                    class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"
                />
                <p
                    class="absolute inset-x-10 bottom-10 max-w-md text-2xl leading-snug font-semibold text-white"
                >
                    <span class="block">Seis agentes votam.</span>
                    <span class="block">Você vê cada voto.</span>
                </p>
            </div>
        </div>
    </div>
</template>

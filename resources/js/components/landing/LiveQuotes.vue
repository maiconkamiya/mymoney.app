<script setup lang="ts">
import { assets, ASSETS, changePct, brl, pct } from '@/lib/mercado';
import FeedStatus from './FeedStatus.vue';
</script>

<template>
    <div
        class="rounded-2xl border border-border bg-card/95 p-5 shadow-[0_24px_60px_-24px_rgb(12_14_18/0.55)] backdrop-blur"
    >
        <FeedStatus compact />
        <ul class="mt-4 grid gap-3">
            <li
                v-for="a in ASSETS"
                :key="a.id"
                class="grid grid-cols-[1fr_auto] items-baseline gap-x-4"
            >
                <div class="min-w-0">
                    <p class="font-medium">{{ assets[a.id].name }}</p>
                    <p class="num text-xs text-muted-foreground">
                        {{ a.pair }}
                    </p>
                </div>
                <div class="text-right">
                    <p
                        :key="assets[a.id].tick"
                        class="num text-lg"
                        :class="
                            assets[a.id].tick
                                ? assets[a.id].dir > 0
                                    ? 'flash-up'
                                    : 'flash-down'
                                : ''
                        "
                    >
                        {{ brl(assets[a.id].price) }}
                    </p>
                    <p
                        class="num text-xs"
                        :class="changePct(a.id) >= 0 ? 'text-up' : 'text-down'"
                    >
                        {{ pct(changePct(a.id)) }} em 3 h
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>

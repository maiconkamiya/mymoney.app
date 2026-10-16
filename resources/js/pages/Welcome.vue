<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { MotionConfig } from 'motion-v';
import { onBeforeUnmount } from 'vue';
import AgentsBento from '@/components/landing/AgentsBento.vue';
import AssetMarquee from '@/components/landing/AssetMarquee.vue';
import ConsensusSection from '@/components/landing/ConsensusSection.vue';
import FaqSection from '@/components/landing/FaqSection.vue';
import FinalCta from '@/components/landing/FinalCta.vue';
import HeroSection from '@/components/landing/HeroSection.vue';
import LandingFooter from '@/components/landing/LandingFooter.vue';
import LandingNav from '@/components/landing/LandingNav.vue';
import MarketSection from '@/components/landing/MarketSection.vue';
import RiskSection from '@/components/landing/RiskSection.vue';
import SimulatorSection from '@/components/landing/SimulatorSection.vue';
import { startMarket, stopMarket } from '@/lib/mercado';

// Inicia no setup, antes dos filhos montarem, para todas as seções já terem candles.
if (typeof window !== 'undefined') {
    startMarket();
}
onBeforeUnmount(stopMarket);
</script>

<template>
    <Head title="Análise e simulação de cripto" />
    <!-- reduced-motion="user": com prefers-reduced-motion, o Motion troca transformações por mudança instantânea. -->
    <MotionConfig reduced-motion="user">
        <div class="min-h-screen scroll-smooth bg-background text-foreground">
            <LandingNav />
            <main>
                <HeroSection />
                <AssetMarquee />
                <MarketSection />
                <AgentsBento />
                <ConsensusSection />
                <RiskSection />
                <SimulatorSection />
                <FaqSection />
                <FinalCta />
            </main>
            <LandingFooter />
        </div>
    </MotionConfig>
</template>

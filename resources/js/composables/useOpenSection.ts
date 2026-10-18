import { ref, watch } from 'vue';

// Accordion de uma seção aberta por vez, com a escolha lembrada neste navegador.
export function useOpenSection(storageKey: string, fallback: string | null) {
    const read = (): string | null => {
        try {
            const saved = localStorage.getItem(storageKey);

            return saved === null ? fallback : saved === '' ? null : saved;
        } catch {
            return fallback;
        }
    };

    const open = ref<string | null>(read());

    watch(open, (value) => {
        try {
            localStorage.setItem(storageKey, value ?? '');
        } catch {
            // Sem armazenamento (aba privada): a escolha vale só nesta visita.
        }
    });

    const toggle = (id: string) => {
        open.value = open.value === id ? null : id;
    };

    return { open, toggle };
}

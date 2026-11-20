<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Images, Plus } from '@lucide/vue';
import { computed, onBeforeUnmount, watch } from 'vue';
import GenerationCard from '@/components/generations/GenerationCard.vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'History', href: index() }] },
});

const props = defineProps<{ generations: Paginated<Generation> }>();

const POLL_MS = 3000;

const hasRunning = computed(() =>
    props.generations.data.some((g) => !g.is_terminal),
);

let timer: ReturnType<typeof setTimeout> | null = null;

function schedule(): void {
    stop();
    if (!hasRunning.value) {
        return;
    }

    timer = setTimeout(() => {
        if (document.hidden) {
            schedule();

            return;
        }

        router.reload({ only: ['generations', 'auth'], onFinish: schedule });
    }, POLL_MS);
}

function stop(): void {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

watch(() => props.generations, schedule, { immediate: true });
onBeforeUnmount(stop);
</script>

<template>
    <Head title="History" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="History"
                description="Your images, their status and what each one cost."
                class="mb-0"
            />
            <Button as-child>
                <Link :href="create()"><Plus /> New image</Link>
            </Button>
        </div>

        <p v-if="hasRunning" class="sr-only" aria-live="polite">
            Some images are still being generated. This page updates
            automatically.
        </p>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
        >
            <Images class="size-10 text-muted-foreground" aria-hidden="true" />
            <h2 class="text-lg font-medium">No images yet</h2>
            <p class="max-w-sm text-sm text-muted-foreground">
                Describe an image and we'll show you the price before you
                generate it.
            </p>
            <Button as-child>
                <Link :href="create()">Generate your first image</Link>
            </Button>
        </div>

        <template v-else>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GenerationCard
                    v-for="generation in generations.data"
                    :key="generation.id"
                    :generation="generation"
                />
            </div>
            <Pagination :meta="generations.meta" />
        </template>
    </div>
</template>

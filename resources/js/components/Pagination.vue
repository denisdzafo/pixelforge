<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{ meta: Paginated<unknown>['meta'] }>();

function label(text: string): string {
    return text.replace('&laquo;', '←').replace('&raquo;', '→');
}
</script>

<template>
    <nav
        v-if="meta.last_page > 1"
        class="flex flex-wrap items-center justify-between gap-3"
        aria-label="Pagination"
    >
        <p class="text-sm text-muted-foreground">
            {{ meta.from }}–{{ meta.to }} of {{ meta.total }}
        </p>
        <ul class="flex flex-wrap gap-1">
            <li v-for="(link, index) in meta.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :class="
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90'
                            : ''
                    "
                    :aria-current="link.active ? 'page' : undefined"
                >
                    {{ label(link.label) }}
                </Link>
                <span
                    v-else
                    class="inline-flex h-8 min-w-8 items-center justify-center px-2 text-sm text-muted-foreground/50"
                    aria-hidden="true"
                    >{{ label(link.label) }}</span
                >
            </li>
        </ul>
    </nav>
</template>

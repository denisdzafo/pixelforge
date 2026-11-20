<script setup lang="ts">
import { CircleAlert, CircleCheck, Clock, Hourglass } from '@lucide/vue';
import { computed } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types';

const props = defineProps<{ status: GenerationStatus }>();

const config = computed(
    () =>
        ({
            pending: {
                label: 'Waiting',
                icon: Clock,
                classes: 'bg-muted text-muted-foreground',
            },
            queued: {
                label: 'Queued',
                icon: Hourglass,
                classes:
                    'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
            },
            processing: {
                label: 'Generating…',
                icon: null,
                classes:
                    'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300',
            },
            succeeded: {
                label: 'Done',
                icon: CircleCheck,
                classes:
                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
            },
            failed: {
                label: 'Failed',
                icon: CircleAlert,
                classes:
                    'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300',
            },
        })[props.status],
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="config.classes"
    >
        <Spinner v-if="status === 'processing'" class="size-3" />
        <component
            :is="config.icon"
            v-else-if="config.icon"
            class="size-3"
            aria-hidden="true"
        />
        {{ config.label }}
    </span>
</template>

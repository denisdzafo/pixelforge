<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ImageOff,
    Pencil,
    RotateCcw,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CostBreakdown from '@/components/generations/CostBreakdown.vue';
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Spinner } from '@/components/ui/spinner';
import { formatApprox, formatRange, formatUsd } from '@/lib/money';
import { dateTime, timeAgo } from '@/lib/time';
import { create, retry } from '@/routes/generations';
import type { Generation } from '@/types';

const props = defineProps<{ generation: Generation }>();

const aspect: Record<string, string> = {
    '1024x1024': 'aspect-square',
    '1536x1024': 'aspect-[3/2]',
    '1024x1536': 'aspect-[2/3]',
};

const imageBroken = ref(false);
const retrying = ref(false);

const g = computed(() => props.generation);
const showImage = computed(
    () => g.value.image_url !== null && !imageBroken.value,
);
const imageGone = computed(
    () =>
        g.value.status === 'succeeded' &&
        (g.value.image_expired ||
            imageBroken.value ||
            g.value.image_url === null),
);

const progressText = computed(() => {
    switch (g.value.status) {
        case 'pending':
            return g.value.waiting_reason ?? 'Waiting for a free slot';
        case 'queued':
            return 'Queued at the image provider';
        case 'processing':
            return 'Generating your image…';
        default:
            return '';
    }
});

function retryGeneration(): void {
    router.post(
        retry.url(g.value.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (retrying.value = true),
            onFinish: () => (retrying.value = false),
        },
    );
}
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-xs"
    >
        <div
            class="relative flex max-h-80 items-center justify-center overflow-hidden bg-muted"
            :class="aspect[g.size] ?? 'aspect-square'"
        >
            <a
                v-if="showImage"
                :href="g.image_url!"
                target="_blank"
                rel="noopener"
                class="block h-full w-full focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :aria-label="`Open full-size image: ${g.prompt}`"
            >
                <img
                    :src="g.image_url!"
                    :alt="g.prompt"
                    loading="lazy"
                    class="h-full w-full object-cover transition-transform hover:scale-[1.02]"
                    @error="imageBroken = true"
                />
            </a>
            <div
                v-else-if="imageGone"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-8" aria-hidden="true" />
                <p>Image link expired</p>
                <p class="text-xs">Pixelforge keeps images for 24 hours.</p>
            </div>
            <div
                v-else-if="g.status === 'failed'"
                class="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground"
            >
                <TriangleAlert class="size-8" aria-hidden="true" />
                <p>No image</p>
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-3 p-6 text-center text-sm text-muted-foreground"
                role="status"
            >
                <div
                    class="absolute inset-0 animate-pulse bg-gradient-to-br from-muted via-background/40 to-muted"
                    aria-hidden="true"
                />
                <Spinner class="relative size-6" />
                <p class="relative">{{ progressText }}</p>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="g.status" />
                <time
                    :datetime="g.created_at"
                    :title="dateTime(g.created_at)"
                    class="text-xs text-muted-foreground"
                >
                    {{ timeAgo(g.created_at) }}
                </time>
            </div>

            <p class="line-clamp-3 text-sm" :title="g.prompt">{{ g.prompt }}</p>

            <p class="text-xs text-muted-foreground">
                {{ g.size }} · {{ g.quality }} quality<template
                    v-if="g.reference_images.length"
                >
                    · {{ g.reference_images.length }} reference{{
                        g.reference_images.length === 1 ? '' : 's'
                    }}</template
                >
            </p>

            <div class="mt-auto space-y-1 border-t pt-3 text-sm">
                <template
                    v-if="g.status === 'succeeded' && g.charged_nanos !== null"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-muted-foreground">Charged</span>
                        <span class="text-right font-medium tabular-nums">
                            {{ formatUsd(g.charged_nanos) }}
                            <span class="font-normal text-muted-foreground">{{
                                formatApprox(g.charged_nanos)
                            }}</span>
                        </span>
                    </div>
                    <div
                        class="flex items-baseline justify-between gap-2 text-xs text-muted-foreground"
                    >
                        <span>Estimated</span>
                        <span class="tabular-nums">{{
                            formatRange(
                                g.estimate.min_nanos,
                                g.estimate.max_nanos,
                            )
                        }}</span>
                    </div>
                    <Collapsible v-if="g.breakdown">
                        <CollapsibleTrigger
                            class="group flex items-center gap-1 rounded text-xs font-medium text-primary hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            Cost details
                            <ChevronDown
                                class="size-3 transition-transform group-data-[state=open]:rotate-180"
                            />
                        </CollapsibleTrigger>
                        <CollapsibleContent class="pt-2">
                            <CostBreakdown :breakdown="g.breakdown" />
                        </CollapsibleContent>
                    </Collapsible>
                </template>

                <template v-else-if="g.status === 'failed'">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-muted-foreground">Charged</span>
                        <span
                            class="font-medium text-emerald-700 dark:text-emerald-400"
                            >Nothing, it's free</span
                        >
                    </div>
                    <p
                        v-if="g.error_message"
                        class="rounded-md bg-red-50 p-2 text-xs text-red-800 dark:bg-red-950/50 dark:text-red-300"
                    >
                        {{ g.error_message }}
                    </p>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="retrying"
                            @click="retryGeneration"
                        >
                            <Spinner v-if="retrying" />
                            <RotateCcw v-else />
                            Retry
                        </Button>
                        <Button size="sm" variant="ghost" as-child>
                            <Link :href="create({ query: { from: g.id } })">
                                <Pencil />
                                Edit &amp; retry
                            </Link>
                        </Button>
                    </div>
                </template>

                <template v-else>
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-muted-foreground">Estimated</span>
                        <span class="font-medium tabular-nums">{{
                            formatRange(
                                g.estimate.min_nanos,
                                g.estimate.max_nanos,
                            )
                        }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Holding up to {{ formatUsd(g.hold_nanos) }} until it's
                        done.
                    </p>
                </template>
            </div>
        </div>
    </article>
</template>

<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ChevronDown, FlaskConical, Info, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import ReferenceImagesInput from '@/components/generations/ReferenceImagesInput.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatApprox, formatRange, formatUsd, toNanos } from '@/lib/money';
import { characterCount, estimate } from '@/lib/pricing';
import { isHttpUrl } from '@/lib/url';
import { index as credits } from '@/routes/credits';
import { create, store } from '@/routes/generations';
import type { PricingCatalog } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Generate', href: create() }] },
});

const props = defineProps<{
    pricing: PricingCatalog;
    markupPercent: number;
    sizes: string[];
    qualities: string[];
    maxPromptLength: number;
    maxReferenceImages: number;
    prefill: {
        prompt: string;
        size: string;
        quality: string;
        reference_images: string[];
    } | null;
}>();

const page = usePage();
const balance = computed(() => toNanos(page.props.auth.user.balance_nanos));

const form = useForm({
    prompt: props.prefill?.prompt ?? '',
    size: props.prefill?.size ?? '1024x1024',
    quality: props.prefill?.quality ?? 'medium',
    reference_images: [...(props.prefill?.reference_images ?? [])] as string[],
});

const sizeOptions: Record<
    string,
    { label: string; ratio: string; box: string }
> = {
    '1024x1024': { label: 'Square', ratio: '1:1', box: 'h-10 w-10' },
    '1536x1024': { label: 'Landscape', ratio: '3:2', box: 'h-8 w-12' },
    '1024x1536': { label: 'Portrait', ratio: '2:3', box: 'h-12 w-8' },
};

const qualityLabels: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

const promptLength = computed(() => characterCount(form.prompt));
const promptTooLong = computed(
    () => promptLength.value > props.maxPromptLength,
);

const filledReferences = computed(() =>
    form.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
);
const invalidReferences = computed(() =>
    filledReferences.value.some((url) => !isHttpUrl(url)),
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const cost = computed(() =>
    estimate(
        form.prompt,
        form.size,
        form.quality,
        filledReferences.value.length,
        props.pricing,
        props.markupPercent,
    ),
);

const canAfford = computed(
    () => cost.value !== null && balance.value >= cost.value.max,
);

const canSubmit = computed(
    () =>
        form.prompt.trim() !== '' &&
        !promptTooLong.value &&
        !invalidReferences.value &&
        canAfford.value &&
        !form.processing,
);

const keywords = [
    ['[succeed]', 'Never fails at random'],
    ['[fail]', 'Accepted, then fails (free)'],
    ['[slow]', 'Takes 60–90 s to process'],
    ['[duplicate]', 'Every webhook is delivered twice'],
    ['[blocked]', 'Rejected by the content policy'],
    ['[overloaded]', 'Provider returns 503 (we retry)'],
] as const;

function insertKeyword(keyword: string): void {
    form.prompt =
        form.prompt.trim() === ''
            ? keyword
            : `${form.prompt.trimEnd()} ${keyword}`;
}

function submit(): void {
    form.post(store.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate" />

    <div class="mx-auto w-full max-w-6xl p-4 md:p-6">
        <Heading
            title="Generate an image"
            description="Describe what you want. You see the price before you submit and pay the exact cost when it's done."
        />

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"
            @submit.prevent="submit"
        >
            <div class="space-y-6">
                <Card>
                    <CardContent class="space-y-6">
                        <div class="grid gap-2">
                            <div class="flex items-end justify-between gap-2">
                                <Label for="prompt">Prompt</Label>
                                <span
                                    id="prompt-count"
                                    class="text-xs tabular-nums"
                                    :class="
                                        promptTooLong
                                            ? 'font-medium text-red-600 dark:text-red-500'
                                            : 'text-muted-foreground'
                                    "
                                    aria-live="polite"
                                >
                                    {{ promptLength.toLocaleString() }} /
                                    {{ maxPromptLength.toLocaleString() }}
                                </span>
                            </div>
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                v-focus
                                rows="6"
                                required
                                aria-describedby="prompt-count"
                                :aria-invalid="
                                    promptTooLong || !!form.errors.prompt
                                "
                                placeholder="A lighthouse on a cliff at dawn, oil painting"
                                class="w-full resize-y rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm dark:bg-input/30"
                            />
                            <InputError
                                :message="
                                    form.errors.prompt ??
                                    (promptTooLong
                                        ? `Prompts can be at most ${maxPromptLength.toLocaleString()} characters.`
                                        : undefined)
                                "
                            />
                        </div>

                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Size
                            </legend>
                            <div class="grid grid-cols-3 gap-3">
                                <label
                                    v-for="size in sizes"
                                    :key="size"
                                    class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border p-3 text-center transition-colors hover:bg-accent/60 has-checked:border-primary has-checked:bg-accent has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                                >
                                    <input
                                        v-model="form.size"
                                        type="radio"
                                        name="size"
                                        :value="size"
                                        class="sr-only"
                                    />
                                    <span class="flex h-12 items-center">
                                        <span
                                            class="rounded-sm border-2 border-current opacity-70"
                                            :class="sizeOptions[size]?.box"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <span class="text-sm font-medium">
                                        {{ sizeOptions[size]?.label ?? size }}
                                    </span>
                                    <span class="text-xs text-muted-foreground">
                                        {{ sizeOptions[size]?.ratio }} ·
                                        {{ size }}
                                    </span>
                                </label>
                            </div>
                            <InputError :message="form.errors.size" />
                        </fieldset>

                        <fieldset class="grid gap-2">
                            <legend class="mb-2 text-sm font-medium">
                                Quality
                            </legend>
                            <div
                                class="inline-grid w-full grid-cols-3 rounded-lg bg-muted p-1 sm:w-auto"
                            >
                                <label
                                    v-for="quality in qualities"
                                    :key="quality"
                                    class="cursor-pointer rounded-md px-4 py-1.5 text-center text-sm font-medium text-muted-foreground transition-colors has-checked:bg-background has-checked:text-foreground has-checked:shadow-sm has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50"
                                >
                                    <input
                                        v-model="form.quality"
                                        type="radio"
                                        name="quality"
                                        :value="quality"
                                        class="sr-only"
                                    />
                                    {{ qualityLabels[quality] ?? quality }}
                                </label>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Higher quality uses more output and reasoning
                                tokens, so it costs more.
                            </p>
                            <InputError :message="form.errors.quality" />
                        </fieldset>

                        <ReferenceImagesInput
                            v-model="form.reference_images"
                            :max="maxReferenceImages"
                            :tokens-per-image="pricing.per_reference_image"
                            :errors="errors"
                        />
                    </CardContent>
                </Card>

                <Collapsible class="rounded-lg border">
                    <CollapsibleTrigger
                        class="group flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    >
                        <FlaskConical class="size-4 text-muted-foreground" />
                        Test keywords
                        <ChevronDown
                            class="ml-auto size-4 transition-transform group-data-[state=open]:rotate-180"
                        />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="px-4 pb-4">
                        <p class="mb-3 text-xs text-muted-foreground">
                            Pixelforge's test switches: put one anywhere in the
                            prompt. They count as prompt text.
                        </p>
                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li
                                v-for="[keyword, description] in keywords"
                                :key="keyword"
                            >
                                <button
                                    type="button"
                                    class="flex w-full items-baseline gap-2 rounded-md px-2 py-1 text-left text-sm hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    @click="insertKeyword(keyword)"
                                >
                                    <code
                                        class="font-mono text-xs font-semibold"
                                        >{{ keyword }}</code
                                    >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ description }}</span
                                    >
                                </button>
                            </li>
                        </ul>
                    </CollapsibleContent>
                </Collapsible>
            </div>

            <div class="lg:sticky lg:top-4 lg:self-start">
                <Card>
                    <CardHeader>
                        <CardTitle>Estimated cost</CardTitle>
                        <CardDescription>
                            Part of the cost is only known once the image is
                            done.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div v-if="cost" aria-live="polite">
                            <p
                                class="text-2xl font-semibold tracking-tight tabular-nums"
                            >
                                {{ formatRange(cost.min, cost.max) }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Most likely {{ formatApprox(cost.expected) }}
                            </p>
                        </div>

                        <div
                            v-if="cost"
                            class="flex gap-2 rounded-md bg-muted/60 p-3 text-xs leading-relaxed text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <p>
                                You're charged the exact amount once it
                                finishes. Until then we hold up to
                                <strong
                                    class="font-medium text-foreground tabular-nums"
                                    >{{ formatUsd(cost.max) }}</strong
                                >
                                from your balance and release the rest. Failed
                                generations are free.
                            </p>
                        </div>

                        <dl class="grid grid-cols-2 gap-y-1 text-sm">
                            <dt class="text-muted-foreground">Your balance</dt>
                            <dd class="text-right font-medium tabular-nums">
                                {{ formatUsd(balance) }}
                            </dd>
                            <template v-if="cost && canAfford">
                                <dt class="text-muted-foreground">
                                    After the hold
                                </dt>
                                <dd class="text-right tabular-nums">
                                    {{ formatUsd(balance - cost.max) }}
                                </dd>
                            </template>
                        </dl>

                        <Alert v-if="cost && !canAfford" variant="destructive">
                            <AlertTitle>Not enough credits</AlertTitle>
                            <AlertDescription>
                                This image can cost up to
                                {{ formatUsd(cost.max) }} and your balance is
                                {{ formatUsd(balance) }}.
                            </AlertDescription>
                        </Alert>

                        <Alert v-if="errors.balance" variant="destructive">
                            <AlertDescription>{{
                                errors.balance
                            }}</AlertDescription>
                        </Alert>

                        <Button
                            v-if="cost && !canAfford"
                            as-child
                            class="w-full"
                            size="lg"
                        >
                            <Link :href="credits()">Buy credits</Link>
                        </Button>
                        <Button
                            v-else
                            type="submit"
                            class="w-full"
                            size="lg"
                            :disabled="!canSubmit"
                        >
                            <Spinner v-if="form.processing" />
                            <Sparkles v-else />
                            Generate image
                        </Button>

                        <p class="text-center text-xs text-muted-foreground">
                            Pixelforge's cost + {{ markupPercent }}%. Credits
                            are US dollars.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </form>
    </div>
</template>

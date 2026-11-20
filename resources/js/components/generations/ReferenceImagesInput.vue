<script setup lang="ts">
import { ImagePlus, Trash2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { isHttpUrl } from '@/lib/url';

const urls = defineModel<string[]>({ required: true });

const props = defineProps<{
    max: number;
    tokensPerImage: number;
    errors: Record<string, string | undefined>;
}>();

const placeholder = 'https://example.com/style.png';

function errorFor(position: number): string | undefined {
    const value = urls.value[position]?.trim() ?? '';

    return (
        props.errors[`reference_images.${position}`] ??
        (value !== '' && !isHttpUrl(value)
            ? 'Enter an http(s) URL.'
            : undefined)
    );
}

function add(): void {
    if (urls.value.length < props.max) {
        urls.value = [...urls.value, ''];
    }
}

function remove(position: number): void {
    urls.value = urls.value.filter((_, i) => i !== position);
}

function update(position: number, value: string | number): void {
    urls.value = urls.value.map((url, i) =>
        i === position ? String(value) : url,
    );
}
</script>

<template>
    <fieldset class="grid gap-2">
        <legend class="mb-1 text-sm font-medium">
            Reference images
            <span class="font-normal text-muted-foreground"
                >(optional, up to {{ max }})</span
            >
        </legend>
        <p class="text-xs text-muted-foreground">
            Links to images that guide the style. Each one adds
            {{ tokensPerImage }} input tokens.
        </p>
        <div v-for="(url, position) in urls" :key="position" class="grid gap-1">
            <div class="flex gap-2">
                <label :for="`reference-${position}`" class="sr-only"
                    >Reference image {{ position + 1 }}</label
                >
                <Input
                    :id="`reference-${position}`"
                    :model-value="url"
                    type="url"
                    inputmode="url"
                    :placeholder="placeholder"
                    :aria-invalid="errorFor(position) !== undefined"
                    @update:model-value="update(position, $event)"
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="`Remove reference image ${position + 1}`"
                    @click="remove(position)"
                >
                    <Trash2 />
                </Button>
            </div>
            <InputError :message="errorFor(position)" />
        </div>
        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="urls.length >= max"
                @click="add"
            >
                <ImagePlus />
                Add reference image
            </Button>
        </div>
        <InputError :message="errors.reference_images" />
    </fieldset>
</template>

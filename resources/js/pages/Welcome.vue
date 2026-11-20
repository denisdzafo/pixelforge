<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BadgeDollarSign, Images, ShieldCheck } from '@lucide/vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { create } from '@/routes/generations';

const features = [
    {
        icon: BadgeDollarSign,
        title: 'See the price first',
        text: 'A live estimate as you type. You pay the exact cost once the image is done.',
    },
    {
        icon: ShieldCheck,
        title: 'Failures are free',
        text: 'If a generation fails, nothing is charged and the hold goes straight back.',
    },
    {
        icon: Images,
        title: 'Follow every image',
        text: 'Live status, the image and a cost breakdown for every generation.',
    },
];
</script>

<template>
    <Head title="Welcome" />
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-5xl items-center justify-between p-4 md:p-6"
        >
            <div class="flex items-center gap-2 font-semibold">
                <span
                    class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5 fill-current" />
                </span>
                {{ $page.props.name }}
            </div>
            <nav class="flex items-center gap-2">
                <Button v-if="$page.props.auth.user" as-child>
                    <Link :href="create()">Open the app</Link>
                </Button>
                <template v-else>
                    <Button variant="ghost" as-child
                        ><Link :href="login()">Log in</Link></Button
                    >
                    <Button as-child
                        ><Link :href="register()">Sign up</Link></Button
                    >
                </template>
            </nav>
        </header>

        <main
            class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-12 p-4 md:p-6"
        >
            <section class="max-w-2xl space-y-4">
                <h1 class="text-4xl font-semibold tracking-tight md:text-5xl">
                    Images from a prompt, at an honest price.
                </h1>
                <p class="text-lg text-muted-foreground">
                    Describe it, see what it will cost, and pay exactly what the
                    generation used, to the nano-dollar.
                </p>
                <Button size="lg" as-child>
                    <Link :href="$page.props.auth.user ? create() : register()"
                        >Start generating</Link
                    >
                </Button>
            </section>

            <section class="grid gap-4 md:grid-cols-3">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border p-5"
                >
                    <component
                        :is="feature.icon"
                        class="mb-3 size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <h2 class="font-medium">{{ feature.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ feature.text }}
                    </p>
                </div>
            </section>
        </main>
    </div>
</template>

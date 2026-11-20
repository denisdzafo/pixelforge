<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Plus, Receipt } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { formatSigned, formatUsd, toNanos } from '@/lib/money';
import { dateTime } from '@/lib/time';
import { index, purchase } from '@/routes/credits';
import type { CreditTransaction, Paginated } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Credits', href: index() }] },
});

defineProps<{
    packs: { usd: string; nanos: string }[];
    onHoldNanos: string;
    transactions: Paginated<CreditTransaction>;
}>();

const page = usePage();
const balance = computed(() => page.props.auth.user.balance_nanos);
const buying = ref<string | null>(null);

function buy(pack: string): void {
    const key = crypto.randomUUID();

    router.post(
        purchase.url(),
        { pack, idempotency_key: key },
        {
            preserveScroll: true,
            onStart: () => (buying.value = pack),
            onFinish: () => (buying.value = null),
        },
    );
}

const amountClass = (tx: CreditTransaction) =>
    toNanos(tx.amount_nanos) < 0n
        ? 'text-foreground'
        : 'text-emerald-700 dark:text-emerald-400';
</script>

<template>
    <Head title="Credits" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Credits"
            description="1 credit = 1 US dollar. You pay the exact cost of each image."
            class="mb-0"
        />

        <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <Card>
                <CardHeader>
                    <CardDescription>Available balance</CardDescription>
                    <CardTitle class="text-3xl tabular-nums">{{
                        formatUsd(balance)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    <p v-if="toNanos(onHoldNanos) > 0n">
                        {{ formatUsd(onHoldNanos) }} is held for images that are
                        still running. What isn't used comes back when they
                        finish.
                    </p>
                    <p v-else>Nothing is on hold right now.</p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Buy credits</CardTitle>
                    <CardDescription
                        >Demo purchase: no payment is taken.</CardDescription
                    >
                </CardHeader>
                <CardContent class="grid grid-cols-3 gap-3">
                    <button
                        v-for="pack in packs"
                        :key="pack.usd"
                        type="button"
                        class="flex flex-col items-center gap-1 rounded-lg border p-4 transition-colors hover:border-primary hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:opacity-50"
                        :disabled="buying !== null"
                        :aria-label="`Buy ${formatUsd(pack.nanos)} of credits`"
                        @click="buy(pack.usd)"
                    >
                        <Spinner v-if="buying === pack.usd" class="size-5" />
                        <Plus
                            v-else
                            class="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="text-lg font-semibold tabular-nums">{{
                            formatUsd(pack.nanos)
                        }}</span>
                    </button>
                </CardContent>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Activity</CardTitle>
                <CardDescription>
                    Every change to your balance. A generation first holds its
                    maximum cost, then releases the hold and is charged the
                    exact amount (or nothing if it failed).
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div
                    v-if="transactions.data.length === 0"
                    class="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted-foreground"
                >
                    <Receipt class="size-8" aria-hidden="true" />
                    No activity yet.
                </div>
                <div v-else class="-mx-6 overflow-x-auto px-6">
                    <table class="w-full min-w-[32rem] text-sm">
                        <thead
                            class="border-b text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th scope="col" class="py-2 pr-4 font-normal">
                                    Date
                                </th>
                                <th scope="col" class="py-2 pr-4 font-normal">
                                    Description
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 pr-4 text-right font-normal"
                                >
                                    Amount
                                </th>
                                <th
                                    scope="col"
                                    class="py-2 text-right font-normal"
                                >
                                    Balance
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="tx in transactions.data" :key="tx.id">
                                <td
                                    class="py-2 pr-4 whitespace-nowrap text-muted-foreground"
                                >
                                    {{ dateTime(tx.created_at) }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ tx.label }}
                                    <span
                                        v-if="tx.generation_prompt"
                                        class="block max-w-xs truncate text-xs text-muted-foreground"
                                    >
                                        “{{ tx.generation_prompt }}”
                                    </span>
                                </td>
                                <td
                                    class="py-2 pr-4 text-right whitespace-nowrap tabular-nums"
                                    :class="amountClass(tx)"
                                >
                                    {{ formatSigned(tx.amount_nanos) }}
                                </td>
                                <td
                                    class="py-2 text-right whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ formatUsd(tx.balance_after_nanos) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination class="mt-4" :meta="transactions.meta" />
            </CardContent>
        </Card>
    </div>
</template>

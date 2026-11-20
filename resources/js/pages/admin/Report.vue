<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBps, formatUsd, toNanos } from '@/lib/money';
import { report as reportRoute } from '@/routes/admin';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin report', href: reportRoute() }] },
});

type Totals = {
    generations: number;
    charged_nanos: string;
    provider_cost_nanos: string;
    profit_nanos: string;
    margin_bps: number | null;
};

type Day = Totals & {
    date: string;
    pixelforge: {
        generations: number;
        cost_nanos: string;
        cost_usd: string;
        difference_nanos: string;
        matches: boolean;
    } | null;
};

type UserRow = Totals & {
    user_id: number | null;
    name: string;
    email: string | null;
};

const props = defineProps<{
    report: {
        start: string;
        end: string;
        days: Day[];
        users: UserRow[];
        total: Totals & {
            pixelforge_cost_nanos: string | null;
            matches: boolean | null;
        };
        usage_error: string | null;
    };
    maxDays: number;
}>();

const start = ref(props.report.start);
const end = ref(props.report.end);
const hideEmpty = ref(true);

const visibleDays = computed(() =>
    hideEmpty.value
        ? props.report.days.filter(
              (d) =>
                  d.generations > 0 ||
                  (d.pixelforge && d.pixelforge.generations > 0),
          )
        : props.report.days,
);
const mismatches = computed(
    () =>
        props.report.days.filter((d) => d.pixelforge && !d.pixelforge.matches)
            .length,
);

function load(range?: { start: string; end: string }): void {
    router.get(
        reportRoute.url(),
        range ?? { start: start.value, end: end.value },
        { preserveState: true, preserveScroll: true },
    );
}

function lastDays(days: number): void {
    const today = new Date();
    const from = new Date(today.getTime() - (days - 1) * 86_400_000);
    const iso = (d: Date) => d.toISOString().slice(0, 10);
    start.value = iso(from);
    end.value = iso(today);
    load();
}

const profitClass = (nanos: string) =>
    toNanos(nanos) < 0n ? 'text-red-600 dark:text-red-400' : '';
</script>

<template>
    <Head title="Admin report" />

    <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
        <Heading
            title="Admin report"
            description="Spend, Pixelforge cost and profit per UTC day (the day a generation completed, as Pixelforge bills it), reconciled with Pixelforge's usage API."
            class="mb-0"
        />

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="load()">
            <div class="grid gap-1">
                <Label for="start">From (UTC)</Label>
                <Input
                    id="start"
                    v-model="start"
                    type="date"
                    required
                    class="w-40"
                />
            </div>
            <div class="grid gap-1">
                <Label for="end">To (UTC)</Label>
                <Input
                    id="end"
                    v-model="end"
                    type="date"
                    required
                    :min="start"
                    class="w-40"
                />
            </div>
            <Button type="submit">Show</Button>
            <div class="flex gap-1">
                <Button
                    v-for="d in [7, 30, 90]"
                    :key="d"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="lastDays(d)"
                >
                    {{ d }} days
                </Button>
            </div>
            <p class="w-full text-xs text-muted-foreground">
                At most {{ maxDays }} days.
            </p>
        </form>

        <Alert v-if="report.usage_error" variant="destructive">
            <AlertDescription>{{ report.usage_error }}</AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader>
                    <CardDescription>Credit spend</CardDescription>
                    <CardTitle class="tabular-nums">{{
                        formatUsd(report.total.charged_nanos)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground"
                    >{{ report.total.generations }} generations</CardContent
                >
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Provider cost</CardDescription>
                    <CardTitle class="tabular-nums">{{
                        formatUsd(report.total.provider_cost_nanos)
                    }}</CardTitle>
                </CardHeader>
                <CardContent class="text-xs">
                    <span
                        v-if="report.total.matches === true"
                        class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                    >
                        <CircleCheck class="size-3.5" /> Matches Pixelforge
                    </span>
                    <span
                        v-else-if="report.total.matches === false"
                        class="inline-flex items-center gap-1 text-red-600 dark:text-red-400"
                    >
                        <CircleAlert class="size-3.5" /> Pixelforge:
                        {{ formatUsd(report.total.pixelforge_cost_nanos!) }}
                    </span>
                    <span v-else class="text-muted-foreground"
                        >Not reconciled</span
                    >
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Profit</CardDescription>
                    <CardTitle
                        class="tabular-nums"
                        :class="profitClass(report.total.profit_nanos)"
                    >
                        {{ formatUsd(report.total.profit_nanos) }}
                    </CardTitle>
                </CardHeader>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Margin</CardDescription>
                    <CardTitle class="tabular-nums">{{
                        formatBps(report.total.margin_bps)
                    }}</CardTitle>
                </CardHeader>
            </Card>
        </div>

        <Card>
            <CardHeader>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <CardTitle>Per day</CardTitle>
                        <CardDescription>
                            <template v-if="report.usage_error"
                                >Pixelforge's numbers are unavailable.</template
                            >
                            <template v-else-if="mismatches === 0"
                                >Every day matches Pixelforge to the
                                nano-dollar.</template
                            >
                            <template v-else
                                >{{ mismatches }} day(s) differ from
                                Pixelforge.</template
                            >
                        </CardDescription>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input
                            v-model="hideEmpty"
                            type="checkbox"
                            class="size-4 rounded border-input"
                        />
                        Hide days without activity
                    </label>
                </div>
            </CardHeader>
            <CardContent>
                <div class="-mx-6 overflow-x-auto px-6">
                    <table class="w-full min-w-[48rem] text-sm tabular-nums">
                        <thead
                            class="border-b text-right text-xs text-muted-foreground"
                        >
                            <tr>
                                <th
                                    scope="col"
                                    class="py-2 text-left font-normal"
                                >
                                    Date (UTC)
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Images
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Credit spend
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Provider cost
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Profit
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Margin
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Pixelforge cost
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Difference
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y text-right">
                            <tr v-if="visibleDays.length === 0">
                                <td
                                    colspan="8"
                                    class="py-6 text-center text-muted-foreground"
                                >
                                    No completed generations in this range.
                                </td>
                            </tr>
                            <tr v-for="day in visibleDays" :key="day.date">
                                <th
                                    scope="row"
                                    class="py-2 text-left font-normal whitespace-nowrap"
                                >
                                    {{ day.date }}
                                </th>
                                <td class="py-2">{{ day.generations }}</td>
                                <td class="py-2">
                                    {{ formatUsd(day.charged_nanos) }}
                                </td>
                                <td class="py-2">
                                    {{ formatUsd(day.provider_cost_nanos) }}
                                </td>
                                <td
                                    class="py-2"
                                    :class="profitClass(day.profit_nanos)"
                                >
                                    {{ formatUsd(day.profit_nanos) }}
                                </td>
                                <td class="py-2">
                                    {{ formatBps(day.margin_bps) }}
                                </td>
                                <td class="py-2">
                                    <template v-if="day.pixelforge">{{
                                        day.pixelforge.cost_usd
                                    }}</template>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="py-2 whitespace-nowrap">
                                    <span
                                        v-if="!day.pixelforge"
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                    <span
                                        v-else-if="day.pixelforge.matches"
                                        class="inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                                    >
                                        <CircleCheck
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Match
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 text-red-600 dark:text-red-400"
                                    >
                                        <CircleAlert
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{
                                            formatUsd(
                                                day.pixelforge.difference_nanos,
                                            )
                                        }}
                                        <span
                                            v-if="
                                                day.pixelforge.generations !==
                                                day.generations
                                            "
                                            class="text-xs"
                                        >
                                            ({{ day.pixelforge.generations }} vs
                                            {{ day.generations }})
                                        </span>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t font-medium">
                            <tr class="text-right">
                                <th scope="row" class="py-2 text-left">
                                    Total
                                </th>
                                <td class="py-2">
                                    {{ report.total.generations }}
                                </td>
                                <td class="py-2">
                                    {{ formatUsd(report.total.charged_nanos) }}
                                </td>
                                <td class="py-2">
                                    {{
                                        formatUsd(
                                            report.total.provider_cost_nanos,
                                        )
                                    }}
                                </td>
                                <td
                                    class="py-2"
                                    :class="
                                        profitClass(report.total.profit_nanos)
                                    "
                                >
                                    {{ formatUsd(report.total.profit_nanos) }}
                                </td>
                                <td class="py-2">
                                    {{ formatBps(report.total.margin_bps) }}
                                </td>
                                <td class="py-2">
                                    <template
                                        v-if="
                                            report.total
                                                .pixelforge_cost_nanos !== null
                                        "
                                        >{{
                                            formatUsd(
                                                report.total
                                                    .pixelforge_cost_nanos,
                                            )
                                        }}</template
                                    >
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </td>
                                <td class="py-2" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Per user</CardTitle>
                <CardDescription
                    >{{ report.start }} to {{ report.end }}</CardDescription
                >
            </CardHeader>
            <CardContent>
                <div class="-mx-6 overflow-x-auto px-6">
                    <table class="w-full min-w-[40rem] text-sm tabular-nums">
                        <thead
                            class="border-b text-right text-xs text-muted-foreground"
                        >
                            <tr>
                                <th
                                    scope="col"
                                    class="py-2 text-left font-normal"
                                >
                                    User
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Images
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Credit spend
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Provider cost
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Profit
                                </th>
                                <th scope="col" class="py-2 font-normal">
                                    Margin
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y text-right">
                            <tr v-if="report.users.length === 0">
                                <td
                                    colspan="6"
                                    class="py-6 text-center text-muted-foreground"
                                >
                                    No completed generations in this range.
                                </td>
                            </tr>
                            <tr
                                v-for="row in report.users"
                                :key="row.user_id ?? 'deleted'"
                            >
                                <th
                                    scope="row"
                                    class="py-2 text-left font-normal"
                                >
                                    {{ row.name }}
                                    <span
                                        v-if="row.email"
                                        class="block text-xs text-muted-foreground"
                                        >{{ row.email }}</span
                                    >
                                </th>
                                <td class="py-2">{{ row.generations }}</td>
                                <td class="py-2">
                                    {{ formatUsd(row.charged_nanos) }}
                                </td>
                                <td class="py-2">
                                    {{ formatUsd(row.provider_cost_nanos) }}
                                </td>
                                <td
                                    class="py-2"
                                    :class="profitClass(row.profit_nanos)"
                                >
                                    {{ formatUsd(row.profit_nanos) }}
                                </td>
                                <td class="py-2">
                                    {{ formatBps(row.margin_bps) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

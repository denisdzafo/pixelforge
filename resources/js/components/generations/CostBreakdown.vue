<script setup lang="ts">
import { formatUsd } from '@/lib/money';
import type { Generation } from '@/types';

defineProps<{ breakdown: NonNullable<Generation['breakdown']> }>();
</script>

<template>
    <div class="space-y-2 text-xs">
        <table class="w-full tabular-nums">
            <caption class="sr-only">
                Cost breakdown
            </caption>
            <thead class="text-muted-foreground">
                <tr>
                    <th scope="col" class="py-1 text-left font-normal">
                        Tokens
                    </th>
                    <th scope="col" class="py-1 text-right font-normal">
                        × $/1M
                    </th>
                    <th scope="col" class="py-1 text-right font-normal">
                        Cost
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="line in breakdown.lines" :key="line.type">
                    <td class="py-0.5">
                        {{ line.label }}
                        <span class="text-muted-foreground">{{
                            line.tokens.toLocaleString()
                        }}</span>
                    </td>
                    <td class="py-0.5 text-right text-muted-foreground">
                        {{ line.rate_usd_per_million }}
                    </td>
                    <td class="py-0.5 text-right">
                        {{ formatUsd(line.cost_nanos) }}
                    </td>
                </tr>
            </tbody>
            <tfoot class="border-t">
                <tr>
                    <th
                        scope="row"
                        colspan="2"
                        class="pt-1 text-left font-normal text-muted-foreground"
                    >
                        Pixelforge cost
                    </th>
                    <td class="pt-1 text-right">
                        {{ formatUsd(breakdown.provider_cost_nanos) }}
                    </td>
                </tr>
                <tr>
                    <th
                        scope="row"
                        colspan="2"
                        class="text-left font-normal text-muted-foreground"
                    >
                        Service fee ({{ breakdown.markup_percent }}%)
                    </th>
                    <td class="text-right">
                        {{ formatUsd(breakdown.markup_nanos) }}
                    </td>
                </tr>
                <tr class="font-medium">
                    <th scope="row" colspan="2" class="text-left">You paid</th>
                    <td class="text-right">
                        {{ formatUsd(breakdown.charged_nanos) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>

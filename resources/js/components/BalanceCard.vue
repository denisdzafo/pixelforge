<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { formatUsd } from '@/lib/money';
import { index as credits } from '@/routes/credits';

const page = usePage();
const balance = computed(() => page.props.auth.user?.balance_nanos ?? '0');
</script>

<template>
    <Link
        :href="credits()"
        class="mx-2 flex items-center gap-3 rounded-lg border border-sidebar-border/70 px-3 py-2 text-sm transition-colors group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        aria-label="Your balance, buy credits"
    >
        <Wallet class="size-4 shrink-0 text-muted-foreground" />
        <span class="flex min-w-0 flex-col">
            <span class="text-xs text-muted-foreground">Balance</span>
            <span class="truncate font-medium tabular-nums">{{
                formatUsd(balance)
            }}</span>
        </span>
        <span class="ml-auto text-xs font-medium text-primary">Buy</span>
    </Link>
</template>

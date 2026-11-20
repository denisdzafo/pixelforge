import { usePage } from '@inertiajs/vue3';
import { ChartColumn, Images, Sparkles, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import { report } from '@/routes/admin';
import { index as credits } from '@/routes/credits';
import { create, index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

export function useMainNav() {
    const page = usePage();

    return computed<NavItem[]>(() => [
        { title: 'Generate', href: create(), icon: Sparkles },
        { title: 'History', href: history(), icon: Images },
        { title: 'Credits', href: credits(), icon: Wallet },
        ...(page.props.auth.user?.is_admin
            ? [{ title: 'Admin report', href: report(), icon: ChartColumn }]
            : []),
    ]);
}

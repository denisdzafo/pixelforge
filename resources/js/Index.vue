<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header v-if="store.isAuthenticated" class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-4 px-4 py-3">
                <RouterLink :to="{ name: 'Dashboard' }" class="font-semibold">{{ t('app.name') }}</RouterLink>
                <RouterLink :to="{ name: 'Items' }" class="text-sm text-gray-600 hover:text-gray-900">{{ t('nav.items') }}</RouterLink>
                <span class="ml-auto text-sm text-gray-600">{{ store.user?.name }}</span>
                <button
                    type="button"
                    class="rounded border border-gray-300 bg-white px-3 py-1 text-sm hover:bg-gray-100 disabled:opacity-50"
                    :disabled="loggingOut"
                    @click="logout"
                >
                    {{ t('nav.logout') }}
                </button>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-6">
            <RouterView />
        </main>

        <div class="fixed right-4 bottom-4 flex flex-col gap-2" aria-live="polite">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="rounded px-4 py-2 text-sm text-white shadow"
                :class="toast.isError ? 'bg-red-700' : 'bg-gray-900'"
            >
                {{ toast.message }}
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { showErrorToast } from '@/services/apiErrors';
import { useApplicationStore } from '@/services/store';
import { toasts } from '@/services/toast';

const { t } = useI18n();
const store = useApplicationStore();
const router = useRouter();
const loggingOut = ref(false);

const logout = async () => {
    loggingOut.value = true;
    try {
        await store.logout();
        router.push({ name: 'Login' });
    } catch (error) {
        console.error('Error signing out:', error);
        showErrorToast(error, t('auth.logoutFailed'));
    } finally {
        loggingOut.value = false;
    }
};
</script>

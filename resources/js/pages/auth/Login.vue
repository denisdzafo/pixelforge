<template>
    <section class="mx-auto mt-12 max-w-sm rounded border border-gray-200 bg-white p-6">
        <h1 class="mb-4 text-xl font-semibold">{{ t('auth.loginTitle') }}</h1>

        <p v-if="errorMessage" role="alert" class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ errorMessage }}</p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.email') }}
                <input v-model="email" name="email" type="email" autocomplete="email" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.password') }}
                <input v-model="password" name="password" type="password" autocomplete="current-password" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700 disabled:opacity-50" :disabled="loading">
                {{ loading ? t('common.loading') : t('auth.signIn') }}
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            {{ t('auth.noAccount') }}
            <RouterLink :to="{ name: 'Register' }" class="underline">{{ t('auth.signUp') }}</RouterLink>
        </p>
    </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { getErrorMessage } from '@/services/apiErrors';
import { useApplicationStore } from '@/services/store';

const { t } = useI18n();
const store = useApplicationStore();
const route = useRoute();
const router = useRouter();

const email = ref('');
const password = ref('');
const loading = ref(false);
const errorMessage = ref('');

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        await store.login({ email: email.value, password: password.value });
        // Only paths inside the app: a crafted ?redirect= must not lead to another site
        const redirect = route.query.redirect;
        router.push(typeof redirect === 'string' && /^\/(?!\/)/.test(redirect) ? redirect : { name: 'Dashboard' });
    } catch (error) {
        console.error('Error signing in:', error);
        errorMessage.value = getErrorMessage(error, t('auth.loginFailed'));
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <section class="mx-auto mt-12 max-w-sm rounded border border-gray-200 bg-white p-6">
        <h1 class="mb-4 text-xl font-semibold">{{ t('auth.registerTitle') }}</h1>

        <p v-if="errorMessage" role="alert" class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ errorMessage }}</p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.name') }}
                <input v-model="name" name="name" type="text" autocomplete="name" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.email') }}
                <input v-model="email" name="email" type="email" autocomplete="email" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.password') }}
                <input v-model="password" name="password" type="password" autocomplete="new-password" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.passwordConfirmation') }}
                <input v-model="passwordConfirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700 disabled:opacity-50" :disabled="loading">
                {{ loading ? t('common.loading') : t('auth.signUp') }}
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            {{ t('auth.haveAccount') }}
            <RouterLink :to="{ name: 'Login' }" class="underline">{{ t('auth.signIn') }}</RouterLink>
        </p>
    </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { getErrorMessage } from '@/services/apiErrors';
import { useApplicationStore } from '@/services/store';

const { t } = useI18n();
const store = useApplicationStore();
const router = useRouter();

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const loading = ref(false);
const errorMessage = ref('');

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        await store.register({
            name: name.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        router.push({ name: 'Dashboard' });
    } catch (error) {
        console.error('Error creating the account:', error);
        errorMessage.value = getErrorMessage(error, t('auth.registerFailed'));
    } finally {
        loading.value = false;
    }
};
</script>

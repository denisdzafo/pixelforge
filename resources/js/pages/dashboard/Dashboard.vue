<template>
    <h1 class="mb-4 text-2xl font-semibold">{{ t('dashboard.title') }}</h1>
    <p>{{ t('dashboard.welcome', { name: store.user?.name }) }}</p>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useApplicationStore } from '@/services/store';

const { t } = useI18n();
const store = useApplicationStore();
</script>

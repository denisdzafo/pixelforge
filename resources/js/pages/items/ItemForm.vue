<template>
    <h1 class="mb-4 text-2xl font-semibold">{{ isEdit ? t('items.editTitle') : t('items.createTitle') }}</h1>

    <div v-if="pageLoading" class="flex justify-center p-12" role="status">{{ t('common.loading') }}</div>

    <div v-else-if="loadError" role="alert" class="rounded bg-red-50 p-4 text-sm text-red-800">
        <p class="mb-2">{{ loadError }}</p>
        <button type="button" class="rounded border border-red-300 bg-white px-3 py-1" @click="loadItem">{{ t('common.tryAgain') }}</button>
    </div>

    <form v-else class="flex max-w-lg flex-col gap-4 rounded border border-gray-200 bg-white p-6" @submit.prevent="save">
        <p v-if="errorMessage" role="alert" class="rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ errorMessage }}</p>

        <label class="flex flex-col gap-1 text-sm">
            {{ t('items.name') }}
            <input v-model="name" name="name" type="text" required class="rounded border border-gray-300 px-3 py-2" />
            <span v-if="errors.name" class="text-red-700">{{ errors.name }}</span>
        </label>
        <label class="flex flex-col gap-1 text-sm">
            {{ t('items.description') }}
            <textarea v-model="description" name="description" rows="4" class="rounded border border-gray-300 px-3 py-2"></textarea>
            <span v-if="errors.description" class="text-red-700">{{ errors.description }}</span>
        </label>
        <label class="flex flex-col gap-1 text-sm">
            {{ t('items.status') }}
            <select v-model="status" name="status" class="rounded border border-gray-300 bg-white px-3 py-2">
                <option value="active">{{ t('items.statusActive') }}</option>
                <option value="archived">{{ t('items.statusArchived') }}</option>
            </select>
        </label>

        <div class="flex justify-end gap-2">
            <RouterLink :to="{ name: 'Items' }" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm">{{ t('common.cancel') }}</RouterLink>
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700 disabled:opacity-50" :disabled="saving">
                {{ saving ? t('common.loading') : t('common.save') }}
            </button>
        </div>
    </form>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import http from '@/services/apiEndPoints';
import { fieldError, getErrorMessage } from '@/services/apiErrors';
import { showSuccessMessage } from '@/services/toast';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'ItemEdit');

const name = ref('');
const description = ref('');
const status = ref('active');
const pageLoading = ref(true);
const saving = ref(false);
const loadError = ref('');
const errorMessage = ref('');
const errors = reactive<{ name: string | null; description: string | null }>({ name: null, description: null });

const loadItem = async () => {
    pageLoading.value = true;
    loadError.value = '';
    try {
        const response = await http.getItem(route.params.id as string);
        const item = response.data.data;
        name.value = item.name;
        description.value = item.description ?? '';
        status.value = item.status;
    } catch (error) {
        console.error('Error loading item:', error);
        loadError.value = getErrorMessage(error, t('items.loadOneFailed'));
    } finally {
        pageLoading.value = false;
    }
};

const save = async () => {
    saving.value = true;
    errorMessage.value = '';
    errors.name = null;
    errors.description = null;
    try {
        const data = { name: name.value, description: description.value || null, status: status.value };
        if (isEdit.value) {
            await http.updateItem({ id: route.params.id as string, ...data });
        } else {
            await http.createItem(data);
        }
        showSuccessMessage(t('items.saved'));
        router.push({ name: 'Items' });
    } catch (error) {
        console.error('Error saving item:', error);
        errors.name = fieldError(error, 'name');
        errors.description = fieldError(error, 'description');
        if (!errors.name && !errors.description) {
            errorMessage.value = getErrorMessage(error, t('items.saveFailed'));
        }
    } finally {
        saving.value = false;
    }
};

onMounted(() => {
    if (isEdit.value) {
        loadItem();
    } else {
        pageLoading.value = false;
    }
});
</script>

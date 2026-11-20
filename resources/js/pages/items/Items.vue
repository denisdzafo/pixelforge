<template>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ t('items.title') }}</h1>
        <RouterLink :to="{ name: 'ItemCreate' }" class="rounded bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">{{ t('items.create') }}</RouterLink>
    </div>

    <div v-if="pageLoading" class="flex justify-center p-12" role="status">{{ t('common.loading') }}</div>

    <!-- A failed load never falls through to the empty state, which would mean "you have no items" -->
    <div v-else-if="errorMessage" role="alert" class="rounded bg-red-50 p-4 text-sm text-red-800">
        <p class="mb-2">{{ errorMessage }}</p>
        <button type="button" class="rounded border border-red-300 bg-white px-3 py-1" @click="loadItems">{{ t('common.tryAgain') }}</button>
    </div>

    <template v-else>
        <div class="mb-4 flex flex-wrap gap-2">
            <input
                name="q"
                :value="filters.q"
                :placeholder="t('items.search')"
                :aria-label="t('items.search')"
                class="rounded border border-gray-300 bg-white px-3 py-2 text-sm"
                @input="onSearch(($event.target as HTMLInputElement).value)"
            />
            <select v-model="filters.status" name="status" :aria-label="t('items.status')" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm" @change="applyFilters">
                <option value="">{{ t('items.allStatuses') }}</option>
                <option value="active">{{ t('items.statusActive') }}</option>
                <option value="archived">{{ t('items.statusArchived') }}</option>
            </select>
            <select v-model="filters.sort" name="sort" :aria-label="t('items.sort')" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm" @change="applyFilters">
                <option value="newest">{{ t('items.sortNewest') }}</option>
                <option value="oldest">{{ t('items.sortOldest') }}</option>
                <option value="name">{{ t('items.sortName') }}</option>
            </select>
        </div>

        <p v-if="items.length === 0" class="rounded border border-gray-200 bg-white p-8 text-center text-gray-600">
            {{ hasActiveFilters ? t('items.emptyFiltered') : t('items.empty') }}
        </p>

        <template v-else>
            <p class="mb-2 text-sm text-gray-600">{{ t('items.count', { count: pagination.total }, pagination.total) }}</p>

            <div class="overflow-x-auto rounded border border-gray-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-gray-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('items.name') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('items.status') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('items.created') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('items.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id" class="border-b border-gray-100 last:border-0">
                            <td class="px-4 py-2">{{ item.name }}</td>
                            <td class="px-4 py-2">{{ item.status === 'archived' ? t('items.statusArchived') : t('items.statusActive') }}</td>
                            <td class="px-4 py-2">{{ new Date(item.created_at).toLocaleDateString() }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <template v-if="confirmingId === item.id">
                                    <span class="mr-2">{{ t('items.confirmDelete') }}</span>
                                    <button type="button" class="mr-2 text-red-700 underline disabled:opacity-50" :disabled="deleting" @click="deleteItem(item.id)">{{ t('common.delete') }}</button>
                                    <button type="button" class="underline" @click="confirmingId = null">{{ t('common.cancel') }}</button>
                                </template>
                                <template v-else>
                                    <RouterLink :to="{ name: 'ItemEdit', params: { id: item.id } }" class="mr-2 underline">{{ t('common.edit') }}</RouterLink>
                                    <button type="button" class="text-red-700 underline" @click="confirmingId = item.id">{{ t('common.delete') }}</button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination: the same row under every paged list -->
            <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <select name="per_page" :value="pagination.per_page" :aria-label="t('common.perPageLabel')" :disabled="loading" class="rounded border border-gray-300 bg-white px-2 py-1 text-sm" @change="changePerPage(($event.target as HTMLSelectElement).value)">
                        <option v-for="size in PER_PAGE_OPTIONS" :key="size" :value="size">{{ size }}</option>
                    </select>
                    <span class="text-sm text-gray-600">{{ t('common.perPage') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50" :disabled="loading || pagination.page <= 1" @click="goToPage(pagination.page - 1)">{{ t('common.previous') }}</button>
                    <span class="text-sm text-gray-600">{{ t('common.pageOf', { page: pagination.page, last: pagination.last_page }) }}</span>
                    <button type="button" class="rounded border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50" :disabled="loading || pagination.page >= pagination.last_page" @click="goToPage(pagination.page + 1)">{{ t('common.next') }}</button>
                </div>
            </div>
        </template>
    </template>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import http from '@/services/apiEndPoints';
import { getErrorMessage, showErrorToast } from '@/services/apiErrors';
import { showSuccessMessage } from '@/services/toast';

const PER_PAGE_OPTIONS = [5, 10, 15, 25, 50];
const DEFAULT_PER_PAGE = 15;
const DEFAULT_SORT = 'newest';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const items = ref<any[]>([]);
const pagination = ref({ page: 1, per_page: DEFAULT_PER_PAGE, total: 0, last_page: 1 });
const pageLoading = ref(true);
const loading = ref(false);
const deleting = ref(false);
const errorMessage = ref('');
const confirmingId = ref<number | null>(null);

// The URL is the source of the filters, so a refresh or a shared link shows the same list
const filters = reactive({ q: '', status: '', sort: DEFAULT_SORT });
const hasActiveFilters = computed(() => !!filters.q || !!filters.status);

function readQuery() {
    filters.q = String(route.query.q ?? '');
    filters.status = String(route.query.status ?? '');
    filters.sort = String(route.query.sort ?? DEFAULT_SORT);
}

// Defaults are left out of the URL
function writeQuery(page: number, perPage: number) {
    const query: Record<string, string | number> = {};
    if (filters.q) query.q = filters.q;
    if (filters.status) query.status = filters.status;
    if (filters.sort !== DEFAULT_SORT) query.sort = filters.sort;
    if (page > 1) query.page = page;
    if (perPage !== DEFAULT_PER_PAGE) query.per_page = perPage;
    router.replace({ query });
}

const loadItems = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await http.getItems({
            q: filters.q || undefined,
            status: filters.status || undefined,
            sort: filters.sort,
            page: Number(route.query.page ?? 1),
            per_page: Number(route.query.per_page ?? DEFAULT_PER_PAGE),
        });
        items.value = response.data.data.items;
        pagination.value = response.data.data.pagination;
    } catch (error) {
        console.error('Error loading items:', error);
        errorMessage.value = getErrorMessage(error, t('items.loadFailed'));
    } finally {
        loading.value = false;
    }
};

function applyFilters() {
    writeQuery(1, pagination.value.per_page);
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearch(value: string) {
    filters.q = value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
}

function goToPage(page: number) {
    writeQuery(page, pagination.value.per_page);
}

// A new page size goes back to page 1
function changePerPage(value: string) {
    writeQuery(1, Number(value));
}

const deleteItem = async (id: number) => {
    deleting.value = true;
    try {
        await http.deleteItem(id);
        confirmingId.value = null;
        showSuccessMessage(t('items.deleted'));
        // The last item of the last page: the page before it, which reloads through the query watcher
        if (items.value.length === 1 && pagination.value.page > 1) {
            goToPage(pagination.value.page - 1);
        } else {
            await loadItems();
        }
    } catch (error) {
        console.error('Error deleting item:', error);
        showErrorToast(error, t('items.deleteFailed'));
    } finally {
        deleting.value = false;
    }
};

// Later reloads (filters, paging) never bring the page spinner back
watch(() => route.query, () => {
    if (route.name === 'Items') {
        readQuery();
        loadItems();
    }
});

onMounted(async () => {
    readQuery();
    await loadItems();
    pageLoading.value = false;
});
</script>

import { ref } from 'vue';

// Short feedback messages, shown by Index.vue
export const toasts = ref<{ id: number; message: string; isError: boolean }[]>([]);

let lastId = 0;

function show(message: string, isError: boolean, duration: number) {
    const id = ++lastId;
    toasts.value.push({ id, message, isError });
    setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, duration);
}

export function showSuccessMessage(message: string) {
    show(message, false, 4000);
}

export function showErrorMessage(message: string) {
    show(message, true, 6000);
}

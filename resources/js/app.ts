import '../css/app.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import Index from './Index.vue';
import i18n, { setupLocale } from './i18n';
import router from './services/router';
import { setSessionExpiredHandler } from './services/httpCommon';
import { useApplicationStore } from './services/store';

const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

const app = createApp(Index);

app.use(pinia);
app.use(router);
app.use(i18n);

// The refresh token stopped working while a page was open: back to the login page, then back to this page
setSessionExpiredHandler(() => {
    useApplicationStore().clearSession();
    const current = router.currentRoute.value;
    if (!current.meta.guest) {
        router.push({ name: 'Login', query: { redirect: current.fullPath } });
    }
});

// The user's language is loaded before the first render, so no page shows English first
setupLocale(navigator.language).finally(() => {
    app.mount('#app');
});

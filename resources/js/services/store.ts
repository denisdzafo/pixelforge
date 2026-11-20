import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import http from '@/services/apiEndPoints';
import { refreshSession, setAccessToken } from '@/services/httpCommon';

export const useApplicationStore = defineStore('applicationSetup', () => {
    const user = ref<any>(null);
    const isAuthenticated = computed(() => !!user.value);

    // Whether this browser was signed in (persisted): the cookie can't be read from JS, and a visitor who never signed in
    // shouldn't get a failing refresh request on every page load
    const hasSession = ref(false);

    function startSession(session: any) {
        setAccessToken(session.access_token);
        user.value = session.user;
        hasSession.value = true;
    }

    function clearSession() {
        setAccessToken(null);
        user.value = null;
        hasSession.value = false;
    }

    async function register(data: { name: string; email: string; password: string; password_confirmation: string }) {
        const response = await http.register(data);
        startSession(response.data.data);
    }

    async function login(data: { email: string; password: string }) {
        const response = await http.login(data);
        startSession(response.data.data);
    }

    async function logout() {
        try {
            await http.logout();
        } catch (error: any) {
            // Already signed out on the server: the browser still has to forget it. Other errors are shown to the user.
            if (error.response?.status !== 401) {
                throw error;
            }
        }
        clearSession();
    }

    // After a page reload the access token is gone: get a new one with the refresh token cookie. Once per page load, unless
    // it failed for a reason other than the session (offline, server error): the next navigation tries again.
    let restored = false;

    async function restoreSession() {
        if (restored || user.value || !hasSession.value) {
            return;
        }

        try {
            startSession(await refreshSession());
            restored = true;
        } catch (error: any) {
            if (error.response?.status === 401) {
                clearSession();
                restored = true;
            } else {
                console.error('Error restoring the session:', error);
            }
        }
    }

    return { user, isAuthenticated, hasSession, startSession, clearSession, register, login, logout, restoreSession };
}, {
    persist: {
        storage: localStorage,
        pick: ['hasSession'],
    },
});

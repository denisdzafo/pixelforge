import axios from 'axios';

const http = axios.create({
    baseURL: `/api`,
    headers: {
        'Content-type': 'application/json',
        Accept: 'application/json',
    },
});

// The access token lives in memory only (never in localStorage, where any script could read it). A page reload gets a new
// one with the httpOnly refresh token cookie (store.restoreSession()).
let accessToken: string | null = null;
let onSessionExpired: () => void = () => {};

export function setAccessToken(token: string | null) {
    accessToken = token;
}

export function setSessionExpiredHandler(handler: () => void) {
    onSessionExpired = handler;
}

// One refresh at a time: requests that fail together all wait for the same one. Refresh tokens rotate, so a second
// parallel refresh would send an already used token.
let refreshRequest: Promise<any> | null = null;

export function refreshSession(): Promise<any> {
    if (!refreshRequest) {
        // The bare axios: a failed refresh must not run into the 401 handling below
        refreshRequest = axios
            .post('/api/auth/refresh', null, { headers: { Accept: 'application/json' } })
            .then((response) => {
                const session = response.data.data;
                setAccessToken(session.access_token);
                return session;
            })
            .finally(() => {
                refreshRequest = null;
            });
    }

    return refreshRequest;
}

http.interceptors.request.use((config) => {
    if (accessToken) {
        config.headers.Authorization = `Bearer ${accessToken}`;
    }

    return config;
});

// These answer 401 for their own reasons (wrong password): never a reason to refresh
const NO_REFRESH_URLS = ['/auth/login', '/auth/register'];

http.interceptors.response.use(
    (response) => response,
    async (error) => {
        const config: any = error.config;

        if (error.response?.status !== 401 || !config || config.retriedAfterRefresh || NO_REFRESH_URLS.includes(config.url)) {
            return Promise.reject(error);
        }

        // Access token expired: refresh once and send the request again
        try {
            await refreshSession();
        } catch (refreshError: any) {
            // Only a rejected refresh token ends the session; being offline or a server error doesn't
            if (refreshError.response?.status === 401) {
                setAccessToken(null);
                onSessionExpired();
            }
            return Promise.reject(error);
        }

        config.retriedAfterRefresh = true;
        return http(config);
    },
);

export default http;

/// <reference types="vite/client" />

import 'vue-router';

declare module 'vue-router' {
    interface RouteMeta {
        // Open without signing in; a signed-in user is sent home (router.ts)
        guest?: boolean;
    }
}

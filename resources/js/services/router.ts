import { createRouter, createWebHistory } from 'vue-router';
import Dashboard from '@/pages/dashboard/Dashboard.vue';
import Login from '@/pages/auth/Login.vue';
import Register from '@/pages/auth/Register.vue';
import ItemForm from '@/pages/items/ItemForm.vue';
import Items from '@/pages/items/Items.vue';
import { useApplicationStore } from '@/services/store';

const routes = [
    { path: '/', component: Dashboard, name: 'Dashboard' },
    { path: '/login', component: Login, name: 'Login', meta: { guest: true } },
    { path: '/register', component: Register, name: 'Register', meta: { guest: true } },
    // Filters live in the query string (?q=&status=&sort=&page=&per_page=)
    { path: '/items', component: Items, name: 'Items' },
    { path: '/items/new', component: ItemForm, name: 'ItemCreate' },
    { path: '/items/:id/edit', component: ItemForm, name: 'ItemEdit' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Every page needs a signed-in user unless it has meta.guest (and guest pages send a signed-in user home). Unknown routes
// (the SPA catch-all) also go home.
router.beforeEach(async (to) => {
    if (to.matched.length === 0) {
        return { name: 'Dashboard' };
    }

    const store = useApplicationStore();
    await store.restoreSession();

    if (to.meta.guest) {
        return store.isAuthenticated ? { name: 'Dashboard' } : true;
    }

    return store.isAuthenticated ? true : { name: 'Login', query: { redirect: to.fullPath } };
});

export default router;

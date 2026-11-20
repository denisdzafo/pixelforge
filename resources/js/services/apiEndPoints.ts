import http from './httpCommon';

class endPointApis {
    register(data: { name: string; email: string; password: string; password_confirmation: string }) {
        return http.post('/auth/register', data);
    }

    login(data: { email: string; password: string }) {
        return http.post('/auth/login', data);
    }

    logout() {
        return http.post('/auth/logout');
    }

    getCurrentUser() {
        return http.get('/auth/me');
    }

    // {items, pagination {page, per_page, total, last_page}}
    getItems(params?: { q?: string; status?: string; sort?: string; page?: number; per_page?: number }) {
        return http.get('/items/list', { params });
    }

    getItem(id: number | string) {
        return http.get('/items/get', { params: { id } });
    }

    createItem(data: { name: string; description?: string | null; status?: string }) {
        return http.post('/items/create', data);
    }

    updateItem(data: { id: number | string; name: string; description?: string | null; status?: string }) {
        return http.post('/items/update', data);
    }

    deleteItem(id: number | string) {
        return http.post('/items/delete', { id });
    }
}

export default new endPointApis();

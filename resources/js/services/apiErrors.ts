import { t } from '@/i18n';
import { showErrorMessage } from '@/services/toast';

// Turns a failed API call into a message a user can understand. Every component shows errors through this
// (inline banners use getErrorMessage, calls without an error area use showErrorToast), never raw response data.
//
//  - no response (offline, timeout, server unreachable) -> connection message
//  - 401 / 419                                          -> the backend's own text (e.g. wrong password), else session message
//  - 429                                                -> the backend's own text (it says when to retry), else a generic one
//  - 5xx                                                -> generic "our side" message (never the server's text)
//  - 422 validation errors                              -> the first field message
//  - other 4xx                                          -> the backend's `error` text (written for users), else the fallback

export function getErrorMessage(error: any, fallback?: string): string {
    fallback = fallback ?? t('errors.generic');
    const response = error?.response;

    if (!response) {
        return error?.code === 'ERR_CANCELED' ? fallback : t('errors.offline');
    }

    const status: number = response.status;
    const data = response.data ?? {};

    if (status === 401 || status === 419) {
        return textOrNull(data.error) ?? t('errors.session');
    }

    if (status >= 500) {
        return t('errors.server');
    }

    if (status === 429) {
        return textOrNull(data.error) ?? t('errors.tooMany');
    }

    return textOrNull(data.error) ?? firstValidationError(data) ?? fallback;
}

/**
 * The validation message of one field (Laravel 422 `errors`), e.g. fieldError(error, 'name')
 */
export function fieldError(error: any, field: string): string | null {
    return textOrNull(error?.response?.data?.errors?.[field]?.[0]);
}

/**
 * For calls without their own error area
 */
export function showErrorToast(error: any, fallback?: string) {
    showErrorMessage(getErrorMessage(error, fallback));
}

function firstValidationError(data: any): string | null {
    const errors = data?.errors;

    if (!errors || typeof errors !== 'object') {
        return null;
    }

    for (const messages of Object.values(errors)) {
        const text = Array.isArray(messages) ? textOrNull(messages[0]) : textOrNull(messages);
        if (text) {
            return text;
        }
    }

    return null;
}

function textOrNull(value: any): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

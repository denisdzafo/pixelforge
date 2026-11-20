import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';

// App texts. en.json holds every text the app shows; other files in locales/ are its translations, with the same keys.
// A file that is empty or misses a key falls back to English. Texts that come from the backend (error messages) are not
// in here. To add a language: add its file, its code in SUPPORTED_LOCALES and its plural forms in PLURAL_CATEGORIES.
export const SUPPORTED_LOCALES = ['en'];

// Plural forms per language, in the order they are written in a message ("form 1 | form 2 | ..."), as CLDR plural
// categories that whole numbers can hit (Intl.PluralRules). E.g. Polish ['one', 'few', 'many'], Japanese ['other'].
export const PLURAL_CATEGORIES: Record<string, string[]> = {
    en: ['one', 'other'],
};

// The form to use for a count: the position of its plural category in PLURAL_CATEGORIES (a category a language only uses
// for fractions or very large numbers falls back to the last form)
function pluralRule(locale: string) {
    const categories = PLURAL_CATEGORIES[locale];
    const rules = new Intl.PluralRules(locale);

    return (choice: number, choicesLength: number) => {
        let index = categories.indexOf(rules.select(Math.abs(choice)));
        if (index === -1) {
            index = categories.length - 1;
        }
        return Math.min(index, choicesLength - 1);
    };
}

// Loaded on demand (raw text, so an empty file is allowed)
const localeFiles = import.meta.glob(['./locales/*.json', '!./locales/en.json'], { query: '?raw', import: 'default' });

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en } as Record<string, any>,
    // Missing texts fall back to English on purpose: no console warning per text
    missingWarn: false,
    fallbackWarn: false,
    pluralRules: Object.fromEntries(Object.keys(PLURAL_CATEGORIES).map((locale) => [locale, pluralRule(locale)])),
});

export default i18n;

// Shorthand for .ts modules (components use useI18n())
export const t = (...args: any[]): string => (i18n.global.t as any)(...args);

/**
 * The supported locale for a browser language ("de", "pt-BR", "en-US"...), English when there is none
 */
export function resolveLocale(locale?: string | null): string {
    if (!locale) {
        return 'en';
    }

    const wanted = locale.replace('_', '-').toLowerCase();
    const exact = SUPPORTED_LOCALES.find((code) => code.toLowerCase() === wanted);
    if (exact) {
        return exact;
    }

    const language = wanted.split('-')[0];
    return SUPPORTED_LOCALES.find((code) => code.toLowerCase() === language) ?? 'en';
}

/**
 * Switches to the user's language
 */
export async function setupLocale(wanted?: string | null) {
    let locale = resolveLocale(wanted);

    if (locale !== 'en') {
        try {
            const raw = (await localeFiles[`./locales/${locale}.json`]?.()) as string | undefined;
            if (raw && raw.trim() !== '') {
                i18n.global.setLocaleMessage(locale, JSON.parse(raw));
            } else {
                // Empty file: the page is English, so say so (screen readers, browser translation)
                locale = 'en';
            }
        } catch (error) {
            console.error('Could not load the language ' + locale + ':', error);
            locale = 'en';
        }
    }

    i18n.global.locale.value = locale;
    document.documentElement.lang = locale;
}

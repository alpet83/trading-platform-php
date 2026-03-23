import { defineI18nConfig } from '#i18n'
import en from './locales/en.json'
import ru from './locales/ru.json'

export default defineI18nConfig(() => ({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'ru',
}))

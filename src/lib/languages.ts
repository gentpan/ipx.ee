export type LanguageCode = 'en' | 'de' | 'es' | 'pt-BR' | 'fr' | 'ja' | 'zh-CN' | 'ru'
export type LanguageLocale = 'en-US' | 'de-DE' | 'es-ES' | 'pt-BR' | 'fr-FR' | 'ja-JP' | 'zh-CN' | 'ru-RU'

export const languages: Array<{
  code: LanguageCode
  label: string
  flag: string
  locale: LanguageLocale
}> = [
  { code: 'en', label: 'English', flag: 'us', locale: 'en-US' },
  { code: 'zh-CN', label: '中文', flag: 'cn', locale: 'zh-CN' },
  { code: 'ja', label: '日本語', flag: 'jp', locale: 'ja-JP' },
  { code: 'de', label: 'Deutsch', flag: 'de', locale: 'de-DE' },
  { code: 'fr', label: 'Français', flag: 'fr', locale: 'fr-FR' },
  { code: 'es', label: 'Español', flag: 'es', locale: 'es-ES' },
  { code: 'pt-BR', label: 'Português', flag: 'br', locale: 'pt-BR' },
  { code: 'ru', label: 'Русский', flag: 'ru', locale: 'ru-RU' },
]

export const defaultLanguage: LanguageCode = 'en'

export function flagUrl(countryCode: string) {
  return `https://flagcdn.io/flags/4x3/${countryCode.trim().toLowerCase()}.svg`
}

export const languageLabels = Object.fromEntries(
  languages.map((language) => [language.code, language.label]),
) as Record<LanguageCode, string>

export function languageFromLocale(locale?: string): LanguageCode {
  const match = languages.find(
    (language) => language.locale.toLowerCase() === locale?.toLowerCase(),
  )
  return match?.code ?? defaultLanguage
}

export function languagePath(language: { locale: LanguageLocale }, section: 'home' | 'api') {
  return section === 'api' ? `/${language.locale}/api` : `/${language.locale}`
}

export function languagePathByCode(code: LanguageCode, section: 'home' | 'api') {
  return languagePath(languages.find((language) => language.code === code) ?? languages[0], section)
}

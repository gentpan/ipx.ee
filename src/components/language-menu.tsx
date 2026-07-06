import { languagePath, languages, type LanguageCode } from '../lib/languages'

type LanguageMenuProps = {
  lang: LanguageCode
  onChange: (lang: LanguageCode) => void
  label?: string
  section?: 'home' | 'api'
}

export function LanguageMenu({
  lang,
  onChange,
  label = 'Language',
  section = 'home',
}: LanguageMenuProps) {
  const activeLanguage = languages.find((language) => language.code === lang) ?? languages[0]

  function selectLanguage(nextLang: LanguageCode, target: EventTarget & HTMLAnchorElement) {
    onChange(nextLang)
    target.closest('details')?.removeAttribute('open')
  }

  return (
    <details className="language-menu">
      <summary className="language-trigger glass-chrome" aria-label={label}>
        <img src={`https://flagcdn.com/${activeLanguage.flag}.svg`} alt="" />
        <span>{activeLanguage.label}</span>
      </summary>
      <div className="language-menu-panel" role="menu" aria-label={label}>
        {languages.map((language) => (
          <a
            key={language.code}
            className={language.code === lang ? 'is-active' : ''}
            href={languagePath(language, section)}
            role="menuitemradio"
            aria-checked={language.code === lang}
            onClick={(event) => selectLanguage(language.code, event.currentTarget)}
          >
            <img src={`https://flagcdn.com/${language.flag}.svg`} alt="" />
            <span>{language.label}</span>
          </a>
        ))}
      </div>
    </details>
  )
}

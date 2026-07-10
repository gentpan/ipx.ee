import { createFileRoute } from '@tanstack/react-router'
import { Check, Clipboard } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { BrandLogo } from '../components/brand-mark'
import { LanguageMenu } from '../components/language-menu'
import { GlassPanel } from '../components/glass'
import { ThemeToggle } from '../components/theme-toggle'
import { TopbarIconLink } from '../components/topbar-icon-link'
import {
  apiFieldNames,
  buildPublicApiURL,
  commonApiFieldNames,
  defaultFieldsBitmask,
  encodeFieldsBitmask,
} from '../lib/api-fields'
import { defaultLanguage, languagePathByCode, type LanguageCode } from '../lib/languages'
import { t } from '../lib/ui-messages'

const allFields = [...apiFieldNames]
const commonFields = [...commonApiFieldNames]

export const Route = createFileRoute('/api')({
  component: () => <APIPage initialLang={defaultLanguage} />,
})

type APIPageProps = {
  initialLang?: LanguageCode
}

export function APIPage({ initialLang = defaultLanguage }: APIPageProps) {
  const [target, setTarget] = useState('8.8.8.8')
  const [lang, setLang] = useState<LanguageCode>(initialLang)
  const [raw, setRaw] = useState(false)
  const [fields, setFields] = useState<string[]>(commonFields)
  const [copied, setCopied] = useState(false)
  const ui = t(lang)

  useEffect(() => {
    document.documentElement.lang = lang === 'zh-CN' ? 'zh-CN' : lang === 'pt-BR' ? 'pt-BR' : lang
  }, [lang])

  const fieldsCode = useMemo(() => encodeFieldsBitmask(fields), [fields])

  const endpoint = useMemo(
    () =>
      buildPublicApiURL({
        target,
        fields,
        lang,
        raw,
      }),
    [fields, lang, raw, target],
  )

  async function copyEndpoint() {
    await navigator.clipboard.writeText(endpoint)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 1400)
  }

  function toggleField(name: string) {
    setFields((current) =>
      current.includes(name)
        ? current.filter((field) => field !== name)
        : [...current, name],
    )
  }

  return (
    <main className="page">
      <header className="topbar">
        <a className="brand" href="/">
          <BrandLogo />
        </a>
        <div className="topbar-actions">
          <LanguageMenu
            lang={lang}
            onChange={setLang}
            label={ui.apiLangLabel}
            section="api"
          />
          <TopbarIconLink
            href={languagePathByCode(lang, 'home')}
            label={ui.lookupNav}
            icon="lookup"
          />
          <ThemeToggle
            labelLight={ui.themeToLight}
            labelDark={ui.themeToDark}
          />
        </div>
      </header>

      <GlassPanel className="hero">
        <div className="eyebrow">{ui.apiEyebrow}</div>
        <h1>{ui.apiTitle}</h1>
        <p>{ui.apiDescription}</p>

        <div className="api-builder">
          <label>
            {ui.target}
            <input
              value={target}
              onChange={(event) => setTarget(event.target.value)}
              placeholder={ui.targetPlaceholder}
            />
          </label>
          <label className="check-option">
            <input
              type="checkbox"
              checked={raw}
              onChange={(event) => setRaw(event.target.checked)}
            />
            {ui.includeRaw}
          </label>
        </div>
      </GlassPanel>

      <GlassPanel className="result-card">
        <div className="result-head">
          <div>
            <span className="result-label">{ui.generatedEndpoint}</span>
            <code className="endpoint-title">{endpoint}</code>
            <div className="fields-code">
              {fieldsCode === defaultFieldsBitmask
                ? 'fields=default'
                : `fields=${fieldsCode || 0}`}
            </div>
          </div>
          <button className="copy-button" type="button" onClick={copyEndpoint}>
            {copied ? <Check size={17} /> : <Clipboard size={17} />}
          </button>
        </div>

        <div className="field-actions">
          <button type="button" onClick={() => setFields(commonFields)}>
            {ui.common}
          </button>
          <button type="button" onClick={() => setFields(allFields)}>
            {ui.allFields}
          </button>
          <button type="button" onClick={() => setFields([])}>
            {ui.clear}
          </button>
        </div>

        <div className="field-picker">
          {allFields.map((field) => (
            <label key={field}>
              <input
                type="checkbox"
                checked={fields.includes(field)}
                onChange={() => toggleField(field)}
              />
              <span className="field-picker-name">{field}</span>
            </label>
          ))}
        </div>

        <div className="code-panel">
          <code>curl "{endpoint}"</code>
        </div>
      </GlassPanel>
    </main>
  )
}

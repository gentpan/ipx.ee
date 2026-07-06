import { createFileRoute } from '@tanstack/react-router'
import { Check, Clipboard, Network } from 'lucide-react'
import { useMemo, useState } from 'react'
import { LanguageMenu } from '../components/language-menu'
import { GlassPanel } from '../components/glass'
import { defaultLanguage, languagePathByCode, type LanguageCode } from '../lib/languages'

const apiFields = [
  'status',
  'message',
  'continent',
  'continentCode',
  'country',
  'countryCode',
  'region',
  'regionName',
  'city',
  'district',
  'zip',
  'lat',
  'lon',
  'timezone',
  'offset',
  'currency',
  'isp',
  'org',
  'as',
  'asname',
  'reverse',
  'mobile',
  'proxy',
  'hosting',
  'query',
]

const commonFields = [
  'status',
  'message',
  'query',
  'country',
  'countryCode',
  'regionName',
  'city',
  'isp',
  'org',
  'as',
  'asname',
  'mobile',
  'proxy',
  'hosting',
]

const publicBaseURL = 'https://ipx.ee'

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

  const endpoint = useMemo(() => {
    const path = target.trim()
      ? `/api/lookup/${encodeURIComponent(target.trim())}`
      : '/api/ip'
    const params = new URLSearchParams({
      fields: fields.join(','),
      lang,
    })
    if (raw) params.set('raw', '1')
    return `${publicBaseURL}${path}?${params.toString()}`
  }, [fields, lang, raw, target])

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
          <span className="brand-mark">
            <Network size={18} aria-hidden="true" />
          </span>
          <span>IPX.EE</span>
        </a>
        <div className="topbar-actions">
          <LanguageMenu
            lang={lang}
            onChange={setLang}
            label="API response language"
            section="api"
          />
          <a className="glass-chrome api-link" href={languagePathByCode(lang, 'home')}>
            Lookup
          </a>
        </div>
      </header>

      <GlassPanel className="hero">
        <div className="eyebrow">API Builder</div>
        <h1>Customize API</h1>
        <p>Choose the target, response language, and fields to generate a custom IPX.EE API URL.</p>

        <div className="api-builder">
          <label>
            Target
            <input
              value={target}
              onChange={(event) => setTarget(event.target.value)}
              placeholder="Leave empty to query current visitor IP"
            />
          </label>
          <label className="check-option">
            <input
              type="checkbox"
              checked={raw}
              onChange={(event) => setRaw(event.target.checked)}
            />
            Include ip-api raw JSON
          </label>
        </div>

      </GlassPanel>

      <GlassPanel className="result-card">
        <div className="result-head">
          <div>
            <span className="result-label">Generated endpoint</span>
            <code className="endpoint-title">{endpoint}</code>
          </div>
          <button className="copy-button" type="button" onClick={copyEndpoint}>
            {copied ? <Check size={17} /> : <Clipboard size={17} />}
          </button>
        </div>

        <div className="field-actions">
          <button type="button" onClick={() => setFields(commonFields)}>
            Common
          </button>
          <button type="button" onClick={() => setFields(apiFields)}>
            All fields
          </button>
          <button type="button" onClick={() => setFields([])}>
            Clear
          </button>
        </div>

        <div className="field-picker">
          {apiFields.map((field) => (
            <label key={field}>
              <input
                type="checkbox"
                checked={fields.includes(field)}
                onChange={() => toggleField(field)}
              />
              {field}
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

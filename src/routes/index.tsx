import { createFileRoute } from '@tanstack/react-router'
import { Check, Clipboard, LoaderCircle, Network, Search } from 'lucide-react'
import { Tooltip } from '@base-ui/react/tooltip'
import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { LanguageMenu } from '../components/language-menu'
import { GlassPanel } from '../components/glass'
import { defaultLanguage, languagePathByCode, type LanguageCode } from '../lib/languages'

type GeoPayload = {
  ip?: string
  lookup?: string
  asn?: string
  as_name?: string
  isp?: string
  org?: string
  is_proxy?: boolean
  is_hosting?: boolean
  is_mobile?: boolean
  country?: string
  country_code?: string
  province?: string
  city?: string
  latitude?: number
  longitude?: number
  timezone?: string
  currency?: string
  reverse?: string
  flag?: string
  error?: string
}

type GeoResponse = {
  ok?: boolean
  data?: GeoPayload
  raw?: Record<string, unknown>
  error?: string
  fields?: string
  lang?: string
  provider?: string
}

type LookupState = {
  status: 'idle' | 'loading' | 'ready' | 'error'
  data: GeoPayload | null
  error: string
}

const apiBase = import.meta.env.VITE_API_ORIGIN || ''

const fieldLabels: Array<{
  key: keyof GeoPayload
  label: Record<LanguageCode, string>
}> = [
  { key: 'country', label: labels('Country / Region', '国家 / 地区') },
  { key: 'province', label: labels('Region / State', '省份 / 州') },
  { key: 'city', label: labels('City', '城市') },
  { key: 'isp', label: labels('ISP', '运营商') },
  { key: 'asn', label: labels('ASN', 'ASN') },
  { key: 'org', label: labels('Organization', '组织') },
  { key: 'timezone', label: labels('Timezone', '时区') },
  { key: 'currency', label: labels('Currency', '货币') },
  { key: 'reverse', label: labels('Reverse DNS', '反向 DNS') },
]

const commonFields =
  'status,message,query,country,countryCode,regionName,city,isp,org,as,asname,mobile,proxy,hosting'

const fullFields =
  'status,message,continent,continentCode,country,countryCode,region,regionName,city,district,zip,lat,lon,timezone,offset,currency,isp,org,as,asname,reverse,mobile,proxy,hosting,query'

export const Route = createFileRoute('/')({
  component: () => <HomePage initialLang={defaultLanguage} />,
})

type HomePageProps = {
  initialLang?: LanguageCode
}

export function HomePage({ initialLang = defaultLanguage }: HomePageProps) {
  const [query, setQuery] = useState('')
  const [current, setCurrent] = useState<LookupState>({
    status: 'loading',
    data: null,
    error: '',
  })
  const [lookup, setLookup] = useState<LookupState>({
    status: 'idle',
    data: null,
    error: '',
  })
  const [copied, setCopied] = useState(false)
  const [lang, setLang] = useState<LanguageCode>(initialLang)
  const [fieldMode, setFieldMode] = useState<'common' | 'full'>('common')
  const [includeRaw, setIncludeRaw] = useState(false)

  useEffect(() => {
    fetchGeo(`/api/ip?fields=${encodeURIComponent(currentFields(fieldMode))}&lang=${lang}`)
      .then((data) => setCurrent({ status: 'ready', data, error: '' }))
      .catch((error: Error) =>
        setCurrent({ status: 'error', data: null, error: error.message }),
      )
  }, [fieldMode, lang])

  const activeData = lookup.data || current.data
  const visibleFields = useMemo(() => {
    if (!activeData) return []
    return fieldLabels.filter((field) => hasValue(activeData[field.key]))
  }, [activeData])

  async function loadLookup(target: string, nextLang = lang) {
    const value = target.trim()
    if (!value) return

    setLookup({ status: 'loading', data: null, error: '' })
    try {
      const params = new URLSearchParams({
        fields: currentFields(fieldMode),
        lang: nextLang,
      })
      if (includeRaw) params.set('raw', '1')
      const data = await fetchGeo(`/api/lookup/${encodeURIComponent(value)}?${params}`)
      setLookup({ status: 'ready', data, error: '' })
    } catch (error) {
      setLookup({
        status: 'error',
        data: null,
        error: error instanceof Error ? error.message : 'Lookup failed',
      })
    }
  }

  async function submitLookup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    await loadLookup(query)
  }

  function changeLanguage(nextLang: LanguageCode) {
    setLang(nextLang)
    if (lookup.data && query.trim()) {
      window.setTimeout(() => void loadLookup(query, nextLang), 0)
    }
  }

  async function copyIP() {
    const ip = activeData?.ip
    if (!ip) return
    await navigator.clipboard.writeText(ip)
    setCopied(true)
    window.setTimeout(() => setCopied(false), 1400)
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
          <LanguageMenu lang={lang} onChange={changeLanguage} section="home" />
          <a className="glass-chrome api-link" href={languagePathByCode(lang, 'api')}>
            API
          </a>
        </div>
      </header>

      <GlassPanel className="hero">
        <div className="eyebrow">IPv4 / IPv6 / GeoIP</div>
        <h1>IP Address Lookup</h1>
        <p>Look up public IP location, ISP, ASN, and network signals. Results follow the selected language.</p>

        <form className="lookup-form" onSubmit={submitLookup}>
          <Search size={18} aria-hidden="true" />
          <label className="sr-only" htmlFor="ip-lookup">
            查询 IP 或域名
          </label>
          <input
            id="ip-lookup"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            placeholder="8.8.8.8 or example.com"
            autoComplete="off"
          />
          <button type="submit" disabled={lookup.status === 'loading'}>
            {lookup.status === 'loading' ? (
              <LoaderCircle className="spin" size={16} aria-hidden="true" />
            ) : (
              <Search size={16} aria-hidden="true" />
            )}
            <span>Lookup</span>
          </button>
        </form>

        <div className="query-options" aria-label="Query options">
          <label>
            Fields
            <select
              value={fieldMode}
              onChange={(event) => setFieldMode(event.target.value as 'common' | 'full')}
            >
              <option value="common">Common</option>
              <option value="full">Full</option>
            </select>
          </label>
          <label className="check-option">
            <input
              type="checkbox"
              checked={includeRaw}
              onChange={(event) => setIncludeRaw(event.target.checked)}
            />
            Raw JSON
          </label>
        </div>

        {lookup.status === 'error' ? <p className="form-error">{lookup.error}</p> : null}
      </GlassPanel>

      <GlassPanel className="result-card">
        <div className="result-head">
          <div>
            <span className="result-label">{statusTitle(current, lookup, lang)}</span>
            <h2>{activeData?.ip || (lang === 'zh-CN' ? '正在识别...' : 'Detecting...')}</h2>
          </div>
          <button
            className="copy-button"
            type="button"
            onClick={copyIP}
            disabled={!activeData?.ip}
            aria-label="复制 IP"
          >
            {copied ? <Check size={17} /> : <Clipboard size={17} />}
          </button>
        </div>

        <div className="summary-line">
          <span>{formatPlace(activeData, lang)}</span>
          <span>{activeData?.isp || activeData?.org || 'Waiting for result'}</span>
        </div>

        <div className="field-grid">
          {visibleFields.length > 0 ? (
            visibleFields.map((field) => (
              <FieldRow
                key={field.key}
                label={field.label[lang]}
                value={String(activeData?.[field.key] ?? '')}
              />
            ))
          ) : (
            <div className="empty-state">Ready for lookup.</div>
          )}
        </div>
      </GlassPanel>
    </main>
  )
}

async function fetchGeo(path: string): Promise<GeoPayload> {
  const response = await fetch(`${apiBase}${path}`, {
    headers: { Accept: 'application/json' },
  })
  const data = (await response.json()) as GeoResponse | GeoPayload
  if (!response.ok || 'error' in data && data.error) {
    throw new Error(('error' in data && data.error) || `HTTP ${response.status}`)
  }
  if ('data' in data && data.data) return data.data
  return data as GeoPayload
}

function hasValue(value: unknown) {
  return value !== undefined && value !== null && value !== ''
}

function formatPlace(data: GeoPayload | null, lang: LanguageCode) {
  if (!data) return lang === 'zh-CN' ? '定位中' : 'Detecting'
  return [data.country, data.province, data.city].filter(Boolean).join(' / ') || (lang === 'zh-CN' ? '未知位置' : 'Unknown location')
}

function currentFields(fieldMode: 'common' | 'full') {
  return fieldMode === 'full' ? fullFields : commonFields
}

function statusTitle(current: LookupState, lookup: LookupState, lang: LanguageCode) {
  const isChinese = lang === 'zh-CN'
  if (lookup.status === 'ready') return isChinese ? '查询结果' : 'Lookup result'
  if (lookup.status === 'loading') return isChinese ? '正在查询' : 'Looking up'
  if (current.status === 'error') return isChinese ? '识别失败' : 'Lookup failed'
  return isChinese ? '你的公网出口' : 'Your public IP'
}

function FieldRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="field-row">
      <Tooltip.Root>
        <Tooltip.Trigger className="field-label" delay={300}>
          {label}
        </Tooltip.Trigger>
        <Tooltip.Portal>
          <Tooltip.Positioner sideOffset={8}>
            <Tooltip.Popup className="tooltip-popup">
              <Tooltip.Arrow className="tooltip-arrow" />
              {label}
            </Tooltip.Popup>
          </Tooltip.Positioner>
        </Tooltip.Portal>
      </Tooltip.Root>
      <strong>{value}</strong>
    </div>
  )
}

function labels(en: string, zhCN: string): Record<LanguageCode, string> {
  return {
    en,
    'zh-CN': zhCN,
    ja: en,
    de: en,
    fr: en,
    es: en,
    'pt-BR': en,
    ru: en,
  }
}

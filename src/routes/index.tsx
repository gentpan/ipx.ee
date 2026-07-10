import { createFileRoute } from '@tanstack/react-router'
import {
  Building2,
  Check,
  Clipboard,
  Clock,
  Crosshair,
  Globe2,
  Map,
  MapPin,
  Search,
  Share2,
  Wifi,
  type LucideIcon,
} from 'lucide-react'
import { Tooltip } from '@base-ui/react/tooltip'
import { useEffect, useMemo, useState } from 'react'
import type { FormEvent } from 'react'
import { BrandLogo } from '../components/brand-mark'
import { LanguageMenu } from '../components/language-menu'
import { GlassBadge, GlassPanel } from '../components/glass'
import { LoadingSpinner } from '../components/loading-spinner'
import { MapModal, type MapPoint } from '../components/map-modal'
import { ThemeToggle } from '../components/theme-toggle'
import { TopbarIconLink } from '../components/topbar-icon-link'
import { defaultLanguage, languagePathByCode, type LanguageCode } from '../lib/languages'
import { fieldLabel, t, type FieldLabelKey } from '../lib/ui-messages'

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
  offset?: number
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
}

type LookupState = {
  status: 'idle' | 'loading' | 'ready' | 'error' | 'unsupported'
  data: GeoPayload | null
  error: string
}

type DisplayRow = {
  key: string
  labelKey: FieldLabelKey
  value: string
  href?: string
  flag?: string
  flagCode?: string
  lat?: number
  lon?: number
}

const apiBase = import.meta.env.VITE_API_ORIGIN || ''
const v6Host = (import.meta.env.VITE_V6_HOST as string | undefined) || 'v6.ipx.ee'

/** Default Geo fields for homepage visitor IP + lookup. */
const homeFields =
  'status,message,query,country,countryCode,regionName,city,lat,lon,timezone,offset,isp,org,as,asname,mobile,proxy,hosting'

export const Route = createFileRoute('/')({
  component: () => <HomePage initialLang={defaultLanguage} />,
})

type HomePageProps = {
  initialLang?: LanguageCode
}

export function HomePage({ initialLang = defaultLanguage }: HomePageProps) {
  const [query, setQuery] = useState('')
  const [ipv4, setIpv4] = useState<LookupState>({
    status: 'loading',
    data: null,
    error: '',
  })
  const [ipv6, setIpv6] = useState<LookupState>({
    status: 'loading',
    data: null,
    error: '',
  })
  const [lookup, setLookup] = useState<LookupState>({
    status: 'idle',
    data: null,
    error: '',
  })
  const [copiedKey, setCopiedKey] = useState<string | null>(null)
  const [lang, setLang] = useState<LanguageCode>(initialLang)
  const [mapPoint, setMapPoint] = useState<MapPoint | null>(null)

  useEffect(() => {
    let cancelled = false

    setIpv4({ status: 'loading', data: null, error: '' })
    setIpv6({ status: 'loading', data: null, error: '' })

    // Main site is IPv4-only; visitor IPv4 via /api/ip. v4.ipx.ee remains API-only.
    const ipParams = new URLSearchParams({ fields: homeFields, lang })
    void fetchGeo(`/api/ip?${ipParams}`)
      .then((data) => {
        if (cancelled) return
        setIpv4({ status: 'ready', data, error: '' })
      })
      .catch((error: Error) => {
        if (cancelled) return
        setIpv4({ status: 'error', data: null, error: error.message })
      })

    void fetchStackGeo(v6Host, homeFields, lang)
      .then((data) => {
        if (cancelled) return
        setIpv6({ status: 'ready', data, error: '' })
      })
      .catch((error: Error) => {
        if (cancelled) return
        const unsupported = isIpv6Unsupported(error.message)
        setIpv6({
          status: unsupported ? 'unsupported' : 'error',
          data: null,
          error: error.message,
        })
      })

    return () => {
      cancelled = true
    }
  }, [lang])

  const ui = t(lang)
  const showLookup = lookup.status !== 'idle'
  const lookupRows = useMemo(() => buildDisplayRows(lookup.data), [lookup.data])
  const ipv4Rows = useMemo(() => buildDisplayRows(ipv4.data), [ipv4.data])
  const ipv6Rows = useMemo(() => buildDisplayRows(ipv6.data), [ipv6.data])

  useEffect(() => {
    document.documentElement.lang = lang === 'zh-CN' ? 'zh-CN' : lang === 'pt-BR' ? 'pt-BR' : lang
  }, [lang])

  async function loadLookup(target: string, nextLang = lang) {
    const value = target.trim()
    if (!value) return

    setLookup({ status: 'loading', data: null, error: '' })
    try {
      const params = new URLSearchParams({
        fields: homeFields,
        lang: nextLang,
      })
      const data = await fetchGeo(`/api/lookup/${encodeURIComponent(value)}?${params}`)
      setLookup({ status: 'ready', data, error: '' })
    } catch (error) {
      setLookup({
        status: 'error',
        data: null,
        error: error instanceof Error ? error.message : t(nextLang).lookupFailed,
      })
    }
  }

  async function submitLookup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    await loadLookup(query)
  }

  function changeLanguage(nextLang: LanguageCode) {
    if (nextLang === lang) return
    setLang(nextLang)
    if (query.trim() && (lookup.data || lookup.status === 'ready' || lookup.status === 'error')) {
      window.setTimeout(() => void loadLookup(query, nextLang), 0)
    }
  }

  async function copyIP(key: string, ip?: string) {
    if (!ip) return
    await navigator.clipboard.writeText(ip)
    setCopiedKey(key)
    window.setTimeout(() => setCopiedKey(null), 1400)
  }

  return (
    <main className="page">
      {lookup.status === 'loading' ? (
        <div className="global-loading-overlay" role="status" aria-live="polite" aria-label={ui.lookingUp}>
          <LoadingSpinner size={40} />
        </div>
      ) : null}
      <header className="topbar">
        <a className="brand" href="/">
          <BrandLogo />
        </a>
        <div className="topbar-actions">
          <LanguageMenu
            lang={lang}
            onChange={changeLanguage}
            label={ui.language}
            section="home"
          />
          <TopbarIconLink
            href={languagePathByCode(lang, 'api')}
            label={ui.api}
            icon="api"
          />
          <ThemeToggle
            labelLight={ui.themeToLight}
            labelDark={ui.themeToDark}
          />
        </div>
      </header>

      <GlassPanel className="hero">
        <div className="eyebrow">{ui.eyebrow}</div>
        <h1>{ui.title}</h1>
        <p>{ui.description}</p>

        <form className="lookup-form" onSubmit={submitLookup}>
          <div className="lookup-shell">
            <label className="sr-only" htmlFor="ip-lookup">
              {ui.queryLabel}
            </label>
            <input
              id="ip-lookup"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={ui.placeholder}
              autoComplete="off"
              spellCheck={false}
            />
            <button type="submit" disabled={lookup.status === 'loading'} aria-label={ui.lookup}>
              {lookup.status === 'loading' ? (
                <LoadingSpinner size={18} />
              ) : (
                <Search size={18} aria-hidden="true" />
              )}
            </button>
          </div>
        </form>

        {lookup.status === 'error' ? <p className="form-error">{lookup.error}</p> : null}
      </GlassPanel>

      {showLookup ? (
        <ResultCard
          className="result-card"
          label={lookupStatusTitle(lookup, ui)}
          state={lookup}
          rows={lookupRows}
          lang={lang}
          ui={ui}
          copied={copiedKey === 'lookup'}
          onCopy={() => void copyIP('lookup', lookup.data?.ip)}
          onOpenMap={setMapPoint}
        />
      ) : (
        <>
          <ResultCard
            className="result-card result-card--v4"
            label={ui.yourPublicIpv4}
            labelBadge="v4"
            state={ipv4}
            rows={ipv4Rows}
            lang={lang}
            ui={ui}
            copied={copiedKey === 'v4'}
            onCopy={() => void copyIP('v4', ipv4.data?.ip)}
            onOpenMap={setMapPoint}
          />

          <ResultCard
            className="result-card result-card--v6"
            label={ui.yourPublicIpv6}
            labelBadge="v6"
            state={ipv6}
            rows={ipv6Rows}
            lang={lang}
            ui={ui}
            copied={copiedKey === 'v6'}
            onCopy={() => void copyIP('v6', ipv6.data?.ip)}
            onOpenMap={setMapPoint}
            unsupportedMessage={ui.ipv6Unsupported}
          />
        </>
      )}

      {mapPoint ? (
        <MapModal
          point={mapPoint}
          title={ui.mapPreview}
          closeLabel={ui.close}
          onClose={() => setMapPoint(null)}
        />
      ) : null}
    </main>
  )
}

function ResultCard({
  className,
  label,
  labelBadge,
  state,
  rows,
  lang,
  ui,
  copied,
  onCopy,
  onOpenMap,
  unsupportedMessage,
}: {
  className: string
  label: string
  labelBadge?: 'v4' | 'v6'
  state: LookupState
  rows: DisplayRow[]
  lang: LanguageCode
  ui: ReturnType<typeof t>
  copied: boolean
  onCopy: () => void
  onOpenMap: (point: MapPoint) => void
  unsupportedMessage?: string
}) {
  const title =
    state.status === 'unsupported'
      ? unsupportedMessage || ui.ipv6Unsupported
      : state.status === 'error'
        ? state.error || ui.lookupFailed
        : state.data?.ip || ui.detecting

  return (
    <GlassPanel className={className}>
      <div className="result-head">
        <div>
          <span
            className={
              labelBadge
                ? `stack-kind-badge stack-kind-badge--${labelBadge}`
                : 'result-label'
            }
          >
            {label}
          </span>
          <h2
            className={
              state.status === 'unsupported' || state.status === 'error'
                ? 'result-ip result-ip--muted'
                : 'result-ip'
            }
          >
            {title}
          </h2>
        </div>
        <button
          className="copy-button"
          type="button"
          onClick={onCopy}
          disabled={!state.data?.ip}
          aria-label={ui.copyIp}
        >
          {copied ? <Check size={17} /> : <Clipboard size={17} />}
        </button>
      </div>

      {state.status === 'unsupported' ? null : (
        <>
          <SignalBadges data={state.data} ui={ui} />
          <div className="field-grid">
            {rows.length > 0 ? (
              rows.map((row) => (
                <FieldRow
                  key={row.key}
                  label={fieldLabel(row.labelKey, lang)}
                  labelKey={row.labelKey}
                  value={row.value}
                  href={row.href}
                  flag={row.flag}
                  flagCode={row.flagCode}
                  lat={row.lat}
                  lon={row.lon}
                  onOpenMap={onOpenMap}
                />
              ))
            ) : state.status === 'loading' ? (
              <div className="empty-state">{ui.detecting}</div>
            ) : state.status === 'error' ? (
              <div className="empty-state">{state.error || ui.lookupFailed}</div>
            ) : (
              <div className="empty-state">{ui.readyForLookup}</div>
            )}
          </div>
        </>
      )}
    </GlassPanel>
  )
}

function buildDisplayRows(data: GeoPayload | null): DisplayRow[] {
  if (!data) return []

  const rows: DisplayRow[] = []

  const asnRow = buildAsnNumberRow(data)
  if (asnRow) rows.push(asnRow)

  const isp = pickIsp(data)
  if (isp) {
    rows.push({ key: 'isp', labelKey: 'isp', value: isp })
  }

  const enterprise = pickEnterprise(data, isp)
  if (enterprise) {
    rows.push({ key: 'enterprise', labelKey: 'enterprise', value: enterprise })
  }

  if (hasValue(data.country)) {
    rows.push({
      key: 'country',
      labelKey: 'country',
      value: String(data.country),
      flag: data.flag,
      flagCode: data.country_code,
    })
  }
  if (hasValue(data.province)) {
    rows.push({ key: 'province', labelKey: 'province', value: String(data.province) })
  }
  if (hasValue(data.city)) {
    rows.push({ key: 'city', labelKey: 'city', value: String(data.city) })
  }

  const coordinates = formatCoordinates(data.latitude, data.longitude)
  if (coordinates && typeof data.latitude === 'number' && typeof data.longitude === 'number') {
    rows.push({
      key: 'coordinates',
      labelKey: 'coordinates',
      value: coordinates,
      lat: data.latitude,
      lon: data.longitude,
    })
  }

  const timezone = formatTimezone(data.timezone, data.offset)
  if (timezone) {
    rows.push({ key: 'timezone', labelKey: 'timezone', value: timezone })
  }

  return rows
}

function pickIsp(data: GeoPayload) {
  return data.isp?.trim() || data.as_name?.trim() || ''
}

function pickEnterprise(data: GeoPayload, isp: string) {
  const org = data.org?.trim() || ''
  if (!org) return ''
  if (isp && org.toLowerCase() === isp.toLowerCase()) return ''
  return org
}

function buildAsnNumberRow(data: GeoPayload): DisplayRow | null {
  const asn = data.asn?.trim() || ''
  if (!asn) return null

  const asnNumber = asn.replace(/^AS/i, '')
  return {
    key: 'asn',
    labelKey: 'asn',
    value: asn.startsWith('AS') ? asn : `AS${asn}`,
    href: `https://bgp.he.net/AS${asnNumber}`,
  }
}

function formatCoordinates(lat?: number, lon?: number) {
  if (typeof lat !== 'number' || typeof lon !== 'number') return ''
  if (!Number.isFinite(lat) || !Number.isFinite(lon)) return ''
  const latHemisphere = lat >= 0 ? 'N' : 'S'
  const lonHemisphere = lon >= 0 ? 'E' : 'W'
  return `${Math.abs(lat).toFixed(4)}°${latHemisphere}, ${Math.abs(lon).toFixed(4)}°${lonHemisphere}`
}

function formatTimezone(timezone?: string, offset?: number) {
  const zone = timezone?.trim() || ''
  if (!zone) return ''
  if (typeof offset !== 'number' || !Number.isFinite(offset)) return zone
  const hours = offset / 3600
  const sign = hours >= 0 ? '+' : '-'
  const absolute = Math.abs(hours)
  const whole = Math.floor(absolute)
  const minutes = Math.round((absolute - whole) * 60)
  const gmt =
    minutes === 0
      ? `GMT${sign}${whole}`
      : `GMT${sign}${whole}:${String(minutes).padStart(2, '0')}`
  return `${zone} (${gmt})`
}

async function fetchGeo(path: string): Promise<GeoPayload> {
  const response = await fetch(`${apiBase}${path}`, {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  })
  return parseGeoResponse(response)
}

async function fetchStackGeo(host: string, fields: string, lang: LanguageCode) {
  const params = new URLSearchParams({
    format: 'json',
    fields,
    lang,
  })
  const response = await fetch(`https://${host}/?${params}`, {
    headers: { Accept: 'application/json' },
    cache: 'no-store',
  })
  return parseGeoResponse(response)
}

async function parseGeoResponse(response: Response): Promise<GeoPayload> {
  const data = (await response.json()) as GeoResponse | GeoPayload
  if (!response.ok || ('error' in data && data.error)) {
    throw new Error(('error' in data && data.error) || `HTTP ${response.status}`)
  }
  if ('data' in data && data.data) return data.data
  return data as GeoPayload
}

function isIpv6Unsupported(message: string) {
  const text = message.toLowerCase()
  return (
    text.includes('ipv6 unavailable') ||
    text.includes('v6 unavailable') ||
    text.includes('failed to fetch') ||
    text.includes('networkerror') ||
    text.includes('load failed')
  )
}

function hasValue(value: unknown) {
  return value !== undefined && value !== null && value !== ''
}

function lookupStatusTitle(lookup: LookupState, ui: ReturnType<typeof t>) {
  if (lookup.status === 'ready') return ui.lookupResult
  if (lookup.status === 'loading') return ui.lookingUp
  if (lookup.status === 'error') return ui.lookupFailed
  return ui.lookupResult
}

function SignalBadges({
  data,
  ui,
}: {
  data: GeoPayload | null
  ui: ReturnType<typeof t>
}) {
  if (!data?.ip) return null

  const badges = [
    {
      key: 'mobile',
      active: data.is_mobile === true,
      tone: 'green' as const,
      label: ui.badgeMobile,
      hint: ui.badgeMobileHint,
      tint: [0.2, 0.85, 0.45] as [number, number, number],
    },
    {
      key: 'proxy',
      active: data.is_proxy === true,
      tone: 'red' as const,
      label: ui.badgeProxy,
      hint: ui.badgeProxyHint,
      tint: [0.95, 0.35, 0.4] as [number, number, number],
    },
    {
      key: 'hosting',
      active: data.is_hosting === true,
      tone: 'blue' as const,
      label: ui.badgeHosting,
      hint: ui.badgeHostingHint,
      tint: [0.35, 0.55, 1] as [number, number, number],
    },
  ]

  return (
    <div className="signal-badges" aria-label="Network signals">
      {badges.map((badge) => {
        const value = badge.active ? ui.badgeYes : ui.badgeNo
        const fullLabel = `${badge.label}: ${value}`
        return (
          <GlassBadge
            key={badge.key}
            label={fullLabel}
            className={`signal-badge signal-badge--${badge.tone}${badge.active ? ' is-yes' : ' is-no'}`}
            tint={badge.active ? badge.tint : undefined}
            tintStrength={badge.active ? 0.22 : 0.06}
          >
            <span className="signal-badge-label" title={badge.hint}>
              {badge.label}
            </span>
            <span className="signal-badge-sep" aria-hidden="true">
              ·
            </span>
            <span className="signal-badge-value">{value}</span>
          </GlassBadge>
        )
      })}
    </div>
  )
}

function flagSrc(flag?: string, code?: string) {
  if (flag) return flag
  const normalized = code?.trim().toLowerCase()
  if (!normalized || normalized.length !== 2) return null
  return `https://flagcdn.com/${normalized}.svg`
}

function CountryFlag({ flag, code }: { flag?: string; code?: string }) {
  const src = flagSrc(flag, code)
  if (!src) return null
  return <img className="country-flag" src={src} alt="" loading="lazy" />
}

const fieldIcons: Partial<Record<FieldLabelKey, LucideIcon>> = {
  asn: Share2,
  isp: Wifi,
  enterprise: Building2,
  country: Globe2,
  province: Map,
  city: MapPin,
  coordinates: Crosshair,
  timezone: Clock,
}

function FieldRow({
  label,
  labelKey,
  value,
  href,
  flag,
  flagCode,
  lat,
  lon,
  onOpenMap,
}: {
  label: string
  labelKey: FieldLabelKey
  value: string
  href?: string
  flag?: string
  flagCode?: string
  lat?: number
  lon?: number
  onOpenMap: (point: MapPoint) => void
}) {
  const Icon = fieldIcons[labelKey]
  const canOpenMap =
    labelKey === 'coordinates' &&
    typeof lat === 'number' &&
    typeof lon === 'number' &&
    Number.isFinite(lat) &&
    Number.isFinite(lon)

  return (
    <div className="field-row">
      <Tooltip.Root>
        <Tooltip.Trigger className="field-label" delay={300}>
          {Icon ? <Icon className="field-label-icon" size={14} strokeWidth={2.1} aria-hidden="true" /> : null}
          <span>{label}</span>
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
      <strong className="field-value">
        <CountryFlag flag={flag} code={flagCode} />
        {canOpenMap ? (
          <button
            className="field-link field-link-button"
            type="button"
            onClick={() => onOpenMap({ lat: lat!, lon: lon!, label: value })}
          >
            {value}
          </button>
        ) : href ? (
          <a className="field-link" href={href} target="_blank" rel="noreferrer">
            {value}
          </a>
        ) : (
          <span>{value}</span>
        )}
      </strong>
    </div>
  )
}

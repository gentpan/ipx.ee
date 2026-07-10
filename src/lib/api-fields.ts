/** Field bit values for the numeric `fields=` parameter (verified against Pro API). */
export const apiFieldBits: Record<string, number> = {
  status: 16384,
  message: 32768,
  continent: 1048576,
  continentCode: 2097152,
  country: 1,
  countryCode: 2,
  region: 4,
  regionName: 8,
  city: 16,
  district: 524288,
  zip: 32,
  lat: 64,
  lon: 128,
  timezone: 256,
  offset: 33554432,
  currency: 8388608,
  isp: 512,
  org: 1024,
  as: 2048,
  asname: 4194304,
  reverse: 4096,
  mobile: 65536,
  proxy: 131072,
  hosting: 16777216,
  query: 8192,
}

export const apiFieldNames = Object.keys(apiFieldBits)

export const commonApiFieldNames = [
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
] as const

/** Default public API fields bitmask (matches Go defaultIPAPIFields / common set). */
export const defaultFieldsBitmask = encodeFieldsBitmask([...commonApiFieldNames])

export const defaultApiLang = 'en'

export function encodeFieldsBitmask(fields: string[]): number {
  let mask = 0
  for (const name of fields) {
    const bit = apiFieldBits[name]
    if (bit) mask |= bit
  }
  return mask
}

export function decodeFieldsBitmask(mask: number): string[] {
  return apiFieldNames.filter((name) => (mask & apiFieldBits[name]) !== 0)
}

export const publicApiBaseURL = (
  (typeof import.meta !== 'undefined' &&
    import.meta.env &&
    (import.meta.env.VITE_PUBLIC_API_ORIGIN as string | undefined)) ||
  'https://api.ipx.ee'
).replace(/\/$/, '')

/** Build a public API URL. Omits fields/lang when they match server defaults. */
export function buildPublicApiURL(options: {
  target?: string
  fields: string[]
  lang: string
  raw?: boolean
}) {
  const target = options.target?.trim()
  const path = target ? `/${encodeURIComponent(target)}` : '/ip'
  const mask = encodeFieldsBitmask(options.fields)
  const params = new URLSearchParams()

  if (mask !== defaultFieldsBitmask) {
    params.set('fields', String(mask))
  }
  if (options.lang && options.lang !== defaultApiLang) {
    params.set('lang', options.lang)
  }
  if (options.raw) params.set('raw', '1')

  const query = params.toString()
  return query ? `${publicApiBaseURL}${path}?${query}` : `${publicApiBaseURL}${path}`
}

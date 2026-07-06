import { createMiddleware, createStart } from '@tanstack/react-start'

const API_PROXY_TARGET = process.env.API_PROXY_TARGET || 'http://127.0.0.1:18080'
const API_PATH = /^\/api\/(health|ip|lookup|raw|fields)(\/|$)/

const proxyGeoApi = createMiddleware({ type: 'request' }).server(
  async ({ request, next }) => {
    const url = new URL(request.url)
    if (!API_PATH.test(url.pathname)) {
      return next()
    }

    const target = `${API_PROXY_TARGET}${url.pathname}${url.search}`
    const headers = new Headers(request.headers)
    headers.delete('host')
    headers.delete('connection')

    try {
      const upstream = await fetch(target, {
        method: request.method,
        headers,
      })
      return new Response(upstream.body, {
        status: upstream.status,
        statusText: upstream.statusText,
        headers: upstream.headers,
      })
    } catch {
      return Response.json(
        {
          ok: false,
          error:
            'GeoIP API backend is unavailable. Start Go backend: cd go-backend && PORT=18080 PROJECT_ROOT=$(pwd)/.. go run main.go',
        },
        { status: 503 },
      )
    }
  },
)

export const startInstance = createStart(() => ({
  requestMiddleware: [proxyGeoApi],
}))

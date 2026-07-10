import { createReadStream, existsSync, statSync } from 'node:fs'
import { createServer } from 'node:http'
import { extname, join, normalize, resolve } from 'node:path'
import { Readable } from 'node:stream'
import serverEntry from './dist/server/server.js'

const host = process.env.HOST || '127.0.0.1'
const port = Number(process.env.PORT || 3012)
const clientDir = resolve('dist/client')

const mimeTypes = {
  '.css': 'text/css; charset=utf-8',
  '.html': 'text/html; charset=utf-8',
  '.ico': 'image/x-icon',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.webp': 'image/webp',
  '.svg': 'image/svg+xml',
  '.txt': 'text/plain; charset=utf-8',
  '.webmanifest': 'application/manifest+json; charset=utf-8',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
}

function staticPath(pathname) {
  const decoded = decodeURIComponent(pathname)
  const normalized = normalize(decoded).replace(/^(\.\.[/\\])+/, '').replace(/^[/\\]+/, '')
  const filePath = join(clientDir, normalized)
  if (!filePath.startsWith(clientDir)) return ''
  if (!existsSync(filePath)) return ''
  const stats = statSync(filePath)
  return stats.isFile() ? filePath : ''
}

function sendStatic(req, res, filePath) {
  const ext = extname(filePath)
  res.statusCode = 200
  res.setHeader('Content-Type', mimeTypes[ext] || 'application/octet-stream')
  if (req.url?.startsWith('/assets/')) {
    res.setHeader('Cache-Control', 'public, max-age=31536000, immutable')
  }
  if (req.method === 'HEAD') {
    res.end()
    return
  }
  createReadStream(filePath).pipe(res)
}

async function sendFetchResponse(res, response) {
  res.statusCode = response.status
  res.statusMessage = response.statusText
  response.headers.forEach((value, key) => res.setHeader(key, value))
  if (!response.body) {
    res.end()
    return
  }
  Readable.fromWeb(response.body).pipe(res)
}

createServer(async (req, res) => {
  try {
    const url = new URL(req.url || '/', `http://${req.headers.host || `${host}:${port}`}`)
    const filePath = staticPath(url.pathname)
    if (filePath) {
      sendStatic(req, res, filePath)
      return
    }
    const request = new Request(url, {
      method: req.method,
      headers: req.headers,
      body: req.method === 'GET' || req.method === 'HEAD' ? undefined : Readable.toWeb(req),
      duplex: 'half',
    })
    await sendFetchResponse(res, await serverEntry.fetch(request))
  } catch (error) {
    console.error(error)
    res.statusCode = 500
    res.end('Internal Server Error')
  }
}).listen(port, host, () => {
  console.log(`ipx.ee web listening on http://${host}:${port}`)
})

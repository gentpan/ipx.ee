import type { ReactNode } from 'react'
import {
  HeadContent,
  Outlet,
  Scripts,
  createRootRoute,
} from '@tanstack/react-router'
import '@ogtirth/liquid-glass-oss/styles.css'
import { GlassBackgroundProvider, GlassBackgroundWallpaper } from '../components/glass'
import '../styles.css'

export const Route = createRootRoute({
  head: () => ({
    meta: [
      { charSet: 'utf-8' },
      { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      {
        name: 'description',
        content: 'IPX.EE provides fast IPv4, IPv6, ASN, ISP, and GeoIP lookup.',
      },
      { title: 'IPX.EE - IP Address Lookup' },
    ],
    links: [
      { rel: 'icon', href: '/favicon.ico' },
      { rel: 'manifest', href: '/site.webmanifest' },
    ],
  }),
  component: RootComponent,
})

function RootComponent() {
  return (
    <RootDocument>
      <Outlet />
    </RootDocument>
  )
}

function RootDocument({ children }: Readonly<{ children: ReactNode }>) {
  return (
    <html lang="en">
      <head>
        <HeadContent />
      </head>
      <body>
        <GlassBackgroundProvider>
          <GlassBackgroundWallpaper />
          <div className="app-root">{children}</div>
        </GlassBackgroundProvider>
        <Scripts />
      </body>
    </html>
  )
}

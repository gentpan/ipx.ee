import type { ReactNode } from 'react'
import {
  HeadContent,
  Outlet,
  Scripts,
  createRootRoute,
} from '@tanstack/react-router'
import '@ogtirth/liquid-glass-oss/styles.css'
import {
  BootLoaderController,
  bootLoaderMarkup,
  themeBootScript,
} from '../components/boot-loader'
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
      { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
      { rel: 'shortcut icon', href: '/favicon.ico' },
      { rel: 'icon', type: 'image/png', sizes: '48x48', href: '/favicon-48x48.png' },
      { rel: 'icon', type: 'image/png', sizes: '32x32', href: '/favicon-32x32.png' },
      { rel: 'icon', type: 'image/png', sizes: '16x16', href: '/favicon-16x16.png' },
      { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' },
      { rel: 'manifest', href: '/site.webmanifest' },
      { rel: 'preload', href: '/assets/images/bg-light.webp', as: 'image', type: 'image/webp' },
      { rel: 'preload', href: '/assets/images/bg-dark.webp', as: 'image', type: 'image/webp' },
    ],
    scripts: [
      {
        src: 'https://stats.cleanip.io/script.js',
        defer: true,
        'data-website-id': 'e7e85005-1a72-4ce4-b9e1-691e91e82ccc',
      },
      {
        children: themeBootScript,
      },
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
    <html lang="en" suppressHydrationWarning>
      <head>
        <HeadContent />
      </head>
      <body>
        <div dangerouslySetInnerHTML={{ __html: bootLoaderMarkup }} />
        <GlassBackgroundProvider>
          <GlassBackgroundWallpaper />
          <BootLoaderController />
          <div className="app-root">{children}</div>
        </GlassBackgroundProvider>
        <Scripts />
      </body>
    </html>
  )
}

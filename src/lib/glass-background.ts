import { createContext, useContext, useSyncExternalStore } from 'react'

export const GLASS_BG_DARK = '/bg-dark.png'
export const GLASS_BG_LIGHT = '/bg-light.png'

type GlassBackgroundContextValue = {
  backgroundImage: string
}

const GlassBackgroundContext = createContext<GlassBackgroundContextValue>({
  backgroundImage: GLASS_BG_LIGHT,
})

function pickBackgroundImage() {
  if (typeof window === 'undefined') return GLASS_BG_LIGHT
  return window.matchMedia('(prefers-color-scheme: light)').matches
    ? GLASS_BG_LIGHT
    : GLASS_BG_DARK
}

function subscribeColorScheme(onStoreChange: () => void) {
  const media = window.matchMedia('(prefers-color-scheme: light)')
  media.addEventListener('change', onStoreChange)
  return () => media.removeEventListener('change', onStoreChange)
}

/** Client + SSR-safe wallpaper URL (fixes stuck SSR bg-light on dark systems). */
export function useGlassBackgroundState() {
  return useSyncExternalStore(
    subscribeColorScheme,
    pickBackgroundImage,
    () => GLASS_BG_LIGHT,
  )
}

export function useGlassBackgroundImage() {
  return useContext(GlassBackgroundContext).backgroundImage
}

export { GlassBackgroundContext }

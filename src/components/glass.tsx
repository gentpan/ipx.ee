import {
  LiquidGlassCard,
  liquidGlassPresets,
  type LiquidGlassSettings,
  type LiquidGlassVariant,
} from '@ogtirth/liquid-glass-oss'
import type { ReactNode } from 'react'
import { useSyncExternalStore } from 'react'
import {
  GlassBackgroundContext,
  useGlassBackgroundImage,
  useGlassBackgroundState,
} from '../lib/glass-background'

export function GlassBackgroundProvider({ children }: { children: ReactNode }) {
  const backgroundImage = useGlassBackgroundState()

  return (
    <GlassBackgroundContext.Provider value={{ backgroundImage }}>
      {children}
    </GlassBackgroundContext.Provider>
  )
}

/** Fixed wallpaper — must use data-liquid-glass-background for WebGL alignment. */
export function GlassBackgroundWallpaper() {
  const backgroundImage = useGlassBackgroundImage()

  return (
    <div
      className="glass-background"
      data-liquid-glass-background
      style={{ backgroundImage: `url("${backgroundImage}")` }}
      aria-hidden="true"
      suppressHydrationWarning
    />
  )
}

type GlassPanelProps = {
  variant?: LiquidGlassVariant
  settings?: Partial<LiquidGlassSettings>
  className?: string
  children?: ReactNode
}

function subscribeColorScheme(onStoreChange: () => void) {
  const media = window.matchMedia('(prefers-color-scheme: dark)')
  media.addEventListener('change', onStoreChange)
  return () => media.removeEventListener('change', onStoreChange)
}

function pickVariant(): LiquidGlassVariant {
  if (typeof window === 'undefined') return 'frosted'
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'frosted'
}

function useGlassVariant(override?: LiquidGlassVariant) {
  const systemVariant = useSyncExternalStore(
    subscribeColorScheme,
    pickVariant,
    () => 'frosted' as LiquidGlassVariant,
  )
  return override ?? systemVariant
}

/**
 * Card tuning — LiquidGlassCard defaults are weaker than preset; restore full
 * frosted/dark refraction so panels match the playground look on wide surfaces.
 */
function cardGlassSettings(variant: LiquidGlassVariant): Partial<LiquidGlassSettings> {
  const preset = liquidGlassPresets[variant]
  return {
    blur: preset.blur,
    refraction: preset.refraction,
    chromaticAberration: preset.chromaticAberration,
    distortion: preset.distortion,
    edgeHighlight: preset.edgeHighlight,
    specular: preset.specular,
    fresnel: preset.fresnel,
    depth: preset.depth,
    darkTint: preset.darkTint,
    tintStrength: preset.tintStrength,
    shadow: variant === 'frosted' ? 0.16 : 0.2,
  }
}

/** WebGL liquid glass card — aligned with liquid-glass-oss playground presets. */
export function GlassPanel({
  variant: variantProp,
  settings,
  className = '',
  children,
}: GlassPanelProps) {
  const backgroundImage = useGlassBackgroundImage()
  const variant = useGlassVariant(variantProp)
  const mergedSettings = { ...cardGlassSettings(variant), ...settings }

  return (
    <LiquidGlassCard
      backgroundImage={backgroundImage}
      variant={variant}
      settings={mergedSettings}
      className={`glass-panel ${className}`.trim()}
    >
      {children}
    </LiquidGlassCard>
  )
}

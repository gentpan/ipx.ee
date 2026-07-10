import {
  LiquidGlassCard,
  type LiquidGlassSettings,
  type LiquidGlassVariant,
} from '@ogtirth/liquid-glass-oss'
import type { CSSProperties, ReactNode } from 'react'
import { ThemeProvider, useTheme } from '../lib/theme'

export function GlassBackgroundProvider({ children }: { children: ReactNode }) {
  return <ThemeProvider>{children}</ThemeProvider>
}

/** Fixed wallpaper — must use data-liquid-glass-background for WebGL alignment. */
export function GlassBackgroundWallpaper() {
  const { backgroundImage } = useTheme()

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

/** #EBF2FF */
const RGB_TINT: [number, number, number] = [235 / 255, 242 / 255, 1]

/**
 * Playground coefficients (Dome / IconButton reference).
 */
export const playgroundGlassSettings: Partial<LiquidGlassSettings> = {
  blur: 0.22,
  refraction: 0.46,
  chromaticAberration: 0.035,
  distortion: 0.015,
  edgeHighlight: 0.12,
  specular: 0.18,
  fresnel: 1.05,
  radius: 22,
  depth: 46,
  brightness: -0.03,
  saturation: 0.03,
  tintStrength: 0.08,
  tintColor: RGB_TINT,
  tint: 0,
  opacity: 1,
  bevel: 1,
  lensWidth: 54,
  lensHeight: 34,
  liquidMotion: 0.14,
  liquidSpring: 0.055,
  liquidDamping: 0.84,
  shadow: 0,
  darkTint: 0.22,
}

/** WebGL liquid glass panel — matched to playground physics. */
export function GlassPanel({
  variant: variantProp,
  settings,
  className = '',
  children,
}: GlassPanelProps) {
  const { theme, backgroundImage } = useTheme()
  const variant = variantProp ?? (theme === 'dark' ? 'dome' : 'frosted')

  return (
    <LiquidGlassCard
      backgroundImage={backgroundImage}
      variant={variant}
      settings={{ ...playgroundGlassSettings, ...settings }}
      className={`glass-panel ${className}`.trim()}
    >
      {children}
    </LiquidGlassCard>
  )
}

type GlassBadgeProps = {
  label: string
  className?: string
  children?: ReactNode
  /** Soft tint when the signal is active. */
  tint?: [number, number, number]
  tintStrength?: number
}

/** Compact capsule — same IconButton glass as the playground squircle. */
export function GlassBadge({
  label,
  className = '',
  children,
  tint = RGB_TINT,
  tintStrength = 0.08,
}: GlassBadgeProps) {
  const tintRgb = tint.map((channel) => Math.round(channel * 255)).join(' ')
  const style = {
    '--glass-badge-tint': tintRgb,
    '--glass-badge-tint-strength': tintStrength,
  } as CSSProperties

  return (
    <span
      role="status"
      aria-label={label}
      className={`glass-badge ${className}`.trim()}
      style={style}
    >
      <span className="glass-badge__content">{children}</span>
    </span>
  )
}

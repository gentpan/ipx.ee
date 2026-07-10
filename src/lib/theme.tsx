import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from 'react'
import { GLASS_BG_DARK, GLASS_BG_LIGHT } from './glass-background'

export type ThemeMode = 'light' | 'dark'

const STORAGE_KEY = 'ipx-theme'

type ThemeContextValue = {
  theme: ThemeMode
  backgroundImage: string
  setTheme: (theme: ThemeMode) => void
  toggleTheme: () => void
}

const ThemeContext = createContext<ThemeContextValue | null>(null)

function systemTheme(): ThemeMode {
  if (typeof window === 'undefined') return 'light'
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function readStoredTheme(): ThemeMode | null {
  if (typeof window === 'undefined') return null
  try {
    const value = window.localStorage.getItem(STORAGE_KEY)
    return value === 'light' || value === 'dark' ? value : null
  } catch {
    return null
  }
}

function subscribeSystemTheme(onStoreChange: () => void) {
  const media = window.matchMedia('(prefers-color-scheme: dark)')
  media.addEventListener('change', onStoreChange)
  return () => media.removeEventListener('change', onStoreChange)
}

function applyDocumentTheme(theme: ThemeMode) {
  document.documentElement.dataset.theme = theme
  document.documentElement.style.colorScheme = theme
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const system = useSyncExternalStore(subscribeSystemTheme, systemTheme, () => 'light' as ThemeMode)
  const [override, setOverride] = useState<ThemeMode | null>(null)
  const [hydrated, setHydrated] = useState(false)

  useEffect(() => {
    setOverride(readStoredTheme())
    setHydrated(true)
  }, [])

  const theme = override ?? system
  const backgroundImage = theme === 'light' ? GLASS_BG_LIGHT : GLASS_BG_DARK

  useEffect(() => {
    if (!hydrated) return
    applyDocumentTheme(theme)
  }, [hydrated, theme])

  const setTheme = useCallback((next: ThemeMode) => {
    setOverride(next)
    try {
      window.localStorage.setItem(STORAGE_KEY, next)
    } catch {
      // ignore quota / private mode
    }
    applyDocumentTheme(next)
  }, [])

  const toggleTheme = useCallback(() => {
    setTheme(theme === 'dark' ? 'light' : 'dark')
  }, [setTheme, theme])

  const value = useMemo(
    () => ({ theme, backgroundImage, setTheme, toggleTheme }),
    [theme, backgroundImage, setTheme, toggleTheme],
  )

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}

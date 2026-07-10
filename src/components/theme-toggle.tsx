import { Moon, Sun } from 'lucide-react'
import { useTheme } from '../lib/theme'

type ThemeToggleProps = {
  labelLight?: string
  labelDark?: string
}

export function ThemeToggle({
  labelLight = 'Switch to light mode',
  labelDark = 'Switch to dark mode',
}: ThemeToggleProps) {
  const { theme, toggleTheme } = useTheme()
  const isDark = theme === 'dark'
  const label = isDark ? labelLight : labelDark

  return (
    <button
      type="button"
      className="glass-chrome theme-toggle"
      onClick={toggleTheme}
      aria-label={label}
      title={label}
    >
      {isDark ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
    </button>
  )
}

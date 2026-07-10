import { useEffect } from 'react'

const STORAGE_KEY = 'ipx-theme'

function currentTheme(): 'light' | 'dark' {
  const stored = document.documentElement.dataset.theme
  if (stored === 'light' || stored === 'dark') return stored
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function backgroundForTheme(theme: 'light' | 'dark') {
  return theme === 'light' ? '/assets/images/bg-light.webp' : '/assets/images/bg-dark.webp'
}

/** Dismiss the SSR boot loader once the wallpaper is ready. */
export function BootLoaderController() {
  useEffect(() => {
    let finished = false
    const loader = document.getElementById('app-boot-loader')

    const finish = () => {
      if (finished) return
      finished = true
      document.documentElement.classList.add('app-ready')
      if (!loader) return
      loader.classList.add('is-done')
      window.setTimeout(() => loader.remove(), 360)
    }

    const img = new Image()
    img.onload = finish
    img.onerror = finish
    img.src = backgroundForTheme(currentTheme())

    // Warm the other theme image in the background.
    const other = new Image()
    other.src = backgroundForTheme(currentTheme() === 'light' ? 'dark' : 'light')

    const timeout = window.setTimeout(finish, 2200)
    return () => {
      window.clearTimeout(timeout)
      img.onload = null
      img.onerror = null
    }
  }, [])

  return null
}

export const themeBootScript = `(function(){try{var k=${JSON.stringify(STORAGE_KEY)};var t=localStorage.getItem(k);if(t!=="light"&&t!=="dark"){t=window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}document.documentElement.dataset.theme=t;document.documentElement.style.colorScheme=t}catch(e){}})();`

export const bootLoaderMarkup = `<div id="app-boot-loader" class="app-boot-loader" aria-hidden="true"><div class="app-boot-loader__inner"><img class="app-boot-loader__svg" src="/spinner.svg" width="36" height="36" alt="" draggable="false" /></div></div>`

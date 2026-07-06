import { createFileRoute } from '@tanstack/react-router'
import { languageFromLocale } from '../../lib/languages'
import { HomePage } from '../index'

export const Route = createFileRoute('/$locale/')({
  component: LocaleHomePage,
})

function LocaleHomePage() {
  const { locale } = Route.useParams()
  return <HomePage initialLang={languageFromLocale(locale)} />
}

import { createFileRoute } from '@tanstack/react-router'
import { languageFromLocale } from '../../lib/languages'
import { APIPage } from '../api'

export const Route = createFileRoute('/$locale/api')({
  component: LocaleAPIPage,
})

function LocaleAPIPage() {
  const { locale } = Route.useParams()
  return <APIPage initialLang={languageFromLocale(locale)} />
}

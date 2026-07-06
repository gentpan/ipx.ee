import { Outlet, createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute('/$locale')({
  component: LocaleLayout,
})

function LocaleLayout() {
  return <Outlet />
}

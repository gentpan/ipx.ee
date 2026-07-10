import { Code2, Search } from 'lucide-react'

type TopbarIconLinkProps = {
  href: string
  label: string
  icon: 'api' | 'lookup'
}

export function TopbarIconLink({ href, label, icon }: TopbarIconLinkProps) {
  const Icon = icon === 'api' ? Code2 : Search

  return (
    <a className="glass-chrome icon-chrome" href={href} aria-label={label} title={label}>
      <Icon size={16} aria-hidden="true" />
    </a>
  )
}

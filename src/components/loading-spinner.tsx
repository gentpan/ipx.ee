type LoadingSpinnerProps = {
  size?: number
  className?: string
}

/** Blue arc spinner from /spinner.svg. */
export function LoadingSpinner({ size = 18, className = '' }: LoadingSpinnerProps) {
  return (
    <img
      className={`loading-spinner ${className}`.trim()}
      src="/spinner.svg"
      width={size}
      height={size}
      alt=""
      aria-hidden="true"
      draggable={false}
    />
  )
}

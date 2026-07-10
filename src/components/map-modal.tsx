import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'
import type { Map as LeafletMap } from 'leaflet'
import { GlassPanel } from './glass'

export type MapPoint = {
  lat: number
  lon: number
  label?: string
}

type MapModalProps = {
  point: MapPoint
  title: string
  closeLabel: string
  onClose: () => void
}

function formatCoords(lat: number, lon: number) {
  return `${lat.toFixed(5)}, ${lon.toFixed(5)}`
}

/** Modal map preview using Google raster tiles via Leaflet (client-only). */
export function MapModal({ point, title, closeLabel, onClose }: MapModalProps) {
  const containerRef = useRef<HTMLDivElement | null>(null)
  const mapRef = useRef<LeafletMap | null>(null)
  const coords = point.label ?? formatCoords(point.lat, point.lon)

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [onClose])

  useEffect(() => {
    const el = containerRef.current
    if (!el) return

    let cancelled = false
    let frame = 0

    void (async () => {
      const leaflet = await import('leaflet')
      await import('leaflet/dist/leaflet.css')
      if (cancelled || !containerRef.current) return

      const L = leaflet.default
      const map = L.map(el, {
        center: [point.lat, point.lon],
        zoom: 11,
        zoomControl: true,
        attributionControl: true,
      })

      L.tileLayer('https://mt{s}.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', {
        subdomains: ['0', '1', '2', '3'],
        maxZoom: 20,
        attribution: '&copy; Google',
      }).addTo(map)

      const marker = L.circleMarker([point.lat, point.lon], {
        radius: 8,
        color: '#111111',
        weight: 2,
        fillColor: '#fafafa',
        fillOpacity: 1,
      }).addTo(map)

      if (point.label) {
        marker.bindPopup(point.label, { closeButton: false }).openPopup()
      }

      mapRef.current = map
      frame = window.requestAnimationFrame(() => {
        map.invalidateSize()
      })
    })()

    return () => {
      cancelled = true
      window.cancelAnimationFrame(frame)
      mapRef.current?.remove()
      mapRef.current = null
    }
  }, [point.lat, point.lon, point.label])

  return (
    <div className="map-modal" role="dialog" aria-modal="true" aria-label={title}>
      <button className="map-modal-backdrop" type="button" aria-label={closeLabel} onClick={onClose} />
      <GlassPanel
        className="map-modal-panel"
        settings={{ radius: 22, shadow: 0.12, depth: 38 }}
      >
        <div className="map-modal-head">
          <div className="map-modal-title">{title}</div>
          <div className="map-modal-subtitle">{coords}</div>
          <button
            className="map-modal-close icon-chrome glass-chrome"
            type="button"
            onClick={onClose}
            aria-label={closeLabel}
          >
            <X size={18} aria-hidden="true" />
          </button>
        </div>
        <div className="map-modal-canvas" ref={containerRef} />
      </GlassPanel>
    </div>
  )
}

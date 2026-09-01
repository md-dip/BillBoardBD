import L from 'leaflet';

function markerColor(billboard) {
  // A billboard's marker colour is fixed by its type / group - status
  // (booked, maintenance, hidden) never changes it.
  switch (billboard.type) {
    case 'led':
      return '#8b5cf6';
    case 'neon':
      return '#ec4899';
    case 'unipole':
      return '#22c55e';
    case 'wall':
      return '#f97316';
    case 'multipole':
      return '#0ea5e9';
    case 'gantry':
      return '#eab308';
    case 'rooftop':
      return '#14b8a6';
    case 'freestanding':
      return '#6366f1';
    case 'static':
      return '#64748b';
    case 'backlit':
      return '#a855f7';
    case 'frontlit':
      return '#f43f5e';
    default:
      return '#3b82f6';
  }
}

/** A billboard-on-a-pole glyph, coloured by billboard type only. */
export function getBillboardIcon(billboard) {
  const color = markerColor(billboard);
  const svg = `
    <svg width="30" height="38" viewBox="0 0 30 38" xmlns="http://www.w3.org/2000/svg">
      <ellipse cx="15" cy="35" rx="6" ry="2" fill="rgba(0,0,0,0.28)" />
      <rect x="13.5" y="17" width="3" height="17" fill="#5b5b5b" />
      <rect x="1" y="1" width="28" height="17" rx="2" fill="${color}" stroke="#fff" stroke-width="1.6" />
      <line x1="5" y1="9.5" x2="25" y2="9.5" stroke="#fff" stroke-width="1.4" stroke-opacity="0.85" />
      <line x1="5" y1="5.5" x2="18" y2="5.5" stroke="#fff" stroke-width="1.4" stroke-opacity="0.6" />
      <line x1="5" y1="13.5" x2="21" y2="13.5" stroke="#fff" stroke-width="1.4" stroke-opacity="0.6" />
    </svg>
  `;

  return L.divIcon({
    className: 'billboard-marker-icon',
    html: svg,
    iconSize: [30, 38],
    iconAnchor: [15, 36],
    popupAnchor: [0, -32],
  });
}

/** A location-pin glyph for "you are here", visually distinct from billboard markers. */
function userPinIcon(color) {
  const svg = `
    <svg width="26" height="34" viewBox="0 0 26 34" xmlns="http://www.w3.org/2000/svg">
      <path d="M13 0C5.8 0 0 5.8 0 13c0 9.7 13 21 13 21s13-11.3 13-21C26 5.8 20.2 0 13 0z" fill="${color}" stroke="#fff" stroke-width="1.6" />
      <circle cx="13" cy="13" r="5" fill="#fff" />
    </svg>
  `;

  return L.divIcon({
    className: 'user-marker-icon',
    html: svg,
    iconSize: [26, 34],
    iconAnchor: [13, 34],
    popupAnchor: [0, -30],
  });
}

export const userIcon = userPinIcon('#3b82f6');
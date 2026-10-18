import { encode } from 'uqr';

/**
 * QR code drawn as SVG rectangles from the encoder's module matrix: no HTML
 * string is ever injected (threat model T-19). Only for the otpauth URI.
 */
export function QrCode({ value, label, size = 192 }: { value: string; label: string; size?: number }) {
  const { data, size: modules } = encode(value, { ecc: 'M', border: 2 });
  const cells: string[] = [];
  data.forEach((row, y) =>
    row.forEach((on, x) => {
      if (on) cells.push(`M${x} ${y}h1v1h-1z`);
    }),
  );

  return (
    <svg className="pc-qr" role="img" aria-label={label} width={size} height={size} viewBox={`0 0 ${modules} ${modules}`} shapeRendering="crispEdges">
      <rect width={modules} height={modules} fill="#ffffff" />
      <path d={cells.join('')} fill="#0B1F3A" />
    </svg>
  );
}

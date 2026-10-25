/** Labels staff can put on an extension, with their colours: HOT and PROMO red, SALE orange, NEW green. */
export const PROMO_LABELS: Record<string, string> = { hot: 'HOT', promo: 'PROMO', sale: 'SALE', new: 'NEW' };

export function PromoLabel({ label, className }: { label: string; className?: string }) {
  const text = PROMO_LABELS[label];
  if (!text) return null;
  return <span className={`pc-promo pc-promo--${label}${className ? ` ${className}` : ''}`}>{text}</span>;
}

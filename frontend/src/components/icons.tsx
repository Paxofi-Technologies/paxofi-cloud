// Minimal 20px stroke icons drawn for PaxofiCloud. Decorative only (aria-hidden).
const base = { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

export const HomeIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" /></svg>
);
export const GlobeIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" /></svg>
);
export const ServerIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="7" rx="2" /><rect x="3" y="13" width="18" height="7" rx="2" /><path d="M7 7.5h.01M7 16.5h.01" /></svg>
);
export const CloudIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><path d="M7 18h10.5a4.5 4.5 0 0 0 .5-9A6.5 6.5 0 0 0 5.6 10.6 3.8 3.8 0 0 0 7 18Z" /></svg>
);
export const ReceiptIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2z" /><path d="M9 8h6M9 12h6" /></svg>
);
export const LifebuoyIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" /><circle cx="12" cy="12" r="3.5" /><path d="m5.6 5.6 4 4M14.4 14.4l4 4M18.4 5.6l-4 4M9.6 14.4l-4 4" /></svg>
);
export const ShieldIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><path d="M12 3 4 6v6c0 4.5 3.4 8.2 8 9 4.6-.8 8-4.5 8-9V6z" /><path d="m9 12 2 2 4-4" /></svg>
);
export const SearchIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
);
export const CheckIcon = () => (
  <svg {...base} aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5" /></svg>
);

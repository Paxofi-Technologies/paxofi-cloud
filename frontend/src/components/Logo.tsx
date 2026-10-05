export function Logo({ inverse = false }: { inverse?: boolean }) {
  return (
    <span className={`pc-logo${inverse ? ' pc-logo--inverse' : ''}`}>
      <svg className="pc-logo__mark" viewBox="0 0 32 32" width="28" height="28" aria-hidden="true" focusable="false">
        <defs>
          <linearGradient id="pc-logo-gradient" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stopColor="var(--pc-color-brand-blue)" />
            <stop offset="1" stopColor="var(--pc-color-brand-teal)" />
          </linearGradient>
        </defs>
        <rect width="32" height="32" rx="8" fill="url(#pc-logo-gradient)" />
        <path d="M10 21.5h12.2a4.3 4.3 0 0 0 .4-8.6 6 6 0 0 0-11.4 1.7A3.5 3.5 0 0 0 10 21.5Z" fill="#fff" />
      </svg>
      <span className="pc-logo__word">
        Paxofi<span className="pc-logo__suffix">Cloud</span>
      </span>
    </span>
  );
}

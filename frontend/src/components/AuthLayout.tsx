import type { ReactNode } from 'react';
import { Logo } from './Logo';

/** The two-column layout shared by sign-in, sign-up and verification screens. */
export function AuthLayout({ children }: { children: ReactNode }) {
  return (
    <div className="pc-auth">
      <div className="pc-auth__panel">
        <Logo />
        {children}
      </div>
      <div className="pc-auth__aside" aria-hidden="true">
        <div className="pc-auth__aside-inner">
          <p className="pc-auth__eyebrow">PaxofiCloud</p>
          <p className="pc-auth__headline">Domains, hosting and cloud servers — built for Nigerian businesses.</p>
          <ul className="pc-auth__points">
            <li>.ng and .com.ng domains in minutes</li>
            <li>Pay in naira or dollars</li>
            <li>Every account protected with two-step verification</li>
          </ul>
        </div>
      </div>
    </div>
  );
}

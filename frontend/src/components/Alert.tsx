import type { ReactNode } from 'react';
import type { Tone } from './Badge';

export interface AlertProps {
  tone?: Exclude<Tone, 'neutral' | 'accent'>;
  title?: string;
  children: ReactNode;
  action?: ReactNode;
}

/** Errors are announced immediately (role="alert"); other tones politely (role="status"). */
export function Alert({ tone = 'info', title, children, action }: AlertProps) {
  return (
    <div className={`pc-alert pc-alert--${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>
      <div className="pc-alert__body">
        {title && <p className="pc-alert__title">{title}</p>}
        <div>{children}</div>
      </div>
      {action && <div className="pc-alert__action">{action}</div>}
    </div>
  );
}

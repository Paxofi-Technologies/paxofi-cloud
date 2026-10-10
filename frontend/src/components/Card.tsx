import type { HTMLAttributes, ReactNode } from 'react';

export interface CardProps extends HTMLAttributes<HTMLElement> {
  title?: string;
  actions?: ReactNode;
  as?: 'section' | 'article' | 'div';
}

export function Card({ title, actions, as: Tag = 'section', className, children, ...rest }: CardProps) {
  return (
    <Tag {...rest} className={['pc-card', className ?? ''].filter(Boolean).join(' ')}>
      {(title || actions) && (
        <header className="pc-card__header">
          {title && <h2 className="pc-card__title">{title}</h2>}
          {actions && <div className="pc-card__actions">{actions}</div>}
        </header>
      )}
      {children}
    </Tag>
  );
}

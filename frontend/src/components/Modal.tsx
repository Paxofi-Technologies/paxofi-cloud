import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export interface ModalProps {
  open: boolean;
  title: string;
  description?: ReactNode;
  onClose: () => void;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'md' | 'lg';
}

const EXIT_MS = 200;
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * An accessible dialog that slides and fades in, and out again before it
 * unmounts (reduced motion turns this off globally). Focus moves to the
 * first field, Tab stays inside, Escape or the backdrop closes, and focus
 * returns to whatever opened it. Rendered into document.body.
 */
export function Modal({ open, title, description, onClose, children, footer, size = 'md' }: ModalProps) {
  const [mounted, setMounted] = useState(open);
  const [visible, setVisible] = useState(false);
  const panel = useRef<HTMLDivElement>(null);
  const opener = useRef<Element | null>(null);
  const titleId = useId();
  const descriptionId = useId();

  // Mount as soon as it opens (state adjusted during render, not in an effect).
  if (open && !mounted) setMounted(true);

  useEffect(() => {
    if (open) {
      opener.current = document.activeElement;
      const frame = window.requestAnimationFrame(() => setVisible(true));
      return () => window.cancelAnimationFrame(frame);
    }
    // Let the exit transition play, then unmount.
    const timer = window.setTimeout(() => {
      setVisible(false);
      setMounted(false);
    }, EXIT_MS);
    return () => window.clearTimeout(timer);
  }, [open]);

  useEffect(() => {
    if (!mounted || !open) return;
    const first = panel.current?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? panel.current)?.focus();
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previous;
      if (opener.current instanceof HTMLElement) opener.current.focus();
    };
  }, [mounted, open]);

  if (!mounted) return null;

  function onKeyDown(event: React.KeyboardEvent) {
    if (event.key === 'Escape') {
      event.stopPropagation();
      onClose();
      return;
    }
    if (event.key !== 'Tab' || !panel.current) return;
    const items = [...panel.current.querySelectorAll<HTMLElement>(FOCUSABLE)];
    if (items.length === 0) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first?.focus();
    }
  }

  // Rendered at the page root: an animated (transformed) ancestor would otherwise trap the fixed overlay.
  return createPortal(
    <div className="pc-modal" data-visible={open && visible} onKeyDown={onKeyDown}>
      <div className="pc-modal__backdrop" onClick={onClose} aria-hidden="true" />
      <div
        ref={panel}
        className={`pc-modal__panel pc-modal__panel--${size}`}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
      >
        <header className="pc-modal__header">
          <h2 id={titleId} className="pc-modal__title">
            {title}
          </h2>
          <button type="button" className="pc-modal__close" onClick={onClose} aria-label="Close">
            ✕
          </button>
        </header>
        {description && (
          <div id={descriptionId} className="pc-modal__description">
            {description}
          </div>
        )}
        <div className="pc-modal__body">{children}</div>
        {footer && <footer className="pc-modal__footer">{footer}</footer>}
      </div>
    </div>,
    document.body,
  );
}

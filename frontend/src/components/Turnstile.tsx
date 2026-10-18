import { useEffect, useRef, useState } from 'react';
import { loadTurnstile, type ChallengeAction } from '../lib/challenge';

export interface TurnstileProps {
  siteKey: string;
  action: ChallengeAction;
  /** A fresh token, or null when it expired or the check failed. */
  onToken: (token: string | null) => void;
  /** Change it to get a new token: each token can be verified only once. */
  resetSignal?: number;
}

/** Cloudflare Turnstile widget (identity threat model §6.12). */
export function Turnstile({ siteKey, action, onToken, resetSignal = 0 }: TurnstileProps) {
  const container = useRef<HTMLDivElement>(null);
  const widget = useRef<string | undefined>(undefined);
  const callback = useRef(onToken);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    callback.current = onToken;
  }, [onToken]);

  useEffect(() => {
    let active = true;
    loadTurnstile()
      .then((api) => {
        if (!active || !container.current) return;
        widget.current = api.render(container.current, {
          sitekey: siteKey,
          action,
          callback: (token) => callback.current(token),
          'expired-callback': () => callback.current(null),
          'error-callback': () => callback.current(null),
          'timeout-callback': () => callback.current(null),
        });
      })
      .catch(() => {
        if (active) setFailed(true);
      });
    return () => {
      active = false;
      if (widget.current !== undefined) window.turnstile?.remove(widget.current);
      widget.current = undefined;
    };
  }, [siteKey, action]);

  useEffect(() => {
    if (resetSignal > 0 && widget.current !== undefined) {
      callback.current(null);
      window.turnstile?.reset(widget.current);
    }
  }, [resetSignal]);

  return (
    <div className="pc-challenge">
      <div ref={container} />
      {failed && (
        <p className="pc-field__error" role="alert">
          The security check could not load. Check your connection or turn off content blockers for this page, then reload.
        </p>
      )}
    </div>
  );
}

import { useState } from 'react';
import { Alert } from './Alert';
import { Button } from './Button';
import { Checkbox } from './Checkbox';

/**
 * Recovery codes shown once, with copy and download, and a confirmation that
 * they were saved before moving on (threat model D-5, §6.13, §6.20).
 */
export function RecoveryCodesView({ codes, onDone, title = 'PaxofiCloud recovery codes', fileName = 'paxoficloud-recovery-codes.txt' }: { codes: string[]; onDone: () => void; title?: string; fileName?: string }) {
  const [saved, setSaved] = useState(false);
  const [copied, setCopied] = useState(false);
  const text = `${title}\nEach code works once.\n\n${codes.join('\n')}\n`;

  return (
    <div className="pc-stack">
      <h2>Save your recovery codes</h2>
      <Alert tone="warning">
        If you lose your phone, a recovery code is the only way back in without contacting support. Each code works once. They will not be shown again.
      </Alert>
      <ol className="pc-recovery-codes" aria-label="Recovery codes">
        {codes.map((code) => (
          <li key={code}>
            <code>{code}</code>
          </li>
        ))}
      </ol>
      <div className="pc-row">
        <Button
          variant="secondary"
          onClick={() => {
            void navigator.clipboard?.writeText(text).then(() => setCopied(true));
          }}
        >
          {copied ? 'Copied' : 'Copy codes'}
        </Button>
        <Button
          variant="secondary"
          onClick={() => {
            const url = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
            const link = document.createElement('a');
            link.href = url;
            link.download = fileName;
            link.click();
            URL.revokeObjectURL(url);
          }}
        >
          Download
        </Button>
      </div>
      <Checkbox checked={saved} onChange={(e) => setSaved(e.target.checked)} label="I have saved these codes somewhere safe" />
      <div>
        <Button disabled={!saved} onClick={onDone}>
          Done
        </Button>
      </div>
    </div>
  );
}

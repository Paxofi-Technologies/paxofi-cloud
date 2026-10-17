import { useId, type InputHTMLAttributes, type ReactNode } from 'react';

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type'> {
  label: ReactNode;
  error?: string | undefined;
}

export function Checkbox({ label, error, className, ...rest }: CheckboxProps) {
  const id = useId();
  const errorId = error ? `${id}-error` : undefined;
  return (
    <div className={['pc-check', error ? 'pc-check--invalid' : '', className ?? ''].filter(Boolean).join(' ')}>
      <input {...rest} id={id} type="checkbox" className="pc-check__input" aria-invalid={error ? true : undefined} aria-describedby={errorId} />
      <label className="pc-check__label" htmlFor={id}>
        {label}
      </label>
      {error && (
        <p id={errorId} className="pc-field__error pc-check__error">
          {error}
        </p>
      )}
    </div>
  );
}

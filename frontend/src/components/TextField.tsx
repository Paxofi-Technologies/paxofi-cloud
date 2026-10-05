import { useId, type InputHTMLAttributes, type ReactNode } from 'react';

export interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> {
  label: string;
  hint?: string;
  error?: string | undefined;
  trailing?: ReactNode;
}

export function TextField({ label, hint, error, trailing, className, ...rest }: TextFieldProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;
  return (
    <div className={['pc-field', error ? 'pc-field--invalid' : '', className ?? ''].filter(Boolean).join(' ')}>
      <label className="pc-field__label" htmlFor={id}>
        {label}
      </label>
      <div className="pc-field__control">
        <input {...rest} id={id} className="pc-input" aria-invalid={error ? true : undefined} aria-describedby={describedBy} />
        {trailing}
      </div>
      {hint && (
        <p id={hintId} className="pc-field__hint">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="pc-field__error">
          {error}
        </p>
      )}
    </div>
  );
}

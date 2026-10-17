/**
 * Client-side mirror of the API's sign-up rules (SRS IAM-001, IAM-003). The
 * server stays the authority; this only gives faster, friendlier feedback.
 */
export interface RegistrationForm {
  fullName: string;
  email: string;
  phone: string;
  password: string;
  acceptTerms: boolean;
}

export type FieldErrors = Partial<Record<keyof RegistrationForm, string>>;

export const PASSWORD_MIN = 12;
export const PASSWORD_MAX = 128;

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
// eslint-disable-next-line no-control-regex
const CONTROL = /[\u0000-\u001f\u007f-\u009f]/;

/** Accepts "+234 803 123 4567", "0803-123-4567" (Nigerian local) etc.; returns E.164 or null. */
export function normalisePhone(raw: string): string | null {
  const compact = raw.replace(/[\s().-]/g, '');
  const e164 = /^0[789][01]\d{8}$/.test(compact) ? `+234${compact.slice(1)}` : compact;
  return /^\+[1-9]\d{7,14}$/.test(e164) ? e164 : null;
}

/** Counts Unicode code points, as the API does (mb_strlen). */
export function passwordLength(password: string): number {
  return [...password].length;
}

export function validate(form: RegistrationForm): FieldErrors {
  const errors: FieldErrors = {};
  const name = form.fullName.trim().replace(/\s+/g, ' ');
  if ([...name].length < 2 || [...name].length > 100) errors.fullName = MESSAGES.full_name.invalid;
  if (!EMAIL.test(form.email.trim())) errors.email = MESSAGES.email.invalid;
  if (form.phone.trim() === '') errors.phone = MESSAGES.phone.required;
  else if (normalisePhone(form.phone) === null) errors.phone = MESSAGES.phone.invalid_e164;
  const length = passwordLength(form.password);
  if (length === 0) errors.password = MESSAGES.password.required;
  else if (CONTROL.test(form.password)) errors.password = MESSAGES.password.invalid_characters;
  else if (length < PASSWORD_MIN) errors.password = MESSAGES.password.too_short;
  else if (length > PASSWORD_MAX) errors.password = MESSAGES.password.too_long;
  if (!form.acceptTerms) errors.acceptTerms = MESSAGES.accept_terms.must_accept;
  return errors;
}

/** The JSON the API expects: exactly these five fields (T-04). */
export function toRequest(form: RegistrationForm): Record<string, unknown> {
  return {
    full_name: form.fullName.trim().replace(/\s+/g, ' '),
    email: form.email.trim(),
    phone: normalisePhone(form.phone) ?? form.phone.trim(),
    password: form.password,
    accept_terms: form.acceptTerms,
  };
}

const MESSAGES = {
  full_name: { required: 'Enter your full name.', invalid: 'Enter your full name (2 to 100 characters).' },
  email: { required: 'Enter your email address.', invalid: 'Enter a valid email address.' },
  phone: { required: 'Enter your phone number.', invalid_e164: 'Enter a valid phone number, e.g. 0803 123 4567 or +234 803 123 4567.' },
  password: {
    required: 'Choose a password.',
    too_short: `Use at least ${PASSWORD_MIN} characters. A short phrase works well.`,
    too_long: `Use ${PASSWORD_MAX} characters or fewer.`,
    invalid_characters: 'Your password contains characters that cannot be used.',
  },
  accept_terms: { must_accept: 'Please accept the Terms of Service and Privacy Policy to continue.' },
} as const;

const FIELD_FOR: Record<string, keyof RegistrationForm> = {
  full_name: 'fullName',
  email: 'email',
  phone: 'phone',
  password: 'password',
  accept_terms: 'acceptTerms',
};

/** Maps the API's field codes to messages; unknown codes get a generic line. */
export function fromServerErrors(errors: Record<string, string>): { fields: FieldErrors; other: boolean } {
  const fields: FieldErrors = {};
  let other = false;
  for (const [field, code] of Object.entries(errors)) {
    const key = FIELD_FOR[field];
    const messages = MESSAGES[field as keyof typeof MESSAGES] as Record<string, string> | undefined;
    if (key === undefined || messages === undefined) {
      other = true;
      continue;
    }
    fields[key] = messages[code] ?? 'Please check this field.';
  }
  return { fields, other };
}

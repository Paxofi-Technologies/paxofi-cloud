import { describe, expect, it } from 'vitest';
import { fromServerErrors, normalisePhone, passwordLength, toRequest, validate, type RegistrationForm } from './registration';

const VALID: RegistrationForm = { fullName: 'Ada Okafor', email: 'ada@example.ng', phone: '0803 123 4567', password: 'correct horse battery', acceptTerms: true };

describe('registration rules (IAM-001, IAM-003)', () => {
  it('accepts a valid form', () => {
    expect(validate(VALID)).toEqual({});
  });

  it.each([
    ['0803 123 4567', '+2348031234567'],
    ['0703-123-4567', '+2347031234567'],
    ['+234 (803) 123 4567', '+2348031234567'],
    ['+44 20 7946 0958', '+442079460958'],
    ['08031234', null],
    ['2348031234567', null],
    ['+0123456789', null],
  ])('normalises phone %s', (raw, expected) => {
    expect(normalisePhone(raw)).toBe(expected);
  });

  it('enforces 12 to 128 characters counted like the server, with no composition rules', () => {
    expect(validate({ ...VALID, password: 'a'.repeat(11) }).password).toMatch(/at least 12/);
    expect(validate({ ...VALID, password: 'a'.repeat(12) }).password).toBeUndefined();
    expect(validate({ ...VALID, password: '🐴'.repeat(12) }).password).toBeUndefined();
    expect(passwordLength('🐴'.repeat(12))).toBe(12);
    expect(validate({ ...VALID, password: 'a'.repeat(129) }).password).toMatch(/128/);
    expect(validate({ ...VALID, password: 'abcdefghijkl\u0000' }).password).toMatch(/cannot be used/);
  });

  it('requires the terms to be accepted', () => {
    expect(validate({ ...VALID, acceptTerms: false }).acceptTerms).toMatch(/Terms of Service/);
  });

  it('sends exactly the five API fields with a normalised phone', () => {
    expect(toRequest({ ...VALID, fullName: '  Ada   Okafor ' })).toEqual({
      full_name: 'Ada Okafor',
      email: 'ada@example.ng',
      phone: '+2348031234567',
      password: 'correct horse battery',
      accept_terms: true,
    });
  });

  it('maps server codes to messages and flags anything unknown', () => {
    expect(fromServerErrors({ phone: 'invalid_e164', password: 'too_short' }).fields).toEqual({
      phone: expect.stringMatching(/valid phone number/) as unknown as string,
      password: expect.stringMatching(/at least 12/) as unknown as string,
    });
    expect(fromServerErrors({ role: 'unknown_field' })).toEqual({ fields: {}, other: true });
  });
});

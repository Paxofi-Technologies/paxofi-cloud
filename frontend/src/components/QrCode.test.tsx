import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { QrCode } from './QrCode';

describe('QrCode', () => {
  it('draws the code as SVG paths with an accessible name, without injected markup', () => {
    const { container } = render(<QrCode value="otpauth://totp/PaxofiCloud:a?secret=GEZDGNBV" label="QR code" />);

    const svg = screen.getByRole('img', { name: 'QR code' });
    expect(svg.tagName.toLowerCase()).toBe('svg');
    expect(svg.querySelector('path')?.getAttribute('d')).toMatch(/^M\d+ \d+h1v1h-1z/);
    expect(container.innerHTML).not.toContain('<script');
  });
});

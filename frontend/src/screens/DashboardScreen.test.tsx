import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DashboardScreen } from './DashboardScreen';

describe('DashboardScreen', () => {
  it('puts the services table in a horizontal scroll box, so it never widens the page on a phone', () => {
    render(<DashboardScreen onFindDomain={() => undefined} />);

    const table = screen.getByRole('table');
    expect(table.parentElement).toHaveClass('pc-table-scroll');
  });
});

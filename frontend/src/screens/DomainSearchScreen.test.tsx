import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { formatMoney } from '../lib/money';
import { samplePrices } from '../mocks/sample';
import { DomainSearchScreen } from './DomainSearchScreen';

describe('DomainSearchScreen', () => {
  it('rejects invalid names with an accessible error', async () => {
    render(<DomainSearchScreen />);
    await userEvent.type(screen.getByLabelText('Domain name'), '-bad-');
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    expect(screen.getByLabelText('Domain name')).toHaveAttribute('aria-invalid', 'true');
    expect(screen.queryByRole('heading', { name: 'Results' })).not.toBeInTheDocument();
  });

  it('lists the exact extension first, then every launch extension', async () => {
    render(<DomainSearchScreen />);
    await userEvent.type(screen.getByLabelText('Domain name'), 'okafordesigns.ng');
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    const items = within(screen.getByRole('list')).getAllByRole('listitem');
    expect(items).toHaveLength(6);
    expect(items[0]).toHaveTextContent('okafordesigns.ng');
  });

  it('keeps cart items and the total across searches', async () => {
    render(<DomainSearchScreen />);
    const input = screen.getByLabelText('Domain name');
    await userEvent.type(input, 'okafordesigns.com.ng');
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add okafordesigns.com.ng to cart' }));
    expect(screen.getByRole('status')).toHaveTextContent(`1 domain in cart · ${formatMoney(samplePrices['com.ng'])}`);

    await userEvent.clear(input);
    await userEvent.type(input, 'lagosbakery.ng');
    await userEvent.click(screen.getByRole('button', { name: 'Search' }));
    await userEvent.click(screen.getByRole('button', { name: 'Add lagosbakery.ng to cart' }));
    const total = { minor: samplePrices['com.ng'].minor + samplePrices.ng.minor, currency: 'NGN' as const };
    expect(screen.getByRole('status')).toHaveTextContent(`2 domains in cart · ${formatMoney(total)}`);

    await userEvent.click(screen.getByRole('button', { name: 'Remove lagosbakery.ng from cart' }));
    expect(screen.getByRole('status')).toHaveTextContent('1 domain in cart');
  });
});

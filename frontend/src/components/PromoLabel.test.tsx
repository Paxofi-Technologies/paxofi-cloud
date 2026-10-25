import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PromoLabel } from './PromoLabel';

describe('PromoLabel', () => {
  it('shows HOT and PROMO in red, SALE in orange, NEW in green', () => {
    render(
      <>
        <PromoLabel label="hot" />
        <PromoLabel label="promo" />
        <PromoLabel label="sale" />
        <PromoLabel label="new" />
      </>,
    );
    expect(screen.getByText('HOT')).toHaveClass('pc-promo--hot');
    expect(screen.getByText('PROMO')).toHaveClass('pc-promo--promo');
    expect(screen.getByText('SALE')).toHaveClass('pc-promo--sale');
    expect(screen.getByText('NEW')).toHaveClass('pc-promo--new');
  });

  it('shows nothing for no label or an unknown one', () => {
    const { container } = render(
      <>
        <PromoLabel label="none" />
        <PromoLabel label="<b>x</b>" />
      </>,
    );
    expect(container).toBeEmptyDOMElement();
  });
});

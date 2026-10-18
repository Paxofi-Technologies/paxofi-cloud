import { act, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fakeTurnstile } from '../test/turnstile';
import { Turnstile } from './Turnstile';

let fake: ReturnType<typeof fakeTurnstile>;
afterEach(() => fake.uninstall());

describe('Turnstile widget (§6.12)', () => {
  it('renders with the site key and the form action, and forwards tokens', async () => {
    fake = fakeTurnstile();
    const onToken = vi.fn();
    render(<Turnstile siteKey="0x4AAAAAAAsite" action="register" onToken={onToken} />);

    await vi.waitFor(() => expect(fake.api.render).toHaveBeenCalledOnce());
    expect(fake.rendered[0]).toMatchObject({ sitekey: '0x4AAAAAAAsite', action: 'register' });
    act(() => fake.solve('tok-1'));
    expect(onToken).toHaveBeenLastCalledWith('tok-1');
    act(() => fake.rendered[0]?.['expired-callback']());
    expect(onToken).toHaveBeenLastCalledWith(null);
  });

  it('resets for a fresh token on request and removes itself on unmount', async () => {
    fake = fakeTurnstile();
    const onToken = vi.fn();
    const { rerender, unmount } = render(<Turnstile siteKey="0x4AAAAAAAsite" action="sign_in" onToken={onToken} />);
    await vi.waitFor(() => expect(fake.api.render).toHaveBeenCalledOnce());

    rerender(<Turnstile siteKey="0x4AAAAAAAsite" action="sign_in" onToken={onToken} resetSignal={1} />);
    expect(fake.api.reset).toHaveBeenCalledWith('widget-1');
    expect(onToken).toHaveBeenLastCalledWith(null);
    unmount();
    expect(fake.api.remove).toHaveBeenCalledWith('widget-1');
  });
});

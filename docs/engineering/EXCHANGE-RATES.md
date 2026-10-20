# Exchange rates runbook

How PaxofiCloud gets the exchange rates it uses to work out naira and dollar
prices from provider costs. SRS PRC-013; commerce threat model CD-16, C-27.

## Source

- **ExchangeRate-API open access** (`https://open.er-api.com/v6/latest/usd`):
  free, no key, updated once a day, covers NGN, USD and EUR.
- **Attribution required:** the public site must credit ExchangeRate-API (see
  their terms). This is added with the public website (Sprint 10).
- The source sits behind the `FxRateSource` port, so a paid source can be added
  later without touching pricing code.

## Commands

| Command | What it does | Exit code |
|---|---|---|
| `bin/paxoficloud fx:update` | Fetches today's rates, checks them, stores the set | 0 accepted · 2 held for Finance · 1 source unavailable |
| `bin/paxoficloud fx:status` | Prints the last five sets with verdict, source, publish time and rates | 0 |

`fx:update` runs in the worker, never in the web tier. Its daily schedule
arrives with the provider sync worker; until then, run it by hand.

## Safety rules (defaults)

| Rule | Default | Effect |
|---|---|---|
| Hold | a move of more than 5% against the last accepted set, in any currency | The set is stored as `held` and never used. Finance confirms or overrides it (admin console, coming with the pricing screens). |
| Repricing hysteresis | more than 3% since the rate a price was derived from | Only then are converted prices recomputed |
| Staleness | no accepted set for 72 hours | Automatic repricing pauses and staff are alerted; stored prices keep selling |

Rates are **never** used at checkout. A customer always pays the stored price in
their billing currency.

## Storage

- `fx_rate_sets` holds one row per fetch: source, verdict, publish time and fetch time.
- `fx_rate_values` holds one row per currency in a set, as a decimal string. There are no floats anywhere.
- Both tables are append-only for the runtime database user. A confirmation or
  override is recorded as a new set, never as an edit.

## When the source is unavailable

`fx:update` exits 1 and logs `fx.update.unavailable` with a fixed reason, such as
`transport`, `http_503`, `rate_ngn_missing` or `unexpected_document`. Nothing is
stored, and the last accepted set stays in use until it is 72 hours old.

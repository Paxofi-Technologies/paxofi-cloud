import { Alert, Badge, Button, Card } from '../components';
import { formatMoney } from '../lib/money';
import { sampleBalance, sampleCustomer, sampleNextInvoice, sampleServices } from '../mocks/sample';

export interface DashboardScreenProps {
  onFindDomain: () => void;
}

export function DashboardScreen({ onFindDomain }: DashboardScreenProps) {
  const firstName = sampleCustomer.name.split(' ')[0] ?? sampleCustomer.name;
  const counts = {
    domains: sampleServices.filter((s) => s.kind === 'Domain').length,
    hosting: sampleServices.filter((s) => s.kind === 'Hosting').length,
    vps: sampleServices.filter((s) => s.kind === 'VPS').length,
  };

  return (
    <div className="pc-stack">
      <div className="pc-page-header">
        <div>
          <h1>Welcome back, {firstName}</h1>
          <p className="pc-muted">Here is everything running on your account.</p>
        </div>
        <Button onClick={onFindDomain}>Register a domain</Button>
      </div>

      {!sampleCustomer.mfaEnrolled && (
        <Alert tone="warning" title="Protect your account" action={<Button size="sm" variant="secondary">Turn on two-step verification</Button>}>
          Your domains and servers are only as safe as your password. Two-step verification takes two minutes to set up.
        </Alert>
      )}

      <div className="pc-stats" role="list">
        <Stat label="Domains" value={String(counts.domains)} />
        <Stat label="Hosting plans" value={String(counts.hosting)} />
        <Stat label="Cloud servers" value={String(counts.vps)} />
        <Stat label="Account credit" value={formatMoney(sampleBalance)} />
      </div>

      <div className="pc-grid-2">
        <Card title="Your services" actions={<Button variant="ghost" size="sm">View all</Button>}>
          <table className="pc-table">
            <thead>
              <tr>
                <th scope="col">Service</th>
                <th scope="col">Type</th>
                <th scope="col">Status</th>
                <th scope="col">Renews</th>
              </tr>
            </thead>
            <tbody>
              {sampleServices.map((service) => (
                <tr key={service.name}>
                  <td className="pc-table__primary">{service.name}</td>
                  <td>{service.kind}</td>
                  <td>
                    <Badge tone={service.status.tone}>{service.status.label}</Badge>
                  </td>
                  <td>{service.renews}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>

        <Card title="Next invoice">
          <p className="pc-amount">{formatMoney(sampleNextInvoice.amount)}</p>
          <p className="pc-muted">Due {sampleNextInvoice.due} · includes VAT</p>
          <div className="pc-card__footer">
            <Button variant="secondary" block>
              Pay now
            </Button>
            <p className="pc-small pc-muted">Card payments are handled securely by Paystack or Flutterwave. We never see your card number.</p>
          </div>
        </Card>
      </div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="pc-stat" role="listitem">
      <p className="pc-stat__label">{label}</p>
      <p className="pc-stat__value">{value}</p>
    </div>
  );
}

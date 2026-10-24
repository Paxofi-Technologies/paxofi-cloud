<?php

declare(strict_types=1);

namespace PaxofiCloud\Application\Registrar;

/**
 * Everything PaxofiCloud can ask a registrar to do. Each action is either a
 * read or a *write*: a write spends money or changes a customer's domain and
 * must pass the RegistrarWriteGuard before any provider call
 * (THREAT-MODEL-DOMAIN-REGISTRAR.md, DR-4).
 */
enum RegistrarAction: string
{
    case PriceList = 'price_list';
    case Availability = 'availability';
    case AccountBalance = 'account_balance';
    case DomainDetails = 'domain_details';

    case Register = 'register';
    case Renew = 'renew';
    case Transfer = 'transfer';
    case Restore = 'restore';
    case NameServers = 'name_servers';
    case ChildNameServers = 'child_name_servers';
    case Contacts = 'contacts';
    case TransferLock = 'transfer_lock';
    case Privacy = 'privacy';
    case DnsZone = 'dns_zone';
    case Forwarding = 'forwarding';

    public function isWrite(): bool
    {
        return match ($this) {
            self::PriceList, self::Availability, self::AccountBalance, self::DomainDetails => false,
            default => true,
        };
    }
}

<?php

declare(strict_types=1);

namespace PaxofiCloud\Integration\Registrar\DomainNameApi;

use PaxofiCloud\Application\Registrar\RegistrarAction;

/**
 * Every DomainNameAPI REST call this adapter may make, with its fixed method
 * and path and the registrar action it performs. A request can only be built
 * from one of these, so a write can never be sent under a read's name
 * (THREAT-MODEL-DOMAIN-REGISTRAR.md §2, DR-4).
 */
enum DomainNameApiOperation
{
    case TldList;
    case BulkSearch;
    case AccountBalance;
    case DomainInfo;
    case RegisterWithContacts;
    case Renew;
    case Transfer;
    case NameServers;
    case ChildNameServerAdd;
    case ChildNameServerUpdate;
    case ChildNameServerDelete;
    case Contacts;
    case Lock;
    case Unlock;
    case Privacy;

    /** @return array{0: 'GET'|'POST'|'PUT'|'DELETE', 1: string, 2: RegistrarAction} */
    public function spec(): array
    {
        return match ($this) {
            self::TldList => ['GET', '/products/tlds', RegistrarAction::PriceList],
            self::BulkSearch => ['POST', '/domains/bulk-search', RegistrarAction::Availability],
            self::AccountBalance => ['GET', '/deposit/accounts/me', RegistrarAction::AccountBalance],
            self::DomainInfo => ['GET', '/domains/info', RegistrarAction::DomainDetails],
            self::RegisterWithContacts => ['POST', '/domains/register-with-contacts', RegistrarAction::Register],
            self::Renew => ['POST', '/domains/renew', RegistrarAction::Renew],
            self::Transfer => ['POST', '/domains/transfer', RegistrarAction::Transfer],
            self::NameServers => ['PUT', '/domains/dns/name-server', RegistrarAction::NameServers],
            self::ChildNameServerAdd => ['POST', '/domains/dns/host', RegistrarAction::ChildNameServers],
            self::ChildNameServerUpdate => ['PUT', '/domains/dns/host', RegistrarAction::ChildNameServers],
            self::ChildNameServerDelete => ['DELETE', '/domains/dns/host', RegistrarAction::ChildNameServers],
            self::Contacts => ['PUT', '/domains/contacts/update', RegistrarAction::Contacts],
            self::Lock => ['POST', '/domains/lock', RegistrarAction::TransferLock],
            self::Unlock => ['POST', '/domains/unlock', RegistrarAction::TransferLock],
            self::Privacy => ['POST', '/domains/privacy', RegistrarAction::Privacy],
        };
    }

    public function action(): RegistrarAction
    {
        return $this->spec()[2];
    }
}

<?php

declare(strict_types=1);

namespace PaxofiCloud\Domain\Staff;

/**
 * One staff capability from SRS §2.2 (ACC-005; threat model §6.16, T-32).
 * Values are stable: audit events and alerts key on them. There is no
 * wildcard and no "admin" permission.
 */
enum Permission: string
{
    case CustomerView = 'customer.view';
    case CustomerViewAs = 'customer.view_as';
    case TicketReply = 'ticket.reply';
    case RefundIssue = 'billing.refund.issue';
    case RefundApprove = 'billing.refund.approve';
    case ProvisioningJobManage = 'provisioning.job.manage';
    case ServiceSuspendBilling = 'service.suspend.billing';
    case ServiceSuspendOperations = 'service.suspend.operations';
    case ServiceSuspendAbuse = 'service.suspend.abuse';
    case ServiceTerminate = 'service.terminate';
    case CatalogueManage = 'catalogue.manage';
    case CatalogueView = 'catalogue.view';
    case ReportsView = 'reports.view';
    case StaffManage = 'staff.manage';
    case ProviderCredentialsManage = 'provider_credentials.manage';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';
    case AuditViewOwn = 'audit.view_own';
}

<?php

namespace App\Modules\Auth\Permissions;

use App\Modules\Shared\Scoping\ScopeLevel;

/**
 * The standard roles of spec §4, seeded with `is_system = true`. They cannot
 * be edited or deleted; HQ creates extra roles for anything else.
 */
enum SystemRole: string
{
    case SuperAdmin = 'Super Admin';
    case HqOperations = 'HQ Operations';
    case HqFinance = 'HQ Finance';
    case FranchiseManager = 'Franchise Manager';
    case RegionalManager = 'Regional Manager';
    case FranchiseOwner = 'Franchise Owner';
    case BranchAdmin = 'Branch Admin';
    case FrontDesk = 'Front Desk';
    case Phlebotomist = 'Phlebotomist';
    case LogisticsRunner = 'Logistics Runner';
    case LabTechnician = 'Lab Technician';
    case LabSupervisor = 'Lab Supervisor';
    case Signatory = 'Signatory';
    case B2bClientUser = 'B2B Client User';

    public function scopeLevel(): ScopeLevel
    {
        return match ($this) {
            self::SuperAdmin, self::HqOperations, self::HqFinance, self::FranchiseManager => ScopeLevel::Organization,
            self::RegionalManager => ScopeLevel::Region,
            self::FranchiseOwner => ScopeLevel::Franchise,
            self::B2bClientUser => ScopeLevel::B2bClient,
            default => ScopeLevel::Branch,
        };
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => $permission->isAllowedFor(ScopeLevel::Organization),
            )),
            self::HqOperations => [
                Permission::ManageBranches, Permission::ManageRouting, Permission::ViewAllReports,
                Permission::ManageCatalog, Permission::ViewBranches, Permission::ViewAudit,
            ],
            self::HqFinance => [
                Permission::ManagePriceLists, Permission::ApproveSettlement, Permission::PostLedgerAdjustment,
                Permission::ViewAllInvoices, Permission::ApproveRefund, Permission::ViewLedger,
            ],
            self::FranchiseManager => [
                Permission::OnboardFranchise, Permission::VerifyKyc, Permission::ApproveAgreement,
                Permission::SuspendFranchise, Permission::ViewBranches,
            ],
            self::RegionalManager => [
                Permission::ViewRegionDashboard, Permission::ViewBranches, Permission::ViewReports,
            ],
            self::FranchiseOwner => [
                Permission::ViewFranchiseDashboard, Permission::ViewLedger, Permission::TopupWallet,
                Permission::ManageFranchiseStaff, Permission::ViewAudit,
            ],
            self::BranchAdmin => [
                Permission::ManageBranchStaff, Permission::ViewBranchReports, Permission::ManageInventory,
                Permission::ViewBranchDashboard, Permission::ManageHomeCollection, Permission::ApproveDiscount,
                Permission::ApproveRefund,
                // Branch admins also work the desk at small collection centres.
                Permission::RegisterPatient, Permission::CreateOrder, Permission::CollectPayment,
            ],
            self::FrontDesk => [
                Permission::RegisterPatient, Permission::CreateOrder, Permission::CollectPayment, Permission::PrintBarcode,
            ],
            self::Phlebotomist => [Permission::ViewAssignedCollections, Permission::MarkCollected],
            self::LogisticsRunner => [Permission::CreateManifest, Permission::ReceiveManifest],
            self::LabTechnician => [Permission::AccessionSample, Permission::RejectSample, Permission::EnterResults],
            self::LabSupervisor => [Permission::VerifyResults, Permission::RerunTest],
            self::Signatory => [Permission::SignReport, Permission::AmendReport, Permission::ReleaseReport],
            self::B2bClientUser => [Permission::CreateB2bOrder, Permission::ViewClientReports, Permission::ViewClientLedger],
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Everything in the organization, including roles and settings.',
            self::HqOperations => 'Branches, routing, catalogue and network-wide reports.',
            self::HqFinance => 'Price lists, settlements, ledger adjustments and invoices.',
            self::FranchiseManager => 'Franchise onboarding, KYC, agreements and suspension.',
            self::RegionalManager => 'Read-only oversight of one region.',
            self::FranchiseOwner => 'The franchise dashboard, ledger, wallet and staff.',
            self::BranchAdmin => 'Branch staff, inventory and home collections.',
            self::FrontDesk => 'Patient registration, orders, payments and barcodes.',
            self::Phlebotomist => 'Assigned home collections.',
            self::LogisticsRunner => 'Sample manifests.',
            self::LabTechnician => 'Sample accession, rejection and result entry.',
            self::LabSupervisor => 'Result verification and reruns.',
            self::Signatory => 'Signing and releasing reports for their department.',
            self::B2bClientUser => 'B2B orders, reports and ledger for one client.',
        };
    }
}

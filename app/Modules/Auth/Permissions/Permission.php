<?php

namespace App\Modules\Auth\Permissions;

use App\Modules\Shared\Scoping\ScopeLevel;

/**
 * Every permission the system checks (spec §4 and §8). The `permissions`
 * table is seeded from this enum; code always refers to these cases.
 *
 * Each permission states which scope levels may hold it, so a role can never
 * hold a permission above its scope (spec §4: a branch role cannot get
 * approve_settlement).
 */
enum Permission: string
{
    // Head office administration
    case ManageOrganization = 'manage_organization';
    case ManageRoles = 'manage_roles';
    case ManageBranches = 'manage_branches';
    case ManageRouting = 'manage_routing';
    case ManageCatalog = 'manage_catalog';
    case ManagePriceLists = 'manage_price_lists';
    case ManageSignatories = 'manage_signatories';
    case ManageNotifications = 'manage_notifications';
    case ManageB2b = 'manage_b2b';
    case ViewAllReports = 'view_all_reports';
    case ViewAllInvoices = 'view_all_invoices';
    case ViewHqDashboard = 'view_hq_dashboard';

    // Money
    case ApproveSettlement = 'approve_settlement';
    case PostLedgerAdjustment = 'post_ledger_adjustment';
    case ApproveRefund = 'approve_refund';
    case ExportAccounts = 'export_accounts';

    // Franchise management
    case OnboardFranchise = 'onboard_franchise';
    case VerifyKyc = 'verify_kyc';
    case ApproveAgreement = 'approve_agreement';
    case SuspendFranchise = 'suspend_franchise';

    // Regional and franchise oversight
    case ViewRegionDashboard = 'view_region_dashboard';
    case ViewBranches = 'view_branches';
    case ViewReports = 'view_reports';
    case ViewFranchiseDashboard = 'view_franchise_dashboard';
    case ViewLedger = 'view_ledger';
    case TopupWallet = 'topup_wallet';
    case ViewAudit = 'view_audit';

    // Staff management
    case ManageStaff = 'manage_staff';
    case ManageFranchiseStaff = 'manage_franchise_staff';
    case ManageBranchStaff = 'manage_branch_staff';

    // Branch operations
    case ViewBranchDashboard = 'view_branch_dashboard';
    case ViewBranchReports = 'view_branch_reports';
    case ManageInventory = 'manage_inventory';
    case RegisterPatient = 'register_patient';
    case CreateOrder = 'create_order';
    case CollectPayment = 'collect_payment';
    case ApproveDiscount = 'approve_discount';
    case PrintBarcode = 'print_barcode';
    case ManageHomeCollection = 'manage_home_collection';
    case ViewAssignedCollections = 'view_assigned_collections';
    case MarkCollected = 'mark_collected';
    case CreateManifest = 'create_manifest';
    case ReceiveManifest = 'receive_manifest';

    // Lab and signing
    case AccessionSample = 'accession_sample';
    case RejectSample = 'reject_sample';
    case EnterResults = 'enter_results';
    case VerifyResults = 'verify_results';
    case RerunTest = 'rerun_test';
    case SignReport = 'sign_report';
    case AmendReport = 'amend_report';
    case ReleaseReport = 'release_report';

    // B2B client portal
    case CreateB2bOrder = 'create_b2b_order';
    case ViewClientReports = 'view_client_reports';
    case ViewClientLedger = 'view_client_ledger';

    /** @return list<ScopeLevel> */
    public function allowedScopeLevels(): array
    {
        $organizationOnly = [ScopeLevel::Organization];
        $regionAndUp = [ScopeLevel::Organization, ScopeLevel::Region];
        $franchiseAndUp = [ScopeLevel::Organization, ScopeLevel::Region, ScopeLevel::Franchise];
        $branchAndUp = [ScopeLevel::Organization, ScopeLevel::Region, ScopeLevel::Franchise, ScopeLevel::Branch];

        return match ($this) {
            self::ManageOrganization, self::ManageRoles, self::ManageBranches, self::ManageRouting,
            self::ManageCatalog, self::ManagePriceLists, self::ManageSignatories, self::ManageNotifications,
            self::ManageB2b, self::ViewAllReports, self::ViewAllInvoices, self::ViewHqDashboard,
            self::ApproveSettlement, self::PostLedgerAdjustment, self::ExportAccounts => $organizationOnly,

            self::OnboardFranchise, self::VerifyKyc, self::ApproveAgreement, self::SuspendFranchise,
            self::ViewRegionDashboard, self::ViewBranches, self::ViewReports, self::ManageStaff => $regionAndUp,

            self::ViewFranchiseDashboard, self::ViewLedger, self::TopupWallet,
            self::ManageFranchiseStaff => $franchiseAndUp,

            self::CreateB2bOrder, self::ViewClientReports, self::ViewClientLedger => [ScopeLevel::B2bClient],

            default => $branchAndUp,
        };
    }

    public function isAllowedFor(ScopeLevel $scopeLevel): bool
    {
        return in_array($scopeLevel, $this->allowedScopeLevels(), true);
    }

    /**
     * Roles holding any of these should use MFA (spec §10.4: Super Admin, HQ
     * Finance, Franchise Manager and every signatory). Sign-in does not force
     * it, so apps use the flag to prompt; signing a report still needs MFA.
     */
    public function requiresMfa(): bool
    {
        return in_array($this, [
            self::ManageRoles, self::ManageOrganization,
            self::ApproveSettlement, self::PostLedgerAdjustment,
            self::OnboardFranchise, self::ApproveAgreement,
            self::SignReport, self::AmendReport,
        ], true);
    }

    public function module(): string
    {
        return match ($this) {
            self::ManageOrganization, self::ManageRoles, self::ManageStaff, self::ManageFranchiseStaff,
            self::ManageBranchStaff, self::ViewAudit => 'auth',
            self::ManageBranches, self::ViewBranches, self::OnboardFranchise, self::VerifyKyc,
            self::ApproveAgreement, self::SuspendFranchise, self::ManageB2b, self::ManageSignatories => 'network',
            self::ManageRouting, self::ManageCatalog, self::ManagePriceLists => 'catalogue',
            self::RegisterPatient, self::CreateOrder, self::CollectPayment, self::ApproveRefund, self::ApproveDiscount,
            self::ManageHomeCollection, self::ViewAssignedCollections, self::CreateB2bOrder,
            self::ViewAllInvoices => 'booking',
            self::PrintBarcode, self::MarkCollected, self::CreateManifest, self::ReceiveManifest,
            self::ManageInventory, self::AccessionSample, self::RejectSample => 'samples',
            self::EnterResults, self::VerifyResults, self::RerunTest, self::SignReport, self::AmendReport,
            self::ReleaseReport, self::ViewAllReports, self::ViewReports, self::ViewBranchReports,
            self::ViewClientReports => 'lab',
            self::ApproveSettlement, self::PostLedgerAdjustment, self::ViewLedger, self::TopupWallet,
            self::ViewClientLedger, self::ExportAccounts => 'ledger',
            self::ManageNotifications => 'shared',
            self::ViewHqDashboard, self::ViewRegionDashboard, self::ViewFranchiseDashboard,
            self::ViewBranchDashboard => 'dashboards',
        };
    }

    public function description(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value)).'.';
    }

    /**
     * @param  list<string>  $names
     * @return list<string> names that are not permissions
     */
    public static function unknownNames(array $names): array
    {
        return array_values(array_filter($names, fn (string $name): bool => self::tryFrom($name) === null));
    }
}

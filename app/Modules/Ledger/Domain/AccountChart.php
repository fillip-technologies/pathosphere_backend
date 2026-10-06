<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\LedgerEntryType;
use LogicException;

/**
 * Which account in the company's books each kind of money goes to, from
 * `pathology.accounting` (set up with the CA; names must match the ledgers
 * in Tally or the accounts in Zoho). Names may use {branch_code}, {code} and
 * {name} placeholders.
 */
final class AccountChart
{
    /**
     * @param  array{
     *     accounts: array<string, array{name: string, group: string}>,
     *     money_accounts: array<string, array{name: string, group: string}>,
     *     partner_entry_accounts: array<string, array{name: string, group: string}>,
     * }  $config
     */
    public function __construct(private readonly array $config) {}

    /** Walk-in patients of one branch, as one customer account. */
    public function patients(string $branchCode): BookAccount
    {
        return $this->account($this->config['accounts'], 'patients', ['branch_code' => $branchCode], party: true);
    }

    public function b2bClient(string $code, string $name): BookAccount
    {
        return $this->account($this->config['accounts'], 'b2b_client', ['code' => $code, 'name' => $name], party: true);
    }

    public function franchise(string $code, string $name): BookAccount
    {
        return $this->account($this->config['accounts'], 'franchise', ['code' => $code, 'name' => $name], party: true);
    }

    /** Online payments for franchise patients, held until finance allocates them. */
    public function franchiseOnlineCollections(): BookAccount
    {
        return $this->account($this->config['accounts'], 'franchise_online_collections');
    }

    public function sales(): BookAccount
    {
        return $this->account($this->config['accounts'], 'sales');
    }

    public function discount(): BookAccount
    {
        return $this->account($this->config['accounts'], 'discount');
    }

    public function outputTax(): BookAccount
    {
        return $this->account($this->config['accounts'], 'output_tax');
    }

    /** Where money paid in this mode is received (a branch's cash, the collection bank account…). */
    public function money(string $paymentMode, string $branchCode): BookAccount
    {
        return $this->account($this->config['money_accounts'], $paymentMode, ['branch_code' => $branchCode]);
    }

    /** The other side of a franchise account movement of this type. */
    public function partnerEntry(LedgerEntryType $type): BookAccount
    {
        return $this->account($this->config['partner_entry_accounts'], $type->value);
    }

    /**
     * @param  array<string, array{name: string, group: string}>  $accounts
     * @param  array<string, string>  $placeholders
     */
    private function account(array $accounts, string $key, array $placeholders = [], bool $party = false): BookAccount
    {
        $account = $accounts[$key] ?? throw new LogicException("No account is configured for {$key} (pathology.accounting).");
        $replacements = [];
        foreach ($placeholders as $placeholder => $value) {
            $replacements['{'.$placeholder.'}'] = $value;
        }

        return new BookAccount(strtr($account['name'], $replacements), $account['group'], $party);
    }
}

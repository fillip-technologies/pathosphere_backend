<?php

namespace App\Modules\Catalogue\Services;

use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Catalogue\Errors\CatalogueError;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\PriceListItem;
use App\Modules\Network\Services\NetworkDirectory;
use App\Modules\Shared\Audit\AuditLogger;
use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Price lists and their items (spec §7.4). Prices are copied onto orders at
 * booking, so changing a list never changes past orders.
 */
final class PriceListService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly NetworkDirectory $network,
    ) {}

    /** @param  array<string, mixed>  $attributes */
    public function create(string $organizationId, array $attributes): PriceList
    {
        $this->assertDefaultIsMrp($attributes);

        return DB::transaction(function () use ($organizationId, $attributes): PriceList {
            if ($attributes['is_default_mrp'] ?? false) {
                $this->clearDefaultMrp();
            }

            $priceList = new PriceList($attributes);
            $priceList->organization_id = $organizationId;
            $priceList->save();
            $this->auditLogger->recordCreated('price_list.create', $priceList);

            return $priceList;
        });
    }

    /**
     * The list type is fixed at creation: switching an MRP list to a partner
     * list would silently re-price every branch using it.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(PriceList $priceList, array $changes): PriceList
    {
        $this->assertDefaultIsMrp($changes + ['list_type' => $priceList->list_type->value]);

        return DB::transaction(function () use ($priceList, $changes): PriceList {
            if (($changes['is_default_mrp'] ?? false) && ! $priceList->is_default_mrp) {
                $this->clearDefaultMrp();
            }

            $priceList->fill($changes)->save();
            $this->auditLogger->recordChanges('price_list.update', $priceList);

            return $priceList;
        });
    }

    public function delete(PriceList $priceList): void
    {
        if ($priceList->is_default_mrp || $this->network->priceListIsAssigned($priceList->id)) {
            throw CatalogueError::inUse('price list');
        }

        DB::transaction(function () use ($priceList): void {
            $priceList->delete();
            $this->auditLogger->record('price_list.delete', $priceList);
        });
    }

    /**
     * Replaces every item on the list (PUT semantics).
     *
     * @param  list<array{test_id?: string|null, package_id?: string|null, price: string}>  $items
     */
    public function replaceItems(PriceList $priceList, array $items): int
    {
        $this->assertItemsExist($items);

        return DB::transaction(function () use ($priceList, $items): int {
            $priceList->items()->delete();
            $this->insertItems($priceList, $items);
            $priceList->touch();
            $this->auditLogger->record('price_list.items_replace', $priceList, [], ['item_count' => count($items)]);

            return count($items);
        });
    }

    /**
     * Adds or updates prices from a CSV with the header `item_type,code,price`
     * (item_type is `test` or `package`). All rows are checked first; if any
     * row is wrong nothing is saved.
     *
     * @return array{created: int, updated: int}
     */
    public function importCsv(PriceList $priceList, string $csv): array
    {
        $rows = $this->parseCsv($csv);

        return DB::transaction(function () use ($priceList, $rows): array {
            $created = 0;
            $updated = 0;

            foreach ($rows as $row) {
                $key = $row['test_id'] !== null ? ['test_id' => $row['test_id']] : ['package_id' => $row['package_id']];
                $item = PriceListItem::query()->where('price_list_id', $priceList->id)->where($key)->first();

                if ($item === null) {
                    $this->insertItems($priceList, [$row]);
                    $created++;

                    continue;
                }

                $item->update(['price' => $row['price']]);
                $updated++;
            }

            $priceList->touch();
            $this->auditLogger->record('price_list.import', $priceList, [], ['created' => $created, 'updated' => $updated]);

            return ['created' => $created, 'updated' => $updated];
        });
    }

    /**
     * @param  list<array{test_id?: string|null, package_id?: string|null, price: string}>  $items
     */
    private function insertItems(PriceList $priceList, array $items): void
    {
        foreach ($items as $item) {
            $priceListItem = new PriceListItem([
                'test_id' => $item['test_id'] ?? null,
                'package_id' => $item['package_id'] ?? null,
                'price' => $item['price'],
            ]);
            $priceListItem->price_list_id = $priceList->id;
            $priceListItem->save();
        }
    }

    /**
     * @return list<array{test_id: string|null, package_id: string|null, price: string}>
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        $header = array_map(fn ($column) => strtolower(trim((string) $column)), str_getcsv((string) array_shift($lines)));

        if ($header !== ['item_type', 'code', 'price']) {
            throw ValidationException::withMessages(['file' => 'The first line must be: item_type,code,price']);
        }

        $testIds = LabTest::query()->pluck('id', 'code')->all();
        $packageIds = Package::query()->pluck('id', 'code')->all();
        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($lines as $offset => $line) {
            $rowNumber = $offset + 2;
            [$type, $code, $price] = array_pad(array_map('trim', str_getcsv($line)), 3, '');
            $ids = $type === 'package' ? $packageIds : ($type === 'test' ? $testIds : null);

            $issue = match (true) {
                $ids === null => "item_type must be 'test' or 'package'.",
                ! isset($ids[$code]) => "No {$type} with code '{$code}'.",
                isset($seen["{$type}:{$code}"]) => "{$type} '{$code}' appears more than once.",
                ! $this->isValidPrice($price) => "'{$price}' is not a valid price.",
                default => null,
            };

            if ($issue !== null) {
                $errors[] = ['field' => "row.{$rowNumber}", 'issue' => $issue];

                continue;
            }

            $seen["{$type}:{$code}"] = true;
            $rows[] = [
                'test_id' => $type === 'test' ? $ids[$code] : null,
                'package_id' => $type === 'package' ? $ids[$code] : null,
                'price' => Money::fromString($price)->toDecimalString(),
            ];
        }

        if ($errors !== []) {
            throw new DomainError('PRICE_IMPORT_INVALID', 'Some rows are invalid; nothing was imported.', 422, $errors);
        }

        return $rows;
    }

    private function isValidPrice(string $price): bool
    {
        try {
            return ! Money::fromString($price)->isNegative();
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * @param  list<array{test_id?: string|null, package_id?: string|null, price: string}>  $items
     */
    private function assertItemsExist(array $items): void
    {
        $testIds = array_values(array_filter(array_column($items, 'test_id')));
        $packageIds = array_values(array_filter(array_column($items, 'package_id')));

        $missingTests = array_diff($testIds, LabTest::query()->whereKey($testIds)->pluck('id')->all());
        $missingPackages = array_diff($packageIds, Package::query()->whereKey($packageIds)->pluck('id')->all());

        if ($missingTests !== [] || $missingPackages !== []) {
            throw ValidationException::withMessages(['items' => 'Unknown tests or packages: '.implode(', ', [...$missingTests, ...$missingPackages]).'.']);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function assertDefaultIsMrp(array $attributes): void
    {
        if (($attributes['is_default_mrp'] ?? false) && ($attributes['list_type'] ?? null) !== PriceListType::Mrp->value) {
            throw ValidationException::withMessages(['is_default_mrp' => 'Only an MRP list can be the default.']);
        }
    }

    private function clearDefaultMrp(): void
    {
        PriceList::query()->where('is_default_mrp', true)->get()->each(function (PriceList $current): void {
            $current->update(['is_default_mrp' => false]);
            $this->auditLogger->recordChanges('price_list.update', $current);
        });
    }
}

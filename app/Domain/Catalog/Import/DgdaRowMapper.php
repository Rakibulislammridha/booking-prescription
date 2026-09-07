<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ImportRow;

/**
 * DGDA registered-product list (one sheet exported to CSV). Header names vary between releases, so every column is
 * looked up through an alias list.
 */
final class DgdaRowMapper implements RowMapper
{
    private const COLUMNS = [
        'manufacturer' => ['manufacturer', 'manufacturer_name', 'company', 'company_name', 'applicant'],
        'brand' => ['brand', 'brand_name', 'product', 'product_name', 'trade_name'],
        'generic' => ['generic', 'generic_name', 'generic_names', 'composition', 'active_ingredient', 'ingredients'],
        'strength' => ['strength', 'strengths', 'potency'],
        'form' => ['form', 'dosage_form', 'dosage_forms', 'type', 'dosage_description'],
        'route' => ['route', 'route_of_administration'],
        'pack' => ['pack', 'pack_size', 'packsize', 'packing'],
        'dar' => ['dar', 'dar_no', 'dar_number', 'registration_no', 'reg_no', 'dar_no_'],
        'status' => ['status', 'registration_status', 'product_status'],
        'price' => ['mrp', 'price', 'unit_price', 'mrp_tk'],
    ];

    public function map(array $record, int $line): iterable
    {
        $brand = $this->pick($record, 'brand');
        $generic = $this->pick($record, 'generic');

        if ($brand === null || $generic === null) {
            return;
        }

        $price = $this->pick($record, 'price');

        yield new ImportRow(
            sourceRow: $line,
            manufacturer: $this->pick($record, 'manufacturer'),
            brand: $brand,
            genericText: $generic,
            strengthLabel: $this->pick($record, 'strength'),
            formText: $this->pick($record, 'form'),
            routeText: $this->pick($record, 'route'),
            packSize: $this->pick($record, 'pack'),
            darNumber: $this->pick($record, 'dar'),
            status: $this->pick($record, 'status') ?? 'active',
            unitPricePaisa: $price === null || ! is_numeric($price) ? null : (int) round(((float) $price) * 100),
        );
    }

    /** @param  array<string, string>  $record */
    private function pick(array $record, string $column): ?string
    {
        foreach (self::COLUMNS[$column] as $alias) {
            if (isset($record[$alias]) && trim($record[$alias]) !== '') {
                return trim($record[$alias]);
            }
        }

        return null;
    }
}

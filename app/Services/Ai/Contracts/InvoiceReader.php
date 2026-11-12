<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiUnavailableException;

interface InvoiceReader
{
    /**
     * Read the fields of an invoice or receipt from a PDF or image.
     *
     * @return array{supplier_name: string|null, supplier_nui: string|null, document_number: string|null, document_date: string|null, currency: string|null, net_amount: float|int|null, tax_amount: float|int|null, gross_amount: float|int|null, lines: list<array{description: string, quantity: float|int|null, unit_price: float|int|null, vat_rate_percent: float|int|null, net_amount: float|int|null}>}
     *
     * @throws AiUnavailableException
     */
    public function read(string $contents, string $mimeType): array;
}

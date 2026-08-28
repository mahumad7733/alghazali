<?php
declare(strict_types=1);

namespace App\Includes;

use PDO;
use RuntimeException;

final class InvoiceService
{
    public function issueForPayment(PDO $pdo, int $paymentId): ?array
    {
        $tables = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tax_settings','invoices','invoice_lines')")->fetchColumn();
        if ($tables < 3) return null;
        $payment = $this->one($pdo, 'SELECT p.*, b.booking_number, b.subtotal_amount, b.discount_amount, b.tax_amount, b.tax_rate, b.tax_snapshot_json, b.total_amount, b.company_id, b.customer_id, b.currency_id, cu.code AS currency_code, cu.decimal_places, cu.symbol_ar, t.trip_number, customer_user.full_name AS customer_name, customer_user.email AS customer_email, customer_user.phone AS customer_phone FROM payments p INNER JOIN bookings b ON b.id = p.booking_id INNER JOIN currencies cu ON cu.id = b.currency_id INNER JOIN trips t ON t.id = b.trip_id LEFT JOIN customers customer ON customer.id = b.customer_id LEFT JOIN users customer_user ON customer_user.id = customer.user_id WHERE p.id = :id LIMIT 1', ['id' => $paymentId]);
        if ($payment === null) return null;
        $settings = $this->one($pdo, 'SELECT vat_enabled, vat_rate, tax_label_ar, invoice_mode, supplier_snapshot_json FROM tax_settings WHERE id = 1 LIMIT 1', []);
        if (!is_array($settings) || ((int) ($settings['vat_enabled'] ?? 0) !== 1 && (string) ($settings['invoice_mode'] ?? 'none') === 'none')) return null;
        $existing = $this->one($pdo, 'SELECT id, invoice_number, status, total_amount, currency_id FROM invoices WHERE payment_id = :payment_id LIMIT 1', ['payment_id' => $paymentId]);
        if ($existing !== null) return $existing;
        $invoiceNumber = $this->newInvoiceNumber($pdo);
        $supplierSnapshot = trim((string) ($settings['supplier_snapshot_json'] ?? '')) ?: null;
        $customerSnapshot = json_encode(['name' => (string) ($payment['customer_name'] ?? 'عميل مباشر'), 'email' => (string) ($payment['customer_email'] ?? ''), 'phone' => (string) ($payment['customer_phone'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $taxRate = $payment['tax_rate'] !== null ? (string) $payment['tax_rate'] : ((string) ($settings['vat_rate'] ?? '') ?: null);
        $taxAmount = number_format((float) ($payment['tax_amount'] ?? 0), 2, '.', '');
        $subtotal = number_format((float) ($payment['subtotal_amount'] ?? $payment['amount']), 2, '.', '');
        $total = number_format((float) ($payment['total_amount'] ?? $payment['amount']), 2, '.', '');
        $invoiceType = in_array((string) ($settings['invoice_mode'] ?? 'none'), ['simplified', 'tax'], true) ? (string) $settings['invoice_mode'] : 'simplified';
        $statement = $pdo->prepare('INSERT INTO invoices (invoice_number, booking_id, payment_id, invoice_type, status, currency_id, subtotal_amount, discount_amount, tax_amount, total_amount, tax_rate, supplier_snapshot_json, customer_snapshot_json, issued_at) VALUES (:number, :booking_id, :payment_id, :type, \'issued\', :currency_id, :subtotal, :discount, :tax, :total, :rate, :supplier, :customer, NOW())');
        $statement->execute(['number' => $invoiceNumber, 'booking_id' => (int) $payment['booking_id'], 'payment_id' => $paymentId, 'type' => $invoiceType, 'currency_id' => (int) $payment['currency_id'], 'subtotal' => $subtotal, 'discount' => (string) ($payment['discount_amount'] ?? '0.00'), 'tax' => $taxAmount, 'total' => $total, 'rate' => $taxRate, 'supplier' => $supplierSnapshot, 'customer' => $customerSnapshot]);
        $invoiceId = (int) $pdo->lastInsertId();
        $line = $pdo->prepare('INSERT INTO invoice_lines (invoice_id, description_ar, quantity, unit_amount, line_subtotal, tax_rate, tax_amount, line_total, snapshot_json) VALUES (:invoice_id, :description, 1, :unit_amount, :line_subtotal, :tax_rate, :tax_amount, :line_total, :snapshot)');
        $line->execute(['invoice_id' => $invoiceId, 'description' => 'حجز رحلة ' . (string) $payment['booking_number'] . ' — ' . (string) $payment['trip_number'], 'unit_amount' => $subtotal, 'line_subtotal' => $subtotal, 'tax_rate' => $taxRate, 'tax_amount' => $taxAmount, 'line_total' => $total, 'snapshot' => $payment['tax_snapshot_json'] ?: null]);
        return $this->one($pdo, 'SELECT id, invoice_number, status, total_amount, currency_id, issued_at FROM invoices WHERE id = :id', ['id' => $invoiceId]);
    }

    private function newInvoiceNumber(PDO $pdo): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'INV-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
            if ($this->one($pdo, 'SELECT id FROM invoices WHERE invoice_number = :number LIMIT 1', ['number' => $number]) === null) return $number;
        }
        throw new RuntimeException('تعذر إنشاء رقم فاتورة فريد.');
    }

    private function one(PDO $pdo, string $sql, array $params = []): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }
}

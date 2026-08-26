<?php

namespace App\Service;

use App\Entity\Rechnung;
use App\Entity\Sepa;

final class InfomaExportService
{
    public function generate(Sepa $sepa, string $account, string $counterAccount): string
    {
        $date = $sepa->getCreatedAt() ?? new \DateTimeImmutable();
        $lines = [
            $this->line(['0', 'EXTSYS', '3', $date->format('d.m.Y'), $date->format('H:i:s'), '0']),
            $this->line(['6', $account, $account, 'Erlöskonto '.$account, '', '0', '0', '', '0', 'Ja', 'Nein']),
            $this->line(['6', $counterAccount, $counterAccount, 'Bank-/Verrechnungskonto '.$counterAccount, '', '1', '0', '', '8', 'Ja', 'Nein']),
        ];

        $sequence = 1;
        foreach ($sepa->getRechnungen() as $invoice) {
            $lines[] = $this->invoiceLine($sepa, $invoice, $account, $sequence);
            $lines[] = $this->counterAccountLine($invoice, $counterAccount, $sequence);
            ++$sequence;
        }

        return implode("\n", $lines)."\n";
    }

    private function invoiceLine(Sepa $sepa, Rechnung $invoice, string $account, int $sequence): string
    {
        $masterData = $invoice->getStammdaten();
        $invoiceNumber = $invoice->getRechnungsnummer() ?: 'EXT-'.$invoice->getId();
        $bookingDate = $invoice->getCreatedAt() ?? $sepa->getCreatedAt();
        $dueDate = $sepa->getEinzugsDatum();
        $iban = preg_replace('/\s+/', '', (string) $masterData->getIban());
        $bankCode = str_starts_with($iban, 'DE') ? substr($iban, 4, 8) : '';
        $bankAccount = str_starts_with($iban, 'DE') ? substr($iban, 12, 10) : '';
        $customerNumber = $masterData->getKundennummerForOrg($sepa->getOrganisation()->getId());

        $fields = array_fill(0, 70, '');
        $values = [0 => '1', 1 => $account, 2 => (string) $sequence, 4 => $invoiceNumber,
            6 => $bookingDate->format('d.m.Y'), 7 => $bookingDate->format('d.m.Y'), 8 => '1',
            9 => $customerNumber ? (string) $customerNumber->getKundennummer() : '', 10 => 'EXT-'.$invoiceNumber,
            11 => '0', 14 => $this->amount((float) $invoice->getSumme()),
            15 => 'Rechnung mit SEPA-LS '.$sequence, 16 => 'EUR', 17 => $dueDate->format('d.m.Y'),
            20 => '1', 37 => $bankCode, 38 => $bankAccount, 39 => 'SEPA-LS',
            40 => $masterData->getKontoinhaber() ?: trim($masterData->getVorname().' '.$masterData->getName()),
            64 => (string) $masterData->getBic(), 65 => $iban,
            66 => 'skb-'.$masterData->getConfirmationCode(),
            67 => ($masterData->getCreatedAt() ?? $bookingDate)->format('d.m.Y'),
            68 => 'DE', 69 => $dueDate->format('d.m.Y')];
        foreach ($values as $index => $value) {
            $fields[$index] = $value;
        }

        return $this->line($fields);
    }

    private function counterAccountLine(Rechnung $invoice, string $counterAccount, int $sequence): string
    {
        return $this->line([
            '2', $counterAccount, (string) $sequence, '', $invoice->getRechnungsnummer() ?: 'EXT-'.$invoice->getId(),
            '0', $counterAccount, '0', '', '', $this->amount(-(float) $invoice->getSumme()),
            'Gegenkonto '.$sequence,
        ]);
    }

    private function amount(float $amount): string
    {
        return number_format($amount, 2, ',', '');
    }

    /** @param array<int, string> $fields */
    private function line(array $fields): string
    {
        return implode('|', array_map(
            static fn (string $value): string => str_replace(['|', "\r", "\n"], [' ', ' ', ' '], $value),
            $fields,
        ));
    }
}

<?php

namespace App\Service;

use App\Entity\Rechnung;
use App\Entity\Sepa;

final class InfomaExportService
{
    public function generate(Sepa $sepa, string $kostenstelle, string $gegenkonto): string
    {
        $date = $sepa->getCreatedAt() ?? new \DateTimeImmutable();
        $lines = [
            ['0', 'EXTSYS', '3', $date->format('d.m.Y'), $date->format('H:i:s'), '0'],
            ['6', $kostenstelle, $gegenkonto, sprintf('Erlöskonto %s', $gegenkonto), '', '0', '0', '', '0', 'Ja', 'Nein'],
            ['6', $gegenkonto, $kostenstelle, sprintf('Gegenkonto %s', $kostenstelle), '', '1', '0', '', '8', 'Ja', 'Nein'],
        ];

        foreach ($sepa->getRechnungen() as $index => $rechnung) {
            $lines[] = $this->invoiceRecord($sepa, $rechnung, $kostenstelle, $index + 1);
            $lines[] = $this->counterRecord($sepa, $rechnung, $kostenstelle, $gegenkonto, $index + 1);
        }

        return implode("\r\n", array_map(fn (array $fields): string => implode('|', $fields), $lines))."\r\n";
    }

    private function invoiceRecord(Sepa $sepa, Rechnung $rechnung, string $kostenstelle, int $sequence): array
    {
        $stammdaten = $rechnung->getStammdaten();
        $customerNumber = $stammdaten->getKundennummerForOrg($sepa->getOrganisation()->getId())?->getKundennummer()
            ?? (string) $stammdaten->getId();
        $externalId = 'EXT-'.$customerNumber;
        $invoiceNumber = $rechnung->getRechnungsnummer() ?: sprintf('D%s', $customerNumber);
        $iban = strtoupper(str_replace(' ', '', (string) $stammdaten->getIban()));
        $fields = array_fill(0, 70, '');
        $values = [
            0 => '1', 1 => $kostenstelle, 2 => (string) $sequence, 4 => $externalId,
            6 => $rechnung->getCreatedAt()->format('d.m.Y'), 7 => $rechnung->getCreatedAt()->format('d.m.Y'),
            8 => '1', 9 => $invoiceNumber, 10 => 'EXT-'.$invoiceNumber, 11 => '0',
            14 => $this->amount($rechnung->getSumme()), 15 => 'Rechnung mit SEPA-LS '.$sequence,
            16 => 'EUR', 17 => $sepa->getEinzugsDatum()->format('d.m.Y'), 20 => '1',
            37 => strlen($iban) >= 14 ? substr($iban, 4, 8) : '',
            38 => strlen($iban) >= 14 ? substr($iban, 12) : '', 39 => 'SEPA-LS',
            40 => $stammdaten->getKontoinhaber() ?: trim($stammdaten->getVorname().' '.$stammdaten->getName()),
            64 => (string) $stammdaten->getBic(), 65 => $iban,
            66 => 'SKIB-'.$stammdaten->getConfirmationCode(),
            67 => $stammdaten->getCreatedAt()?->format('d.m.Y') ?? $rechnung->getCreatedAt()->format('d.m.Y'),
            68 => substr($iban, 0, 2), 69 => $sepa->getEinzugsDatum()->format('d.m.Y'),
        ];
        foreach ($values as $position => $value) {
            $fields[$position] = $this->sanitize((string) $value);
        }

        return $fields;
    }

    private function counterRecord(Sepa $sepa, Rechnung $rechnung, string $kostenstelle, string $gegenkonto, int $sequence): array
    {
        $customerNumber = $rechnung->getStammdaten()->getKundennummerForOrg($sepa->getOrganisation()->getId())?->getKundennummer()
            ?? (string) $rechnung->getStammdaten()->getId();

        return ['2', $kostenstelle, (string) $sequence, '', 'EXT-'.$customerNumber, '0', $gegenkonto, '0', '', '', '-'.$this->amount($rechnung->getSumme()), 'Erlös Gegenkonto '.$sequence];
    }

    private function amount(float $amount): string
    {
        return number_format(abs($amount), 2, ',', '');
    }

    private function sanitize(string $value): string
    {
        return str_replace(["|", "\r", "\n"], [' ', ' ', ' '], $value);
    }
}

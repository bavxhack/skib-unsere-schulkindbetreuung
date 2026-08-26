<?php

namespace App\Service;

use App\Entity\Kind;
use App\Entity\Rechnung;
use App\Entity\Sepa;

final class InfomaExportService
{
    private const FIBU_FIELD_COUNT = 70;
    private const COUNTER_FIELD_COUNT = 12;

    public function __construct(private readonly BerechnungsService $calculationService)
    {
    }

    public function generate(
        Sepa $sepa,
        string $revenueAccount,
        string $bankAccount,
        string $externalSystemId,
        string $paymentMethodCode,
    ): string {
        $createdAt = $sepa->getCreatedAt() ?? new \DateTimeImmutable();
        $this->assertValue($externalSystemId, 'Extern-ID', 10);
        $this->assertValue($revenueAccount, 'Erlöskonto', 20);
        $this->assertValue($bankAccount, 'Bank-/Verrechnungskonto', 20);
        $this->assertValue($paymentMethodCode, 'Zahlungsformcode', 20);

        $lines = [
            $this->record(6, [
                1 => '0', 2 => $externalSystemId, 3 => (string) ($sepa->getId() ?? 1),
                4 => $createdAt->format('d.m.Y'), 5 => $createdAt->format('H:i:s'), 6 => '0',
            ]),
            $this->record(11, [
                1 => '6', 2 => '10000', 3 => $revenueAccount, 4 => 'Erlöskonto '.$revenueAccount,
                6 => '0', 7 => '0', 9 => '0', 10 => 'Ja', 11 => 'Nein',
            ]),
            $this->record(11, [
                1 => '6', 2 => '20000', 3 => $bankAccount, 4 => 'Bank-/Verrechnungskonto '.$bankAccount,
                6 => '1', 7 => '0', 9 => '8', 10 => 'Ja', 11 => 'Nein',
            ]),
        ];

        $sequence = 1;
        $bookingCount = 0;
        foreach ($sepa->getRechnungen() as $invoice) {
            foreach ($invoice->getKinder() as $child) {
                $calculationDate = $invoice->getVon() ?? $sepa->getVon();
                $amount = $this->calculationService->getPreisforBetreuung(
                    $child,
                    false,
                    $calculationDate ? \DateTime::createFromInterface($calculationDate) : null,
                );
                if (!is_finite($amount) || $amount <= 0) {
                    throw new \InvalidArgumentException('Der Infoma-Betrag muss für jedes Kind größer als 0,00 sein.');
                }

                $lineNumber = (string) ($sequence * 10000);
                $externalDocumentNumber = $this->externalDocumentNumber($sepa, $invoice, $child, $sequence);
                $lines[] = $this->fibuRecord(
                    $sepa,
                    $invoice,
                    $child,
                    $amount,
                    $lineNumber,
                    $externalDocumentNumber,
                    $paymentMethodCode,
                );
                $lines[] = $this->counterRecord(
                    $child,
                    $amount,
                    $lineNumber,
                    $externalDocumentNumber,
                    $revenueAccount,
                );
                ++$sequence;
                ++$bookingCount;
            }
        }
        if ($bookingCount === 0) {
            throw new \InvalidArgumentException('Der Infoma-Export enthält keine Kinderbuchungen.');
        }

        return implode("\n", $lines)."\n";
    }

    private function fibuRecord(
        Sepa $sepa,
        Rechnung $invoice,
        Kind $child,
        float $amount,
        string $lineNumber,
        string $externalDocumentNumber,
        string $paymentMethodCode,
    ): string {
        $masterData = $invoice->getStammdaten();
        $bookingDate = $invoice->getCreatedAt() ?? $sepa->getCreatedAt();
        $dueDate = $sepa->getEinzugsDatum();
        if (!$bookingDate || !$dueDate) {
            throw new \InvalidArgumentException('Beleg-, Buchungs- und Fälligkeitsdatum müssen vorhanden sein.');
        }

        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $masterData->getIban()));
        $confirmationCode = (string) $masterData->getConfirmationCode();
        $this->assertValue($confirmationCode, 'Mandatsreferenz', 31);
        $mandateReference = 'skb-'.$confirmationCode;
        $mandateDate = $masterData->getCreatedAt();
        if ($mandateReference !== 'skb-' && ($iban === '' || !$mandateDate)) {
            throw new \InvalidArgumentException('IBAN und Unterschriftsdatum sind bei einer Mandatsreferenz erforderlich.');
        }

        $customer = $masterData->getKundennummerForOrg($sepa->getOrganisation()->getId());
        $customerNumber = (string) ($customer?->getKundennummer() ?? '');
        $this->assertValue($customerNumber, 'Debitorennummer', 20);
        $this->assertValue($externalDocumentNumber, 'Externe Belegnummer', 27);
        $this->assertValue('EXT-'.$customerNumber, 'Externe Kontonummer', 30);
        $this->assertValue($iban, 'IBAN', 50);
        $this->assertValue($mandateReference, 'Mandatsreferenz', 35);
        if ($masterData->getBic()) {
            $this->assertValue((string) $masterData->getBic(), 'BIC', 20);
        }

        return $this->record(self::FIBU_FIELD_COUNT, [
            1 => '1', 2 => $lineNumber, 3 => '1', 5 => $externalDocumentNumber,
            7 => $bookingDate->format('d.m.Y'), 8 => $bookingDate->format('d.m.Y'),
            9 => '1', 10 => $customerNumber, 11 => 'EXT-'.$customerNumber, 12 => '0',
            15 => $this->amount($amount), 16 => $this->bookingText($child, $masterData->getVorname(), $masterData->getName()),
            17 => 'EUR', 18 => $dueDate->format('d.m.Y'), 21 => '1',
            38 => str_starts_with($iban, 'DE') ? substr($iban, 4, 8) : '',
            39 => str_starts_with($iban, 'DE') ? substr($iban, 12, 10) : '',
            40 => $paymentMethodCode,
            41 => $masterData->getKontoinhaber() ?: trim($masterData->getVorname().' '.$masterData->getName()),
            65 => strtoupper((string) $masterData->getBic()), 66 => $iban,
            67 => $mandateReference, 68 => $mandateDate?->format('d.m.Y') ?? '',
            69 => str_starts_with($iban, 'DE') ? 'DE' : substr($iban, 0, 2),
            70 => $dueDate->format('d.m.Y'),
        ]);
    }

    private function counterRecord(
        Kind $child,
        float $amount,
        string $lineNumber,
        string $externalDocumentNumber,
        string $revenueAccount,
    ): string {
        return $this->record(self::COUNTER_FIELD_COUNT, [
            1 => '2', 2 => $lineNumber, 3 => '1', 5 => $externalDocumentNumber,
            6 => '0', 7 => $revenueAccount, 8 => '0', 11 => $this->amount(-$amount),
            12 => $this->limit('Erlös Gegenkonto '.$child->getVorname().' '.$child->getNachname(), 50),
        ]);
    }

    private function externalDocumentNumber(Sepa $sepa, Rechnung $invoice, Kind $child, int $sequence): string
    {
        return sprintf('EXT-%s-%s-%s', $sepa->getId() ?? 0, $invoice->getId() ?? 0, $child->getId() ?? $sequence);
    }

    private function bookingText(Kind $child, ?string $parentFirstName, ?string $parentLastName): string
    {
        return $this->limit(sprintf(
            'Kind: %s %s; Eltern: %s %s',
            $child->getVorname(),
            $child->getNachname(),
            $parentFirstName,
            $parentLastName,
        ), 50);
    }

    private function amount(float $amount): string
    {
        return number_format($amount, 2, ',', '');
    }

    /** @param array<int, scalar|null> $assignments */
    private function record(int $fieldCount, array $assignments): string
    {
        $fields = array_fill(0, $fieldCount, '');
        foreach ($assignments as $position => $value) {
            if ($position < 1 || $position > $fieldCount) {
                throw new \LogicException(sprintf('Infoma-Position %d liegt außerhalb eines Satzes mit %d Feldern.', $position, $fieldCount));
            }
            $field = trim((string) ($value ?? ''));
            if (str_contains($field, '|') || str_contains($field, "\r") || str_contains($field, "\n")) {
                throw new \InvalidArgumentException(sprintf('Infoma-Feld %d enthält ein unzulässiges Trenn- oder Zeilenumbruchzeichen.', $position));
            }
            if (preg_match('//u', $field) !== 1) {
                throw new \InvalidArgumentException(sprintf('Infoma-Feld %d ist nicht gültig UTF-8.', $position));
            }
            $fields[$position - 1] = $field;
        }

        return implode('|', $fields);
    }

    private function assertValue(string $value, string $field, int $maxLength): void
    {
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(sprintf('%s muss ausgefüllt sein und darf höchstens %d Zeichen enthalten.', $field, $maxLength));
        }
        if (str_contains($value, '|') || str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new \InvalidArgumentException(sprintf('%s enthält unzulässige Zeichen.', $field));
        }
    }

    private function limit(string $value, int $maxLength): string
    {
        return mb_substr(trim($value), 0, $maxLength);
    }
}

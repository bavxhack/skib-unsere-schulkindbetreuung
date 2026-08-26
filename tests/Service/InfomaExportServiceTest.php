<?php

namespace App\Tests\Service;

use App\Entity\Organisation;
use App\Entity\Rechnung;
use App\Entity\Sepa;
use App\Entity\Stammdaten;
use App\Service\InfomaExportService;
use PHPUnit\Framework\TestCase;

final class InfomaExportServiceTest extends TestCase
{
    public function testItCreatesAnInfomaBookingAndCounterBookingForEveryInvoice(): void
    {
        $organisation = new Organisation();
        $sepa = (new Sepa())
            ->setOrganisation($organisation)
            ->setCreatedAt(new \DateTimeImmutable('2026-06-11 09:00:00'))
            ->setEinzugsDatum(new \DateTimeImmutable('2026-06-18'));
        $masterData = (new Stammdaten())
            ->setVorname('Max')
            ->setName('Mustermann')
            ->setKontoinhaber('Max Mustermann')
            ->setIban('DE89370400440532013000')
            ->setBic('COBADEFFXXX')
            ->setConfirmationCode('MANDAT-0001')
            ->setCreatedAt(new \DateTimeImmutable('2024-01-01'));
        $invoice = (new Rechnung())
            ->setStammdaten($masterData)
            ->setRechnungsnummer('D10001')
            ->setCreatedAt(new \DateTimeImmutable('2026-06-11'))
            ->setSumme(119.0);
        $sepa->addRechnungen($invoice);

        $lines = explode("\n", trim((new InfomaExportService())->generate($sepa, '400000', '120000')));

        self::assertCount(5, $lines);
        $booking = explode('|', $lines[3]);
        $counterBooking = explode('|', $lines[4]);
        self::assertCount(70, $booking);
        self::assertSame('119,00', $booking[14]);
        self::assertSame('37040044', $booking[37]);
        self::assertSame('0532013000', $booking[38]);
        self::assertSame('-119,00', $counterBooking[10]);
        self::assertSame('120000', $counterBooking[6]);
    }

    public function testItSanitizesCharactersThatWouldBreakTheFormat(): void
    {
        $sepa = (new Sepa())
            ->setOrganisation(new Organisation())
            ->setCreatedAt(new \DateTimeImmutable('2026-06-11'))
            ->setEinzugsDatum(new \DateTimeImmutable('2026-06-18'));
        $masterData = (new Stammdaten())
            ->setName("Bei|spiel\nName")
            ->setConfirmationCode('1');
        $sepa->addRechnungen((new Rechnung())
            ->setStammdaten($masterData)
            ->setRechnungsnummer('1')
            ->setCreatedAt(new \DateTimeImmutable('2026-06-11'))
            ->setSumme(1.0));

        $lines = explode("\n", trim((new InfomaExportService())->generate($sepa, '1', '2')));

        self::assertStringContainsString('Bei spiel Name', $lines[3]);
        self::assertCount(70, explode('|', $lines[3]));
    }
}

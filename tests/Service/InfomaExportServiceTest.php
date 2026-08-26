<?php

namespace App\Tests\Service;

use App\Entity\Kind;
use App\Entity\KinderRechnung;
use App\Entity\Kundennummern;
use App\Entity\Organisation;
use App\Entity\Rechnung;
use App\Entity\Sepa;
use App\Entity\Stammdaten;
use App\Service\InfomaExportService;
use PHPUnit\Framework\TestCase;

final class InfomaExportServiceTest extends TestCase
{
    public function testItCreatesSpecificationCompliantBalancedRecordsForEveryChild(): void
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
        $customerNumber = (new Kundennummern())->setKundennummer('D10001');
        $organisation->addKundennummern($customerNumber);
        $masterData->addKundennummern($customerNumber);
        $invoice = (new Rechnung())
            ->setStammdaten($masterData)
            ->setRechnungsnummer('D10001')
            ->setCreatedAt(new \DateTimeImmutable('2026-06-11'))
            ->setVon(new \DateTimeImmutable('2026-06-01'))
            ->setSumme(119.0);
        $invoice->addKinderRechnung((new KinderRechnung())
            ->setKind((new Kind())->setVorname('Anna')->setNachname('Mustermann'))
            ->setSumme(70.0)
            ->setBruttoSumme(80.0)
            ->setRabatt(10.0));
        $invoice->addKinderRechnung((new KinderRechnung())
            ->setKind((new Kind())->setVorname('Ben')->setNachname('Mustermann'))
            ->setSumme(49.0)
            ->setBruttoSumme(49.0)
            ->setRabatt(0.0));
        $sepa->addRechnungen($invoice);

        $csv = (new InfomaExportService())->generate(
            $sepa,
            '400000',
            '120000',
            'EXTSYS',
            'SEPA-LS',
        );
        $lines = explode("\n", rtrim($csv, "\n"));

        self::assertCount(7, $lines);
        self::assertSame('0|EXTSYS|1|11.06.2026|09:00:00|0', $lines[0]);
        self::assertSame('6|10000|400000|Erlöskonto 400000||0|0||0|Ja|Nein', $lines[1]);
        self::assertSame('6|20000|120000|Bank-/Verrechnungskonto 120000||1|0||8|Ja|Nein', $lines[2]);

        $firstBooking = explode('|', $lines[3]);
        $firstCounterBooking = explode('|', $lines[4]);
        self::assertCount(70, $firstBooking);
        self::assertCount(12, $firstCounterBooking);
        self::assertSame('10000', $firstBooking[1]);
        self::assertSame('1', $firstBooking[2]);
        self::assertSame($firstBooking[4], $firstCounterBooking[4]);
        self::assertSame($firstBooking[2], $firstCounterBooking[2]);
        self::assertSame('D10001', $firstBooking[9]);
        self::assertSame('70,00', $firstBooking[14]);
        self::assertSame('Kind: Anna Mustermann; Eltern: Max Mustermann', $firstBooking[15]);
        self::assertSame('37040044', $firstBooking[37]);
        self::assertSame('0532013000', $firstBooking[38]);
        self::assertSame('SEPA-LS', $firstBooking[39]);
        self::assertSame('COBADEFFXXX', $firstBooking[64]);
        self::assertSame('DE89370400440532013000', $firstBooking[65]);
        self::assertSame('skb-MANDAT-0001', $firstBooking[66]);
        self::assertSame('01.01.2024', $firstBooking[67]);
        self::assertSame('DE', $firstBooking[68]);
        self::assertSame('18.06.2026', $firstBooking[69]);
        self::assertSame('400000', $firstCounterBooking[6]);
        self::assertSame('-70,00', $firstCounterBooking[10]);
        self::assertSame(0.0, $this->parseAmount($firstBooking[14]) + $this->parseAmount($firstCounterBooking[10]));

        $secondBooking = explode('|', $lines[5]);
        $secondCounterBooking = explode('|', $lines[6]);
        self::assertSame('20000', $secondBooking[1]);
        self::assertNotSame($firstBooking[4], $secondBooking[4]);
        self::assertSame('49,00', $secondBooking[14]);
        self::assertSame('-49,00', $secondCounterBooking[10]);
    }

    public function testItRejectsCharactersThatWouldBreakTheFileFormat(): void
    {
        $sepa = (new Sepa())
            ->setOrganisation(new Organisation())
            ->setCreatedAt(new \DateTimeImmutable('2026-06-11'))
            ->setEinzugsDatum(new \DateTimeImmutable('2026-06-18'));
        $this->expectException(\InvalidArgumentException::class);
        (new InfomaExportService())->generate($sepa, '40|0000', '120000', 'EXTSYS', 'SEPA-LS');
    }

    private function parseAmount(string $amount): float
    {
        return (float) str_replace(',', '.', $amount);
    }
}

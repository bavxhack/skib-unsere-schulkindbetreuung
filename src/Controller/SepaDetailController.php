<?php

namespace App\Controller;

use App\Entity\Rechnung;
use App\Entity\Sepa;
use App\Service\BerechnungsService;
use App\Service\PrintRechnungService;
use App\Service\SepaCreateService;
use App\Service\SepaExcel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;

class SepaDetailController extends AbstractController
{
    public function __construct(private \Doctrine\Persistence\ManagerRegistry $managerRegistry)
    {
    }
    /**
     * @Route("/org_accounting/sepa/detail", name="accounting_sepa_detail")
     */
    public function index(Request $request,SepaCreateService $sepaCreateService)
    {
        set_time_limit(600);
       $sepa = $this->managerRegistry->getRepository(Sepa::class)->find($request->get('id'));
       if($sepa->getOrganisation() != $this->getUser()->getOrganisation()){
           throw new \Exception('Wrong Organisation');
       }
       $simDate = (clone $sepa->getVon())->modify('first day of next month');

       $stammdatenChange = $sepaCreateService->diffToThisMonth($sepa,$simDate);
       return $this->render('sepa_detail/detail.html.twig',array('sepa'=>$sepa,'diffs'=>$stammdatenChange,'simDate'=>$simDate));
    }
    /**
     * @Route("/org_accounting/print/detail", name="accounting_sepa_print")
     */
    public function print(Request $request,PrintRechnungService $printRechnungService)
    {
        $rechnung = $this->managerRegistry->getRepository(Rechnung::class)->find($request->get('id'));

        if($rechnung->getKinder()->toArray()[0]->getSchule()->getOrganisation() != $this->getUser()->getOrganisation()){
            throw new \Exception('Wrong Organisation');
        }

        return $printRechnungService->printRechnung('Test',$rechnung->getKinder()->toArray()[0]->getSchule()->getOrganisation(),$rechnung,'D');
    }
    /**
     * @Route("/org_accounting/print/sepaXML", name="accounting_sepa_printXML")
     */
    public function printXML(Request $request,PrintRechnungService $printRechnungService)
    {
        $sepa = $this->managerRegistry->getRepository(Sepa::class)->find($request->get('id'));
        if($sepa->getOrganisation() != $this->getUser()->getOrganisation()){
            throw new \Exception('Wrong Organisation');
        }
        $response = new Response($sepa->getSepaXML());
        $filename= 'SEPA-'.$sepa->getCreatedAt()->format('dmY_H_i_s');
        // Create the disposition of the file
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename.'.xml'
        );
        // Set the content disposition
        $response->headers->set('Content-Disposition', $disposition);

        // Dispatch request
        return $response;


    }
    /**
     * @Route("/org_accounting/print/excel", name="accounting_sepa_printExcel")
     */
    public function printExcel(Request $request,PrintRechnungService $printRechnungService,SepaExcel $sepaExcel)
    {
        $sepa = $this->managerRegistry->getRepository(Sepa::class)->find($request->get('sepa_id'));
        if($sepa->getOrganisation() != $this->getUser()->getOrganisation()){
            throw new \Exception('Wrong Organisation');
        }
        return $this->file($sepaExcel->generateExcel($sepa),'SEPA_ID'.$sepa->getId().'.xlsx', ResponseHeaderBag::DISPOSITION_INLINE);
    }

    /**
     * @Route("/org_accounting/print/infoma/form", name="accounting_sepa_printInfoma_form", methods={"GET","POST"})
     */
    public function printInfomaForm(Request $request): Response
    {
        $sepa = $this->managerRegistry->getRepository(Sepa::class)->find($request->get('sepa_id'));
        if ($sepa->getOrganisation() != $this->getUser()->getOrganisation()) {
            throw new \Exception('Wrong Organisation');
        }

        $form = $this->createFormBuilder(null, ['method' => 'GET'])
            ->add('sachkonto', TextType::class, [
                'label' => 'Sachkonto',
                'data' => '3461000',
            ])
            ->add('kostenstelle', TextType::class, [
                'label' => 'Kostenstelle Grundschulbetreeung',
                'data' => '33000030',
            ])
            ->add('kostentraeger', TextType::class, [
                'label' => 'Kostenträger Nummer',
                'data' => '21100100',
            ])
            ->add('download', SubmitType::class, ['label' => 'Infoma Export herunterladen'])
            ->setAction($this->generateUrl('accounting_sepa_printInfoma', ['sepa_id' => $sepa->getId()]))
            ->getForm();
        $form->handleRequest($request);

        return $this->render('sepa/infoma_form.html.twig', [
            'sepa' => $sepa,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/org_accounting/print/infoma", name="accounting_sepa_printInfoma", methods={"GET"})
     */
    public function printInfoma(Request $request, BerechnungsService $berechnungsService): Response
    {
        $sepa = $this->managerRegistry->getRepository(Sepa::class)->find($request->get('sepa_id'));
        if ($sepa->getOrganisation() != $this->getUser()->getOrganisation()) {
            throw new \Exception('Wrong Organisation');
        }

        $sachkonto = trim((string)$request->query->get('sachkonto', ''));
        $kostenstelle = trim((string)$request->query->get('kostenstelle', ''));
        $kostentraeger = trim((string)$request->query->get('kostentraeger', ''));
        if ($sachkonto === '' || $kostenstelle === '' || $kostentraeger === '') {
            return $this->redirectToRoute('accounting_sepa_printInfoma_form', ['sepa_id' => $sepa->getId()]);
        }

        $response = new StreamedResponse(function () use ($sepa, $berechnungsService, $sachkonto, $kostenstelle, $kostentraeger) {
            $out = fopen('php://output', 'w');
            $write = static function (array $row) use ($out): void {
                fputcsv($out, $row, ';');
            };

            $header = [];
            for ($i = 1; $i <= 70; $i++) {
                $header[] = 'Column'.$i;
            }
            $write($header);

            $write([
                'einfach reinschreiben', 'weglassen', 'weglassen', 'weglassen', 'weglassen', '', '', '', '', 'Kundennummer',
                'Kundenummer', '', '', '', '', '', '', 'ignorieren', '', '', '', '', '', '', '', '', '', '', '', '', 'einach so lassen',
                'Kundenummer', 'einfach so lassen', '', '', '', '', '', '', 'einfach so lassen', '', '', '', '', '', '', '', '', '', '',
                'wie vorne', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'BIC', 'IBAN', 'leerlassen', 'leerlassen', '', '',
            ]);

            $runningId = 10970000;
            $debitorRows = [];
            $gegenkontoRows = [];
            $period = $sepa->getVon()->format('m/Y');
            $bookingDate = $sepa->getVon()->format('d.m.Y');
            $lastschriftDate = $sepa->getEinzugsDatum()->format('d.m.Y');

            foreach ($sepa->getRechnungen() as $rechnung) {
                $stammdaten = $rechnung->getStammdaten();
                $customerIdEntity = $stammdaten->getKundennummerForOrg($sepa->getOrganisation()->getId());
                $customerId = $customerIdEntity ? (string)$customerIdEntity->getKundennummer() : '';
                $customerAccount = $customerId !== '' ? $customerId.'510' : '';

                $kinder = $rechnung->getKinder()->toArray();
                usort($kinder, static function ($left, $right) {
                    return strcmp(($left->getNachname() ?? '').($left->getVorname() ?? ''), ($right->getNachname() ?? '').($right->getVorname() ?? ''));
                });

                foreach ($kinder as $kind) {
                    $summe = $berechnungsService->getPreisforBetreuung($kind, false, clone $sepa->getVon());
                    if ($summe <= 0) {
                        continue;
                    }

                    $kindName = trim(sprintf('%s_%s', (string)$kind->getVorname(), (string)$kind->getNachname()));
                    $text = sprintf('Betreuungsentgelt_%s_%s', $period, str_replace(' ', '_', $kindName));
                    $buchungsnummer = (string)$runningId;
                    $runningId += 10000;
                    $summePositiv = number_format($summe, 2, ',', '');
                    $summeNegativ = number_format($summe * -1, 2, ',', '');
                    $rechnungsId = preg_replace('/\D+/', '', (string)$rechnung->getRechnungsnummer()) ?: (string)$rechnung->getId();

                    $debitorRows[] = [
                        'Finanzbuchhaltung', $buchungsnummer, 'Rechnung', $rechnungsId, $customerAccount, '', $bookingDate, $bookingDate, 'Debitor', $customerId,
                        $customerId, '', '', 'Normale MwSt.', $summePositiv, $text, '', $bookingDate, '', '', '', '', '', '', '', '', '', '', '', '01',
                        $customerId, '510', '', '', '', '', '', '', 'ABBUCHUNG', '', '', '', '', '', '', '', '', '', '', '', $text, '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '', $stammdaten->getBic() ? strtoupper((string)$stammdaten->getBic()) : '',
                        $stammdaten->getIban() ? strtoupper((string)$stammdaten->getIban()) : '', 'BAH CIG '.$stammdaten->getConfirmationCode(), $lastschriftDate, '', '',
                    ];

                    $gegenkontoRows[] = [
                        'Gegenkonto', $buchungsnummer, 'Rechnung', $rechnungsId, $customerAccount, 'Sachkonto', $sachkonto, 'Verkauf', '', 'Normale MwSt.',
                        $summeNegativ, str_replace(['_', ' '], '', $text), $kostenstelle, $kostentraeger, '', '', '', '', '01', '', '510', '', '', '', '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '', $text, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '',
                        '', '', '', '', '', '', '', '', '',
                    ];
                }
            }

            foreach ($debitorRows as $row) {
                $write(array_pad($row, 70, ''));
            }

            $write(array_pad([
                '', 'weglassen', 'weglassen', 'weglassen', 'weglasen', '', $sachkonto, '', '', '', '', '', $kostenstelle, $kostentraeger,
            ], 70, ''));

            foreach ($gegenkontoRows as $row) {
                $write(array_pad($row, 70, ''));
            }

            fclose($out);
        });

        $filename = sprintf('INFOMA_SEPA_ID%s_%s.csv', $sepa->getId(), date('Y-m-d'));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}

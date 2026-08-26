<?php

namespace App\Controller;

use App\Entity\Rechnung;
use App\Entity\Sepa;
use App\Service\PrintRechnungService;
use App\Service\SepaCreateService;
use App\Service\SepaExcel;
use App\Service\InfomaExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

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

    #[Route('/org_accounting/print/infoma', name: 'accounting_sepa_print_infoma', methods: ['POST'])]
    public function printInfoma(Request $request, InfomaExportService $exportService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ORG_INFOMA_EXPORT');

        $sepa = $this->managerRegistry->getRepository(Sepa::class)->find($request->request->getInt('sepa_id'));
        if (!$sepa || $sepa->getOrganisation() !== $this->getUser()->getOrganisation()) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('infoma-export-'.$sepa->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges CSRF-Token.');
        }

        $account = trim((string) $request->request->get('account'));
        $counterAccount = trim((string) $request->request->get('counter_account'));
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $account) || !preg_match('/^[A-Za-z0-9.-]+$/', $counterAccount)) {
            throw new BadRequestHttpException('Bitte geben Sie gültige Konten an.');
        }

        $response = new Response($exportService->generate($sepa, $account, $counterAccount));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'INFOMA_SEPA_ID'.$sepa->getId().'.csv',
        ));

        return $response;
    }
}

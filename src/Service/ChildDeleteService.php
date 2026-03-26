<?php

namespace App\Service;

use App\Entity\Active;
use App\Entity\Anwesenheit;
use App\Entity\EmailResponse;
use App\Entity\Kind;
use App\Entity\Log;
use App\Entity\Organisation;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\Rechnung;
use App\Entity\Stadt;

use App\Entity\Stammdaten;
use App\Entity\User;
use App\Entity\Abwesend;
use App\Entity\Kundennummern;
use Beelab\Recaptcha2Bundle\Form\Type\RecaptchaType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;


// <- Add this

class ChildDeleteService
{
    private $em;
    private $translator;
    private $templating;
    private $mailer;
    private $abschluss;
    private $parameterBag;
    private $logger;
    private FilesystemOperator $internFileSystem;

    public function __construct(FilesystemOperator $internFileSystem, LoggerInterface $logger, ParameterBagInterface $parameterBag, WorkflowAbschluss $workflowAbschluss, MailerService $mailer, Environment $environment, TranslatorInterface $translator, EntityManagerInterface $entityManager)
    {
        $this->em = $entityManager;
        $this->translator = $translator;
        $this->templating = $environment;
        $this->mailer = $mailer;
        $this->abschluss = $workflowAbschluss;
        $this->parameterBag = $parameterBag;
        $this->logger = $logger;
        $this->internFileSystem = $internFileSystem;
    }

    public function deleteChild(Kind $kind, User $user)
    {
        try {
            $stammdaten = $kind->getEltern();
            $childHist = $this->em->getRepository(Kind::class)->findHistoryOfThisChild($kind);
            if (sizeof($childHist) === 0) {
                $childHist = [$kind];
            }

            if ($this->parameterBag->get('noEmailOnDelete') == 0) {
                $this->sendEmail($kind->getEltern(), $kind, $kind->getSchule()->getOrganisation());
            }

            foreach ($childHist as $data) {
                $this->removeChildRelationsAndEntity($data);
            }
            $this->em->flush();

            if ($stammdaten && $this->em->getRepository(Kind::class)->count(['eltern' => $stammdaten]) === 0) {
                $this->removeStammdatenAndRelations($stammdaten);
                $this->em->flush();
            }

            $message = 'child Deleted: Tracing' . $kind->getTracing() .
                'Name: ' . $kind->getVorname().' '.$kind->getNachname() . '; ' .
                'fos_user_id: ' . $user->getId() . '; ';
            $log = new Log();
            $log->setUser($user->getEmail());
            $log->setDate(new \DateTime());
            $log->setMessage($message);
            $this->em->persist($log);
            $this->em->flush();

            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    private function removeChildRelationsAndEntity(Kind $kind): void
    {
        foreach ($kind->getAbwesends()->toArray() as $abwesend) {
            $this->em->remove($abwesend);
        }
        foreach ($kind->getAnwesenheitenSchulkindbetreuung()->toArray() as $anwesenheit) {
            $this->em->remove($anwesenheit);
        }
        foreach ($kind->getZeitblocks()->toArray() as $zeitblock) {
            $kind->removeZeitblock($zeitblock);
        }
        foreach ($kind->getBeworben()->toArray() as $zeitblock) {
            $kind->removeBeworben($zeitblock);
            $zeitblock->removeKinderBeworben($kind);
        }
        foreach ($kind->getWarteliste()->toArray() as $zeitblock) {
            $kind->removeWarteliste($zeitblock);
            $zeitblock->removeWartelisteKinder($kind);
        }
        foreach ($kind->getMovedToWaiting()->toArray() as $zeitblock) {
            $kind->removeMovedToWaiting($zeitblock);
            $zeitblock->removeMovedToWaitingKid($kind);
        }
        foreach ($kind->getRechnungen()->toArray() as $rechnung) {
            $kind->removeRechnungen($rechnung);
        }

        $this->em->remove($kind);
    }

    private function removeStammdatenAndRelations(Stammdaten $stammdaten): void
    {
        foreach ($stammdaten->getRechnungs()->toArray() as $rechnung) {
            foreach ($rechnung->getKinder()->toArray() as $kind) {
                $rechnung->removeKinder($kind);
            }
            foreach ($rechnung->getZeitblocks()->toArray() as $zeitblock) {
                $rechnung->removeZeitblock($zeitblock);
                $zeitblock->removeRechnungen($rechnung);
            }
            $this->em->remove($rechnung);
        }

        foreach ($stammdaten->getPaymentFerien()->toArray() as $payment) {
            foreach ($payment->getRefunds()->toArray() as $refund) {
                $this->em->remove($refund);
            }
            $this->em->remove($payment);
        }

        foreach ($stammdaten->getKundennummerns()->toArray() as $kundennummer) {
            $this->em->remove($kundennummer);
        }

        $emailResponses = $this->em->getRepository(EmailResponse::class)->findBy(['stammdaten' => $stammdaten]);
        foreach ($emailResponses as $emailResponse) {
            $this->em->remove($emailResponse);
        }

        $this->em->remove($stammdaten);
    }

    public function sendEmail(Stammdaten $stammdaten, Kind $kind, Organisation $organisation)
    {
        $mailBetreff = $this->translator->trans('Abmeldung der Schulkindbetreuung für ') . $kind->getVorname() . ' ' . $kind->getNachname();
        $mailContent = $this->templating->render('email/abmeldebestatigung.html.twig', array('eltern' => $stammdaten, 'kind' => $kind, 'org' => $organisation, 'stadt' => $organisation->getStadt()));
        $attachment = array();
        foreach ($organisation->getStadt()->getEmailDokumenteSchulkindbetreuungAbmeldung() as $att) {
            $attachment[] = array(
                'body' => $this->internFileSystem->read($att->getFileName()),
                'filename' => $att->getOriginalName(),
                'type' => $att->getType()
            );
        }
        $this->mailer->sendEmail(
            $kind->getSchule()->getOrganisation()->getName(),
            $kind->getSchule()->getOrganisation()->getEmail(),
            $stammdaten->getEmail(),
            $mailBetreff,
            $mailContent,
            $kind->getSchule()->getOrganisation()->getEmail(),
            $attachment
        );
        foreach ($stammdaten->getPersonenberechtigters() as $data){
            $this->mailer->sendEmail(
                $kind->getSchule()->getOrganisation()->getName(),
                $kind->getSchule()->getOrganisation()->getEmail(),
                $data->getEmail(),
                $mailBetreff,
                $mailContent,
                $kind->getSchule()->getOrganisation()->getEmail(),
                $attachment
            );
        }

    }
}

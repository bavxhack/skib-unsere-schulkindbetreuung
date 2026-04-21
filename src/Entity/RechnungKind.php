<?php

namespace App\Entity;

use App\Repository\RechnungKindRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RechnungKindRepository::class)]
class RechnungKind
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Rechnung::class, inversedBy: 'rechnungKinds')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Rechnung $rechnung = null;

    #[ORM\ManyToOne(targetEntity: Kind::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Kind $kind = null;

    #[ORM\Column(type: 'float')]
    private float $betrag = 0.0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRechnung(): ?Rechnung
    {
        return $this->rechnung;
    }

    public function setRechnung(?Rechnung $rechnung): self
    {
        $this->rechnung = $rechnung;
        return $this;
    }

    public function getKind(): ?Kind
    {
        return $this->kind;
    }

    public function setKind(?Kind $kind): self
    {
        $this->kind = $kind;
        return $this;
    }

    public function getBetrag(): float
    {
        return $this->betrag;
    }

    public function setBetrag(float $betrag): self
    {
        $this->betrag = $betrag;
        return $this;
    }
}

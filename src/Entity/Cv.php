<?php

namespace App\Entity;


namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\CvRepository;

#[ORM\Entity(repositoryClass: CvRepository::class)]
class Cv
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 255)]
    private ?string $Cv = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $CvGenerique = null;
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ficheCompetence = null;
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $texteExtrait = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $questionsJson = null;
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $evaluationJson = null;

    public function getEvaluationJson(): ?array
    {
        return $this->evaluationJson;
    }

    public function setEvaluationJson(?array $evaluationJson): void
    {
        $this->evaluationJson = $evaluationJson;
    }



    public function getQuestionsJson(): ?array
    {
        return $this->questionsJson;
    }

    public function setQuestionsJson(?array $questionsJson): self
    {
        $this->questionsJson = $questionsJson;
        return $this;
    }
    public function getTexteExtrait(): ?string
    {
        return $this->texteExtrait;
    }
    public function setTexteExtrait(?string $texteExtrait): self
    {
        $this->texteExtrait = $texteExtrait;
        return $this;
    }
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getCv(): ?string
    {
        return $this->Cv;
    }
    public function setCv(string $Cv): self
    {
        $this->Cv = $Cv;
        return $this;
    }
    public function getCvGenerique(): ?string
    {
        return $this->CvGenerique;
    }
    public function setCvGenerique(?string $CvGenerique): self
    {
        $this->CvGenerique = $CvGenerique;
        return $this;
    }
    public function getFicheCompetence(): ?string
    {
        return $this->ficheCompetence;
    }
    public function setFicheCompetence(?string $ficheCompetence): self
    {
        $this->ficheCompetence = $ficheCompetence;
        return $this;
    }
}

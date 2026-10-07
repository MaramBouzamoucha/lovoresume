<?php

namespace App\Repository;

use App\Entity\TemplateCv;
use App\Entity\TemplateFicheCompetence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TemplateFicheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemplateFicheCompetence::class);
    }


    public function findAllTemplates(): array
    {
        return $this->findAll();
    }
}
<?php

namespace App\Repository;

use App\Entity\TemplateCv;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TemplateCvRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemplateCv::class);
    }


    public function findAllTemplates(): array
    {
        return $this->findAll();
    }

}
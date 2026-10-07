<?php

namespace App\Service;
use App\Entity\TemplateCv;
use App\Repository\TemplateCvRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\CssSelector\Parser\Reader;

class TemplateCvService
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TemplateCvRepository $templateCvRepository) {}


    public function createTemplateCv(String $textExtrait , String $HtmlContent): string{
        $processedHtml = str_replace('{{content}}', $textExtrait, $HtmlContent
        );

        return $processedHtml;

    }

}
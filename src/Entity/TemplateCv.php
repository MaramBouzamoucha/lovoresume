<?php

namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
class TemplateCv
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private $id;

    #[ORM\Column(type: 'string', length: 255)]
    private $fileName;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private $filePath;

    #[ORM\Column(type: "text", nullable: true)]
    private $htmlContent;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logo = null;
    public function getLogo(): ?string
    {
        return $this->logo;  // Retourne simplement le chemin du fichier
    }

    public function setLogo(string $logo): self
    {
        $this->logo = $logo;
        return $this;
    }


    public function getHtmlContent()
    {
        return $this->htmlContent;
    }

    /**
     * @param mixed $htmlContent
     */
    public function setHtmlContent($htmlContent): void
    {
        $this->htmlContent = $htmlContent;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(?string $filePath): self
    {
        $this->filePath = $filePath;

        return $this;
    }
}
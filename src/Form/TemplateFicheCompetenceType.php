<?php
namespace App\Form;

use App\Entity\TemplateFicheCompetence;
use FOS\CKEditorBundle\Form\Type\CKEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TemplateFicheCompetenceType extends AbstractType
{
    private $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fileName', TextType::class, [
                'label' => 'Nom de la fiche',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un nom de fiche.',
                    ]),
                ],
            ])
            ->add('filePath', FileType::class, [
                'label' => 'Fichier',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => [
                            'application/pdf',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'text/plain',
                        ],
                        'mimeTypesMessage' => 'Seuls les fichiers PDF, DOCX et TXT sont acceptés.',
                    ]),
                ],
            ])
            ->add('htmlContent', CKEditorType::class, [
                'label' => 'Contenu HTML',
                'config' => [
                    'uiColor' => '#ffffff',
                ],
                'required' => false,
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo (Image file)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize' => '2M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                        ],
                        'mimeTypesMessage' => 'Veuillez télécharger une image valide (JPEG, PNG, GIF).',
                    ]),
                ],
            ]);
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            $templateFiche = $event->getData();
            $form = $event->getForm();
            if ($templateFiche) {
                if ($templateFiche->getFilePath()) {
                    $filePath = $templateFiche->getFilePath();
                    $fullFilePath = $this->params->get('kernel.project_dir') . '/public' . $filePath;
                    if (file_exists($fullFilePath)) {
                        $file = new SymfonyFile($fullFilePath);
                        $form->get('filePath')->setData($file);
                    } else {
                    }
                }
                if ($templateFiche->getLogo()) {
                    $logoPath = $templateFiche->getLogo();
                    $fullLogoPath = $this->params->get('kernel.project_dir') . '/public' . $logoPath;
                    if (file_exists($fullLogoPath)) {
                        $logo = new SymfonyFile($fullLogoPath);
                        $form->get('logo')->setData($logo);
                    } else {
                    }
                }
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TemplateFicheCompetence::class,
        ]);
    }
}
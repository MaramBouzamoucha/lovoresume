<?php

namespace App\Form;

use App\Entity\TemplateCv;
use FOS\CKEditorBundle\Form\Type\CKEditorType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

class TemplateCvType extends AbstractType
{
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
                'label' => 'Fichier (PDF, DOCX, TXT)',
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
                'mapped' => true,
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo (JPEG, PNG, GIF)',
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TemplateCv::class,
        ]);
    }
}
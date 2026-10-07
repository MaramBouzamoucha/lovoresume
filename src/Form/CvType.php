<?php

namespace App\Form;

use App\Entity\Cv;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class CvType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('cvFile', FileType::class, [
                'label' => 'Télécharger un CV',
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new File([
                        'mimeTypes' => [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        ],
                        'mimeTypesMessage' => 'Seuls les fichiers PDF et Word (.doc, .docx) sont autorisés.',
                    ])
                ],
            ]);}

    public function configureOptions(OptionsResolver $resolver)
    {

        $resolver->setDefaults([
            'data_class' => Cv::class,
            'csrf_protection' => true, // ✅ Assure-toi que c’est activé
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'upload_cv', // Ce nom doit correspondre à celui utilisé dans le contrôleur si tu fais une vérification manuelle
        ]);
    }
}

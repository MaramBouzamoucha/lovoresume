<?php

namespace App\Form;
use App\Entity\Recruteur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
class ModifierRecruteur extends AbstractType
{

        public function buildForm(FormBuilderInterface $builder, array $options)
        {
            $builder
                ->add('nom', TextType::class, [
                    'label' => 'Nom',
                    'attr' => ['class' => 'form-control'],
                ])
                ->add('email', TextType::class, [
                    'label' => 'Email',
                    'attr' => ['class' => 'form-control'],
                ])
                ->add('ancien_password', PasswordType::class, [
                    'label' => 'Ancien Mot de Passe',
                    'attr' => ['class' => 'form-control'],
                    'mapped' => false,
                    'required' => false, // Optionnel
                ])
                ->add('nouveau_password', PasswordType::class, [
                    'label' => 'Nouveau Mot de Passe',
                    'attr' => ['class' => 'form-control'],
                    'mapped' => false,
                    'required' => false, // Optionnel
                ]);
        }

        public
        function configureOptions(OptionsResolver $resolver)
        {
            $resolver->setDefaults([
                'data_class' => Recruteur::class,
            ]);
        }
    }
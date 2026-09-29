<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\CompteIdentiteData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de modification de l'identité d'un compte (administration de la plateforme). */
final class CompteIdentiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'administration.compte.prenom',
                'attr' => ['autocomplete' => 'off', 'autofocus' => true, 'maxlength' => 80],
            ])
            ->add('nom', TextType::class, [
                'label' => 'administration.compte.nom',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80],
            ])
            ->add('email', EmailType::class, [
                'label' => 'administration.compte.email.label',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CompteIdentiteData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

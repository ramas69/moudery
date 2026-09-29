<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ProfilData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire des paramètres du compte connecté : identité et adresse de connexion. */
final class ProfilType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'parametres.profil.prenom',
                'attr' => ['autocomplete' => 'given-name', 'maxlength' => 80],
            ])
            ->add('nom', TextType::class, [
                'label' => 'parametres.profil.nom',
                'attr' => ['autocomplete' => 'family-name', 'maxlength' => 80],
            ])
            ->add('email', EmailType::class, [
                'label' => 'parametres.profil.email',
                'attr' => ['autocomplete' => 'email', 'inputmode' => 'email', 'maxlength' => 180],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfilData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

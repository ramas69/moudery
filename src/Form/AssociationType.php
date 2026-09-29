<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\AssociationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de création d'une association (administration de la plateforme). */
final class AssociationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'administration.association.nom.label',
                'attr' => ['autofocus' => true, 'autocomplete' => 'organization', 'maxlength' => 120],
            ])
            ->add('village', TextType::class, [
                'label' => 'administration.association.village.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80, 'placeholder' => 'Moudery'],
            ])
            // Rarement saisi : l'identifiant se déduit du village. Le champ reste là pour les cas particuliers.
            ->add('slug', TextType::class, [
                'label' => 'administration.association.slug.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80, 'spellcheck' => 'false', 'placeholder' => 'moudery'],
            ])
            ->add('emailBureauCentral', EmailType::class, [
                'label' => 'administration.association.bureau_central.label',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AssociationData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

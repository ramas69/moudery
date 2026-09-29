<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ActivationCompteData;
use App\Form\Model\NouveauMotDePasseData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire d'activation d'un compte invité (F-02). */
final class ActivationCompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'invitation.prenom',
                'attr' => ['autocomplete' => 'given-name', 'autofocus' => true, 'maxlength' => 80],
            ])
            ->add('nom', TextType::class, [
                'label' => 'invitation.nom',
                'attr' => ['autocomplete' => 'family-name', 'maxlength' => 80],
            ])
            ->add('telephone', \Symfony\Component\Form\Extension\Core\Type\TelType::class, [
                'label' => 'invitation.telephone',
                'required' => false,
                'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel', 'maxlength' => 30],
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => 'invitation.mot_de_passe',
                'attr' => ['autocomplete' => 'new-password', 'minlength' => NouveauMotDePasseData::LONGUEUR_MINIMALE],
            ])
            ->add('consentEmail', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label' => 'invitation.consentement',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ActivationCompteData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ChangementMotDePasseData;
use App\Form\Model\NouveauMotDePasseData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Changement de mot de passe depuis les paramètres : l'actuel, puis le nouveau saisi deux fois. */
final class ChangementMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('actuel', PasswordType::class, [
                'label' => 'parametres.mot_de_passe.actuel',
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('motDePasse', PasswordType::class, [
                'label' => 'parametres.mot_de_passe.nouveau',
                'attr' => ['autocomplete' => 'new-password', 'minlength' => NouveauMotDePasseData::LONGUEUR_MINIMALE],
            ])
            ->add('confirmation', PasswordType::class, [
                'label' => 'parametres.mot_de_passe.confirmation',
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangementMotDePasseData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

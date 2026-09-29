<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\NouveauMotDePasseData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Choix d'un nouveau mot de passe, saisi deux fois (F-03). */
final class NouveauMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motDePasse', PasswordType::class, [
                'label' => 'reinitialisation.mot_de_passe',
                'attr' => ['autocomplete' => 'new-password', 'autofocus' => true, 'minlength' => NouveauMotDePasseData::LONGUEUR_MINIMALE],
            ])
            ->add('confirmation', PasswordType::class, [
                'label' => 'reinitialisation.confirmation',
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NouveauMotDePasseData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

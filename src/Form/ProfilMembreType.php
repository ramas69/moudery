<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ProfilMembreData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProfilMembreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('telephone', TelType::class, ['label' => 'mon_espace.profil.telephone', 'required' => false, 'attr' => ['autocomplete' => 'tel', 'inputmode' => 'tel']])
            ->add('consentEmail', CheckboxType::class, ['label' => 'mon_espace.profil.consentement', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProfilMembreData::class]);
    }
}

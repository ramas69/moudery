<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ModeMontant;
use App\Entity\UniteContribution;
use App\Form\Model\TypeContributionData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Réglage d'un type de contribution par le bureau central (F-11). */
final class TypeContributionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'contributions_association.formulaire.nom',
                'attr' => ['maxlength' => 80, 'autocomplete' => 'off'],
            ])
            ->add('unite', EnumType::class, [
                'label' => 'contributions_association.formulaire.unite',
                'class' => UniteContribution::class,
                'expanded' => true,
                'choice_label' => static fn (UniteContribution $u): string => 'contributions_association.unite.'.$u->value,
            ])
            ->add('mode', EnumType::class, [
                'label' => 'contributions_association.formulaire.mode',
                'class' => ModeMontant::class,
                'expanded' => true,
                'choice_label' => static fn (ModeMontant $m): string => 'appels_association.mode.'.$m->value,
            ])
            ->add('montantDefaut', MoneyType::class, [
                'label' => 'contributions_association.formulaire.montant',
                'required' => false,
                'currency' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01', 'inputmode' => 'decimal'],
            ])
            ->add('tauxReversement', IntegerType::class, [
                'label' => 'contributions_association.formulaire.taux',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TypeContributionData::class]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ModeMontant;
use App\Entity\TypeContribution;
use App\Form\Model\AppelData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Nouvel appel à contribution : les champs de la maquette 04 ; le gabarit les dispose (cartes de type, grille de détails). */
final class AppelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<TypeContribution> $types */
        $types = $options['types'];
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'appels_association.formulaire.type',
                'expanded' => true,
                'choices' => array_combine(array_map(static fn (TypeContribution $t): string => $t->getNom(), $types), array_map(static fn (TypeContribution $t): string => $t->getCode(), $types)),
                'choice_translation_domain' => false,
            ])
            ->add('objet', TextType::class, [
                'label' => 'appels_association.formulaire.objet',
                'attr' => ['maxlength' => 160, 'autocomplete' => 'off', 'placeholder' => 'appels_association.formulaire.objet_exemple'],
            ])
            ->add('montant', NumberType::class, [
                'label' => 'appels_association.formulaire.montant',
                'required' => false,
                'scale' => 2,
                'html5' => false,
                'attr' => ['inputmode' => 'decimal', 'autocomplete' => 'off'],
                'invalid_message' => 'appel.montant.invalide',
            ])
            ->add('mode', EnumType::class, [
                'label' => 'appels_association.formulaire.mode',
                'class' => ModeMontant::class,
                'choice_label' => static fn (ModeMontant $mode): string => 'appels_association.mode.'.$mode->value,
            ])
            ->add('dateLimite', DateType::class, [
                'label' => 'appels_association.formulaire.date_limite',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'invalid_message' => 'appel.date_limite.obligatoire',
            ])
            ->add('perimetre', ChoiceType::class, [
                'label' => 'appels_association.formulaire.perimetre',
                'choices' => $options['perimetres'],
                'choice_translation_domain' => false,
            ])
            ->add('taux', IntegerType::class, [
                'label' => 'appels_association.formulaire.reversement',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'appels_association.formulaire.message',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 4000],
            ])
            ->add('relancesAuto', CheckboxType::class, [
                'label' => 'appels_association.formulaire.relances',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => AppelData::class]);
        $resolver->setRequired(['types', 'perimetres']);
    }
}

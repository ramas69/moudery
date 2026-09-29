<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Ville;
use App\Form\Model\ReversementData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Enregistrement d'un reversement reçu par le bureau central (F-20). Options : `villes` (les villes proposées, dans
 * l'ordre) et `exercices` (libellé par année de début, du plus récent au premier).
 */
final class ReversementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Ville> $villes */
        $villes = $options['villes'];
        /** @var array<int, string> $exercices */
        $exercices = $options['exercices'];

        $builder
            ->add('ville', ChoiceType::class, [
                'label' => 'reversements_association.formulaire.ville',
                'choices' => $villes,
                'choice_value' => static fn (?Ville $ville): string => null === $ville ? '' : (string) $ville->getId(),
                'choice_label' => static fn (Ville $ville): string => $ville->getNom(),
                'placeholder' => 'reversements_association.formulaire.ville_choisir',
                'disabled' => 1 === \count($villes) && $options['ville_fixee'],
            ])
            ->add('exercice', ChoiceType::class, [
                'label' => 'reversements_association.formulaire.exercice',
                'choices' => array_flip($exercices),
                'choice_translation_domain' => false,
            ])
            ->add('montant', MoneyType::class, [
                'label' => 'reversements_association.formulaire.montant',
                'currency' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01', 'inputmode' => 'decimal'],
            ])
            ->add('recuLe', DateType::class, [
                'label' => 'reversements_association.formulaire.recu_le',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('reference', TextType::class, [
                'label' => 'reversements_association.formulaire.reference',
                'required' => false,
                'attr' => ['maxlength' => 80, 'autocomplete' => 'off'],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'reversements_association.formulaire.note',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReversementData::class,
            'villes' => [],
            'exercices' => [],
            'ville_fixee' => false,
        ]);
        $resolver->setAllowedTypes('villes', 'array');
        $resolver->setAllowedTypes('exercices', 'array');
        $resolver->setAllowedTypes('ville_fixee', 'bool');
    }
}

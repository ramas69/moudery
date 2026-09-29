<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Echeance;
use App\Entity\MoyenPaiement;
use App\Form\Model\PaiementData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Enregistrement d'un paiement manuel (F-17). Option `echeances` : les échéances dues du membre, à cocher. */
final class PaiementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<Echeance> $echeances */
        $echeances = $options['echeances'];
        $builder
            ->add('echeances', ChoiceType::class, [
                'label' => 'paiements_association.formulaire.echeances',
                'choices' => $echeances,
                'choice_value' => static fn (?Echeance $e): string => null === $e ? '' : (string) $e->getId(),
                'choice_label' => static fn (Echeance $e): string => (string) $e->getId(),
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('moyen', EnumType::class, [
                'label' => 'paiements_association.formulaire.moyen',
                'class' => MoyenPaiement::class,
                'choice_label' => static fn (MoyenPaiement $m): string => 'paiements_association.moyen.'.$m->value,
            ])
            ->add('recuLe', DateType::class, [
                'label' => 'paiements_association.formulaire.recu_le',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('reference', TextType::class, [
                'label' => 'paiements_association.formulaire.reference',
                'required' => false,
                'attr' => ['maxlength' => 80, 'autocomplete' => 'off'],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'paiements_association.formulaire.note',
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 255],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PaiementData::class, 'echeances' => []]);
        $resolver->setAllowedTypes('echeances', 'array');
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AbonnementStatut;
use App\Entity\Periodicite;
use App\Form\Model\AbonnementData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de l'abonnement d'une association (administration de la plateforme). */
final class AbonnementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('formule', TextType::class, [
                'label' => 'administration.abonnement.formule.label',
                'attr' => ['autofocus' => true, 'maxlength' => 60, 'placeholder' => 'Gratuit'],
            ])
            ->add('statut', EnumType::class, [
                'label' => 'administration.abonnement.statut.label',
                'class' => AbonnementStatut::class,
                'choice_label' => static fn (AbonnementStatut $statut): string => 'statut_abonnement.'.$statut->value,
            ])
            ->add('montant', MoneyType::class, [
                'label' => 'administration.abonnement.montant.label',
                // Le symbole est dans le libellé : le thème de formulaire n'a pas à l'accoler au champ.
                'currency' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01', 'inputmode' => 'decimal'],
            ])
            ->add('periodicite', EnumType::class, [
                'label' => 'administration.abonnement.periodicite.label',
                'class' => Periodicite::class,
                'choice_label' => static fn (Periodicite $periodicite): string => 'periodicite.'.$periodicite->value,
            ])
            ->add('debutLe', DateType::class, [
                'label' => 'administration.abonnement.debut.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('prochaineEcheanceLe', DateType::class, [
                'label' => 'administration.abonnement.echeance.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'administration.abonnement.notes.label',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 1000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AbonnementData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

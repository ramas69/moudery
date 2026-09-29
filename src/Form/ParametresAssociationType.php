<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ParametresAssociationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Paramètres de l'association réglés par le bureau central (onglet « Association » des Paramètres). */
final class ParametresAssociationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('emailContact', EmailType::class, [
                'label' => 'administration.association.email_contact.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180],
            ])
            ->add('telephoneContact', TelType::class, [
                'label' => 'administration.association.telephone.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'tel', 'maxlength' => 30],
            ])
            ->add('adresseSiege', TextareaType::class, [
                'label' => 'administration.association.adresse.label',
                'required' => false,
                'attr' => ['rows' => 3, 'maxlength' => 500],
            ])
            ->add('debutExerciceMois', ChoiceType::class, [
                'label' => 'administration.association.exercice.label',
                'choices' => self::mois(),
            ])
            ->add('premierExercice', IntegerType::class, [
                'label' => 'administration.association.premier_exercice.label',
                'attr' => ['min' => 2000, 'max' => 2100, 'inputmode' => 'numeric'],
            ])
            ->add('tauxReversementDefaut', IntegerType::class, [
                'label' => 'administration.association.taux.label',
                'attr' => ['min' => 0, 'max' => 100, 'inputmode' => 'numeric'],
            ])
            ->add('calendrierRelances', TextType::class, [
                'label' => 'administration.association.relances.label',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 60, 'placeholder' => '-7, 0, 15'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ParametresAssociationData::class]);
    }

    /** @return array<string, int> libellé du mois en français => numéro */
    public static function mois(): array
    {
        $formateur = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL');
        $choix = [];
        for ($mois = 1; $mois <= 12; ++$mois) {
            $libelle = (string) $formateur->format((int) gmmktime(12, 0, 0, $mois, 1, 2026));
            $choix[mb_convert_case($libelle, \MB_CASE_TITLE, 'UTF-8')] = $mois;
        }

        return $choix;
    }
}

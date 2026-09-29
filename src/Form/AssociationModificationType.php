<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\AssociationModificationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de modification d'une association (administration de la plateforme). */
final class AssociationModificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'administration.association.nom.label',
                'attr' => ['autofocus' => true, 'autocomplete' => 'organization', 'maxlength' => 120],
            ])
            ->add('slug', TextType::class, [
                'label' => 'administration.association.slug.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80, 'spellcheck' => 'false'],
            ])
            ->add('village', TextType::class, [
                'label' => 'administration.association.village.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80],
            ])
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
            ->add('numeroRna', TextType::class, [
                'label' => 'administration.association.rna.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 14, 'placeholder' => 'W123456789'],
            ])
            ->add('siren', TextType::class, [
                'label' => 'administration.association.siren.label',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'maxlength' => 11, 'placeholder' => '123 456 789'],
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
        $resolver->setDefaults([
            'data_class' => AssociationModificationData::class,
            'translation_domain' => 'messages',
        ]);
    }

    /** « Janvier » → 1 … « Décembre » → 12, en français. @return array<string, int> */
    private static function mois(): array
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

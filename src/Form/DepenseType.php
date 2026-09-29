<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\CategorieDepense;
use App\Entity\MoyenPaiement;
use App\Form\Model\DepenseData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Saisie d'une dépense ; le gabarit dispose les champs comme la maquette « 08a Saisie d'une dépense ». */
final class DepenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('libelle', TextType::class, ['label' => 'depenses_association.formulaire.libelle', 'attr' => ['maxlength' => 160, 'autocomplete' => 'off']])
            ->add('montant', NumberType::class, ['label' => 'depenses_association.formulaire.montant', 'scale' => 2, 'html5' => false, 'required' => true, 'attr' => ['inputmode' => 'decimal', 'autocomplete' => 'off', 'placeholder' => '0,00'], 'invalid_message' => 'depense.montant.invalide'])
            ->add('date', DateType::class, ['label' => 'depenses_association.formulaire.date', 'widget' => 'single_text', 'input' => 'datetime_immutable', 'invalid_message' => 'depense.date.obligatoire'])
            ->add('categorie', EnumType::class, ['label' => 'depenses_association.formulaire.categorie', 'class' => CategorieDepense::class, 'choice_label' => static fn (CategorieDepense $c): string => 'depenses_association.categorie.'.$c->value])
            ->add('appel', ChoiceType::class, ['label' => 'depenses_association.formulaire.appel', 'required' => false, 'placeholder' => 'depenses_association.formulaire.aucun_appel', 'choices' => $options['appels'], 'choice_translation_domain' => false])
            ->add('beneficiaire', TextType::class, ['label' => 'depenses_association.formulaire.beneficiaire', 'attr' => ['maxlength' => 160, 'autocomplete' => 'off']])
            ->add('moyen', EnumType::class, ['label' => 'depenses_association.formulaire.moyen', 'class' => MoyenPaiement::class, 'choice_label' => static fn (MoyenPaiement $m): string => 'depenses_association.moyen.'.$m->value])
            ->add('justificatif', FileType::class, ['label' => 'depenses_association.formulaire.justificatif', 'required' => false, 'attr' => ['accept' => '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png']])
            ->add('commentaire', TextareaType::class, ['label' => 'depenses_association.formulaire.commentaire', 'required' => false, 'attr' => ['rows' => 3, 'maxlength' => 2000]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DepenseData::class]);
        $resolver->setRequired('appels');
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\ReglagesImportData;
use App\Ville\ImportMembres;
use App\Ville\ReglagesAnalyse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Réglages d'un import (F-31) : la feuille retenue, la ligne d'en-têtes, un choix de champ par colonne utile du fichier. */
final class ReglagesImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choix = [];
        foreach (ImportMembres::CHAMPS as $champ) {
            $choix['assistant_ville.membres.apercu.champ.'.$champ] = $champ;
        }

        $builder
            ->add('feuille', HiddenType::class)
            ->add('ligneEnTete', IntegerType::class, [
                'label' => 'assistant_ville.membres.reglages.ligne_en_tete',
                'attr' => ['min' => 0, 'max' => 200, 'inputmode' => 'numeric'],
            ])
            ->add('ordreNomComplet', ChoiceType::class, [
                'label' => 'assistant_ville.membres.reglages.ordre',
                'choices' => [
                    'assistant_ville.membres.reglages.ordre_nom_prenom' => ReglagesAnalyse::ORDRE_NOM_PRENOM,
                    'assistant_ville.membres.reglages.ordre_prenom_nom' => ReglagesAnalyse::ORDRE_PRENOM_NOM,
                ],
                'expanded' => true,
            ])
            // « Appliquer » relit le fichier à partir de cette ligne d'en-têtes et réattribue les colonnes, sans valider.
            ->add('appliquer', SubmitType::class, [
                'label' => 'assistant_ville.membres.reglages.appliquer',
                'validate' => false,
            ]);

        $colonnes = $builder->create('colonnes', FormType::class, ['label' => false, 'error_bubbling' => false]);
        foreach ($options['indices'] as $indice) {
            $colonnes->add(ReglagesImportData::cle($indice), ChoiceType::class, [
                'label' => false,
                'required' => false,
                'placeholder' => 'assistant_ville.membres.reglages.ignorer',
                'choices' => $choix,
            ]);
        }
        $builder->add($colonnes);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReglagesImportData::class,
            'translation_domain' => 'messages',
        ]);
        $resolver->setRequired('indices');
        $resolver->setAllowedTypes('indices', 'int[]');
    }
}

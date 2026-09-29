<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Association;
use App\Entity\VilleStatut;
use App\Form\Model\VilleAdministrationData;
use App\Repository\AssociationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de modification d'une ville dans l'administration : l'association est figée, le nom et le statut se changent. */
final class VilleAdministrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('association', EntityType::class, [
                'class' => Association::class,
                'choice_label' => 'nom',
                'placeholder' => 'administration.ville.association.placeholder',
                'label' => 'administration.ville.association.label',
                'disabled' => true,
                'query_builder' => static fn (AssociationRepository $associations) => $associations->createQueryBuilder('a')->orderBy('a.nom', 'ASC'),
                'attr' => [],
            ])
            ->add('nom', TextType::class, [
                'label' => 'administration.ville.nom.label',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 120, 'autofocus' => true],
            ])
            ->add('statut', EnumType::class, [
                'class' => VilleStatut::class,
                'label' => 'administration.ville.statut.label',
                'choice_label' => static fn (VilleStatut $statut) => 'assistant_ville.statut.'.$statut->value,
                'choice_translation_domain' => 'messages',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VilleAdministrationData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

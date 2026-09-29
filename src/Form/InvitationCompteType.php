<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Association;
use App\Entity\Ville;
use App\Form\Model\InvitationCompteData;
use App\Repository\AssociationRepository;
use App\Repository\VilleRepository;
use App\Security\Role;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire d'invitation d'une personne depuis l'administration : association, rôle, ville éventuelle, adresse. */
final class InvitationCompteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('association', EntityType::class, [
                'class' => Association::class,
                'choice_label' => 'nom',
                'placeholder' => 'administration.ville.association.placeholder',
                'label' => 'administration.compte.association.label',
                'query_builder' => static fn (AssociationRepository $associations) => $associations->createQueryBuilder('a')->orderBy('a.nom', 'ASC'),
                'attr' => ['autofocus' => true],
            ])
            ->add('role', EnumType::class, [
                'class' => Role::class,
                'label' => 'administration.compte.role.label',
                'choice_filter' => static fn (?Role $role) => null !== $role && Role::SuperAdmin !== $role,
                'choice_label' => static fn (Role $role) => 'role.'.$role->value,
                'choice_translation_domain' => 'messages',
            ])
            ->add('ville', EntityType::class, [
                'class' => Ville::class,
                'required' => false,
                'choice_label' => 'nom',
                'placeholder' => 'administration.compte.ville.placeholder',
                'label' => 'administration.compte.ville.label',
                'group_by' => static fn (Ville $ville) => $ville->getAssociation()->getNom(),
                'query_builder' => static fn (VilleRepository $villes) => $villes->createQueryBuilder('v')->innerJoin('v.association', 'a')->addSelect('a')->orderBy('a.nom', 'ASC')->addOrderBy('v.nom', 'ASC'),
            ])
            ->add('email', EmailType::class, [
                'label' => 'administration.compte.email.label',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvitationCompteData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Ville;
use App\Form\Model\InvitationResponsableData;
use App\Security\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Inviter un responsable (F-02) : l'adresse, le rôle parmi ceux que la personne connectée peut attribuer, la ville
 * parmi les villes actives qu'elle peut modifier (une ville en brouillon reçoit ses responsables dans l'assistant).
 */
final class InvitationResponsableType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $roles = [];
        foreach ($options['roles'] as $role) {
            \assert($role instanceof Role);
            $roles['role.'.$role->value] = $role;
        }
        $villes = [];
        foreach ($options['villes'] as $ville) {
            \assert($ville instanceof Ville);
            $villes[$ville->getNom()] = $ville;
        }

        $builder
            ->add('email', EmailType::class, [
                'label' => 'responsables_association.inviter.email',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180, 'placeholder' => 'prenom.nom@example.org'],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'responsables_association.inviter.role',
                'choices' => $roles,
                'choice_value' => static fn (?Role $role): string => $role?->value ?? '',
                'expanded' => true,
            ])
            ->add('ville', ChoiceType::class, [
                'label' => 'responsables_association.inviter.ville',
                'required' => false,
                'placeholder' => 'responsables_association.inviter.ville_aucune',
                'choices' => $villes,
                'choice_value' => static fn (?Ville $ville): string => (string) $ville?->getId(),
                'choice_translation_domain' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InvitationResponsableData::class,
            'translation_domain' => 'messages',
            'roles' => [],
            'villes' => [],
        ]);
        $resolver->setAllowedTypes('roles', Role::class.'[]');
        $resolver->setAllowedTypes('villes', Ville::class.'[]');
    }
}

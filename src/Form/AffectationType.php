<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use App\Entity\Ville;
use App\Form\Model\AffectationData;
use App\Repository\VilleRepository;
use App\Security\Perimetre;
use App\Security\Role;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Ajout d'un rôle à un compte. Seuls les rôles que le compte peut encore recevoir sont proposés :
 * un compte sans association ne peut être que super-admin ; un compte d'association reçoit les rôles
 * de son association et peut aussi devenir super-admin (règle du 27 septembre 2026). Le champ « ville »
 * n'existe que pour un compte d'association, avec les villes de celle-ci.
 */
final class AffectationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $compte = $options['compte'];
        \assert($compte instanceof Utilisateur);
        $association = $compte->getAssociation();

        $builder->add('role', EnumType::class, [
            'class' => Role::class,
            'choices' => self::rolesProposes($compte),
            'label' => 'administration.compte.role.label',
            'choice_label' => static fn (Role $role) => 'role.'.$role->value,
            'choice_translation_domain' => 'messages',
        ]);

        if (null !== $association) {
            $builder->add('ville', EntityType::class, [
                'class' => Ville::class,
                'required' => false,
                'choice_label' => 'nom',
                'placeholder' => 'administration.compte.ville.placeholder',
                'label' => 'administration.compte.ville.label',
                'query_builder' => static fn (VilleRepository $villes) => $villes->createQueryBuilder('v')
                    ->andWhere('v.association = :association')
                    ->setParameter('association', $association)
                    ->orderBy('v.nom', 'ASC'),
            ]);
        }
    }

    /**
     * Les rôles qu'un compte peut encore recevoir : ceux de son périmètre, sans ceux qu'il détient déjà
     * et qui ne se cumulent pas d'une ville à l'autre (super-admin, bureau central).
     *
     * @return list<Role>
     */
    public static function rolesProposes(Utilisateur $compte): array
    {
        $association = $compte->getAssociation();

        return array_values(array_filter(Role::cases(), static function (Role $role) use ($compte, $association): bool {
            if (null === $association && Role::SuperAdmin !== $role) {
                return false;
            }

            return Perimetre::Ville === $role->perimetre() || !$compte->aLeRole($role);
        }));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AffectationData::class,
            'translation_domain' => 'messages',
        ]);
        $resolver->setRequired('compte');
        $resolver->setAllowedTypes('compte', Utilisateur::class);
    }
}

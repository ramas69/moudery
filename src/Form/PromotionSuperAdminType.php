<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use App\Form\Model\PromotionSuperAdminData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Choix, parmi les comptes qui ne sont pas encore super-admins, de celui qui le devient. */
final class PromotionSuperAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('compte', EntityType::class, [
            'class' => Utilisateur::class,
            'choices' => $options['candidats'],
            'choice_label' => static function (Utilisateur $compte): string {
                $association = $compte->getAssociation();

                return $compte->getNomComplet().' · '.$compte->getEmail().(null === $association ? '' : ' · '.$association->getNom());
            },
            'placeholder' => 'administration.equipe.promouvoir.placeholder',
            'label' => 'administration.equipe.promouvoir.label',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PromotionSuperAdminData::class,
            'translation_domain' => 'messages',
        ]);
        $resolver->setRequired('candidats');
        $resolver->setAllowedTypes('candidats', 'array');
    }
}

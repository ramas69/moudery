<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Association;
use App\Form\Model\EquipeInvitationData;
use App\Repository\AssociationRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire d'ajout d'un super-admin à l'équipe de la plateforme, rattaché ou non à une association. */
final class EquipeInvitationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'administration.equipe.prenom',
                'attr' => ['autocomplete' => 'off', 'autofocus' => true, 'maxlength' => 80],
            ])
            ->add('nom', TextType::class, [
                'label' => 'administration.equipe.nom',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80],
            ])
            ->add('email', EmailType::class, [
                'label' => 'administration.equipe.email.label',
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180],
            ])
            ->add('association', EntityType::class, [
                'class' => Association::class,
                'required' => false,
                'choice_label' => 'nom',
                'placeholder' => 'administration.equipe.association.placeholder',
                'label' => 'administration.equipe.association.label',
                'query_builder' => static fn (AssociationRepository $associations) => $associations->createQueryBuilder('a')->orderBy('a.nom', 'ASC'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EquipeInvitationData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

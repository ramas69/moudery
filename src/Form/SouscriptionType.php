<?php

declare(strict_types=1);

namespace App\Form;

use App\Abonnement\Catalogue;
use App\Form\Model\SouscriptionData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Souscription d'une association par son bureau central : les champs d'activation du compte, plus l'offre et les conditions. */
final class SouscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('offre', ChoiceType::class, [
                'label' => 'souscription.offre.label',
                'choices' => array_combine(Catalogue::codes(), Catalogue::codes()),
                'choice_label' => static fn (string $code): string => $code,
                'expanded' => true,
                'multiple' => false,
                'placeholder' => false,
            ])
            ->add('conditions', CheckboxType::class, [
                'label' => 'souscription.conditions.label',
                'required' => false,
            ]);
    }

    public function getParent(): string
    {
        return ActivationCompteType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => SouscriptionData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TypeContribution;
use App\Form\Model\CotisationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Ouverture ou réglage de la cotisation d'une ville (F-12). Option `types` : les types de contribution proposés. */
final class CotisationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<TypeContribution> $types */
        $types = $options['types'];
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'cotisations_association.ouverture.type',
                'choices' => array_combine(array_map(static fn (TypeContribution $t): string => $t->getNom(), $types), array_map(static fn (TypeContribution $t): string => $t->getCode(), $types)),
                'choice_translation_domain' => false,
                'disabled' => $options['type_fige'],
            ])
            ->add('montantMensuel', MoneyType::class, [
                'label' => 'cotisations_association.ouverture.montant',
                'currency' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01', 'inputmode' => 'decimal'],
            ])
            ->add('jourEcheance', IntegerType::class, [
                'label' => 'cotisations_association.ouverture.jour',
                'attr' => ['min' => 1, 'max' => 28, 'inputmode' => 'numeric'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CotisationData::class, 'types' => [], 'type_fige' => false]);
        $resolver->setAllowedTypes('types', 'array');
        $resolver->setAllowedTypes('type_fige', 'bool');
    }
}

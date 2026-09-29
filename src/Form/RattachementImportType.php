<?php

declare(strict_types=1);

namespace App\Form;

use App\Association\ImportAssociation;
use App\Entity\Ville;
use App\Form\Model\RattachementImportData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rattachement d'un import d'association (F-31). Mode « onglets » : un choix de ville par onglet importable ;
 * mode « colonne » : la feuille, la colonne « caisse », puis un choix de ville par valeur de cette colonne.
 * Les libellés des villes sont des données, pas des traductions : les choix sont construits traduits.
 */
final class RattachementImportType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $traducteur)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('mode', HiddenType::class);
        $cibles = $builder->create('cibles', FormType::class, ['label' => false, 'error_bubbling' => false]);

        if (ImportAssociation::MODE_COLONNE === $options['mode']) {
            $builder
                ->add('feuille', ChoiceType::class, [
                    'label' => 'import_association.rattachement.feuille',
                    'choices' => array_flip($options['feuillesChoix']),
                    'choice_translation_domain' => false,
                ])
                ->add('colonne', ChoiceType::class, [
                    'label' => 'import_association.rattachement.colonne',
                    'choices' => array_flip($options['colonnesChoix']),
                    'choice_translation_domain' => false,
                ])
                ->add('appliquer', SubmitType::class, ['label' => 'import_association.rattachement.appliquer', 'validate' => false]);

            foreach ($options['valeurs'] as $indice => $valeur) {
                $cibles->add('v'.$indice, ChoiceType::class, [
                    'label' => false,
                    'choices' => $this->choix($options['villes'], $valeur['valeur']),
                    'choice_translation_domain' => false,
                ]);
            }
        } else {
            foreach ($options['feuilles'] as $feuille) {
                $cibles->add('f'.$feuille['indice'], ChoiceType::class, [
                    'label' => false,
                    'choices' => $this->choix($options['villes'], (string) ($feuille['nom'] ?? '')),
                    'choice_translation_domain' => false,
                ]);
            }
        }
        $builder->add($cibles);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RattachementImportData::class,
            'translation_domain' => 'messages',
            'feuilles' => [],
            'feuillesChoix' => [],
            'colonnesChoix' => [],
            'valeurs' => [],
            'villes' => [],
        ]);
        $resolver->setRequired('mode');
        $resolver->setAllowedValues('mode', ImportAssociation::MODES);
        $resolver->setAllowedTypes('feuilles', 'array');
        $resolver->setAllowedTypes('feuillesChoix', 'array');
        $resolver->setAllowedTypes('colonnesChoix', 'array');
        $resolver->setAllowedTypes('valeurs', 'array');
        $resolver->setAllowedTypes('villes', Ville::class.'[]');
    }

    /**
     * @param list<Ville> $villes
     *
     * @return array<string, string> libellé => valeur
     */
    private function choix(array $villes, string $nom): array
    {
        $choix = [$this->traducteur->trans('import_association.rattachement.ignorer') => ImportAssociation::CIBLE_IGNORER];
        if ('' !== trim($nom)) {
            $choix[$this->traducteur->trans('import_association.rattachement.creer', ['nom' => $nom])] = ImportAssociation::CIBLE_CREER;
        }
        foreach ($villes as $ville) {
            $choix[$ville->getNom()] = 'ville:'.$ville->getId();
        }

        return $choix;
    }
}

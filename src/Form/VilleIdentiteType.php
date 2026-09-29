<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\VilleIdentiteData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire de l'étape Identité de l'assistant de création d'une ville (F-40). */
final class VilleIdentiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'assistant_ville.identite.nom.label',
                'attr' => [
                    'autofocus' => true,
                    'autocomplete' => 'off',
                    'maxlength' => 120,
                    'data-action' => 'input->ville-identite#marquerModifie',
                ],
            ])
            ->add('emailTresorier', EmailType::class, $this->optionsEmail('assistant_ville.identite.tresorier.label'))
            ->add('emailPresident', EmailType::class, $this->optionsEmail('assistant_ville.identite.president.label'))
            ->add('emailSecretaire', EmailType::class, $this->optionsEmail('assistant_ville.identite.secretaire.label'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VilleIdentiteData::class,
            'translation_domain' => 'messages',
        ]);
    }

    /** @return array<string, mixed> */
    private function optionsEmail(string $label): array
    {
        return [
            'label' => $label,
            'required' => false,
            'attr' => [
                'autocomplete' => 'email',
                'inputmode' => 'email',
                'maxlength' => 180,
                'data-ville-identite-target' => 'email',
                'data-action' => 'input->ville-identite#marquerModifie',
            ],
        ];
    }
}

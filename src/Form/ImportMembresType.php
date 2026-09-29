<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

/** Import d'un fichier de membres, CSV ou Excel (.xlsx) (F-31, F-42). Le fichier n'est lu qu'une fois, pour l'aperçu ; rien n'est stocké sur disque. */
final class ImportMembresType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('fichier', FileType::class, [
            'label' => 'assistant_ville.membres.import.fichier',
            'mapped' => false,
            'attr' => ['accept' => '.csv,.txt,.xlsx,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'constraints' => [
                new NotNull(message: 'membre.import.fichier_obligatoire'),
                new File(
                    maxSize: '4M',
                    maxSizeMessage: 'membre.import.trop_lourd',
                    // CSV (deviné texte brut ou CSV selon le système) et classeur Excel .xlsx (parfois deviné comme une archive ZIP).
                    mimeTypes: [
                        'text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'text/x-comma-separated-values', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream',
                    ],
                    mimeTypesMessage: 'membre.import.format',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'messages']);
    }
}

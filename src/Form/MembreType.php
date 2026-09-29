<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Foyer;
use App\Entity\Ville;
use App\Form\Model\MembreData;
use App\Repository\FoyerRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire d'ajout d'un membre à une ville (F-42). Les foyers proposés sont ceux de la ville. */
final class MembreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $ville = $options['ville'];
        \assert($ville instanceof Ville);

        $builder
            ->add('prenom', TextType::class, [
                'label' => 'assistant_ville.membres.formulaire.prenom',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80],
            ])
            ->add('nom', TextType::class, [
                'label' => 'assistant_ville.membres.formulaire.nom',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80],
            ])
            ->add('email', EmailType::class, [
                'label' => 'assistant_ville.membres.formulaire.email',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'email', 'maxlength' => 180],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'assistant_ville.membres.formulaire.telephone',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'tel', 'maxlength' => 30, 'placeholder' => '06 12 34 56 78'],
            ])
            ->add('localite', TextType::class, [
                'label' => 'assistant_ville.membres.formulaire.localite',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 80, 'placeholder' => 'Creil'],
            ])
            ->add('anneeNaissance', IntegerType::class, [
                'label' => 'assistant_ville.membres.formulaire.annee_naissance',
                'required' => false,
                'attr' => ['inputmode' => 'numeric', 'min' => 1900, 'max' => (int) date('Y'), 'placeholder' => '1985'],
            ])
            ->add('foyer', EntityType::class, [
                'class' => Foyer::class,
                'label' => 'assistant_ville.membres.formulaire.foyer',
                'required' => false,
                'placeholder' => 'assistant_ville.membres.formulaire.foyer_aucun',
                'choice_label' => 'nom',
                'query_builder' => static fn (FoyerRepository $foyers) => $foyers->createQueryBuilder('f')->andWhere('f.ville = :ville')->setParameter('ville', $ville)->orderBy('f.nom', 'ASC'),
            ])
            ->add('nouveauFoyer', TextType::class, [
                'label' => 'assistant_ville.membres.formulaire.nouveau_foyer',
                'required' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => 120, 'placeholder' => 'Famille Diaby'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MembreData::class,
            'translation_domain' => 'messages',
        ]);
        $resolver->setRequired('ville');
        $resolver->setAllowedTypes('ville', Ville::class);
    }
}

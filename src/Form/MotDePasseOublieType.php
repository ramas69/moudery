<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Model\MotDePasseOublieData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Formulaire « mot de passe oublié » (F-03). */
final class MotDePasseOublieType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'connexion.email',
            'attr' => ['autocomplete' => 'email', 'inputmode' => 'email', 'autofocus' => true, 'maxlength' => 180],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MotDePasseOublieData::class,
            'translation_domain' => 'messages',
        ]);
    }
}

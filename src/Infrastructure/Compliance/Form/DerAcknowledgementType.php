<?php

declare(strict_types=1);

namespace App\Infrastructure\Compliance\Form;

use App\Application\Compliance\DTO\Request\AcknowledgeDerRequest;
use App\Domain\Compliance\ValueObject\DerStatement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DerAcknowledgementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('declaredName', TextType::class, [
                'label' => 'Vos nom et prénom',
                'attr' => ['placeholder' => 'Jean Dupont', 'autocomplete' => 'name'],
                'empty_data' => '',
            ])
            ->add('accepted', CheckboxType::class, [
                // Même libellé que celui figé sur l'accusé : ce que le client coche est ce qui est prouvé.
                'label' => DerStatement::TEXT,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AcknowledgeDerRequest::class,
        ]);
    }
}

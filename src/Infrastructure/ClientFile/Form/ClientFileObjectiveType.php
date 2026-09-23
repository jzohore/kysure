<?php

declare(strict_types=1);

namespace App\Infrastructure\ClientFile\Form;

use App\Application\ClientFile\DTO\Request\ClientFileObjectiveDTO;
use App\Domain\ClientFile\Enum\InvestmentHorizon;
use App\Domain\ClientFile\Enum\InvestmentObjectiveType;
use App\Domain\ClientFile\Enum\ObjectivePriority;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientFileObjectiveType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Objectif',
                'required' => false,
                'placeholder' => 'Emplacement non utilisé',
                'choices' => $this->choicesFor(InvestmentObjectiveType::cases()),
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priorité',
                'required' => false,
                'placeholder' => '—',
                'choices' => $this->choicesFor(ObjectivePriority::cases()),
            ])
            ->add('horizon', ChoiceType::class, [
                'label' => 'Horizon',
                'required' => false,
                'placeholder' => '—',
                'choices' => $this->choicesFor(InvestmentHorizon::cases()),
            ])
            ->add('amount', IntegerType::class, [
                'label' => 'Montant envisagé (€)',
                'required' => false,
                'attr' => ['min' => 0, 'placeholder' => 'ex: 50000'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ClientFileObjectiveDTO::class]);
    }

    /**
     * @param list<InvestmentObjectiveType|ObjectivePriority|InvestmentHorizon> $cases
     *
     * @return array<string, string>
     */
    private function choicesFor(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$case->getLabel()] = $case->value;
        }

        return $choices;
    }
}

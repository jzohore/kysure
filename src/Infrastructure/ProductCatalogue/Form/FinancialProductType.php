<?php

declare(strict_types=1);

namespace App\Infrastructure\ProductCatalogue\Form;

use App\Application\ProductCatalogue\DTO\Request\FinancialProductRequest;
use App\Domain\Suitability\Enum\InvestorProfileLevel;
use App\Domain\Suitability\Enum\ProductFamily;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FinancialProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Nom du produit'])
            ->add('isin', TextType::class, [
                'label' => 'ISIN',
                'required' => false,
                'empty_data' => null,
                'help' => '12 caractères, laisser vide si non applicable (ex. contrat d\'assurance-vie interne).',
            ])
            ->add('family', ChoiceType::class, [
                'label' => 'Famille de produit',
                'placeholder' => 'Choisir...',
                'choices' => $this->choicesFor(ProductFamily::cases()),
            ])
            ->add('sriLevel', ChoiceType::class, [
                'label' => 'SRI (échelle de risque, 1 à 7)',
                'placeholder' => 'Choisir...',
                'choices' => array_combine(range(1, 7), range(1, 7)),
            ])
            ->add('minimumHorizonYears', IntegerType::class, [
                'label' => 'Horizon de placement minimum (années)',
                'attr' => ['min' => 0],
            ])
            ->add('annualFeesPercent', NumberType::class, [
                'label' => 'Frais annuels moyens (%)',
                'scale' => 2,
                'help' => 'Frais de gestion cumulés estimés, en pourcentage par an (ex. 1.50).',
                'attr' => ['min' => 0, 'step' => 0.01],
            ])
            ->add('targetInvestorProfiles', ChoiceType::class, [
                'label' => 'Profils investisseurs cibles',
                'choices' => $this->choicesFor(InvestorProfileLevel::cases()),
                'multiple' => true,
                'expanded' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FinancialProductRequest::class]);
    }

    /**
     * @param list<ProductFamily|InvestorProfileLevel> $cases
     *
     * @return array<string, int|string>
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\ClientFile\Form;

use App\Application\ClientFile\DTO\Request\UpdateClientFileRequest;
use App\Domain\ClientFile\Enum\Civility;
use App\Domain\ClientFile\Enum\MaritalStatus;
use App\Domain\ClientFile\Enum\ProfessionalStatus;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ClientFileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // --- État civil ---
            ->add('civility', ChoiceType::class, [
                'label' => 'Civilité',
                'placeholder' => 'Choisir...',
                'choices' => $this->choicesFor(Civility::cases()),
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Date de naissance',
                'widget' => 'single_text',
                'html5' => true,
            ])
            ->add('birthPlace', TextType::class, ['label' => 'Lieu de naissance'])
            ->add('nationality', TextType::class, ['label' => 'Nationalité'])
            // --- Situation familiale ---
            ->add('maritalStatus', ChoiceType::class, [
                'label' => 'Situation matrimoniale',
                'placeholder' => 'Choisir...',
                'choices' => $this->choicesFor(MaritalStatus::cases()),
            ])
            ->add('childrenCount', IntegerType::class, ['label' => 'Nombre d\'enfants', 'attr' => ['min' => 0]])
            // --- Situation professionnelle ---
            ->add('professionalStatus', ChoiceType::class, [
                'label' => 'Situation professionnelle',
                'placeholder' => 'Choisir...',
                'choices' => $this->choicesFor(ProfessionalStatus::cases()),
            ])
            ->add('profession', TextType::class, ['label' => 'Profession', 'required' => false])
            ->add('employer', TextType::class, ['label' => 'Employeur', 'required' => false])
            ->add('professionalSeniorityYears', IntegerType::class, [
                'label' => 'Ancienneté (années)',
                'required' => false,
                'attr' => ['min' => 0],
            ])
            // --- Situation patrimoniale ---
            ->add('annualIncome', IntegerType::class, ['label' => 'Revenus annuels du foyer (€)', 'attr' => ['min' => 0]])
            ->add('annualExpenses', IntegerType::class, ['label' => 'Charges annuelles (€)', 'attr' => ['min' => 0]])
            ->add('outstandingDebt', IntegerType::class, ['label' => 'Encours de crédits (€)', 'attr' => ['min' => 0]])
            ->add('investmentCapacity', IntegerType::class, [
                'label' => 'Capacité d\'investissement disponible (€)',
                'help' => 'Montant que le conseiller estime raisonnablement investissable — ce n\'est pas un simple total de liquidités.',
                'attr' => ['min' => 0],
            ])
            ->add('realEstateAmount', IntegerType::class, ['label' => 'Patrimoine immobilier (€)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('lifeInsuranceAmount', IntegerType::class, ['label' => 'Assurance-vie (€)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('peaAmount', IntegerType::class, ['label' => 'PEA (€)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('securitiesAccountAmount', IntegerType::class, ['label' => 'Compte-titres (€)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('regulatedSavingsAmount', IntegerType::class, ['label' => 'Livrets / épargne réglementée (€)', 'required' => false, 'attr' => ['min' => 0]])
            ->add('availableLiquidityAmount', IntegerType::class, ['label' => 'Liquidités disponibles (€)', 'required' => false, 'attr' => ['min' => 0]])
            // --- Objectifs d'investissement (3 emplacements fixes) ---
            ->add('objectives', CollectionType::class, [
                'label' => false,
                'entry_type' => ClientFileObjectiveType::class,
                'allow_add' => false,
                'allow_delete' => false,
                'by_reference' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => UpdateClientFileRequest::class]);
    }

    /**
     * @param list<Civility|MaritalStatus|ProfessionalStatus> $cases
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

<?php

namespace Database\Seeders;

use App\Modules\Catalogue\Enums\PriceListType;
use App\Modules\Catalogue\Enums\ResultType;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Catalogue\Models\Department;
use App\Modules\Catalogue\Models\LabTest;
use App\Modules\Catalogue\Models\LabTestCapability;
use App\Modules\Catalogue\Models\Package;
use App\Modules\Catalogue\Models\PriceList;
use App\Modules\Catalogue\Models\PriceListItem;
use App\Modules\Catalogue\Models\ReferenceRange;
use App\Modules\Catalogue\Models\RoutingRule;
use App\Modules\Catalogue\Models\TestParameter;
use App\Modules\Network\Enums\B2bClientStatus;
use App\Modules\Network\Enums\B2bClientType;
use App\Modules\Network\Models\B2bClient;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Franchise;
use App\Modules\Network\Models\Organization;
use App\Modules\Shared\Enums\Gender;
use App\Modules\Shared\Money\Money;
use Illuminate\Database\Seeder;

/**
 * Demo catalogue from spec §11.6: 50 tests in 7 departments with parameters
 * and reference ranges, 5 packages, MRP / partner / client price lists, lab
 * capabilities, routing for the demo network and two B2B clients.
 *
 * Runs after DevelopmentNetworkSeeder; local and testing only.
 */
class DevelopmentCatalogueSeeder extends Seeder
{
    /** Tests only the reference lab runs; clinical labs run the rest. */
    private const SPECIALISED = [
        'VITD', 'VITB12', 'FERR', 'PSA', 'INS-F', 'CORT', 'FT3', 'FT4',
        'UCS', 'BCS', 'AFB', 'BIOPSY', 'PAP', 'RTPCR', 'HBVDNA',
    ];

    /** @var array<string, LabTest> */
    private array $tests = [];

    public function run(): void
    {
        if (LabTest::query()->exists()) {
            return;
        }

        $organization = Organization::query()->firstOrFail();
        $departments = $this->departments($organization);

        foreach ($this->testDefinitions() as $definition) {
            $this->tests[$definition['code']] = $this->createTest($organization, $departments[$definition['department']], $definition);
        }

        $packages = $this->packages($organization);
        $this->priceLists($organization, $packages);
        $this->labCapabilitiesAndRouting();
    }

    /** @return array<string, Department> */
    private function departments(Organization $organization): array
    {
        $definitions = [
            'Haematology' => [SigningDiscipline::Pathology, 1],
            'Biochemistry' => [SigningDiscipline::Biochemistry, 2],
            'Serology' => [SigningDiscipline::Microbiology, 3],
            'Clinical Pathology' => [SigningDiscipline::Pathology, 4],
            'Microbiology' => [SigningDiscipline::Microbiology, 5],
            'Histopathology' => [SigningDiscipline::Pathology, 6],
            'Molecular' => [SigningDiscipline::Pathology, 7],
        ];

        $departments = [];
        foreach ($definitions as $name => [$discipline, $order]) {
            $department = new Department(['name' => $name, 'signing_discipline' => $discipline, 'report_order' => $order]);
            $department->organization_id = $organization->id;
            $department->save();
            $departments[$name] = $department;
        }

        return $departments;
    }

    /**
     * Each test: department, sample, container, TAT hours, MRP, parameters.
     * Parameter: [code, name, unit, low, high] for numeric; ['option', code, name, options] for options;
     * ['text', code, name] for free text; ['calc', code, name, unit, formula, low, high].
     *
     * @return list<array<string, mixed>>
     */
    private function testDefinitions(): array
    {
        $serum = ['Serum', 'Red top'];
        $edta = ['EDTA whole blood', 'Lavender top'];
        $citrate = ['Citrated plasma', 'Blue top'];
        $fluoride = ['Fluoride plasma', 'Grey top'];
        $urine = ['Urine', 'Sterile container'];
        $positiveNegative = ['Negative', 'Positive'];

        $test = fn (string $code, string $name, string $department, array $sample, int $tat, string $price, array $parameters, ?string $loinc = null) => [
            'code' => $code, 'name' => $name, 'department' => $department, 'sample_type' => $sample[0],
            'container_type' => $sample[1], 'tat_hours' => $tat, 'price' => $price, 'parameters' => $parameters, 'loinc' => $loinc,
        ];

        return [
            // Haematology
            $test('CBC', 'Complete Blood Count', 'Haematology', $edta, 6, '350', [
                ['HB', 'Haemoglobin', 'g/dL', '12', '17'], ['TLC', 'Total Leucocyte Count', '10^3/µL', '4', '11'],
                ['PLT', 'Platelet Count', '10^3/µL', '150', '410'], ['RBC', 'RBC Count', '10^6/µL', '4.2', '5.9'],
                ['PCV', 'Packed Cell Volume', '%', '36', '50'],
            ], '58410-2'),
            $test('ESR', 'Erythrocyte Sedimentation Rate', 'Haematology', $edta, 4, '150', [['ESR', 'ESR', 'mm/hr', '0', '20']]),
            $test('PS', 'Peripheral Smear', 'Haematology', $edta, 24, '300', [['text', 'PS', 'Peripheral smear findings']]),
            $test('PT', 'Prothrombin Time with INR', 'Haematology', $citrate, 6, '400', [['PT', 'Prothrombin Time', 'sec', '11', '13.5'], ['INR', 'INR', null, '0.8', '1.1']]),
            $test('APTT', 'Activated Partial Thromboplastin Time', 'Haematology', $citrate, 6, '450', [['APTT', 'APTT', 'sec', '25', '35']]),
            $test('BG', 'Blood Group and Rh Type', 'Haematology', $edta, 4, '150', [['option', 'ABO', 'ABO Group', ['A', 'B', 'AB', 'O']], ['option', 'RH', 'Rh Type', ['Positive', 'Negative']]]),

            // Biochemistry
            $test('GLU-F', 'Glucose Fasting', 'Biochemistry', $fluoride, 4, '100', [['GLUF', 'Glucose Fasting', 'mg/dL', '70', '100']], '1558-6'),
            $test('GLU-PP', 'Glucose Post Prandial', 'Biochemistry', $fluoride, 4, '100', [['GLUPP', 'Glucose PP', 'mg/dL', '70', '140']]),
            $test('GLU-R', 'Glucose Random', 'Biochemistry', $fluoride, 4, '100', [['GLUR', 'Glucose Random', 'mg/dL', '70', '140']]),
            $test('HBA1C', 'Glycated Haemoglobin (HbA1c)', 'Biochemistry', $edta, 8, '550', [['HBA1C', 'HbA1c', '%', '4', '5.6']], '4548-4'),
            $test('LIPID', 'Lipid Profile', 'Biochemistry', $serum, 8, '600', [
                ['TC', 'Total Cholesterol', 'mg/dL', '0', '200'], ['TG', 'Triglycerides', 'mg/dL', '0', '150'],
                ['HDL', 'HDL Cholesterol', 'mg/dL', '40', '60'],
                ['calc', 'LDL', 'LDL Cholesterol (calculated)', 'mg/dL', 'TC - HDL - (TG / 5)', '0', '100'],
                ['calc', 'VLDL', 'VLDL Cholesterol (calculated)', 'mg/dL', 'TG / 5', '5', '40'],
            ]),
            $test('LFT', 'Liver Function Test', 'Biochemistry', $serum, 8, '700', [
                ['TBIL', 'Total Bilirubin', 'mg/dL', '0.3', '1.2'], ['DBIL', 'Direct Bilirubin', 'mg/dL', '0', '0.3'],
                ['SGOT', 'SGOT (AST)', 'U/L', '0', '40'], ['SGPT', 'SGPT (ALT)', 'U/L', '0', '41'],
                ['ALP', 'Alkaline Phosphatase', 'U/L', '40', '129'], ['TP', 'Total Protein', 'g/dL', '6.4', '8.3'],
                ['ALB', 'Albumin', 'g/dL', '3.5', '5.2'],
            ]),
            $test('KFT', 'Kidney Function Test', 'Biochemistry', $serum, 8, '650', [
                ['UREA', 'Urea', 'mg/dL', '17', '43'], ['CREAT', 'Creatinine', 'mg/dL', '0.7', '1.3'],
                ['URIC', 'Uric Acid', 'mg/dL', '3.5', '7.2'], ['NA', 'Sodium', 'mmol/L', '136', '145'],
                ['K', 'Potassium', 'mmol/L', '3.5', '5.1'], ['CL', 'Chloride', 'mmol/L', '98', '107'],
            ]),
            $test('TSH', 'Thyroid Stimulating Hormone', 'Biochemistry', $serum, 12, '350', [['TSH', 'TSH', 'µIU/mL', '0.27', '4.2']], '3016-3'),
            $test('T3', 'Total T3', 'Biochemistry', $serum, 12, '250', [['T3', 'T3', 'ng/dL', '80', '200']]),
            $test('T4', 'Total T4', 'Biochemistry', $serum, 12, '250', [['T4', 'T4', 'µg/dL', '5.1', '14.1']]),
            $test('FT3', 'Free T3', 'Biochemistry', $serum, 24, '450', [['FT3', 'Free T3', 'pg/mL', '2', '4.4']]),
            $test('FT4', 'Free T4', 'Biochemistry', $serum, 24, '450', [['FT4', 'Free T4', 'ng/dL', '0.93', '1.7']]),
            $test('VITD', 'Vitamin D (25-OH)', 'Biochemistry', $serum, 24, '1400', [['VITD', '25-OH Vitamin D', 'ng/mL', '30', '100']], '1989-3'),
            $test('VITB12', 'Vitamin B12', 'Biochemistry', $serum, 24, '1100', [['VITB12', 'Vitamin B12', 'pg/mL', '197', '771']]),
            $test('FERR', 'Ferritin', 'Biochemistry', $serum, 24, '800', [['FERR', 'Ferritin', 'ng/mL', '30', '400']]),
            $test('IRON', 'Serum Iron', 'Biochemistry', $serum, 12, '400', [['IRON', 'Iron', 'µg/dL', '65', '175']]),
            $test('CA', 'Calcium', 'Biochemistry', $serum, 8, '200', [['CA', 'Calcium', 'mg/dL', '8.6', '10']]),
            $test('PHOS', 'Phosphorus', 'Biochemistry', $serum, 8, '200', [['PHOS', 'Phosphorus', 'mg/dL', '2.5', '4.5']]),
            $test('AMY', 'Amylase', 'Biochemistry', $serum, 8, '450', [['AMY', 'Amylase', 'U/L', '28', '100']]),
            $test('LIP', 'Lipase', 'Biochemistry', $serum, 8, '550', [['LIP', 'Lipase', 'U/L', '13', '60']]),
            $test('CRP', 'C-Reactive Protein', 'Biochemistry', $serum, 8, '400', [['CRP', 'CRP', 'mg/L', '0', '6']]),
            $test('HSCRP', 'High Sensitivity CRP', 'Biochemistry', $serum, 12, '650', [['HSCRP', 'hs-CRP', 'mg/L', '0', '1']]),
            $test('PSA', 'Prostate Specific Antigen (Total)', 'Biochemistry', $serum, 24, '800', [['PSA', 'Total PSA', 'ng/mL', '0', '4']]),
            $test('INS-F', 'Insulin Fasting', 'Biochemistry', $serum, 24, '900', [['INSF', 'Insulin Fasting', 'µIU/mL', '2.6', '24.9']]),
            $test('CORT', 'Cortisol (Morning)', 'Biochemistry', $serum, 24, '850', [['CORT', 'Cortisol', 'µg/dL', '6.2', '19.4']]),

            // Serology
            $test('HBSAG', 'Hepatitis B Surface Antigen', 'Serology', $serum, 8, '350', [['option', 'HBSAG', 'HBsAg', ['Non-reactive', 'Reactive']]]),
            $test('HIV', 'HIV 1 & 2 Antibodies', 'Serology', $serum, 8, '450', [['option', 'HIV', 'HIV 1 & 2', ['Non-reactive', 'Reactive']]]),
            $test('HCV', 'Hepatitis C Antibody', 'Serology', $serum, 8, '600', [['option', 'HCV', 'Anti-HCV', ['Non-reactive', 'Reactive']]]),
            $test('VDRL', 'VDRL', 'Serology', $serum, 8, '200', [['option', 'VDRL', 'VDRL', ['Non-reactive', 'Reactive']]]),
            $test('WIDAL', 'Widal Test', 'Serology', $serum, 8, '250', [['text', 'WIDAL', 'Widal titres']]),
            $test('NS1', 'Dengue NS1 Antigen', 'Serology', $serum, 6, '600', [['option', 'NS1', 'Dengue NS1', $positiveNegative]]),
            $test('MALAG', 'Malaria Antigen', 'Serology', $edta, 4, '350', [['option', 'MALAG', 'Malaria Antigen', $positiveNegative]]),
            $test('RA', 'Rheumatoid Factor', 'Serology', $serum, 8, '400', [['RA', 'RA Factor', 'IU/mL', '0', '14']]),
            $test('ASO', 'ASO Titre', 'Serology', $serum, 8, '400', [['ASO', 'ASO', 'IU/mL', '0', '200']]),

            // Clinical pathology
            $test('URINE-RE', 'Urine Routine Examination', 'Clinical Pathology', $urine, 4, '150', [
                ['text', 'UCOL', 'Colour'], ['URPH', 'pH', null, '4.6', '8'],
                ['option', 'UPROT', 'Protein', ['Nil', 'Trace', '+', '++', '+++']], ['option', 'USUG', 'Sugar', ['Nil', 'Trace', '+', '++', '+++']],
            ]),
            $test('STOOL-RE', 'Stool Routine Examination', 'Clinical Pathology', ['Stool', 'Stool container'], 8, '200', [['text', 'STOOL', 'Stool findings']]),
            $test('UMA', 'Urine Microalbumin', 'Clinical Pathology', $urine, 8, '500', [['UMA', 'Microalbumin', 'mg/L', '0', '20']]),

            // Microbiology
            $test('UCS', 'Urine Culture and Sensitivity', 'Microbiology', $urine, 72, '800', [['text', 'UCS', 'Culture report']]),
            $test('BCS', 'Blood Culture and Sensitivity', 'Microbiology', ['Blood', 'Culture bottle'], 120, '1200', [['text', 'BCS', 'Culture report']]),
            $test('AFB', 'Sputum for AFB', 'Microbiology', ['Sputum', 'Sterile container'], 24, '300', [['option', 'AFB', 'AFB', ['Not seen', 'Seen']]]),

            // Histopathology
            $test('BIOPSY', 'Histopathology (Small Biopsy)', 'Histopathology', ['Tissue in formalin', 'Formalin container'], 120, '1500', [['text', 'HPE', 'Histopathology report']]),
            $test('PAP', 'Pap Smear', 'Histopathology', ['Cervical smear', 'Slide'], 72, '700', [['text', 'PAP', 'Cytology report']]),

            // Molecular
            $test('RTPCR', 'SARS-CoV-2 RT-PCR', 'Molecular', ['Nasopharyngeal swab', 'VTM tube'], 24, '500', [['option', 'COVID', 'SARS-CoV-2 RNA', ['Not detected', 'Detected']]]),
            $test('HBVDNA', 'HBV DNA Quantitative', 'Molecular', $edta, 120, '4500', [['HBVDNA', 'HBV viral load', 'IU/mL', null, null]]),
        ];
    }

    /** @param  array<string, mixed>  $definition */
    private function createTest(Organization $organization, Department $department, array $definition): LabTest
    {
        $test = new LabTest([
            'department_id' => $department->id,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'short_name' => $definition['code'],
            'loinc_code' => $definition['loinc'],
            'sample_type' => $definition['sample_type'],
            'container_type' => $definition['container_type'],
            'tat_hours' => $definition['tat_hours'],
            'base_price' => $definition['price'],
        ]);
        $test->organization_id = $organization->id;
        $test->save();

        foreach ($definition['parameters'] as $order => $parameter) {
            $this->createParameter($test, $parameter, $order);
        }

        return $test;
    }

    /** @param  list<mixed>  $definition */
    private function createParameter(LabTest $test, array $definition, int $order): void
    {
        [$type, $code, $name] = match ($definition[0]) {
            'option' => [ResultType::Option, $definition[1], $definition[2]],
            'text' => [ResultType::Text, $definition[1], $definition[2]],
            'calc' => [ResultType::Calculated, $definition[1], $definition[2]],
            default => [ResultType::Numeric, $definition[0], $definition[1]],
        };

        $parameter = new TestParameter([
            'parameter_name' => $name,
            'code' => $code,
            'result_type' => $type,
            'unit' => match ($type) {
                ResultType::Numeric => $definition[2],
                ResultType::Calculated => $definition[3],
                default => null,
            },
            'decimal_places' => in_array($type, [ResultType::Numeric, ResultType::Calculated], true) ? 1 : null,
            'options' => $type === ResultType::Option ? $definition[3] : null,
            'formula' => $type === ResultType::Calculated ? $definition[4] : null,
            'display_order' => $order,
        ]);
        $parameter->test_id = $test->id;
        $parameter->save();

        [$low, $high] = match ($type) {
            ResultType::Numeric => [$definition[3], $definition[4]],
            ResultType::Calculated => [$definition[5], $definition[6]],
            default => [null, null],
        };

        if ($low === null && $high === null) {
            return;
        }

        // Haemoglobin differs by gender; everything else uses one adult range.
        $ranges = $code === 'HB'
            ? [[Gender::Male, '13', '17'], [Gender::Female, '12', '15']]
            : [[null, $low, $high]];

        foreach ($ranges as [$gender, $rangeLow, $rangeHigh]) {
            $range = new ReferenceRange([
                'gender' => $gender,
                'age_min_days' => 18 * 365,
                'ref_low' => $rangeLow,
                'ref_high' => $rangeHigh,
                'critical_low' => $code === 'HB' ? '7' : null,
                'critical_high' => $code === 'K' ? '6.5' : null,
                'display_text' => "{$rangeLow} - {$rangeHigh}",
            ]);
            $range->test_parameter_id = $parameter->id;
            $range->save();
        }
    }

    /** @return list<array{Package, Money}> each package with its MRP */
    private function packages(Organization $organization): array
    {
        $definitions = [
            'PKG-BASIC' => ['Basic Health Check', ['CBC', 'GLU-F', 'URINE-RE'], '799'],
            'PKG-DIAB' => ['Diabetes Care', ['GLU-F', 'GLU-PP', 'HBA1C', 'KFT', 'UMA'], '1499'],
            'PKG-HEART' => ['Heart Health', ['LIPID', 'HSCRP', 'GLU-F'], '1299'],
            'PKG-THY' => ['Thyroid Profile', ['T3', 'T4', 'TSH'], '599'],
            'PKG-FULL' => ['Full Body Check-up', ['CBC', 'LIPID', 'LFT', 'KFT', 'TSH', 'GLU-F', 'HBA1C', 'VITD', 'VITB12', 'URINE-RE'], '3499'],
        ];

        $packages = [];
        foreach ($definitions as $code => [$name, $testCodes, $price]) {
            $package = new Package(['code' => $code, 'name' => $name]);
            $package->organization_id = $organization->id;
            $package->save();
            $package->tests()->sync(array_map(fn (string $testCode) => $this->tests[$testCode]->id, $testCodes));
            $packages[] = [$package, Money::fromString($price)];
        }

        return $packages;
    }

    /** @param  list<array{Package, Money}>  $packages */
    private function priceLists(Organization $organization, array $packages): void
    {
        $mrp = $this->priceList($organization, 'MRP 2026', PriceListType::Mrp, isDefault: true);
        $partner = $this->priceList($organization, 'Partner Tier B', PriceListType::Partner);
        $client = $this->priceList($organization, 'Hospital Rates 2026', PriceListType::Client);
        $premium = $this->priceList($organization, 'MRP Patna Premium', PriceListType::Mrp);

        foreach ($this->tests as $test) {
            $this->price($mrp, $test->base_price, testId: $test->id);
            $this->price($partner, $test->base_price->percent('60'), testId: $test->id);
            $this->price($client, $test->base_price->percent('70'), testId: $test->id);
        }

        foreach ($packages as [$package, $price]) {
            $this->price($mrp, $price, packageId: $package->id);
            $this->price($partner, $price->percent('60'), packageId: $package->id);
            $this->price($client, $price->percent('70'), packageId: $package->id);
        }

        // One branch with its own patient prices for a few tests (spec §7.4 override).
        $this->price($premium, Money::fromString('400'), testId: $this->tests['CBC']->id);
        $this->price($premium, Money::fromString('650'), testId: $this->tests['LIPID']->id);
        Branch::query()->where('branch_code', 'PATPSC2')->update(['mrp_price_list_id' => $premium->id]);

        Franchise::query()->update(['partner_price_list_id' => $partner->id]);

        $this->b2bClient($organization, 'CLPCH', 'Patna City Hospital', B2bClientType::Hospital, 'PATCL1', $client);
        $this->b2bClient($organization, 'CLRCN', 'Ranchi Care Nursing Home', B2bClientType::NursingHome, 'RNCCL1', $client);
    }

    private function labCapabilitiesAndRouting(): void
    {
        $branch = fn (string $code): Branch => Branch::query()->where('branch_code', $code)->firstOrFail();
        $routine = array_filter($this->tests, fn (LabTest $test) => ! in_array($test->code, self::SPECIALISED, true));

        $this->capabilities($branch('PATREF'), $this->tests);
        $this->capabilities($branch('PATCL1'), $routine);
        $this->capabilities($branch('RNCCL1'), $routine);

        // Collection sites send to their nearest clinical lab, falling back to
        // the reference lab for specialised tests (spec §7.4 priority fallback).
        $routes = [
            'PATPSC1' => ['PATCL1', 'PATREF'],
            'PATPSC2' => ['PATCL1', 'PATREF'],
            'GAYPSC1' => ['PATCL1', 'PATREF'],
            'GAYPSC2' => ['PATCL1', 'PATREF'],
            'RNCPSC1' => ['RNCCL1', 'PATREF'],
            'DHNPSC1' => ['RNCCL1', 'PATREF'],
        ];

        foreach ($routes as $sourceCode => $labCodes) {
            foreach ($labCodes as $priority => $labCode) {
                RoutingRule::query()->create([
                    'source_branch_id' => $branch($sourceCode)->id,
                    'test_id' => null,
                    'processing_branch_id' => $branch($labCode)->id,
                    'priority' => $priority + 1,
                ]);
            }
        }

        // Clinical labs run their own routine tests (the spec's "source itself"
        // step); a default rule would send even those away, so only the
        // specialised tests get a rule to the reference lab.
        foreach (['PATCL1', 'RNCCL1'] as $labCode) {
            foreach (self::SPECIALISED as $testCode) {
                RoutingRule::query()->create([
                    'source_branch_id' => $branch($labCode)->id,
                    'test_id' => $this->tests[$testCode]->id,
                    'processing_branch_id' => $branch('PATREF')->id,
                    'priority' => 1,
                ]);
            }
        }
    }

    private function priceList(Organization $organization, string $name, PriceListType $type, bool $isDefault = false): PriceList
    {
        $priceList = new PriceList([
            'name' => $name,
            'list_type' => $type,
            'is_default_mrp' => $isDefault,
            'valid_from' => '2026-04-01',
        ]);
        $priceList->organization_id = $organization->id;
        $priceList->save();

        return $priceList;
    }

    private function price(PriceList $priceList, Money $price, ?string $testId = null, ?string $packageId = null): void
    {
        $item = new PriceListItem(['test_id' => $testId, 'package_id' => $packageId, 'price' => $price]);
        $item->price_list_id = $priceList->id;
        $item->save();
    }

    /** @param  array<string, LabTest>  $tests */
    private function capabilities(Branch $lab, array $tests): void
    {
        foreach ($tests as $test) {
            $capability = new LabTestCapability(['test_id' => $test->id]);
            $capability->branch_id = $lab->id;
            $capability->save();
        }
    }

    private function b2bClient(Organization $organization, string $code, string $name, B2bClientType $type, string $branchCode, PriceList $priceList): void
    {
        $servicingBranch = Branch::query()->where('branch_code', $branchCode)->firstOrFail();

        B2bClient::factory()->servicedBy($servicingBranch)->create([
            'organization_id' => $organization->id,
            'client_code' => $code,
            'name' => $name,
            'client_type' => $type,
            'price_list_id' => $priceList->id,
            'credit_limit' => '200000.00',
            'status' => B2bClientStatus::Active,
        ]);
    }
}

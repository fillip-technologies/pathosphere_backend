<?php

namespace Database\Seeders;

use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Auth\Services\StaffAccounts;
use App\Modules\Catalogue\Enums\SigningDiscipline;
use App\Modules\Catalogue\Models\Department;
use App\Modules\Lab\Models\Signatory;
use App\Modules\Network\Models\Branch;
use App\Modules\Shared\Files\PrivateFileStore;
use App\Modules\Shared\Files\PrivatePaths;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo signatories from spec §11.6: at every lab, a pathologist for the
 * pathology departments, a biochemist and a microbiologist. Local only: it
 * writes signature images to private storage.
 *
 * All demo doctors share one password, printed once; each enrols an
 * authenticator app at first sign-in.
 */
class DevelopmentLabSeeder extends Seeder
{
    private const DOCTORS = [
        'pathologist' => [SigningDiscipline::Pathology, 'MD Pathology', ['Haematology', 'Clinical Pathology', 'Histopathology', 'Molecular']],
        'biochemist' => [SigningDiscipline::Biochemistry, 'MD Biochemistry', ['Biochemistry']],
        'microbiologist' => [SigningDiscipline::Microbiology, 'MD Microbiology', ['Serology', 'Microbiology']],
    ];

    private const LABS = ['PATREF', 'PATCL1', 'RNCCL1'];

    public function run(StaffAccounts $accounts, PrivateFileStore $files): void
    {
        if (Signatory::query()->exists()) {
            return;
        }

        $roleId = Role::query()->whereNull('organization_id')->where('name', SystemRole::Signatory->value)->value('id');
        $departments = Department::query()->pluck('id', 'name');
        $password = Str::password(16);
        $phone = 9100000000;

        foreach (self::LABS as $labCode) {
            $lab = Branch::query()->where('branch_code', $labCode)->firstOrFail();

            foreach (self::DOCTORS as $role => [$discipline, $qualification, $departmentNames]) {
                $user = new User([
                    'role_id' => $roleId,
                    'branch_id' => $lab->id,
                    'name' => 'Dr. '.ucfirst($role).' '.$labCode,
                    'email' => strtolower("{$role}.{$labCode}@demo.local"),
                    'phone' => (string) $phone++,
                ]);
                $user->organization_id = $lab->organization_id;
                $user->status = UserStatus::Active;
                $user->save();
                $accounts->create($user, $password);

                foreach ($departmentNames as $departmentName) {
                    $signatory = new Signatory([
                        'user_id' => $user->id,
                        'branch_id' => $lab->id,
                        'department_id' => $departments[$departmentName],
                        'signing_discipline' => $discipline,
                        'qualification' => $qualification,
                        'council_name' => 'Bihar Medical Council',
                        'registration_no' => 'DEMO-'.strtoupper(Str::random(6)),
                        'valid_till' => now()->addYear()->toDateString(),
                    ]);
                    $signatory->id = $signatory->newUniqueId();
                    $signatory->organization_id = $lab->organization_id;
                    $signatory->signature_image_path = $files->putNew(PrivatePaths::signature($signatory->id), $this->signatureImage($user->name))->path;
                    $signatory->save();
                }
            }
        }

        $this->command?->warn("Demo signatories (e.g. pathologist.patcl1@demo.local) created with password: {$password}");
    }

    /** A PNG with the doctor's name, standing in for a scanned signature. */
    private function signatureImage(string $name): string
    {
        $image = imagecreatetruecolor(240, 60);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagestring($image, 5, 10, 22, $name, (int) imagecolorallocate($image, 20, 40, 120));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}

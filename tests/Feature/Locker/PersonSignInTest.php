<?php

namespace Tests\Feature\Locker;

use App\Modules\Auth\Models\Account;
use App\Modules\Auth\Models\OtpVerification;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Booking\Models\Patient;
use App\Modules\Locker\Models\FamilyMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\Support\Booking\BooksOrders;
use Tests\Support\Lab\RunsLab;
use Tests\Support\Locker\FillsLocker;
use Tests\Support\Samples\MovesSamples;
use Tests\TestCase;

/** Patient and doctor sign-in by SMS code (spec §8.1, §10.3). */
final class PersonSignInTest extends TestCase
{
    use BooksOrders;
    use BuildsStaff;
    use FillsLocker;
    use MovesSamples;
    use RefreshDatabase;
    use RunsLab;

    private const PHONE = '9876501234';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpDemoNetwork();
        CarbonImmutable::setTestNow('2026-10-12 05:00:00');
        $this->actingAsStaff($this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1'))->registerPatient();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_an_unknown_phone_gets_the_same_answer_and_no_message(): void
    {
        $known = $this->requestCode(self::PHONE)->assertStatus(202)->json('data');
        $this->messages->clear();
        $unknown = $this->requestCode('9000011111')->assertStatus(202)->json('data');

        $this->assertSame(array_keys($known), array_keys($unknown));
        $this->assertSame([], $this->messages->sent);
        $this->verifyCode('9000011111', '123456')->assertStatus(401)->assertJsonPath('error.code', 'OTP_INVALID');
    }

    public function test_only_a_keyed_hash_of_the_code_is_stored_and_a_code_works_once(): void
    {
        $this->requestCode(self::PHONE)->assertStatus(202);
        $code = $this->lastOtpSentTo(self::PHONE);

        $stored = OtpVerification::query()->sole();
        $this->assertNotSame($code, $stored->code_hash);
        $this->assertNotSame(hash('sha256', $code), $stored->code_hash);
        $this->assertSame(64, strlen($stored->code_hash));

        $this->verifyCode(self::PHONE, $code)->assertOk()->assertJsonPath('data.status', 'authenticated');
        $this->verifyCode(self::PHONE, $code)->assertStatus(401)->assertJsonPath('error.code', 'OTP_INVALID');
    }

    public function test_five_wrong_codes_kill_the_code(): void
    {
        $this->requestCode(self::PHONE);
        $code = $this->lastOtpSentTo(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($try = 1; $try <= 5; $try++) {
            $this->verifyCode(self::PHONE, $wrong)->assertStatus(401)->assertJsonPath('error.code', 'OTP_INVALID');
        }

        $this->verifyCode(self::PHONE, $code)->assertStatus(401)->assertJsonPath('error.code', 'OTP_ATTEMPTS_EXCEEDED');
    }

    public function test_a_code_expires_after_five_minutes(): void
    {
        $this->requestCode(self::PHONE);
        $code = $this->lastOtpSentTo(self::PHONE);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5)->addSecond());
        $this->verifyCode(self::PHONE, $code)->assertStatus(401)->assertJsonPath('error.code', 'OTP_EXPIRED');
    }

    public function test_a_new_code_waits_thirty_seconds_replaces_the_old_one_and_is_capped_per_day(): void
    {
        $this->requestCode(self::PHONE)->assertStatus(202);
        $first = $this->lastOtpSentTo(self::PHONE);

        $this->requestCode(self::PHONE)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'OTP_RESEND_TOO_SOON')
            ->assertJsonPath('error.details.0.retry_after_seconds', 30);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(31));
        $this->requestCode(self::PHONE)->assertStatus(202);
        $second = $this->lastOtpSentTo(self::PHONE);

        if ($first !== $second) {
            $this->verifyCode(self::PHONE, $first)->assertStatus(401);
        }
        $this->verifyCode(self::PHONE, $second)->assertOk();

        // Ten codes a day per phone (two sent so far).
        for ($sent = 3; $sent <= 10; $sent++) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
            $this->requestCode(self::PHONE)->assertStatus(202);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));
        $this->requestCode(self::PHONE)->assertStatus(429)->assertJsonPath('error.code', 'OTP_DAILY_LIMIT');
    }

    public function test_one_phone_signs_in_the_family_and_links_everyone_on_it(): void
    {
        $holderId = $this->asSystem(fn () => (string) Patient::query()->where('phone', self::PHONE)->value('id'));
        $frontDesk = $this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1');
        $son = $this->actingAsStaff($frontDesk)->registerPatient(['name' => 'Aman Kumar', 'gender' => 'male', 'age_years' => 12, 'guardian_patient_id' => $holderId]);
        $mother = $this->actingAsStaff($frontDesk)->registerPatient(['name' => 'Sita Devi', 'age_years' => 62]);

        $token = $this->signInByPhone(self::PHONE);

        $account = Account::query()->where('owner_type', 'patient')->sole();
        $this->assertSame([$holderId, self::PHONE, 'otp'], [$account->owner_id, $account->login_identifier, $account->auth_method->value]);
        $family = $this->asPerson($token)->getJson('/api/v1/me/family')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [[$son['id'], 'child'], [$mother['id'], 'other']],
            array_map(fn (array $member) => [$member['patient']['id'], $member['relation']], $family['members']),
        );
        $this->assertSame('******1234', $family['account_holder']['phone']);

        // The holder fixes a relation and removes a member; the next sign-in keeps that.
        $membersByPatient = array_combine(array_map(fn (array $member) => $member['patient']['id'], $family['members']), $family['members']);
        $motherRow = $membersByPatient[$mother['id']];
        $this->asPerson($token)->patchJson("/api/v1/me/family/{$motherRow['id']}", ['relation' => 'parent'])->assertOk()->assertJsonPath('data.relation', 'parent');
        $this->asPerson($token)->patchJson("/api/v1/me/family/{$motherRow['id']}", ['name' => 'Someone Else'])->assertStatus(422)->assertJsonPath('error.code', 'FAMILY_MEMBER_LINKED');
        $sonRow = $membersByPatient[$son['id']];
        $this->asPerson($token)->deleteJson("/api/v1/me/family/{$sonRow['id']}")->assertNoContent();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $token = $this->signInByPhone(self::PHONE);
        $members = $this->asPerson($token)->getJson('/api/v1/me/family')->json('data.members');
        $this->assertSame([[$mother['id'], 'parent']], array_map(fn (array $member) => [$member['patient']['id'], $member['relation']], $members));
        $this->asPerson($token, $son['id'])->getJson('/api/v1/me/records')->assertNotFound();
        $this->asPerson($token, $mother['id'])->getJson('/api/v1/me/records')->assertOk();
        $this->assertSame(1, Account::query()->where('owner_type', 'patient')->count());

        // A dependant not yet registered anywhere is kept by name.
        $baby = $this->asPerson($token)->postJson('/api/v1/me/family', ['name' => 'Baby Kumari', 'relation' => 'grandchild', 'dob' => '2026-05-01'])
            ->assertCreated()
            ->assertJsonPath('data.can_switch', false)
            ->json('data');
        $this->asPerson($token)->getJson("/api/v1/me/family/{$baby['id']}")->assertOk()->assertJsonPath('data.name', 'Baby Kumari');
        $this->assertSame(1, FamilyMember::query()->whereNull('member_patient_id')->count());
    }

    public function test_patients_doctors_and_staff_each_stay_on_their_own_endpoints(): void
    {
        $frontDesk = $this->lockerStaff(SystemRole::FrontDesk, 'PATPSC1');
        $this->actingAsStaff($frontDesk)->postJson('/api/v1/doctors', ['name' => 'Dr Anil Sinha', 'phone' => '+919811100000'])->assertCreated();

        $patientToken = $this->signInByPhone(self::PHONE);
        $doctorToken = $this->signInByPhone('9811100000', 'doctor');

        $this->asPerson($patientToken)->getJson('/api/v1/me/records')->assertOk();
        $this->asPerson($patientToken)->getJson('/api/v1/me/shared-records')->assertForbidden();
        $this->asPerson($patientToken)->getJson('/api/v1/orders')->assertForbidden();
        $this->asPerson($doctorToken)->getJson('/api/v1/me/shared-records')->assertOk()->assertJsonCount(0, 'data');
        $this->asPerson($doctorToken)->getJson('/api/v1/me/records')->assertForbidden();
        $this->actingAsStaff($frontDesk)->getJson('/api/v1/me/records')->assertForbidden();

        // An unregistered doctor phone gets no account.
        $this->messages->clear();
        $this->requestCode('9811199999', 'doctor')->assertStatus(202);
        $this->assertSame([], $this->messages->sent);

        // Signing out ends the session.
        app('auth')->forgetGuards();
        $this->withToken($patientToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->asPerson($patientToken)->getJson('/api/v1/me/records')->assertUnauthorized();
    }

    public function test_code_requests_are_rate_limited_per_phone(): void
    {
        RateLimiter::clear('otp-challenge-phone:'.self::PHONE);

        for ($request = 1; $request <= 3; $request++) {
            $this->requestCode(self::PHONE);
        }

        $this->requestCode(self::PHONE)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    /** @return TestResponse<JsonResponse> */
    private function requestCode(string $phone, string $accountType = 'patient'): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp-challenges', ['phone' => $phone, 'account_type' => $accountType]);
    }

    /** @return TestResponse<JsonResponse> */
    private function verifyCode(string $phone, string $code, string $accountType = 'patient'): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp-verifications', ['phone' => $phone, 'account_type' => $accountType, 'code' => $code]);
    }
}

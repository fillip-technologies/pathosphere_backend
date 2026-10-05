<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Domain\Totp;
use App\Modules\Auth\Enums\UserStatus;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Permissions\SystemRole;
use App\Modules\Auth\Services\StaffAccounts;
use App\Modules\Network\Models\Branch;
use App\Modules\Network\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Support\Auth\BuildsStaff;
use Tests\TestCase;

/** Staff sign-in, MFA, refresh and sign-out (spec §8.1, §10.3–10.4). */
final class StaffSignInTest extends TestCase
{
    use BuildsStaff;
    use RefreshDatabase;

    private User $frontDesk;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();

        $branch = $this->asSystem(fn () => Branch::factory()
            ->in(Region::factory()->create(['organization_id' => $this->organization->id]))
            ->create());

        $this->frontDesk = $this->staff(SystemRole::FrontDesk, ['branch_id' => $branch->id], ['email' => 'desk@example.com']);
        $this->superAdmin = $this->staff(SystemRole::SuperAdmin, [], ['email' => 'admin@example.com']);
    }

    public function test_staff_without_mfa_get_tokens_that_work(): void
    {
        $response = $this->signIn('desk@example.com', self::STAFF_PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.tokens.token_type', 'Bearer');

        $this->withToken($response->json('data.tokens.access_token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'desk@example.com')
            ->assertJsonPath('data.permissions', ['register_patient', 'create_order', 'collect_payment', 'print_barcode']);
    }

    public function test_the_login_identifier_is_case_insensitive(): void
    {
        $this->signIn('DESK@Example.com', self::STAFF_PASSWORD)->assertJsonPath('data.status', 'authenticated');
    }

    public function test_wrong_password_and_unknown_account_look_the_same(): void
    {
        $this->signIn('desk@example.com', 'wrong-password')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $this->signIn('nobody@example.com', 'wrong-password')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_five_failures_lock_the_account_even_for_the_right_password(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->signIn('desk@example.com', 'wrong-password')->assertUnauthorized();
        }

        $this->signIn('desk@example.com', self::STAFF_PASSWORD)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'ACCOUNT_LOCKED');

        $this->travel(16)->minutes();
        $this->signIn('desk@example.com', self::STAFF_PASSWORD)->assertJsonPath('data.status', 'authenticated');
    }

    public function test_disabled_staff_cannot_sign_in_or_use_old_tokens(): void
    {
        $token = $this->tokenFor($this->frontDesk);
        $this->asSystem(fn () => $this->frontDesk->forceFill(['status' => UserStatus::Disabled])->save());

        $this->signIn('desk@example.com', self::STAFF_PASSWORD)->assertJsonPath('error.code', 'ACCOUNT_DISABLED');

        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    public function test_roles_that_need_mfa_must_enrol_then_verify_a_code(): void
    {
        $challenge = $this->signIn('admin@example.com', self::STAFF_PASSWORD)
            ->assertOk()
            ->assertJsonPath('data.status', 'mfa_enrollment_required')
            ->assertJsonMissingPath('data.tokens')
            ->json('data.mfa_challenge_token');

        $enrolment = $this->postJson('/api/v1/auth/mfa-enrollments', ['mfa_challenge_token' => $challenge])->assertOk();
        $secret = $enrolment->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $enrolment->json('data.otpauth_uri'));

        $this->postJson('/api/v1/auth/mfa-verifications', ['mfa_challenge_token' => $challenge, 'code' => $this->wrongCode($secret)])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'MFA_CODE_INVALID');

        $this->postJson('/api/v1/auth/mfa-verifications', ['mfa_challenge_token' => $challenge, 'code' => Totp::codeAt($secret, time())])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated');

        $this->assertTrue($this->asSystem(fn () => app(StaffAccounts::class)->for($this->superAdmin)->mfa_enabled));

        // Next time, MFA is asked for, not enrolment.
        $this->signIn('admin@example.com', self::STAFF_PASSWORD)->assertJsonPath('data.status', 'mfa_required');
    }

    public function test_a_challenge_cannot_be_reused_after_success(): void
    {
        $challenge = $this->signIn('admin@example.com', self::STAFF_PASSWORD)->json('data.mfa_challenge_token');
        $secret = $this->postJson('/api/v1/auth/mfa-enrollments', ['mfa_challenge_token' => $challenge])->json('data.secret');
        $code = Totp::codeAt($secret, time());

        $this->postJson('/api/v1/auth/mfa-verifications', ['mfa_challenge_token' => $challenge, 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/mfa-verifications', ['mfa_challenge_token' => $challenge, 'code' => $code])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'MFA_CHALLENGE_INVALID');
    }

    public function test_refresh_rotates_and_the_old_pair_stops_working(): void
    {
        $first = $this->signIn('desk@example.com', self::STAFF_PASSWORD)->json('data.tokens');

        $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()
            ->json('data.tokens');

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_REFRESH_TOKEN');

        app('auth')->forgetGuards();
        $this->withToken($first['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken($second['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_access_tokens_expire_after_fifteen_minutes(): void
    {
        $token = $this->signIn('desk@example.com', self::STAFF_PASSWORD)->json('data.tokens.access_token');

        $this->travel(16)->minutes();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_logout_revokes_the_session(): void
    {
        $tokens = $this->signIn('desk@example.com', self::STAFF_PASSWORD)->json('data.tokens');

        $this->withToken($tokens['access_token'])->postJson('/api/v1/auth/logout')->assertNoContent();

        app('auth')->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertUnauthorized();
    }

    /** @return TestResponse<JsonResponse> */
    private function signIn(string $identifier, string $password): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['login_identifier' => $identifier, 'password' => $password]);
    }

    private function wrongCode(string $secret): string
    {
        $valid = [Totp::codeAt($secret, time() - 30), Totp::codeAt($secret, time()), Totp::codeAt($secret, time() + 30)];

        foreach (range(0, 999999) as $candidate) {
            $code = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);
            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }

        $this->fail('Could not build a wrong code.');
    }
}

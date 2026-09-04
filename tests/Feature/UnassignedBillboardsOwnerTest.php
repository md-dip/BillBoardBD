<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\User;
use Database\Seeders\UnassignedBillboardsOwnerSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cover for the second demo owner (owner2@test.com) and the seeder that hands
 * it every billboard nobody else owns. An unowned board can never clear the
 * owner-approval stage of a booking, so leaving any behind is a dead end.
 */
class UnassignedBillboardsOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hands_every_unowned_board_to_the_demo_owner(): void
    {
        $existingOwner = User::create(['name' => 'Existing', 'email' => 'existing@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $alreadyOwned = $this->billboard('Already Owned', $existingOwner->id);
        $orphanOne = $this->billboard('Orphan One', null);
        $orphanTwo = $this->billboard('Orphan Two', null);

        $this->seed(UserSeeder::class);
        $this->seed(UnassignedBillboardsOwnerSeeder::class);

        $demoOwner = User::query()->where('email', UnassignedBillboardsOwnerSeeder::OWNER_EMAIL)->firstOrFail();

        $this->assertSame('owner', $demoOwner->role);
        $this->assertSame($demoOwner->id, $orphanOne->fresh()->owner_id);
        $this->assertSame($demoOwner->id, $orphanTwo->fresh()->owner_id);
        $this->assertSame(0, Billboard::query()->whereNull('owner_id')->count(), 'no board may be left unowned');

        // Someone else's board is not up for grabs.
        $this->assertSame($existingOwner->id, $alreadyOwned->fresh()->owner_id);
    }

    public function test_running_it_again_claims_nothing_further(): void
    {
        $existingOwner = User::create(['name' => 'Existing', 'email' => 'existing@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $alreadyOwned = $this->billboard('Already Owned', $existingOwner->id);
        $this->billboard('Orphan', null);

        $this->seed(UserSeeder::class);
        $this->seed(UnassignedBillboardsOwnerSeeder::class);
        $this->seed(UnassignedBillboardsOwnerSeeder::class);

        $demoOwner = User::query()->where('email', UnassignedBillboardsOwnerSeeder::OWNER_EMAIL)->firstOrFail();

        $this->assertSame(1, Billboard::query()->where('owner_id', $demoOwner->id)->count());
        $this->assertSame($existingOwner->id, $alreadyOwned->fresh()->owner_id);
    }

    public function test_the_new_owner_logs_in_like_the_other_demo_accounts(): void
    {
        $this->seed(UserSeeder::class);

        // Demo emails skip the password check (Shared\AuthController), which is
        // what the login page's "Any password works" note promises.
        $this->postJson('/api/login', ['email' => 'owner2@test.com', 'password' => 'anything-at-all'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'owner')
            ->assertJsonPath('data.user.name', 'Dhaka Outdoor Network');
    }

    public function test_the_claimed_boards_show_up_as_that_owners_own(): void
    {
        $this->billboard('Orphan One', null);
        $this->billboard('Orphan Two', null);

        $this->seed(UserSeeder::class);
        $this->seed(UnassignedBillboardsOwnerSeeder::class);

        Sanctum::actingAs(User::query()->where('email', UnassignedBillboardsOwnerSeeder::OWNER_EMAIL)->firstOrFail());

        $titles = collect($this->getJson('/api/owner/billboards')->assertOk()->json('data.data'))->pluck('title');

        $this->assertEqualsCanonicalizing(['Orphan One', 'Orphan Two'], $titles->all());
    }

    public function test_an_owner_gets_every_board_back_not_just_the_first_page(): void
    {
        // 25 boards - more than the old page size of 20. Nothing in the app
        // renders pagination controls, so a short page does not mean "page 2
        // exists", it means those boards are invisible and the dashboard's
        // count is wrong.
        for ($i = 1; $i <= 25; $i++) {
            $this->billboard('Orphan '.$i, null);
        }

        $this->seed(UserSeeder::class);
        $this->seed(UnassignedBillboardsOwnerSeeder::class);

        Sanctum::actingAs(User::query()->where('email', UnassignedBillboardsOwnerSeeder::OWNER_EMAIL)->firstOrFail());

        $page = $this->getJson('/api/owner/billboards')->assertOk()->json('data');

        $this->assertSame(25, $page['total']);
        $this->assertCount(25, $page['data'], 'every board must come back in one page');
    }

    private function billboard(string $title, ?int $ownerId): Billboard
    {
        return Billboard::create([
            'owner_id' => $ownerId, 'title' => $title, 'address' => 'Dhaka',
            'latitude' => 23.8, 'longitude' => 90.4, 'size' => '10ft x 8ft', 'type' => 'neon',
            'daily_rate' => 1000, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);
    }
}

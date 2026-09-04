<?php

namespace Database\Seeders;

use App\Models\Billboard;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Hands every billboard nobody owns to one demo owner account, so no board in
 * the catalogue is left unowned.
 *
 * An unowned board is a dead end in the booking flow: the owner-approval stage
 * is scoped by billboards.owner_id (Owner\BookingController), so a client who
 * books one would sit at pending_owner_approval with nobody able to accept -
 * and the board shows up on no owner dashboard, no payout, no revenue figure.
 *
 * Runs LAST in DatabaseSeeder: OwnerDemoSeeder claims its four named boards for
 * owner@test.com first, and this picks up whatever is still unclaimed.
 * Re-running is safe - it only ever touches rows that have no owner_id.
 */
class UnassignedBillboardsOwnerSeeder extends Seeder
{
    /** The demo login itself is created in UserSeeder, with the other three. */
    public const OWNER_EMAIL = 'owner2@test.com';

    public function run(): void
    {
        $owner = User::query()->where('email', self::OWNER_EMAIL)->first();

        if (! $owner) {
            return;
        }

        $claimed = Billboard::query()->whereNull('owner_id')->update(['owner_id' => $owner->id]);

        $this->command?->info("Assigned {$claimed} unowned billboard(s) to ".self::OWNER_EMAIL);
    }
}

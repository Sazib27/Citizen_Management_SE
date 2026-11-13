<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    public function run(): void
    {
        $citizens = User::where('role', 'citizen')->orderBy('id')->take(5)->get();

        if ($citizens->isEmpty()) {
            return;
        }

        foreach ($citizens as $index => $citizen) {
            $baseValue = 3500000 + ($index * 250000);
            Property::create([
                'owner_id' => $citizen->id,
                'title' => 'Property #'.($index + 1),
                'type' => $index % 2 === 0 ? 'residential' : 'commercial',
                'address_line' => rand(10, 200).' Example Street',
                'city' => 'Dhaka',
                'state' => 'Dhaka Division',
                'postal_code' => '120'.$index,
                'area_sqft' => 1200 + ($index * 150),
                'is_active' => true,
                'is_available_for_rent' => $index % 2 === 0,
                'rent_price' => $index % 2 === 0 ? 15000 + ($index * 1000) : null,
                'assessed_value' => $baseValue,
                'land_use' => $index % 2 === 0 ? 'residential' : 'commercial',
                'last_valuation_at' => now()->subMonths($index + 1),
                'description' => 'Sample property managed via Property Management module.',
            ]);
        }

        /*
         * The demo rent agreement needs a rentable property belonging to
         * the demo landlord (test1@gmail.com). The loop above assigns
         * properties by position, so the landlord may or may not end up
         * with a rentable one — guarantee it here rather than leaving
         * the demo seeder to fail with a warning.
         */
        $landlord = User::where('email', 'test1@gmail.com')->first();

        if ($landlord) {
            $hasRentable = Property::where('owner_id', $landlord->id)
                ->where('is_available_for_rent', true)
                ->exists();

            if (! $hasRentable) {
                Property::create([
                    'owner_id' => $landlord->id,
                    'title' => 'Demo Rental Property',
                    'type' => 'residential',
                    'address_line' => '12 Demo Housing Road',
                    'city' => 'Dhaka',
                    'state' => 'Dhaka Division',
                    'postal_code' => '1209',
                    'area_sqft' => 1800,
                    'is_active' => true,
                    'is_available_for_rent' => true,
                    'rent_price' => 15000,
                    'assessed_value' => 3500000,
                    'land_use' => 'residential',
                    'last_valuation_at' => now()->subMonth(),
                    'description' => 'Demo property used by the rent agreement showcase seeder.',
                ]);
            }
        }
    }
}

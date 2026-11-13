<?php

namespace Database\Seeders;

use App\Models\Property;
use App\Models\RentAgreement;
use App\Models\RentalRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RentAgreementDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = User::where('email', 'test@gmail.com')->first();
        $landlord = User::where('email', 'test1@gmail.com')->first();
        $admin = User::where('role', 'admin')->first();

        if (!$tenant || !$landlord) {
            $this->command?->warn('Run UserSeeder first (test@gmail.com + test1@gmail.com).');
            return;
        }

        $property = Property::where('owner_id', $landlord->id)
            ->where('is_available_for_rent', true)
            ->first();

        if (!$property) {
            $this->command?->warn('No rentable property owned by test1@gmail.com. Run PropertySeeder first.');
            return;
        }

        $start = Carbon::today();
        $end = $start->copy()->addYear();

        $rentalRequest = RentalRequest::updateOrCreate(
            [
                'property_id' => $property->id,
                'user_id' => $tenant->id,
            ],
            [
                'status' => 'approved',
                'message' => 'Demo: pre-approved rental request.',
                'tenant_start_date' => $start,
                'tenant_end_date' => $end,
                'tenant_monthly_rent' => $property->rent_price ?? 15000,
                'tenant_security_deposit' => 30000,
                'owner_start_date' => $start,
                'owner_end_date' => $end,
                'owner_monthly_rent' => $property->rent_price ?? 15000,
                'owner_security_deposit' => 30000,
                'owner_notes' => 'Demo: owner-approved terms.',
                'ready_for_admin' => true,
                'owner_confirmed_at' => now(),
                'owner_confirmed_by' => $landlord->id,
                'decision_note' => 'Demo: pre-approved for showcase.',
                'decided_by' => $admin?->id,
                'decided_at' => now(),
            ]
        );

        RentAgreement::updateOrCreate(
            ['rental_request_id' => $rentalRequest->id],
            [
                'property_id' => $property->id,
                'landlord_id' => $landlord->id,
                'tenant_id' => $tenant->id,
                'approved_by' => $admin?->id,
                'agreement_number' => 'RA-' . now()->format('Ymd') . '-' . Str::upper(Str::random(5)),
                'start_date' => $start,
                'end_date' => $end,
                'monthly_rent' => $property->rent_price ?? 15000,
                'security_deposit' => 30000,
                'terms_text' => "Demo agreement between {$landlord->display_name} (landlord) and {$tenant->display_name} (tenant) for {$property->title}.\n\nTenant agrees to pay BDT " . number_format($property->rent_price ?? 15000, 2) . " monthly. Both parties comply with municipal regulations.",
                'status' => 'active',
                'generated_at' => now(),
            ]
        );

        $this->command?->info('Demo rent agreement created.');
        $this->command?->info("Tenant login: {$tenant->email} / 12345678");
        $this->command?->info("Landlord login: {$landlord->email} / 12345678");
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Property;
use App\Models\PropertyRequest;
use App\Models\RentAgreement;
use App\Models\RentalRequest;
use App\Models\User;
use App\Notifications\PropertyTransferCompleted;
use App\Notifications\RentAgreementGenerated;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class AdminPropertyController extends Controller
{
    public function index(Request $request)
    {
        $properties = Property::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->q.'%';

                $query->where(function ($subQuery) use ($term) {
                    $subQuery->where('title', 'like', $term)
                        ->orWhere('address_line', 'like', $term)
                        ->orWhere('city', 'like', $term)
                        ->orWhere('postal_code', 'like', $term)
                        ->orWhereHas('owner', fn ($q) => $q->where('full_name', 'like', $term)
                            ->orWhere('name', 'like', $term));
                });
            })
            ->when($request->filled('city'), fn ($query) => $query->where('city', $request->city))
            ->when($request->filled('owner'), function ($query) use ($request) {
                $query->whereHas('owner', fn ($q) => $q->where('full_name', 'like', '%'.$request->owner.'%')
                    ->orWhere('name', 'like', '%'.$request->owner.'%'));
            })
            ->paginate(15)
            ->withQueryString();

        return view('admin.properties.index', compact('properties'));
    }

    public function create()
    {
        $citizens = User::where('role', 'citizen')
            ->orderByRaw('COALESCE(full_name, name, email) asc')
            ->get();

        return view('admin.properties.create', compact('citizens'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'owner_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'address_line' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'area_sqft' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_available_for_rent' => 'nullable|boolean',
            'rent_price' => 'nullable|numeric|min:0',
            'assessed_value' => 'nullable|numeric|min:0',
            'land_use' => 'nullable|string|max:50',
            'last_valuation_at' => 'nullable|date',
        ]);

        $data['is_available_for_rent'] = $request->boolean('is_available_for_rent');

        Property::create($data);

        return redirect()->route('admin.properties.index')->with('status', 'Property created.');
    }

    public function edit(Property $property)
    {
        $citizens = User::where('role', 'citizen')
            ->orderByRaw('COALESCE(full_name, name, email) asc')
            ->get();

        return view('admin.properties.edit', compact('property', 'citizens'));
    }

    public function update(Request $request, Property $property)
    {
        $data = $request->validate([
            'owner_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:50',
            'address_line' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'area_sqft' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_available_for_rent' => 'nullable|boolean',
            'rent_price' => 'nullable|numeric|min:0',
            'assessed_value' => 'nullable|numeric|min:0',
            'land_use' => 'nullable|string|max:50',
            'last_valuation_at' => 'nullable|date',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);
        $data['is_available_for_rent'] = $request->boolean('is_available_for_rent');

        $property->update($data);

        return redirect()->route('admin.properties.index')->with('status', 'Property updated.');
    }

    public function destroy(Property $property)
    {
        /*
         * Guard the delete. `rent_agreements.property_id`,
         * `tax_assessments.property_id` and (transitively)
         * `tax_payments.tax_assessment_id` all cascade on delete, so
         * removing a property would silently destroy executed rental
         * agreements and the whole payment history behind them.
         *
         * A property with live obligations must be deactivated instead,
         * which keeps the records intact and removes it from the
         * citizen-facing listings.
         */
        $blockers = [];

        if ($property->rentAgreements()->where('status', 'active')->exists()) {
            $blockers[] = 'it has active rent agreements';
        }

        if ($property->taxAssessments()->whereIn('status', ['issued', 'overdue'])->exists()) {
            $blockers[] = 'it has outstanding tax assessments';
        }

        if ($blockers !== []) {
            return back()->withErrors([
                'property' => 'This property cannot be deleted because '.implode(' and ', $blockers)
                    .'. Deactivate it instead to remove it from listings while preserving the records.',
            ]);
        }

        $property->delete();

        return back()->with('status', 'Property removed.');
    }

    public function requests()
    {
        $requests = PropertyRequest::with(['user', 'property.owner'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return view('admin.properties.requests', compact('requests'));
    }

    public function handleRequest(Request $request, PropertyRequest $propertyRequest)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'decision_note' => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($propertyRequest, $validated) {
            $propertyRequest->update([
                'status' => $validated['action'] === 'approve' ? 'approved' : 'rejected',
                'decision_note' => $validated['decision_note'] ?? null,
                'decided_by' => auth()->id(),
                'decided_at' => Carbon::now(),
            ]);

            if ($validated['action'] === 'approve') {
                $this->applyRequestPayload($propertyRequest);
            }
        });

        return back()->with('status', 'Request processed.');
    }

    public function rentalRequests()
    {
        /*
         * Every pending request is listed here, regardless of whether the
         * owner has confirmed the terms yet.
         *
         * Filtering on `ready_for_admin` made the queue unreadable: the
         * sidebar badge counted all pending requests while the page listed
         * only the owner-confirmed ones, so a badge showing "3" could sit
         * above an empty table. Worse, the request could only become
         * owner-confirmed by an owner opening their property page — and
         * owners whose account is not `verified` are bounced off every
         * property route by the `verified.citizen` middleware, so their
         * requests could never be forwarded at all.
         *
         * The admin is the backstop: the request is visible and the owner
         * confirmation is reported as a state the admin can still act on.
         */
        $requests = RentalRequest::with(['property.owner', 'user'])
            ->where('status', 'pending')
            ->latest()
            ->paginate(15);

        return view('admin.properties.rentals', compact('requests'));
    }

    public function handleRental(Request $request, RentalRequest $rentalRequest)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'decision_note' => 'nullable|string|max:500',
            'start_date' => 'required_if:action,approve|date',
            'end_date' => 'required_if:action,approve|date|after:start_date',
            'monthly_rent' => 'required_if:action,approve|numeric|min:0',
            'security_deposit' => 'nullable|numeric|min:0',
            'terms_text' => 'nullable|string',
        ]);

        /*
         * A request whose owner never confirmed the terms can still be
         * approved. This is deliberate.
         *
         * The request sits in the queue precisely because it needs a
         * decision, and the owner is often unable to confirm it
         * themselves: an unverified account is redirected away from every
         * property route by the `verified.citizen` middleware, and a
         * property with no assigned owner has nobody to ask. Refusing to
         * approve left those requests permanently stuck, so the admin acts
         * as the backstop.
         */
        $rentalRequest->update([
            'status' => $validated['action'] === 'approve' ? 'approved' : 'rejected',
            'decision_note' => $validated['decision_note'] ?? null,
            'decided_by' => auth()->id(),
            'decided_at' => Carbon::now(),
        ]);

        if ($validated['action'] === 'approve') {
            $this->createRentAgreement($rentalRequest, $validated);
        }

        return back()->with('status', 'Rental request updated.');
    }

    protected function applyRequestPayload(PropertyRequest $request): void
    {
        $data = $request->payload;

        if ($request->type === 'add') {
            Property::create(array_merge($data, [
                'owner_id' => $request->user_id,
            ]));

            return;
        }

        if (! $request->property) {
            return;
        }

        if ($request->type === 'update') {
            $request->property->update($data);

            return;
        }

        if ($request->type === 'transfer') {
            $target = User::where('email', $data['target_email'] ?? null)->first();
            if ($target) {
                $previousOwner = $request->property->owner;
                $request->property->update(['owner_id' => $target->id]);

                $this->notifyTransferCompleted($request, $target, $previousOwner);
            }
        }
    }

    protected function createRentAgreement(RentalRequest $rentalRequest, array $validated): void
    {
        $property = $rentalRequest->property->refresh();
        $agreementNumber = 'RA-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        $terms = $validated['terms_text'] ?? $this->defaultAgreementTerms($property->title, $validated['monthly_rent']);

        $agreement = RentAgreement::create([
            'property_id' => $property->id,
            'rental_request_id' => $rentalRequest->id,
            'landlord_id' => $property->owner_id,
            'tenant_id' => $rentalRequest->user_id,
            'approved_by' => auth()->id(),
            'agreement_number' => $agreementNumber,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'monthly_rent' => $validated['monthly_rent'],
            'security_deposit' => $validated['security_deposit'] ?? 0,
            'terms_text' => $terms,
            'status' => 'active',
            'generated_at' => now(),
        ]);

        /*
         * Distribution. Without this the agreement existed only in the
         * database and neither party was told about it — the only way to
         * discover it was to browse the Rent Agreements page.
         */
        $recipients = collect([$agreement->tenant, $agreement->landlord])
            ->filter()
            ->unique('id')
            ->values();

        Notification::send($recipients, new RentAgreementGenerated($agreement));
    }

    protected function defaultAgreementTerms(string $propertyTitle, float $monthlyRent): string
    {
        return <<<TEXT
This agreement is made between the landlord and tenant for {$propertyTitle}. The tenant agrees to remit a monthly rent of BDT {$monthlyRent} on or before the 5th day of each month. Both parties agree to comply with municipal regulations, maintain the property in good condition, and provide 30 days' notice for termination. Security deposits may be retained to cover unpaid dues or damages.
TEXT;
    }

    protected function notifyTransferCompleted(PropertyRequest $propertyRequest, User $newOwner, ?User $previousOwner): void
    {
        $property = $propertyRequest->property->fresh(['owner']);
        $recipients = collect([$newOwner, $propertyRequest->user])
            ->filter()
            ->unique(fn ($user) => $user->id)
            ->all();

        if (empty($recipients)) {
            return;
        }

        Notification::send($recipients, new PropertyTransferCompleted($property, $previousOwner));
    }
}

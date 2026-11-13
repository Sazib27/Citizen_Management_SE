@extends('layouts.citizen')

@section('title', 'Rental Requests')
@section('page-title', 'Rental Requests on My Properties')

@section('content')
<div class="space-y-6">
    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded">
            <ul class="list-disc list-inside text-sm space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white shadow rounded-lg p-6">
        <h2 class="text-xl font-semibold text-gray-900">Pending rental requests</h2>
        <p class="text-sm text-gray-500 mt-1">
            Confirm the terms for each request to forward it to municipal approval.
        </p>

        @if($requests->isEmpty())
            <p class="text-sm text-gray-500 mt-4">No pending rental requests on your properties.</p>
        @else
            <div class="mt-6 space-y-5">
                @foreach($requests as $rentalRequest)
                    <div class="border border-gray-200 rounded-lg p-5 space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-semibold text-gray-900">{{ $rentalRequest->property->title ?? 'Property removed' }}</p>
                                <p class="text-sm text-gray-500">{{ $rentalRequest->user->display_name }} · {{ $rentalRequest->user->email }}</p>
                                <p class="text-xs text-gray-500">Requested {{ $rentalRequest->created_at->diffForHumans() }}</p>
                            </div>
                            <span class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-full {{ $rentalRequest->ready_for_admin ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' }}">
                                {{ $rentalRequest->ready_for_admin ? 'Forwarded to admin' : 'Awaiting your confirmation' }}
                            </span>
                        </div>

                        @if($rentalRequest->message)
                            <div class="text-sm text-gray-700 bg-gray-50 rounded p-3">{{ $rentalRequest->message }}</div>
                        @endif

                        <dl class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs text-gray-600">
                            <div>
                                <dt class="uppercase tracking-wide">Preferred start</dt>
                                <dd class="font-semibold text-gray-900">{{ optional($rentalRequest->tenant_start_date)->format('M d, Y') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide">Preferred end</dt>
                                <dd class="font-semibold text-gray-900">{{ optional($rentalRequest->tenant_end_date)->format('M d, Y') ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide">Proposed rent</dt>
                                <dd class="font-semibold text-gray-900">{{ $rentalRequest->tenant_monthly_rent ? 'BDT ' . number_format($rentalRequest->tenant_monthly_rent, 2) : '—' }}</dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide">Security deposit</dt>
                                <dd class="font-semibold text-gray-900">{{ $rentalRequest->tenant_security_deposit ? 'BDT ' . number_format($rentalRequest->tenant_security_deposit, 2) : '—' }}</dd>
                            </div>
                        </dl>

                        @if(! $rentalRequest->ready_for_admin)
                            <form method="POST" action="{{ route('citizen.rental-requests.owner-confirm', $rentalRequest) }}" class="space-y-3 border-t pt-4">
                                @csrf
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="text-xs font-semibold text-gray-600">Start date</label>
                                        <input type="date" name="owner_start_date" value="{{ old('owner_start_date', optional($rentalRequest->tenant_start_date)->format('Y-m-d')) }}" class="mt-1 w-full border rounded px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold text-gray-600">End date</label>
                                        <input type="date" name="owner_end_date" value="{{ old('owner_end_date', optional($rentalRequest->tenant_end_date)->format('Y-m-d')) }}" class="mt-1 w-full border rounded px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold text-gray-600">Monthly rent (BDT)</label>
                                        <input type="number" step="0.01" name="owner_monthly_rent" value="{{ old('owner_monthly_rent', $rentalRequest->tenant_monthly_rent) }}" class="mt-1 w-full border rounded px-3 py-2 text-sm" required>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold text-gray-600">Security deposit (BDT)</label>
                                        <input type="number" step="0.01" name="owner_security_deposit" value="{{ old('owner_security_deposit', $rentalRequest->tenant_security_deposit) }}" class="mt-1 w-full border rounded px-3 py-2 text-sm">
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-gray-600">Note to admin (optional)</label>
                                    <textarea name="owner_notes" rows="2" class="mt-1 w-full border rounded px-3 py-2 text-sm" placeholder="Add extra context or requirements">{{ old('owner_notes') }}</textarea>
                                </div>
                                <div class="flex justify-between items-center">
                                    <a href="mailto:{{ $rentalRequest->user->email }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded border border-gray-300 text-gray-700">Contact Tenant</a>
                                    <button class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-xs font-semibold rounded">Forward to Admin</button>
                                </div>
                            </form>
                        @else
                            <div class="border-t pt-4 flex justify-between items-center text-xs text-gray-600">
                                <div>
                                    <p>Forwarded on {{ $rentalRequest->owner_confirmed_at?->format('M d, Y h:i A') }}</p>
                                    @if($rentalRequest->owner_notes)
                                        <p class="text-gray-500">Your note: {{ $rentalRequest->owner_notes }}</p>
                                    @endif
                                </div>
                                <a href="mailto:{{ $rentalRequest->user->email }}" class="inline-flex items-center px-3 py-2 text-xs font-semibold rounded border border-gray-300 text-gray-700">Contact Tenant</a>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection

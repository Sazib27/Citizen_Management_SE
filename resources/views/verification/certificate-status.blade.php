@extends('layouts.app')

@section('title', 'Certificate Verification')

@section('content')
<div class="max-w-2xl mx-auto py-10 px-4">
    <div class="bg-white shadow rounded-lg p-8">
        <h1 class="text-2xl font-bold text-gray-900">Certificate Verification</h1>

        @if(! $found)
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-5">
                <p class="text-lg font-semibold text-red-900">Certificate not found</p>
                <p class="mt-2 text-sm text-red-800">
                    No certificate was issued with that number. Please check the number printed on the
                    certificate and try again.
                </p>
            </div>
        @else
            <div class="mt-6 rounded-lg border p-5 @class([
                'border-green-200 bg-green-50' => $valid,
                'border-red-200 bg-red-50' => ! $valid,
            ])">
                @if($valid)
                    <p class="text-lg font-semibold text-green-900">✓ This certificate is valid</p>
                    <p class="mt-1 text-sm text-green-800">The details below match an active certificate issued by the Citizen Services Portal.</p>
                @else
                    <p class="text-lg font-semibold text-red-900">✗ This certificate is not valid</p>
                    <p class="mt-1 text-sm text-red-800">
                        The certificate is <strong>{{ ucfirst($certificate->effectiveStatus()) }}</strong>.
                        @if($certificate->revocation_reason)
                            Reason: {{ $certificate->revocation_reason }}
                        @endif
                    </p>
                @endif
            </div>

            <dl class="mt-6 divide-y divide-gray-200 text-sm">
                <div class="flex justify-between py-3">
                    <dt class="text-gray-500">Certificate number</dt>
                    <dd class="font-semibold text-gray-900">{{ $certificate->certificate_number }}</dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-gray-500">Holder</dt>
                    <dd class="font-semibold text-gray-900">{{ $certificate->holder_name }}</dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-gray-500">Status</dt>
                    <dd class="font-semibold text-gray-900">{{ ucfirst($certificate->effectiveStatus()) }}</dd>
                </div>
                <div class="flex justify-between py-3">
                    <dt class="text-gray-500">Issued on</dt>
                    <dd class="font-semibold text-gray-900">{{ optional($certificate->issued_at)->format('F d, Y') ?? '—' }}</dd>
                </div>
                @if($certificate->expires_at)
                    <div class="flex justify-between py-3">
                        <dt class="text-gray-500">Valid until</dt>
                        <dd class="font-semibold text-gray-900">{{ $certificate->expires_at->format('F d, Y') }}</dd>
                    </div>
                @endif
            </dl>

            <p class="mt-6 text-xs text-gray-500">
                The NID number and date of birth are deliberately withheld from this page so that a
                certificate can be checked without disclosing the holder's identity data.
            </p>
        @endif
    </div>
</div>
@endsection

@extends('layouts.citizen')

@section('title', 'Demo Payment Recorded')
@section('page-title', 'Demo Payment Confirmation')

@section('content')
@php
    $payment = session('payment');
    $assessment = $payment?->assessment;
@endphp
<div class="max-w-2xl mx-auto space-y-6">
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
        <div class="flex items-center">
            <svg class="h-6 w-6 text-yellow-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-sm font-semibold text-yellow-800">DEMO MODE — no real money was charged</p>
        </div>
    </div>

    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <h3 class="text-lg font-semibold text-gray-900">Payment recorded locally</h3>
        @if($payment)
            <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500">Reference</dt>
                    <dd class="font-mono text-gray-900">{{ $payment->reference }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Method</dt>
                    <dd class="text-gray-900">{{ ucfirst($payment->method) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Amount</dt>
                    <dd class="text-gray-900">BDT {{ number_format($payment->amount, 2) }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Paid at</dt>
                    <dd class="text-gray-900">{{ optional($payment->paid_at)->format('M d, Y h:i A') }}</dd>
                </div>
                @if($assessment)
                <div class="md:col-span-2">
                    <dt class="text-gray-500">Assessment</dt>
                    <dd class="text-gray-900">
                        Fiscal year {{ $assessment->fiscal_year }} —
                        Property: {{ $assessment->property?->title ?? 'N/A' }}
                    </dd>
                </div>
                @endif
                @if($payment->notes)
                <div class="md:col-span-2">
                    <dt class="text-gray-500">Notes</dt>
                    <dd class="text-gray-700 text-xs">{{ $payment->notes }}</dd>
                </div>
                @endif
            </dl>
        @endif

        <div class="pt-4 border-t border-gray-200 flex justify-end">
            <a href="{{ route('citizen.taxes.index') }}"
               class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                Back to my assessments
            </a>
        </div>
    </div>
</div>
@endsection

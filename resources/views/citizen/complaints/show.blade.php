@extends('layouts.citizen')

@section('title', $complaint->subject)
@section('page-title', 'Complaint Details')

@section('content')
<div class="space-y-6 max-w-4xl">
    <div class="bg-white shadow rounded-lg p-6 space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-500">Subject</p>
                <p class="text-2xl font-bold text-gray-900">{{ $complaint->subject }}</p>
            </div>
            <span class="px-3 py-1 rounded-full text-sm font-semibold {{ $complaint->status->badgeClasses() }}">
                {{ $complaint->status->label() }}
            </span>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-sm text-gray-500">Category</p>
                <p class="text-lg font-semibold text-gray-900">{{ $complaint->category ?? 'General' }}</p>
            </div>
            <div>
                <p class="text-sm text-gray-500">Property</p>
                <p class="text-lg font-semibold text-gray-900">{{ $complaint->property?->title ?? 'General' }}</p>
            </div>
        </div>

        <div>
            <p class="text-sm text-gray-500">Description</p>
            <div class="mt-2 rounded border border-gray-200 bg-gray-50 p-4 text-gray-700 whitespace-pre-line">
                {{ $complaint->description }}
            </div>
        </div>

        @if($complaint->attachment_path)
            <div>
                <p class="text-sm text-gray-500">Attachment</p>
                <a href="{{ asset('storage/' . $complaint->attachment_path) }}" class="text-indigo-600 hover:text-indigo-800" target="_blank">View attachment</a>
            </div>
        @endif

        <div>
            <p class="text-sm text-gray-500">Submitted</p>
            <p class="text-lg text-gray-700">{{ $complaint->created_at->format('M d, Y h:i A') }}</p>
        </div>

        @if($complaint->admin_reply)
            <div class="border rounded-lg p-4 @class([
                'bg-green-50 border-green-200' => $complaint->status->value === 'resolved',
                'bg-red-50 border-red-200' => $complaint->status->value === 'rejected',
                'bg-gray-50 border-gray-200' => ! $complaint->status->isClosed(),
            ])">
                <p class="text-sm font-semibold @class([
                    'text-green-900' => $complaint->status->value === 'resolved',
                    'text-red-900' => $complaint->status->value === 'rejected',
                    'text-gray-900' => ! $complaint->status->isClosed(),
                ])">Admin Response</p>
                <p class="text-sm text-gray-700 whitespace-pre-line mt-2">{{ $complaint->admin_reply }}</p>
                @if($complaint->resolver)
                    <p class="text-xs text-gray-500 mt-2">— {{ $complaint->resolver->display_name }}, {{ $complaint->resolved_at?->format('M d, Y h:i A') }}</p>
                @endif
            </div>
        @endif
    </div>

    {{-- Progress timeline: how the complaint reached its current state. --}}
    <div class="bg-white shadow rounded-lg p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Progress Timeline</h2>
        <ol class="space-y-4 border-l border-gray-200 ml-2">
            @forelse($complaint->updates as $update)
                <li class="ml-4">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-1 rounded-full text-xs font-semibold {{ \App\Enums\ComplaintStatus::from($update->to_status->value)->badgeClasses() }}">
                            {{ \App\Enums\ComplaintStatus::from($update->to_status->value)->label() }}
                        </span>
                        <span class="text-xs text-gray-500">{{ $update->created_at->format('M d, Y h:i A') }}</span>
                    </div>
                    @if($update->from_status)
                        <p class="text-xs text-gray-500 mt-1">
                            Changed from {{ \App\Enums\ComplaintStatus::from($update->from_status->value)->label() }}
                        </p>
                    @endif
                    @if($update->note)
                        <p class="text-sm text-gray-700 mt-1 whitespace-pre-line">{{ $update->note }}</p>
                    @endif
                    @if($update->author)
                        <p class="text-xs text-gray-400 mt-1">by {{ $update->author->display_name }}</p>
                    @endif
                </li>
            @empty
                <li class="ml-4 text-sm text-gray-500">No timeline entries yet.</li>
            @endforelse
        </ol>
    </div>

    {{-- Citizen satisfaction rating, available once the complaint is closed. --}}
    @if($complaint->isClosed())
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-2">Rate the resolution</h2>
            @if($complaint->rating)
                <p class="text-sm text-gray-700">
                    You rated this resolution
                    <span class="text-yellow-500">{{ str_repeat('★', $complaint->rating) }}</span>
                    <span class="text-gray-300">{{ str_repeat('★', 5 - $complaint->rating) }}</span>
                    on {{ $complaint->rated_at?->format('M d, Y') }}.
                </p>
            @else
                <form method="POST" action="{{ route('citizen.complaints.rate', $complaint) }}" class="flex items-center gap-3 mt-3">
                    @csrf
                    <select name="rating" required class="rounded border-gray-300 text-sm px-3 py-2 focus:border-green-500 focus:ring-green-500">
                        <option value="">Select a rating</option>
                        @for($i = 5; $i >= 1; $i--)
                            <option value="{{ $i }}">{{ $i }} star{{ $i > 1 ? 's' : '' }}</option>
                        @endfor
                    </select>
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded text-sm font-semibold">Submit rating</button>
                </form>
            @endif
        </div>
    @endif

    <a href="{{ route('citizen.complaints.index') }}" class="inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-600 bg-white border border-gray-200 rounded shadow-sm hover:bg-gray-50">Back to list</a>
</div>
@endsection

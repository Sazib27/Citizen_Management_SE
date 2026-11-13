@extends('layouts.citizen')

@section('title', 'Complaints')
@section('page-title', 'My Complaints')

@section('content')
<div class="space-y-6">
    @if(session('status'))
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded">{{ session('status') }}</div>
    @endif

    <div class="flex flex-wrap justify-between items-center gap-3">
        <form method="GET" action="{{ route('citizen.complaints.index') }}" class="flex flex-wrap items-center gap-2">
            <input type="text" name="q" value="{{ request('q') }}" placeholder="Search by subject"
                   class="rounded border-gray-300 text-sm px-3 py-2 focus:border-green-500 focus:ring-green-500">
            <select name="status" class="rounded border-gray-300 text-sm px-3 py-2 focus:border-green-500 focus:ring-green-500">
                <option value="">All statuses</option>
                @foreach(\App\Enums\ComplaintStatus::cases() as $case)
                    <option value="{{ $case->value }}" @selected(request('status') === $case->value)>{{ $case->label() }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-3 py-2 bg-gray-800 text-white rounded text-sm">Filter</button>
            @if(request()->hasAny(['q', 'status']))
                <a href="{{ route('citizen.complaints.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            @endif
        </form>

        <a href="{{ route('citizen.complaints.create') }}" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded shadow">File Complaint</a>
    </div>

    <div class="bg-white shadow rounded-lg overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-3 text-left">Subject</th>
                    <th class="px-4 py-3 text-left">Category</th>
                    <th class="px-4 py-3 text-left">Property</th>
                    <th class="px-4 py-3 text-left">Status</th>
                    <th class="px-4 py-3 text-left">Rating</th>
                    <th class="px-4 py-3 text-left">Updated</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($complaints as $complaint)
                    <tr>
                        <td class="px-4 py-3 text-gray-900 font-medium">{{ $complaint->subject }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $complaint->category ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $complaint->property?->title ?? 'General' }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $complaint->status->badgeClasses() }}">
                                {{ $complaint->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            @if($complaint->rating)
                                <span class="text-yellow-500">{{ str_repeat('★', $complaint->rating) }}<span class="text-gray-300">{{ str_repeat('★', 5 - $complaint->rating) }}</span></span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $complaint->updated_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('citizen.complaints.show', $complaint) }}" class="text-indigo-600 hover:text-indigo-800">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-6 text-center text-gray-500">No complaints submitted yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-3 border-t border-gray-200">
            {{ $complaints->links() }}
        </div>
    </div>
</div>
@endsection

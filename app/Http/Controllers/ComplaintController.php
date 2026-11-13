<?php

namespace App\Http\Controllers;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ComplaintController extends Controller
{
    public function index(Request $request)
    {
        $complaints = Complaint::with('property')
            ->where('user_id', Auth::id())
            // Let the citizen filter their own grievances by state.
            ->when(
                $request->filled('status') && in_array($request->status, ComplaintStatus::values(), true),
                fn ($query) => $query->where('status', $request->status)
            )
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->where('subject', 'like', '%'.$request->q.'%');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('citizen.complaints.index', compact('complaints'));
    }

    public function create()
    {
        $properties = Property::where('owner_id', Auth::id())
            ->orderBy('title')
            ->get();

        return view('citizen.complaints.create', compact('properties'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'nullable|string|max:100',
            'property_id' => 'nullable|exists:properties,id',
            'attachment' => 'nullable|file|max:4096',
        ]);

        if (! empty($data['property_id'])) {
            $ownsProperty = Property::where('id', $data['property_id'])
                ->where('owner_id', Auth::id())
                ->exists();

            abort_unless($ownsProperty, 403);
        }

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('complaints', 'public');
        }

        $data['user_id'] = Auth::id();
        $data['status'] = ComplaintStatus::Open->value;

        $complaint = Complaint::create($data);

        // Open the timeline so the citizen can follow the grievance.
        $complaint->logStatusChange(null, ComplaintStatus::Open, 'Complaint submitted.', Auth::id());

        return redirect()->route('citizen.complaints.index')->with('status', 'Complaint submitted successfully.');
    }

    public function show(Complaint $complaint)
    {
        abort_unless($complaint->user_id === Auth::id(), 403);

        $complaint->load(['property', 'updates.author']);

        return view('citizen.complaints.show', compact('complaint'));
    }

    /**
     * Let the citizen rate the resolution once the complaint is closed.
     */
    public function rate(Request $request, Complaint $complaint)
    {
        abort_unless($complaint->user_id === Auth::id(), 403);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        if (! $complaint->isClosed()) {
            return back()->withErrors([
                'rating' => 'You can only rate a complaint once it has been resolved or rejected.',
            ]);
        }

        $complaint->update([
            'rating' => (int) $data['rating'],
            'rated_at' => now(),
        ]);

        return back()->with('status', 'Thanks for rating the resolution.');
    }
}

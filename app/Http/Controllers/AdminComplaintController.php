<?php

namespace App\Http\Controllers;

use App\Enums\ComplaintStatus;
use App\Models\Complaint;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminComplaintController extends Controller
{
    public function index(Request $request)
    {
        $complaints = Complaint::with(['user', 'property'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->category))
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->where(function ($sub) use ($request) {
                    $sub->where('subject', 'like', '%'.$request->q.'%')
                        ->orWhereHas('user', fn ($userQuery) => $userQuery->where('full_name', 'like', '%'.$request->q.'%')
                            ->orWhere('name', 'like', '%'.$request->q.'%'));
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.complaints.index', compact('complaints'));
    }

    public function show(Complaint $complaint)
    {
        $complaint->load(['user', 'property', 'updates.author']);

        return view('admin.complaints.show', compact('complaint'));
    }

    public function update(Request $request, Complaint $complaint)
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::enum(ComplaintStatus::class)],
            'admin_reply' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:500',
        ]);

        $target = ComplaintStatus::from($data['status']);
        $current = $complaint->status ?? ComplaintStatus::Open;

        /*
         * Guard the transition. A closed complaint can be reopened, and
         * an open one can be rejected, but the workflow should not jump
         * arbitrarily between states.
         */
        if ($target !== $current && ! $current->canTransitionTo($target)) {
            return back()->withErrors([
                'status' => sprintf('Cannot move a complaint from "%s" to "%s".', $current->label(), $target->label()),
            ]);
        }

        /*
         * A rejection without a stated reason gives the citizen nothing
         * to act on, so the reason is mandatory for that outcome.
         */
        if ($target === ComplaintStatus::Rejected && blank($data['admin_reply'] ?? null)) {
            return back()->withErrors([
                'admin_reply' => 'Please state a reason when rejecting a complaint.',
            ]);
        }

        $complaint->fill([
            'status' => $target,
            'admin_reply' => $data['admin_reply'] ?? $complaint->admin_reply,
        ]);

        if ($target->isClosed()) {
            $complaint->resolved_at = now();
            $complaint->resolved_by = auth()->id();
            $complaint->closed_at = now();
        } else {
            // Reopening clears the closure stamps so the state is not
            // left stale on the row.
            $complaint->resolved_at = null;
            $complaint->resolved_by = null;
            $complaint->closed_at = null;
        }

        $complaint->save();

        $complaint->logStatusChange(
            $current,
            $target,
            $data['note'] ?? null,
            auth()->id()
        );

        return redirect()->route('admin.complaints.show', $complaint)->with('status', 'Complaint updated.');
    }
}

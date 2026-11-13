<?php

namespace App\Http\Controllers;

use App\Models\RentAgreement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CitizenRentAgreementController extends Controller
{
    public function index()
    {
        $userId = Auth::id();

        $agreements = RentAgreement::with('property')
            ->where(function ($query) use ($userId) {
                $query->where('tenant_id', $userId)
                    ->orWhere('landlord_id', $userId);
            })
            ->latest('start_date')
            ->paginate(10);

        return view('citizen.rent-agreements.index', compact('agreements'));
    }

    public function show(RentAgreement $rentAgreement)
    {
        abort_unless(in_array(Auth::id(), [$rentAgreement->tenant_id, $rentAgreement->landlord_id]), 403);

        $rentAgreement->load(['property', 'landlord', 'tenant']);

        return view('citizen.rent-agreements.show', compact('rentAgreement'));
    }

    /**
     * Download the agreement as a PDF document.
     *
     * The agreement is a legal artefact that both parties are supposed
     * to hold, so it must be exportable rather than only readable on
     * screen.
     */
    public function download(RentAgreement $rentAgreement)
    {
        abort_unless(in_array(Auth::id(), [$rentAgreement->tenant_id, $rentAgreement->landlord_id]), 403);

        $rentAgreement->load(['property', 'landlord', 'tenant']);

        $pdf = Pdf::loadView('citizen.rent-agreements.pdf', [
            'rentAgreement' => $rentAgreement,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('rent-agreement-'.$rentAgreement->agreement_number.'.pdf');
    }

    /**
     * Look an agreement up by its agreement number.
     *
     * Both parties to an agreement may use this to find their document
     * without needing to know the numeric id from the URL.
     */
    public function lookup(Request $request)
    {
        $request->validate([
            'agreement_number' => ['required', 'string', 'max:64'],
        ]);

        $agreement = RentAgreement::with(['property', 'landlord', 'tenant'])
            ->where('agreement_number', $request->string('agreement_number')->trim())
            ->first();

        abort_unless($agreement, 404, 'No rent agreement found with that number.');

        abort_unless(
            in_array(Auth::id(), [$agreement->tenant_id, $agreement->landlord_id]),
            403,
            'That agreement does not belong to you.'
        );

        return redirect()->route('citizen.rent-agreements.show', $agreement);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\RentAgreement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class AdminRentAgreementController extends Controller
{
    public function index(Request $request)
    {
        $agreements = RentAgreement::with(['property', 'tenant'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->where(function ($sub) use ($request) {
                    $sub->whereHas('property', fn ($propertyQuery) => $propertyQuery->where('title', 'like', '%'.$request->q.'%'))
                        ->orWhereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('full_name', 'like', '%'.$request->q.'%')
                            ->orWhere('name', 'like', '%'.$request->q.'%'));
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.rent-agreements.index', compact('agreements'));
    }

    public function show(RentAgreement $rentAgreement)
    {
        $rentAgreement->load(['property', 'tenant', 'landlord']);

        return view('admin.rent-agreements.show', compact('rentAgreement'));
    }

    /**
     * Download the agreement PDF from the admin side.
     */
    public function download(RentAgreement $rentAgreement)
    {
        $rentAgreement->load(['property', 'tenant', 'landlord']);

        $pdf = Pdf::loadView('citizen.rent-agreements.pdf', [
            'rentAgreement' => $rentAgreement,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('rent-agreement-'.$rentAgreement->agreement_number.'.pdf');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\TaxAssessment;
use App\Models\TaxPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CitizenTaxController extends Controller
{
    public function index(Request $request)
    {
        $userId = Auth::id();

        $assessmentsQuery = TaxAssessment::with(['property', 'payments' => fn ($query) => $query->latest('paid_at')])
            ->where('owner_id', $userId)
            ->orderByDesc('fiscal_year');

        $statusFilter = $request->string('status')->toString();
        if ($statusFilter) {
            $assessmentsQuery->where('status', $statusFilter);
        }

        $assessments = $assessmentsQuery->paginate(10)->withQueryString();

        $outstandingAssessments = TaxAssessment::where('owner_id', $userId)
            ->whereIn('status', ['issued', 'overdue'])
            ->get();

        $outstandingTotal = $outstandingAssessments->sum('tax_amount');
        $nextDueDate = $outstandingAssessments->filter(fn ($item) => $item->due_date)->min('due_date');

        $recentPayments = TaxPayment::with(['assessment.property'])
            ->where('payer_id', $userId)
            ->latest('paid_at')
            ->take(10)
            ->get();

        return view('citizen.taxes.index', [
            'assessments' => $assessments,
            'outstandingTotal' => $outstandingTotal,
            'nextDueDate' => $nextDueDate,
            'statusFilter' => $statusFilter,
            'sslczEnabled' => $this->sslczEnabled(),
            'recentPayments' => $recentPayments,
        ]);
    }

    public function showPay(TaxAssessment $taxAssessment)
    {
        $this->authorizeAssessment($taxAssessment);

        if (! in_array($taxAssessment->status, ['issued', 'overdue'])) {
            return redirect()->route('citizen.taxes.index')->withErrors([
                'payment' => 'Only issued or overdue assessments can be paid online.',
            ]);
        }

        return view('citizen.taxes.pay', [
            'assessment' => $taxAssessment->loadMissing('property'),
            'sslczEnabled' => $this->sslczEnabled(),
        ]);
    }

    /**
     * SSL Commerz is enabled when:
     *   - demo mode is on (any environment), OR
     *   - real credentials are configured and not running against
     *     the sandbox host in production.
     */
    private function sslczEnabled(): bool
    {
        $cfg = config('services.sslcommerz', []);

        // Demo mode is always considered "enabled" — it works without
        // external credentials and is safe to keep on in any env.
        if ($cfg['demo_mode'] ?? false) {
            return true;
        }

        if (empty($cfg['store_id']) || empty($cfg['store_passwd'])) {
            return false;
        }

        if (! app()->environment('local') && ($cfg['sandbox'] ?? true)) {
            // Production must not run against the sandbox host.
            return false;
        }

        return true;
    }

    public function receipt(TaxPayment $taxPayment)
    {
        $this->authorizePayment($taxPayment);

        $taxPayment->load('assessment.property');

        return view('citizen.taxes.receipt', [
            'payment' => $taxPayment,
        ]);
    }

    protected function authorizePayment(TaxPayment $payment): void
    {
        if ($payment->payer_id !== Auth::id()) {
            abort(403);
        }
    }

    protected function authorizeAssessment(TaxAssessment $assessment): void
    {
        if ($assessment->owner_id !== Auth::id()) {
            abort(403);
        }
    }
}

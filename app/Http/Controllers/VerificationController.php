<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use App\Models\User;
use App\Models\FakeNid;
use App\Notifications\NewVerificationApplication;
use Barryvdh\DomPDF\Facade\Pdf;

class VerificationController extends Controller
{
    public function __construct()
    {
    $this->middleware('auth')->except(['publicStatus']);
        $this->middleware('citizen')->except(['adminIndex', 'show', 'approve', 'reject', 'document', 'publicStatus']);
        $this->middleware('admin')->only(['adminIndex', 'show', 'approve', 'reject', 'document']);
    }

    /**
     * Show the verification application form
     */
    public function editProfile()
    {
        return view('dashboard.citizenprofile_edit', [
            'user' => Auth::user(),
        ]);
    }

    /**
     * Persist profile edits from the citizen profile page.
     *
     * Allows updating: name, full_name, email, phone_number, addresses.
     * Does NOT allow updating: role, verification_status, nid_number, or
     * any of the verification timestamps / verifiers — those are owned
     * by the admin verification flow.
     */
    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'full_name'         => 'nullable|string|max:255',
            'email'             => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone_number'      => 'nullable|string|regex:/^[0-9]{11}$/',
            'permanent_address' => 'nullable|string|max:500',
            'present_address'   => 'nullable|string|max:500',
        ], [
            'phone_number.regex' => 'The phone number must be exactly 11 digits.',
        ]);

        $user->update($validated);

        return redirect()
            ->route('citizen.profile')
            ->with('success', 'Your profile has been updated.');
    }

    /**
     * Show the verification application form
     */
    public function create()
    {
        $user = Auth::user();

        /*
         * Block duplicate submissions. A user can only be in one
         * verification state at a time:
         *   - verified  -> nothing to apply for
         *   - pending   -> already submitted, wait for admin decision
         *   - rejected  -> rejected; allow re-application only when no
         *                  pending request exists and the previous
         *                  rejection was finalised (rejected_at set).
         */
        if ($user->verification_status === 'verified') {
            return redirect()->route('citizen.dashboard')->with('info', 'You are already verified!');
        }

        if ($user->verification_status === 'pending' && $user->verification_requested_at) {
            return redirect()->route('citizen.dashboard')->with('info', 'Your verification request is already pending review.');
        }

        return view('verification.create', [
            'prefillData' => session('prefillData', []),
        ]);
    }

    /**
     * Prefill verification form fields using Fake NID data
     */
    public function prefill(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'nid_number' => 'required|string|regex:/^[0-9]+$/',
        ], [
            'nid_number.required' => 'This NID number is not found in our database.',
            'nid_number.regex'    => 'This NID number is not found in our database.',
        ]);

        $validator->after(function ($v) use ($request) {
            $nid = $request->input('nid_number');
            if (!is_string($nid) || !preg_match('/^[0-9]{10}$/', $nid)) {
                if (!$v->errors()->has('nid_number')) {
                    $v->errors()->add(
                        'nid_number',
                        'This NID number is not found in our database.'
                    );
                }
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $validated = $validator->validated();

        $nidRecord = FakeNid::where('nid', $validated['nid_number'])->first();

        if (!$nidRecord) {
            return back()->withErrors(['nid_number' => 'This NID number is not found in our database.'])->withInput();
        }

        if ($nidRecord->is_verified) {
            return back()->withErrors(['nid_number' => 'This NID is already verified.'])->withInput();
        }

        if ($nidRecord->is_blocked) {
            return back()->withErrors(['nid_number' => 'This NID has been blocked for security reasons.'])->withInput();
        }

        $prefillData = [
            'nid_number' => $nidRecord->nid,
            'full_name' => $nidRecord->name,
            'date_of_birth' => optional($nidRecord->date_of_birth)->format('Y-m-d'),
            'father_name' => $nidRecord->father_name,
            'mother_name' => $nidRecord->mother_name,
            'permanent_address' => $nidRecord->permanent_address,
            'present_address' => $nidRecord->present_address,
        ];

    session()->flash('prefillData', $prefillData);

    $inputPrefill = array_filter($prefillData, fn ($value) => !is_null($value));

    return back()->withInput(array_merge($request->all(), $inputPrefill))->with('success', 'NID data loaded from registry.');
    }

    /**
     * Store the verification application
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        /*
         * Defence-in-depth guard: even if the citizen reaches this
         * endpoint without going through create() (e.g. direct POST),
         * never let an already-verified or already-pending user overwrite
         * their verification data.
         */
        if ($user->verification_status === 'verified') {
            return redirect()->route('citizen.dashboard')->with('info', 'You are already verified.');
        }

        if ($user->verification_status === 'pending' && $user->verification_requested_at) {
            return redirect()->route('citizen.dashboard')->with('info', 'Your verification request is already pending review.');
        }

        /*
         * Validate the form data. The nid_number check enforces format
         * only (10 digits) so any citizen can submit their application;
         * admin will review and approve/reject in the verification queue.
         * The fake_nids registry is consulted only as a soft signal — if
         * a matching record exists AND it is blocked or already verified
         * by someone else, we reject the submission. Otherwise the
         * application is accepted and queued for admin review (this is
         * the user-facing flow: don't block citizens on registry state).
         */
        $validator = \Validator::make($request->all(), [
            'nid_number'        => 'required|string|regex:/^[0-9]{10}$/',
            'full_name'         => 'required|string|max:255',
            'date_of_birth'     => 'required|date|before:today',
            'father_name'       => 'required|string|max:255',
            'mother_name'       => 'required|string|max:255',
            'permanent_address' => 'required|string|max:500',
            'present_address'   => 'required|string|max:500',
            'phone_number'      => 'required|string|regex:/^[0-9]{11}$/',
            'email'             => 'required|email|max:255',
            'nid_front_image'   => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'nid_back_image'    => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'passport_photo'    => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ], [
            'nid_number.regex'   => 'The NID number must be exactly 10 digits.',
            'phone_number.regex' => 'The phone number must be exactly 11 digits.',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        $validated = $validator->validated();

        /*
         * Soft registry check: only BLOCK submission when the NID is
         * blocked or already verified by another citizen. A NID that is
         * simply not in the registry is allowed through — admin review
         * handles the rest.
         */
        $nidRecord = FakeNid::where('nid', $validated['nid_number'])->first();

        if ($nidRecord && $nidRecord->is_verified && $nidRecord->verified_by !== Auth::id()) {
            return back()->withErrors(['nid_number' => 'This NID is already verified by another citizen.'])->withInput();
        }

        if ($nidRecord && $nidRecord->is_blocked) {
            return back()->withErrors(['nid_number' => 'This NID has been blocked for security reasons.'])->withInput();
        }

        /*
         * When the registry has the NID, verify the citizen's typed
         * full name + DOB match the registry. If the NID is not in the
         * registry, skip the comparison — admin will verify manually.
         */
        if ($nidRecord) {
            if (strtolower(trim($nidRecord->name)) !== strtolower(trim($validated['full_name']))) {
                return back()->withErrors(['full_name' => 'Full name does not match our NID records.'])->withInput();
            }

            $nidDateOfBirth = optional($nidRecord->date_of_birth)->format('Y-m-d');

            if ($nidDateOfBirth !== $validated['date_of_birth']) {
                return back()->withErrors(['date_of_birth' => 'Date of birth does not match our NID records.'])->withInput();
            }
        }

        /*
         * Persist the application atomically so a failed notification never
         * orphans uploaded files, and the user row is always in the state the
         * admin queue expects (pending + verification_requested_at set).
         *
         * Files are stored INSIDE the transaction. If the DB update throws,
         * the rollback will not undo file writes (filesystems aren't
         * transactional), so on failure we make a best-effort cleanup of the
         * files we just wrote.
         */
        $writtenPaths = [];

        try {
            DB::transaction(function () use ($request, $user, $validated, &$writtenPaths) {
                $writtenPaths['nid_front']   = $request->file('nid_front_image')->store('verification/nid_front', 'public');
                $writtenPaths['nid_back']    = $request->file('nid_back_image')->store('verification/nid_back', 'public');
                $writtenPaths['passport']    = $request->file('passport_photo')->store('verification/passport_photos', 'public');

                $user->update([
                    'nid_number'        => $validated['nid_number'],
                    'full_name'         => $validated['full_name'],
                    'date_of_birth'     => $validated['date_of_birth'],
                    'father_name'       => $validated['father_name'],
                    'mother_name'       => $validated['mother_name'],
                    'permanent_address' => $validated['permanent_address'],
                    'present_address'   => $validated['present_address'],
                    'phone_number'      => $validated['phone_number'],
                    'verification_status' => 'pending',
                    'nid_front_image'   => $writtenPaths['nid_front'],
                    'nid_back_image'    => $writtenPaths['nid_back'],
                    'passport_photo'    => $writtenPaths['passport'],
                    'verification_requested_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Verification submission failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            foreach ($writtenPaths as $path) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (\Throwable $cleanupError) {
                    Log::warning('Failed to clean up orphaned verification file', [
                        'user_id' => $user->id,
                        'path'    => $path,
                        'error'   => $cleanupError->getMessage(),
                    ]);
                }
            }

            return back()
                ->withErrors(['error' => 'We could not save your application right now. Please try again.'])
                ->withInput();
        }

        /*
         * Always dispatch a database notification so the admin verification
         * queue (which reads from the notifications table) updates the
         * moment the citizen submits. If no admin user exists yet, log a
         * warning and persist the application anyway — the data is still
         * recoverable from the users table, and creating a hardcoded
         * admin row on every citizen request is the wrong safety net.
         */
        $admins = User::where('role', 'admin')->get();

        if ($admins->isEmpty()) {
            Log::warning('No admin user exists; verification request persisted but no notification delivered.', [
                'user_id' => $user->id,
            ]);
        } else {
            try {
                Notification::send(
                    $admins,
                    new NewVerificationApplication($user->fresh())
                );
            } catch (\Throwable $e) {
                Log::warning('Admin notification dispatch failed', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('citizen.dashboard')->with('success', 'Verification application submitted successfully! Your request is now pending review.');
    }

    /**
     * Admin view to manage verification requests
     */
    public function adminIndex()
    {
        $pendingRequests = User::where('verification_status', 'pending')
            ->where('role', 'citizen')
            ->whereNotNull('verification_requested_at')
            ->orderBy('verification_requested_at', 'asc')
            ->get();

        $verifiedUsers = User::where('verification_status', 'verified')
            ->where('role', 'citizen')
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return view('admin.verification.index', compact('pendingRequests', 'verifiedUsers'));
    }

    /**
     * Show verification request details for admin
     */
    public function show(User $user)
    {
        if ($user->verification_status !== 'pending') {
            return redirect()->route('admin.verification.index')->with('error', 'This user does not have a pending verification request.');
        }

    $nidRecord = FakeNid::where('nid', $user->nid_number)->first();

        return view('admin.verification.show', compact('user', 'nidRecord'));
    }

    /**
     * Stream a verification document for the admin review page.
     *
     * The view renders <img src="..."> using this route instead of the
     * public/storage symlink, so document previews keep working on
     * deployments where `php artisan storage:link` was never run.
     * The field is restricted to the three allowed columns to avoid
     * leaking arbitrary user columns.
     */
    public function document(Request $request, User $user, string $field)
    {
        $allowed = [
            'nid_front_image' => 'NID Front',
            'nid_back_image'  => 'NID Back',
            'passport_photo'  => 'Passport Photo',
        ];

        if (!array_key_exists($field, $allowed)) {
            abort(404);
        }

        $path = $user->{$field};

        if (empty($path) || !Storage::disk('public')->exists($path)) {
            abort(404, 'Document not found.');
        }

        /*
         * Stream from the public disk without exposing a direct file
         * URL. We use the response()->file() helper which sets the
         * correct Content-Type and supports range requests.
         */
        $absolutePath = Storage::disk('public')->path($path);

        return response()->file(
            $absolutePath,
            [
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    /**
     * Approve verification request
     */
    public function approve(User $user)
    {
        if ($user->verification_status !== 'pending') {
            return redirect()->route('admin.verification.index')->with('error', 'This user does not have a pending verification request.');
        }

        if (empty($user->nid_number)) {
            return redirect()->route('admin.verification.index')->with('error', 'This application has no NID on file.');
        }

        /*
         * Atomic approval: lock the user row, check the NID, and only
         * then mark both the user and the fake_nids record. Without the
         * transaction + row lock, two admins clicking Approve at the same
         * time could each pass the guard and end up double-updating.
         *
         * Important: an admin-approved NID is allowed even when it is
         * not present in the fake_nids registry. The registry is a soft
         * signal — when it's missing we auto-create a non-blocked,
         * non-pre-verified record so subsequent approvals know the NID
         * is now consumed.
         */
        $result = DB::transaction(function () use ($user) {
            $locked = User::where('id', $user->id)
                ->where('verification_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return ['ok' => false, 'reason' => 'This request is no longer pending.'];
            }

            $nid = FakeNid::where('nid', $user->nid_number)->lockForUpdate()->first();

            /*
             * No registry row yet — admin is approving a NID not in the
             * fake_nids table. Create a row so future submissions cannot
             * re-use this NID.
             */
            if (!$nid) {
                $nid = FakeNid::create([
                    'nid'               => $user->nid_number,
                    'name'              => $user->full_name ?: ($user->name ?: 'Unknown'),
                    'father_name'       => $user->father_name ?: 'Unknown',
                    'mother_name'       => $user->mother_name ?: 'Unknown',
                    'date_of_birth'     => $user->date_of_birth ?: now()->subYears(20)->toDateString(),
                    'permanent_address' => $user->permanent_address ?: 'Unknown',
                    'present_address'   => $user->present_address ?: ($user->permanent_address ?: 'Unknown'),
                    'is_blocked'        => false,
                    'is_verified'       => false,
                ]);
            }

            if ($nid->is_blocked) {
                return ['ok' => false, 'reason' => 'This NID has been blocked and cannot be verified.'];
            }

            if ($nid->is_verified && $nid->verified_by !== Auth::id()) {
                /*
                 * Another user already used this NID. Do NOT allow a
                 * second user to inherit verification — a NID is one
                 * citizen, one record.
                 */
                return ['ok' => false, 'reason' => 'This NID has already been used to verify another citizen.'];
            }

            $nid->update([
                'is_verified' => true,
                'verified_at' => now(),
                'verified_by' => Auth::id(),
            ]);

            $user->update([
                'verification_status' => 'verified',
                'verified_at'         => now(),
                'verified_by'         => Auth::id(),
            ]);

            return ['ok' => true];
        });

        if (!$result['ok']) {
            return redirect()->route('admin.verification.index')->with('error', $result['reason']);
        }

        return redirect()->route('admin.verification.index')->with('success', 'User verification approved successfully!');
    }

    /**
     * Reject verification request
     */
    public function reject(Request $request, User $user)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500'
        ]);

        if ($user->verification_status !== 'pending') {
            return redirect()->route('admin.verification.index')->with('error', 'This user does not have a pending verification request.');
        }

        /*
         * Atomic rejection. Update user status and clear uploaded files
         * inside a single transaction so a partial failure cannot leave
         * stale images on disk pointing to a rejected record.
         */
        DB::transaction(function () use ($request, $user) {
            $locked = User::where('id', $user->id)
                ->where('verification_status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                return;
            }

            $images = array_filter([
                $user->nid_front_image,
                $user->nid_back_image,
                $user->passport_photo,
            ]);

            $locked->update([
                'verification_status' => 'rejected',
                'rejection_reason'    => $request->rejection_reason,
                'rejected_at'         => now(),
                'rejected_by'         => Auth::id(),
                'nid_front_image'     => null,
                'nid_back_image'      => null,
                'passport_photo'      => null,
                /*
                 * Reset verification_requested_at so the citizen's create()
                 * guard ("already pending + requested_at") does not keep
                 * blocking them after rejection. The rejection itself is
                 * recorded via rejection_reason + rejected_at.
                 */
                'verification_requested_at' => null,
            ]);

            /*
             * Delete the previously uploaded files. Failures here are
             * logged but do not block the rejection — orphaned images are
             * preferable to a stuck pending record.
             */
            foreach ($images as $path) {
                try {
                    Storage::disk('public')->delete($path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete rejected verification image', [
                        'user_id' => $user->id,
                        'path'    => $path,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        return redirect()->route('admin.verification.index')->with('success', 'Verification request rejected.');
    }

    /**
     * Download verification certificate as PDF for verified citizens
     */
    public function downloadCertificate()
    {
        $user = Auth::user();

        if ($user->verification_status !== 'verified') {
            return redirect()->back()->with('error', 'Certificate is available only after verification.');
        }

        $certificateNumber = sprintf('VCN-%06d', $user->id);
        $issuedDate = optional($user->verified_at)->format('F d, Y') ?? now()->format('F d, Y');
        $qrUrl = URL::temporarySignedRoute('verification.status', now()->addYears(5), ['user' => $user->id]);

        $qrImage = null;
        $qrResponse = Http::timeout(10)->get('https://api.qrserver.com/v1/create-qr-code/', [
            'size' => '320x320',
            'data' => $qrUrl,
            'margin' => 0,
        ]);

        if ($qrResponse->successful()) {
            $qrImage = 'data:image/png;base64,' . base64_encode($qrResponse->body());
        }

        $pdf = Pdf::loadView('verification.certificate', [
            'user' => $user,
            'certificateNumber' => $certificateNumber,
            'issuedDate' => $issuedDate,
            'qrImage' => $qrImage,
            'qrFallbackUrl' => $qrUrl,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('verification-certificate-' . $user->id . '.pdf');
    }

    /**
     * Public verification status endpoint used by QR code scans
     */
    public function publicStatus(Request $request, User $user)
    {
        $status = match ($user->verification_status) {
            'verified' => 'verified',
            'rejected' => 'rejected',
            default    => 'pending',
        };

        return view('verification.status', [
            'user'   => $user,
            'status' => $status,
        ]);
    }
}
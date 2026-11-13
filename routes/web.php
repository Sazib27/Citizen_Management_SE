<?php

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\AdminPropertyController;
use App\Http\Controllers\AdminRentAgreementController;
use App\Http\Controllers\AdminRevenueController;
use App\Http\Controllers\AdminTaxController;
use App\Http\Controllers\CitizenRentAgreementController;
use App\Http\Controllers\CitizenTaxController;
use App\Http\Controllers\SSLCommerzTaxPaymentController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\AdminComplaintController;


/*
|--------------------------------------------------------------------------
| Database Test Route
|--------------------------------------------------------------------------
*/

Route::get('/db', function () {

    try {

        DB::connection()->getPdo();

        return " Database connected successfully.";

    } catch (\Exception $e) {

        return " Could not connect to the database. Error: "
            . $e->getMessage();
    }

});


/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/

Route::get('/', [
    AuthController::class,
    'showLogin'
])->name('login');


Route::get('/login', function () {

    return redirect()->route('login');

});


Route::get('/register', [
    AuthController::class,
    'showRegistration'
])->name('register');


Route::post('/register', [
    AuthController::class,
    'register'
])
    ->name('register.post')
    ->middleware('throttle:register');


Route::post('/login', [
    AuthController::class,
    'login'
])
    ->name('login.post')
    ->middleware('throttle:login');


Route::post('/logout', [
    AuthController::class,
    'logout'
])
    ->name('logout')
    ->middleware('auth');


/*
|--------------------------------------------------------------------------
| Email Verification Routes
|--------------------------------------------------------------------------
*/


/*
 * Verification notice page.
 */

Route::get('/email/verify', function () {

    return view('auth.verify-email');

})
    ->middleware('auth')
    ->name('verification.notice');


/*
 * Verification link from Gmail.
 */

Route::get('/email/verify/{id}/{hash}', function (
    EmailVerificationRequest $request
) {

    $request->fulfill();

    return redirect()
        ->route('citizen.dashboard')
        ->with(
            'success',
            'Your email has been successfully verified.'
        );

})
    ->middleware([
        'auth',
        'signed',
        'throttle:6,1'
    ])
    ->name('verification.verify');


/*
 * Resend verification email + OTP.
 */

Route::post('/email/verification-notification', [
    AuthController::class,
    'resendVerificationEmail'
])
    ->middleware([
        'auth',
        'throttle:6,1'
    ])
    ->name('verification.send');


/*
 * Verify email using OTP.
 */

Route::post('/email/verify-otp', [
    AuthController::class,
    'verifyOtp'
])
    ->middleware([
        'auth',
        'throttle:5,1'
    ])
    ->name('verification.otp');


/*
|--------------------------------------------------------------------------
| Password Recovery Routes
|--------------------------------------------------------------------------
*/


/*
 * Forgot password page.
 */

Route::get('/forgot-password', [
    AuthController::class,
    'showForgotPassword'
])
    ->middleware('guest')
    ->name('password.request');


/*
 * Send password reset email.
 */

Route::post('/forgot-password', [
    AuthController::class,
    'sendResetLink'
])
    ->middleware([
        'guest',
        'throttle:6,1'
    ])
    ->name('password.email');


/*
 * Password reset page.
 */

Route::get('/reset-password/{token}', [
    AuthController::class,
    'showResetPassword'
])
    ->middleware('guest')
    ->name('password.reset');


/*
 * Reset password.
 */

Route::post('/reset-password', [
    AuthController::class,
    'resetPassword'
])
    ->middleware('guest')
    ->name('password.update');


/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'admin'
])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        Route::get('/dashboard', function () {

            return view('dashboard.admindashboard');

        })->name('dashboard');

    });


/*
|--------------------------------------------------------------------------
| Citizen Routes
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth',
    'citizen'
])
    ->prefix('citizen')
    ->name('citizen.')
    ->group(function () {


        Route::get('/dashboard', function () {

            return view('dashboard.normalcitizenn');

        })->name('dashboard');


        /*
         * Citizen Profile
         */

        Route::get('/profile', function () {

            return view('dashboard.citizenprofile');

        })->name('profile');


        /*
         * Citizen profile edit + update. Allows the citizen to update
         * their name, email, and phone. NID + verification fields are
         * intentionally NOT editable here — those go through the
         * verification flow so admin review stays the source of truth.
         */

        Route::get(
            '/profile/edit',
            [
                VerificationController::class,
                'editProfile'
            ]
        )->name('profile.edit');

        Route::put(
            '/profile',
            [
                VerificationController::class,
                'updateProfile'
            ]
        )->name('profile.update');


        /*
         * Verification Certificate
         */

        Route::get(
            '/verification/certificate',
            [
                VerificationController::class,
                'downloadCertificate'
            ]
        )->name('verification.certificate');


        /*
         * Existing Citizen/NID Verification Middleware
         *
         * IMPORTANT:
         * This remains unchanged.
         *
         * `verified.email` is layered on top so that a citizen who
         * registered but never confirmed their address cannot reach the
         * high-value service routes by posting to them directly. The
         * dashboard, profile and verification routes stay outside this
         * group so the citizen can still complete the verification.
         */

        Route::middleware(['verified.email', 'verified.citizen'])->group(function () {


            /*
             * Property browsing & requests.
             */

            Route::get(
                '/properties',
                [
                    PropertyController::class,
                    'index'
                ]
            )->name('properties.index');


            Route::get(
                '/properties/request/add',
                [
                    PropertyController::class,
                    'createAddRequest'
                ]
            )->name('properties.request.add');


            Route::post(
                '/properties/request/add',
                [
                    PropertyController::class,
                    'storeAddRequest'
                ]
            )->name('properties.request.add.store');


            Route::get(
                '/properties/{property}/request/update',
                [
                    PropertyController::class,
                    'createUpdateRequest'
                ]
            )->name('properties.request.update');


            Route::post(
                '/properties/{property}/request/update',
                [
                    PropertyController::class,
                    'storeUpdateRequest'
                ]
            )->name('properties.request.update.store');


            Route::get(
                '/properties/{property}/request/transfer',
                [
                    PropertyController::class,
                    'createTransferRequest'
                ]
            )->name('properties.request.transfer');


            Route::post(
                '/properties/{property}/request/transfer',
                [
                    PropertyController::class,
                    'storeTransferRequest'
                ]
            )->name('properties.request.transfer.store');


            Route::post(
                '/properties/{property}/rental-request',
                [
                    PropertyController::class,
                    'submitRentalRequest'
                ]
            )->name('properties.rental-request');


            Route::get(
                '/properties/{property}',
                [
                    PropertyController::class,
                    'show'
                ]
            )->name('properties.show');


            /*
             * Rent Agreements.
             */

            Route::get(
                '/rent-agreements',
                [
                    CitizenRentAgreementController::class,
                    'index'
                ]
            )->name('rent-agreements.index');


            Route::get(
                '/rent-agreements/{rentAgreement}',
                [
                    CitizenRentAgreementController::class,
                    'show'
                ]
            )->name('rent-agreements.show');


            Route::get(
                '/rent-agreements/{rentAgreement}/download',
                [
                    CitizenRentAgreementController::class,
                    'download'
                ]
            )->name('rent-agreements.download');


            Route::get(
                '/rent-agreements-lookup',
                [
                    CitizenRentAgreementController::class,
                    'lookup'
                ]
            )->name('rent-agreements.lookup');


            /*
             * Taxes.
             */

            Route::get(
                '/taxes',
                [
                    CitizenTaxController::class,
                    'index'
                ]
            )->name('taxes.index');


            Route::get(
                '/taxes/{taxAssessment}/pay',
                [
                    CitizenTaxController::class,
                    'showPay'
                ]
            )->name('taxes.pay.show');


            Route::post(
                '/taxes/{taxAssessment}/pay',
                [
                    SSLCommerzTaxPaymentController::class,
                    'create'
                ]
            )->name('taxes.pay');


            /*
             * SSL Commerz return URLs. `success` and `ipn` are both
             * needed — success is the customer-facing redirect, ipn is
             * the server-to-server authoritative confirmation.
             */
            Route::get(
                '/taxes/payment/success',
                [
                    SSLCommerzTaxPaymentController::class,
                    'success'
                ]
            )->name('taxes.payment.success');

            Route::get(
                '/taxes/payment/fail',
                [
                    SSLCommerzTaxPaymentController::class,
                    'fail'
                ]
            )->name('taxes.payment.fail');

            Route::get(
                '/taxes/payment/cancel',
                [
                    SSLCommerzTaxPaymentController::class,
                    'cancel'
                ]
            )->name('taxes.payment.cancel');

            /*
             * Demo-only confirmation endpoint. Returns 404 in
             * production — the controller checks the demo flag.
             */
            Route::get(
                '/taxes/payment/demo',
                [
                    SSLCommerzTaxPaymentController::class,
                    'demoConfirm'
                ]
            )->name('taxes.payment.demo');


            Route::get(
                '/taxes/payments/{taxPayment}/receipt',
                [
                    CitizenTaxController::class,
                    'receipt'
                ]
            )->name('taxes.payments.receipt');


            /*
             * Complaints.
             */

            Route::get(
                '/complaints',
                [
                    ComplaintController::class,
                    'index'
                ]
            )->name('complaints.index');


            Route::get(
                '/complaints/create',
                [
                    ComplaintController::class,
                    'create'
                ]
            )->name('complaints.create');


            Route::post(
                '/complaints',
                [
                    ComplaintController::class,
                    'store'
                ]
            )->name('complaints.store');


            Route::get(
                '/complaints/{complaint}',
                [
                    ComplaintController::class,
                    'show'
                ]
            )->name('complaints.show');


            Route::post(
                '/complaints/{complaint}/rating',
                [
                    ComplaintController::class,
                    'rate'
                ]
            )->name('complaints.rate');

        });


        /*
         * Owner rental-request queue.
         *
         * Deliberately placed OUTSIDE the `verified.citizen` group above.
         *
         * A property owner whose citizen verification is pending or
         * rejected is redirected away from every property route, so they
         * could never open their own property page to confirm a rental
         * request. With the confirmation unreachable, the request could
         * never be forwarded to the admin either, and the admin had no
         * way to complete the workflow.
         *
         * The owner only needs to be able to *read* the request and
         * confirm terms on their own property, so this stays behind
         * `auth` + `citizen` only.
         */

        Route::get(
            '/property-rental-requests',
            [
                PropertyController::class,
                'ownerRentalRequests'
            ]
        )->name('properties.owner-rental-requests');


        Route::post(
            '/rental-requests/{rentalRequest}/owner-confirm',
            [
                PropertyController::class,
                'confirmRentalRequest'
            ]
        )->name('rental-requests.owner-confirm');

    });


/*
|--------------------------------------------------------------------------
| SSL Commerz Server-to-Server Callbacks
|--------------------------------------------------------------------------
|
| The IPN endpoint is called by SSL Commerz's servers, not by a browser.
| It therefore sits OUTSIDE the `auth` / `citizen` middleware groups —
| a gateway POST carries no session cookie and would otherwise be
| rejected before reaching the controller.
|
| Because the endpoint is unauthenticated, it must be protected by
| something else. Two independent layers are applied:
|
|   1. A shared-secret header check (SSLCZ lets you configure a custom
|      header value; we compare it against SSLCZ_IPN_SECRET).
|   2. Server-side reconciliation against the gateway's Validation API
|      inside the controller, which confirms amount, currency and
|      transaction id before any payment row is written.
|
*/

Route::any(
    '/citizen/taxes/payment/ipn',
    [
        SSLCommerzTaxPaymentController::class,
        'ipn'
    ]
)->name('citizen.taxes.payment.ipn');


/*
|--------------------------------------------------------------------------
| Citizen/NID Verification Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {


    /*
     * Citizen verification routes.
     */

    Route::get(
        '/verification/apply',
        [
            VerificationController::class,
            'create'
        ]
    )
        ->name('verification.create')
        ->middleware('citizen');


    Route::post(
        '/verification/prefill',
        [
            VerificationController::class,
            'prefill'
        ]
    )
        ->name('verification.prefill')
        ->middleware('citizen');


    Route::post(
        '/verification/apply',
        [
            VerificationController::class,
            'store'
        ]
    )
        ->name('verification.store')
        ->middleware('citizen');


    /*
     * Admin verification routes.
     */

    Route::middleware(['admin'])
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {


            Route::get(
                '/verification',
                [
                    VerificationController::class,
                    'adminIndex'
                ]
            )->name('verification.index');


            /*
             * Stream a verification document (NID front/back/passport)
             * from the private storage disk. This avoids relying on a
             * public/storage symlink being present in the deployment —
             * common when the app is served via a separate front-end
             * proxy or a fresh clone where `php artisan storage:link`
             * was never run.
             *
             * Registered BEFORE `/verification/{user}` so the more
             * specific path wins route matching.
             */

            Route::get(
                '/verification/{user}/document/{field}',
                [
                    VerificationController::class,
                    'document'
                ]
            )->name('verification.document');


            Route::get(
                '/verification/{user}',
                [
                    VerificationController::class,
                    'show'
                ]
            )->name('verification.show');


            Route::post(
                '/verification/{user}/approve',
                [
                    VerificationController::class,
                    'approve'
                ]
            )->name('verification.approve');


            Route::post(
                '/verification/{user}/reject',
                [
                    VerificationController::class,
                    'reject'
                ]
            )->name('verification.reject');


            /*
             * Admin properties.
             */

            Route::get(
                '/properties',
                [
                    AdminPropertyController::class,
                    'index'
                ]
            )->name('properties.index');


            Route::get(
                '/properties/create',
                [
                    AdminPropertyController::class,
                    'create'
                ]
            )->name('properties.create');


            Route::post(
                '/properties',
                [
                    AdminPropertyController::class,
                    'store'
                ]
            )->name('properties.store');


            Route::get(
                '/properties/{property}/edit',
                [
                    AdminPropertyController::class,
                    'edit'
                ]
            )->name('properties.edit');


            Route::put(
                '/properties/{property}',
                [
                    AdminPropertyController::class,
                    'update'
                ]
            )->name('properties.update');


            Route::delete(
                '/properties/{property}',
                [
                    AdminPropertyController::class,
                    'destroy'
                ]
            )->name('properties.destroy');


            Route::get(
                '/properties/requests',
                [
                    AdminPropertyController::class,
                    'requests'
                ]
            )->name('properties.requests');


            Route::post(
                '/properties/requests/{propertyRequest}',
                [
                    AdminPropertyController::class,
                    'handleRequest'
                ]
            )->name('properties.requests.handle');


            Route::get(
                '/rental-requests',
                [
                    AdminPropertyController::class,
                    'rentalRequests'
                ]
            )->name('properties.rentals');


            Route::post(
                '/rental-requests/{rentalRequest}',
                [
                    AdminPropertyController::class,
                    'handleRental'
                ]
            )->name('rental-requests.handle');


            /*
             * Admin rent agreements.
             */

            Route::get(
                '/rent-agreements',
                [
                    AdminRentAgreementController::class,
                    'index'
                ]
            )->name('rent-agreements.index');


            Route::get(
                '/rent-agreements/{rentAgreement}',
                [
                    AdminRentAgreementController::class,
                    'show'
                ]
            )->name('rent-agreements.show');


            Route::get(
                '/rent-agreements/{rentAgreement}/download',
                [
                    AdminRentAgreementController::class,
                    'download'
                ]
            )->name('rent-agreements.download');


            /*
             * Admin taxes.
             */

            Route::get(
                '/taxes',
                [
                    AdminTaxController::class,
                    'index'
                ]
            )->name('taxes.index');


            Route::get(
                '/taxes/create',
                [
                    AdminTaxController::class,
                    'create'
                ]
            )->name('taxes.create');


            Route::post(
                '/taxes',
                [
                    AdminTaxController::class,
                    'store'
                ]
            )->name('taxes.store');


            Route::get(
                '/taxes/{taxAssessment}',
                [
                    AdminTaxController::class,
                    'show'
                ]
            )->name('taxes.show');


            Route::post(
                '/taxes/{taxAssessment}/issue',
                [
                    AdminTaxController::class,
                    'issue'
                ]
            )->name('taxes.issue');


            Route::post(
                '/taxes/{taxAssessment}/payments',
                [
                    AdminTaxController::class,
                    'recordPayment'
                ]
            )->name('taxes.payments.store');


            /*
             * Admin revenue.
             */

            Route::get(
                '/revenue',
                [
                    AdminRevenueController::class,
                    'index'
                ]
            )->name('revenue.index');


            Route::get(
                '/revenue/export',
                [
                    AdminRevenueController::class,
                    'export'
                ]
            )->name('revenue.export');


            Route::get(
                '/revenue/export-valuations',
                [
                    AdminRevenueController::class,
                    'exportValuations'
                ]
            )->name('revenue.export-valuations');


            /*
             * Admin complaints.
             */

            Route::get(
                '/complaints',
                [
                    AdminComplaintController::class,
                    'index'
                ]
            )->name('complaints.index');


            Route::get(
                '/complaints/{complaint}',
                [
                    AdminComplaintController::class,
                    'show'
                ]
            )->name('complaints.show');


            Route::put(
                '/complaints/{complaint}',
                [
                    AdminComplaintController::class,
                    'update'
                ]
            )->name('complaints.update');

        });

});


/*
|--------------------------------------------------------------------------
| Public Verification Endpoints
|--------------------------------------------------------------------------
|
| `verification.certificate.verify` is the endpoint the QR code on the
| issued certificate points at. It is signed (so the URL cannot be
| forged) but otherwise public — a third party holding the certificate
| must be able to check it without an account.
|
*/

Route::get(
    '/verification/certificate/{certificate}',
    [
        VerificationController::class,
        'verifyCertificate'
    ]
)
    ->name('verification.certificate.verify')
    ->middleware('signed');


Route::get(
    '/verification/status/{user}',
    [
        VerificationController::class,
        'publicStatus'
    ]
)
    ->name('verification.status')
    ->middleware('signed');
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
use App\Http\Controllers\StripeTaxPaymentController;
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
])->name('register.post');


Route::post('/login', [
    AuthController::class,
    'login'
])->name('login.post');


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
         */

        Route::middleware('verified.citizen')->group(function () {


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


            Route::post(
                '/rental-requests/{rentalRequest}/owner-confirm',
                [
                    PropertyController::class,
                    'confirmRentalRequest'
                ]
            )->name('rental-requests.owner-confirm');


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
                    StripeTaxPaymentController::class,
                    'create'
                ]
            )->name('taxes.pay');


            Route::get(
                '/taxes/payment/success',
                [
                    StripeTaxPaymentController::class,
                    'success'
                ]
            )->name('taxes.payment.success');


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

        });

    });


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
| Public Verification Status Endpoint
|--------------------------------------------------------------------------
*/

Route::get(
    '/verification/status/{user}',
    [
        VerificationController::class,
        'publicStatus'
    ]
)
    ->name('verification.status')
    ->middleware('signed');
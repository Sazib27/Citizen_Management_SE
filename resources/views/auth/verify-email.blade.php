<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Verify Your Email</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 40px 20px;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #ffffff;

            color: #111827;
        }

        .container {
            width: 100%;
            max-width: 650px;
            margin: 0 auto;
            text-align: center;
        }

        .email-icon {
            width: 90px;
            height: 90px;

            margin: 0 auto 25px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 20px;

            background:
                linear-gradient(
                    135deg,
                    #f3e8ff,
                    #dbeafe
                );

            font-size: 48px;
        }

        h1 {
            margin: 10px 0 25px;

            font-size: 46px;

            font-weight: 700;

            color: #111827;
        }

        .description {
            font-size: 19px;

            line-height: 1.6;

            color: #4b5563;
        }

        .email {
            margin: 25px 0;

            font-size: 22px;

            font-weight: 700;

            color: #111827;

            word-break: break-word;
        }

        .success {
            margin: 25px 0;

            padding: 18px 22px;

            border-radius: 12px;

            background: #dcfce7;

            color: #166534;

            font-size: 17px;

            line-height: 1.5;
        }

        .warning {
            margin: 25px 0;

            padding: 18px 22px;

            border-radius: 12px;

            background: #fef3c7;

            color: #92400e;

            font-size: 17px;

            line-height: 1.5;
        }

        .errors {
            margin: 25px 0;

            padding: 18px 22px;

            border-radius: 12px;

            background: #fee2e2;

            color: #991b1b;

            font-size: 16px;

            line-height: 1.5;
        }

        .otp-box {
            margin-top: 35px;

            padding: 30px;

            border-radius: 16px;

            background: #f8fafc;

            border: 1px solid #e5e7eb;
        }

        .otp-box h2 {
            margin: 0 0 10px;

            font-size: 27px;

            color: #111827;
        }

        .otp-description {
            margin-bottom: 20px;

            font-size: 16px;

            color: #6b7280;
        }

        .otp-input {
            width: 240px;

            max-width: 100%;

            padding: 14px 15px;

            border: 2px solid #d1d5db;

            border-radius: 10px;

            outline: none;

            text-align: center;

            font-size: 28px;

            font-weight: 600;

            letter-spacing: 8px;

            color: #111827;

            background: #ffffff;
        }

        .otp-input:focus {
            border-color: #2563eb;

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, 0.12);
        }

        .verify-button {
            margin-top: 18px;

            padding: 14px 32px;

            border: none;

            border-radius: 8px;

            background: #2563eb;

            color: #ffffff;

            font-size: 17px;

            font-weight: 600;

            cursor: pointer;
        }

        .verify-button:hover {
            background: #1d4ed8;
        }

        .divider {
            display: flex;

            align-items: center;

            gap: 15px;

            margin: 30px 0;

            color: #9ca3af;

            font-size: 15px;
        }

        .divider::before,
        .divider::after {
            content: "";

            flex: 1;

            height: 1px;

            background: #e5e7eb;
        }

        .link-info {
            font-size: 16px;

            color: #6b7280;

            line-height: 1.6;
        }

        .resend-button {
            margin-top: 22px;

            padding: 14px 28px;

            border: none;

            border-radius: 8px;

            background: #2563eb;

            color: #ffffff;

            font-size: 17px;

            font-weight: 600;

            cursor: pointer;
        }

        .resend-button:hover {
            background: #1d4ed8;
        }

        .logout-button {
            margin-top: 22px;

            padding: 13px 30px;

            border: none;

            border-radius: 8px;

            background: #e5e7eb;

            color: #111827;

            font-size: 16px;

            cursor: pointer;
        }

        .logout-button:hover {
            background: #d1d5db;
        }

        .expiry {
            margin-top: 15px;

            font-size: 13px;

            color: #9ca3af;
        }

        @media (max-width: 600px) {

            body {
                padding: 25px 15px;
            }

            h1 {
                font-size: 36px;
            }

            .description {
                font-size: 17px;
            }

            .email {
                font-size: 19px;
            }

            .otp-box {
                padding: 22px 15px;
            }

        }

    </style>

</head>


<body>

<div class="container">


    <!-- Email Icon -->

    <div class="email-icon">
        📧
    </div>


    <!-- Heading -->

    <h1>
        Verify Your Email
    </h1>


    <p class="description">
        Thanks for registering with our system.
    </p>


    <p class="description">
        We have sent a verification link and
        a 6-digit OTP to:
    </p>


    <!-- User Email -->

    <div class="email">

        {{ Auth::user()->email }}

    </div>


    <!-- Success Message -->

    @if (session('success'))

        <div class="success">

            {{ session('success') }}

        </div>

    @endif


    <!-- Warning Message -->

    @if (session('warning'))

        <div class="warning">

            {{ session('warning') }}

        </div>

    @endif


    <!-- Validation Errors -->

    @if ($errors->any())

        <div class="errors">

            @foreach ($errors->all() as $error)

                <div>
                    {{ $error }}
                </div>

            @endforeach

        </div>

    @endif


    <!-- OTP Verification -->

    <div class="otp-box">

        <h2>
            Verify Using OTP
        </h2>


        <p class="otp-description">

            Enter the 6-digit verification code
            sent to your Gmail address.

        </p>


        <form
            method="POST"
            action="{{ route('verification.otp') }}"
        >

            @csrf


            <input
                type="text"
                name="otp"
                class="otp-input"
                maxlength="6"
                minlength="6"
                pattern="[0-9]{6}"
                inputmode="numeric"
                autocomplete="one-time-code"
                placeholder="000000"
                required
            >


            <br>


            <button
                type="submit"
                class="verify-button"
            >
                Verify OTP
            </button>

        </form>


        <div class="expiry">

            OTP expires after 10 minutes.

        </div>

    </div>


    <!-- Verification Link -->

    <div class="divider">
        OR
    </div>


    <p class="link-info">

        Open your Gmail inbox and click the
        <strong>Verify My Email</strong>
        button in the verification email.

    </p>


    <!-- Resend -->

    <form
        method="POST"
        action="{{ route('verification.send') }}"
    >

        @csrf


        <button
            type="submit"
            class="resend-button"
        >
            Resend Verification Email & OTP
        </button>

    </form>


    <!-- Logout -->

    <form
        method="POST"
        action="{{ route('logout') }}"
    >

        @csrf


        <button
            type="submit"
            class="logout-button"
        >
            Logout
        </button>

    </form>

</div>

</body>

</html>
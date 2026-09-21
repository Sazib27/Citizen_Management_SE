<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Forgot Password</title>

</head>

<body>

<div style="
    max-width:500px;
    margin:60px auto;
    padding:30px;
    font-family:Arial, sans-serif;
">

    <h1>Forgot Password?</h1>

    <p>
        Enter your email address and we will send you
        a password reset link.
    </p>


    @if (session('success'))

        <div style="
            padding:15px;
            margin:20px 0;
            background:#dcfce7;
            color:#166534;
            border-radius:8px;
        ">

            {{ session('success') }}

        </div>

    @endif


    @if ($errors->any())

        <div style="
            padding:15px;
            margin:20px 0;
            background:#fee2e2;
            color:#991b1b;
            border-radius:8px;
        ">

            @foreach ($errors->all() as $error)

                <div>{{ $error }}</div>

            @endforeach

        </div>

    @endif


    <form
        method="POST"
        action="{{ route('password.email') }}"
    >

        @csrf

        <label>
            Email Address
        </label>

        <br><br>

        <input
            type="email"
            name="email"
            value="{{ old('email') }}"
            required
            style="
                width:100%;
                padding:12px;
                border:1px solid #ccc;
                border-radius:6px;
            "
        >

        <br><br>

        <button
            type="submit"
            style="
                padding:12px 25px;
                background:#2563eb;
                color:white;
                border:none;
                border-radius:6px;
                cursor:pointer;
            "
        >
            Send Reset Link
        </button>

    </form>


    <br>

    <a href="{{ route('login') }}">
        Back to Login
    </a>

</div>

</body>

</html>
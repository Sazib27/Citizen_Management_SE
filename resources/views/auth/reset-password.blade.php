<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Reset Password</title>

</head>

<body>

<div style="
    max-width:500px;
    margin:60px auto;
    padding:30px;
    font-family:Arial, sans-serif;
">

    <h1>Reset Password</h1>


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
        action="{{ route('password.update') }}"
    >

        @csrf


        <input
            type="hidden"
            name="token"
            value="{{ $token }}"
        >


        <label>
            Email Address
        </label>

        <br><br>

        <input
            type="email"
            name="email"
            value="{{ old('email', $email) }}"
            required
            style="
                width:100%;
                padding:12px;
                border:1px solid #ccc;
                border-radius:6px;
            "
        >

        <br><br>


        <label>
            New Password
        </label>

        <br><br>

        <input
            type="password"
            name="password"
            required
            minlength="8"
            style="
                width:100%;
                padding:12px;
                border:1px solid #ccc;
                border-radius:6px;
            "
        >

        <br><br>


        <label>
            Confirm New Password
        </label>

        <br><br>

        <input
            type="password"
            name="password_confirmation"
            required
            minlength="8"
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
            Reset Password
        </button>

    </form>

</div>

</body>

</html>
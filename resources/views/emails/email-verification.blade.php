<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verify Your Email</title>

</head>

<body
    style="
        margin:0;
        padding:0;
        background:#f3f4f6;
        font-family:Arial, Helvetica, sans-serif;
    "
>

<table
    width="100%"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="padding:40px 15px;"
>

<tr>

<td align="center">

<table
    width="600"
    cellpadding="0"
    cellspacing="0"
    border="0"
    style="
        max-width:600px;
        width:100%;
        background:#ffffff;
        border-radius:12px;
        overflow:hidden;
    "
>

<tr>

<td
    align="center"
    style="
        padding:35px 25px 20px;
        background:#2563eb;
    "
>

<h1
    style="
        margin:0;
        color:#ffffff;
        font-size:30px;
    "
>
    Verify Your Email
</h1>

</td>

</tr>

<tr>

<td
    style="
        padding:35px 30px;
        color:#374151;
        text-align:center;
    "
>

<p
    style="
        font-size:18px;
        margin:0 0 20px;
    "
>
    Hello {{ $user->name }},
</p>

<p
    style="
        font-size:16px;
        line-height:1.6;
    "
>
    Thank you for registering with our system.
    Please verify your email address using the button below
    or enter the OTP shown below.
</p>


<!-- Verification Button -->

<p style="margin:30px 0;">

<a
    href="{{ $verificationUrl }}"
    style="
        display:inline-block;
        padding:14px 30px;
        background:#2563eb;
        color:#ffffff;
        text-decoration:none;
        border-radius:8px;
        font-size:17px;
        font-weight:bold;
    "
>
    Verify My Email
</a>

</p>


<!-- OTP -->

<div
    style="
        margin:30px 0;
        padding:25px;
        background:#f8fafc;
        border:1px solid #e5e7eb;
        border-radius:10px;
    "
>

<p
    style="
        margin:0 0 10px;
        font-size:16px;
        color:#6b7280;
    "
>
    Your 6-digit verification OTP is:
</p>

<div
    style="
        font-size:32px;
        font-weight:bold;
        letter-spacing:8px;
        color:#111827;
    "
>
    {{ $otp }}
</div>

<p
    style="
        margin:15px 0 0;
        font-size:13px;
        color:#9ca3af;
    "
>
    This OTP expires in 10 minutes.
</p>

</div>


<p
    style="
        font-size:14px;
        line-height:1.6;
        color:#6b7280;
    "
>
    If you did not create this account, you can safely ignore this email.
</p>

</td>

</tr>

<tr>

<td
    align="center"
    style="
        padding:20px;
        background:#f9fafb;
        color:#9ca3af;
        font-size:13px;
    "
>

© {{ date('Y') }} {{ config('app.name') }}

</td>

</tr>

</table>

</td>

</tr>

</table>

</body>

</html>
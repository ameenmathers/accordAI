<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AccordAI Invitation</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f4f4f5; margin: 0; padding: 40px 20px; }
        .card { background: #fff; border-radius: 12px; max-width: 480px; margin: 0 auto; padding: 40px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        .logo { font-size: 22px; font-weight: 700; color: #4f46e5; margin-bottom: 24px; }
        h1 { font-size: 20px; color: #111827; margin: 0 0 12px; }
        p { font-size: 15px; color: #6b7280; line-height: 1.6; margin: 0 0 16px; }
        .badge { display: inline-block; background: #ede9fe; color: #4f46e5; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; margin-bottom: 24px; }
        .btn { display: inline-block; background: #4f46e5; color: #fff; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 15px; font-weight: 600; margin: 8px 0 24px; }
        .note { font-size: 13px; color: #9ca3af; }
        .note a { color: #6b7280; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">AccordAI</div>

        <span class="badge">{{ $contextType }} Mediation</span>

        <h1>{{ $invitedBy->name }} invited you to a session</h1>

        <p>
            You've been invited to join <strong>{{ $chatTitle }}</strong> — an AI-mediated conversation
            where AccordAI helps guide the discussion with balanced, evidence-based insights.
        </p>

        <p>Click the button below to join. You'll need to log in or create an account first.</p>

        <a href="{{ $acceptUrl }}" class="btn">Join the Session</a>

        <p class="note">
            If you weren't expecting this, you can ignore this email.<br>
            Link: <a href="{{ $acceptUrl }}">{{ $acceptUrl }}</a>
        </p>
    </div>
</body>
</html>

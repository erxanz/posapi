<!DOCTYPE html>
<html lang='id'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; font-weight: 600; }
        .content { padding: 30px 25px; }
        .content h2 { color: #333; font-size: 22px; margin-top: 0; margin-bottom: 15px; }
        .content p { color: #555; font-size: 16px; line-height: 1.6; margin: 10px 0; }
        .button-container { text-align: center; margin: 30px 0; }
        .btn { display: inline-block; padding: 12px 35px; background-color: #667eea; color: white; text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 16px; }
        .btn:hover { background-color: #5568d3; }
        .info-box { background-color: #f9f9f9; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .info-box strong { color: #333; }
        .footer { background-color: #f5f5f5; padding: 20px 25px; text-align: center; border-top: 1px solid #e0e0e0; }
        .footer p { color: #999; font-size: 13px; margin: 5px 0; }
        .divider { height: 1px; background-color: #e0e0e0; margin: 25px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Reset Password</h1>
        </div>
        <div class='content'>
            <h2>Halo, {{ $user->name }}</h2>
            <p>Kami menerima permintaan untuk mereset password akun Anda. Klik tombol di bawah untuk melanjutkan proses reset password:</p>

            <div class='button-container'>
                <a href='{{ $resetLink }}' class='btn'>Reset Password</a>
            </div>

            <p>Atau salin dan tempel link berikut ke browser Anda:</p>
            <p style='word-break: break-all; background-color: #f9f9f9; padding: 10px; border-radius: 4px; font-size: 13px; color: #666;'>{{ $resetLink }}</p>

            <div class='info-box'>
                <strong>Penting:</strong> Link reset password ini hanya berlaku selama <strong>15 menit</strong>. Jika Anda tidak mereset password dalam waktu tersebut, Anda harus meminta link baru.
            </div>

            <div class='divider'></div>

            <p style='color: #888; font-size: 14px;'><strong>Keamanan:</strong> Jika Anda tidak meminta reset password ini, abaikan email ini dan hubungi tim support kami segera.</p>
        </div>
        <div class='footer'>
            <p>© {{ date('Y') }} POS API. Semua hak dilindungi.</p>
            <p>Email ini dikirim ke <strong>{{ $user->email }}</strong></p>
            <p style='font-size: 12px; color: #bbb;'>Mohon jangan membalas email ini, karena mailbox ini tidak dimonitor.</p>
        </div>
    </div>
</body>
</html>


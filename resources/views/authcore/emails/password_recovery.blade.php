<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Recuperación de contraseña</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:Arial, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 0; background-color:#f1f5f9;">
    <tr>
        <td align="center">

            <!-- Card -->
            <table width="600" cellpadding="0" cellspacing="0"
                   style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.08);">

                <!-- Header -->
                <tr>
                    <td style="background:#0f172a; padding:30px; text-align:center;">
                        <h1 style="color:#ffffff; margin:0; font-size:22px;">
                            TalentSafe Seguridad
                        </h1>
                        <p style="color:#94a3b8; margin-top:8px; font-size:14px;">
                            Recuperación de contraseña
                        </p>
                    </td>
                </tr>

                <!-- Body -->
                <tr>
                    <td style="padding:40px 30px; text-align:center;">

                        <h2 style="color:#0f172a; margin-bottom:15px;">
                            Código de verificación
                        </h2>

                        <p style="color:#475569; font-size:15px; margin-bottom:30px;">
                            Usa el siguiente código para restablecer tu contraseña.
                        </p>

                        <!-- OTP BOX -->
                        <div style="
                            display:inline-block;
                            padding:18px 30px;
                            background:#f8fafc;
                            border:2px dashed #2563eb;
                            border-radius:10px;
                            font-size:32px;
                            letter-spacing:8px;
                            font-weight:bold;
                            color:#0f172a;
                        ">
                            {{ $otp }}
                        </div>

                        <p style="margin-top:30px; font-size:13px; color:#64748b;">
                            Este código expira en <strong>10 minutos</strong>.
                        </p>

                    </td>
                </tr>

                <!-- Footer -->
                <tr>
                    <td style="background:#f8fafc; padding:25px; text-align:center; font-size:12px; color:#94a3b8;">
                        Si no solicitaste este código, puedes ignorar este mensaje.
                        <br><br>
                        © {{ date('Y') }} TalentSafe. Todos los derechos reservados.
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Tus accesos de Communication 360</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:Arial, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 0; background-color:#f1f5f9;">
    <tr>
        <td align="center">

            <table width="600" cellpadding="0" cellspacing="0"
                   style="background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.08);">

                <tr>
                    <td style="background:#0f172a; padding:30px; text-align:center;">
                        <h1 style="color:#ffffff; margin:0; font-size:22px;">
                            TalentSafe Comunicación 360
                        </h1>
                        <p style="color:#94a3b8; margin-top:8px; font-size:14px;">
                            Acceso a plataforma
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:40px 30px; text-align:center;">

                        <h2 style="color:#0f172a; margin:0 0 15px 0;">
                            Tus accesos están listos
                        </h2>

                        <p style="color:#475569; font-size:15px; margin:0 0 30px 0; line-height:1.6;">
                            Hola <strong>{{ $nombre }}</strong>,<br>
                            se generaron tus credenciales temporales para ingresar a <strong>Communication 360</strong>.
                        </p>

                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:24px;">
                            <tr>
                                <td style="padding:24px; text-align:left;">

                                    <div style="font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.6px; margin-bottom:8px;">
                                        Usuario
                                    </div>
                                    <div style="font-size:16px; font-weight:bold; color:#0f172a; margin-bottom:20px;">
                                        {{ $correo }}
                                    </div>

                                    <div style="font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:.6px; margin-bottom:8px;">
                                        Contraseña temporal
                                    </div>
                                    <div style="
                                        display:inline-block;
                                        padding:16px 22px;
                                        background:#ffffff;
                                        border:2px dashed #2563eb;
                                        border-radius:10px;
                                        font-size:24px;
                                        letter-spacing:2px;
                                        font-weight:bold;
                                        color:#0f172a;
                                    ">
                                        {{ $passwordPlano }}
                                    </div>

                                </td>
                            </tr>
                        </table>

                        <table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 24px auto;">
                            <tr>
                                <td align="center" bgcolor="#2563eb" style="border-radius:8px;">
                                    <a href="{{ $loginUrl ?? '#' }}"
                                       style="display:inline-block; padding:14px 28px; font-size:14px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">
                                        Ingresar mi Portal
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <div style="
                            background:#fff7ed;
                            border:1px solid #fed7aa;
                            border-radius:10px;
                            padding:16px 18px;
                            color:#9a3412;
                            font-size:14px;
                            line-height:1.6;
                            text-align:left;
                        ">
                            <strong>Importante:</strong> por seguridad, deberás cambiar tu contraseña al iniciar sesión.
                        </div>

                    </td>
                </tr>

                <tr>
                    <td style="background:#f8fafc; padding:25px; text-align:center; font-size:12px; color:#94a3b8;">
                        Si no reconoces este acceso, comunícate con el área correspondiente.
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
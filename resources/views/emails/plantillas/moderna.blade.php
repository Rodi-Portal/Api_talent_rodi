<!-- resources/views/emails/plantillas/moderna.blade.php -->
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <style>
  body {
    background-color: #f5f9ff;
    font-family: 'Segoe UI', sans-serif;
    padding: 40px;
    color: #333;
  }

  .containerPlantilla {
    background: #ffffff;
    border-radius: 12px;
    padding: 30px;
    max-width: 600px;
    margin: auto;
    box-shadow: 0 5px 25px rgba(0, 123, 255, 0.15);
  }

  .headerPlantilla {
    border-bottom: 3px solid #007bff;
    margin-bottom: 20px;
  }

  .contentPlantilla {
    font-size: 15px;
    line-height: 1.6;
  }

  .footerPlantilla {
    margin-top: 30px;
    font-size: 0.9em;
    color: #555;
    text-align: right;
    border-top: 1px dashed #ccc;
    padding-top: 20px;
  }
  </style>
</head>

<body>
  <div class="containerPlantilla">
    {{-- Logo inline --}}
    @if (!empty($logo_src))
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td align="center" style="padding-bottom: 20px;">
          <img src="{{ $logo_src }}" alt="Logo" width="200" style="display:block; height:auto; border:0;" />
        </td>
      </tr>
    </table>
    @endif
    <div class="headerPlantilla">
      <h1>{{ $titulo ?? 'Título predeterminado' }}</h1>
    </div>
    <div class="contentPlantilla">
      {!! $cuerpo !!}
    </div>
    @if(isset($saludo))
    <div class="footerPlantilla">{{ $saludo }}</div>
    @endif
  </div>
</body>

</html>
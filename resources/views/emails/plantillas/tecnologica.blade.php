<!-- resources/views/emails/plantillas/tecnologica.blade.php -->
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <style>
  body {
    background: #0f172a;
    font-family: 'Roboto', sans-serif;
    padding: 50px 20px;
    color: #e2e8f0;
  }

  .containerPlantilla {
    background: #1e293b;
    border-radius: 14px;
    max-width: 660px;
    margin: auto;
    padding: 35px;
    box-shadow: 0 0 25px rgba(0, 0, 0, 0.5);
  }


  .headerPlantilla {
    text-align: center;
    font-size: 24px;
    font-weight: bold;
    color: #38bdf8;
    margin-bottom: 25px;
  }

  .contentPlantilla {
    font-size: 15px;
    line-height: 1.8;
    color: #cbd5e1;
  }

  .footerPlantilla {
    margin-top: 30px;
    border-top: 1px solid #334155;
    padding-top: 15px;
    text-align: right;
    font-size: 14px;
    color: #94a3b8;
  }
  </style>
</head>

<body>
  <div class="containerPlantilla">
    {{-- Logo inline --}}
    @if (!empty($logo_src))
    <img src="{{ $logo_src }}" alt="Logo" width="200" style="display:block; height:auto; margin:0 auto 20px;" />
    @endif
    <div class="headerPlantilla">{{ $titulo ?? 'Notificación importante' }}</div>
    <div class="contentPlantilla">
      {!! $cuerpo !!}
    </div>
    @if(isset($saludo))
    <div class="footerPlantilla">{{ $saludo }}</div>
    @endif
  </div>
</body>

</html>
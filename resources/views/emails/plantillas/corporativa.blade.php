<!-- resources/views/emails/plantillas/corporativa.blade.php -->
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <style>
  body {
    background-color: #121212;
    color: #f1f1f1;
    font-family: 'Helvetica Neue', sans-serif;
    padding: 40px;
  }

  .containerPlantilla {
    background: #1f1f1f;
    border-radius: 12px;
    padding: 30px;
    max-width: 650px;
    margin: auto;
    box-shadow: 0 5px 20px rgba(255, 255, 255, 0.1);
  }
  .contentPlantilla {
    line-height: 1.7;
    font-size: 16px;
    color: #ffffff !important;
}
  .logoPlantilla {
    max-height: 50px;
    margin-bottom: 20px;
  }

  .headerPlantilla {
    border-left: 5px solid #00bcd4;
    padding-left: 15px;
    margin-bottom: 20px;
  }

  .footerPlantilla {
    margin-top: 40px;
    font-size: 0.85em;
    color: #aaa;
    border-top: 1px solid #333;
    padding-top: 15px;
    text-align: center;
  }
  </style>
</head>

<body>
  <div class="containerPlantilla">
  {{-- Logo inline --}}
    @if (!empty($logo_src))
    <img src="{{ $logo_src }}" alt="Logo" width="200" style="display:block; height:auto; margin:0 auto 20px;" />
    @endif
    <div class="headerPlantilla">
      <h1>{{ $titulo ?? 'Título corporativo' }}</h1>
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

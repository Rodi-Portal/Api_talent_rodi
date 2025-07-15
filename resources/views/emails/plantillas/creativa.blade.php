<!-- resources/views/emails/plantillas/creativa.blade.php -->
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <style>
  body {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
    font-family: 'Poppins', sans-serif;
    padding: 40px;
    color: #333;
  }

  .containerPlantilla {
    background: #fff;
    border-radius: 20px;
    padding: 35px;
    max-width: 620px;
    margin: auto;
    box-shadow: 0 6px 30px rgba(255, 120, 50, 0.3);
    border: 3px solid #ffa07a;
  }

  .logoPlantilla {
    max-height: 70px;
    display: block;
    margin: 0 auto 20px;
  }

  .tituloPlantilla {
    text-align: center;
    color: #ff5722;
    font-size: 26px;
    margin-bottom: 20px;
  }

  .contenidoPlantilla {
    line-height: 1.7;
    font-size: 16px;
  }

  .saludoPlantilla {
    margin-top: 30px;
    text-align: right;
    color: #555;
    font-style: italic;
    border-top: 1px dotted #ffa07a;
    padding-top: 15px;
  }
  </style>
</head>

<body>
  <div class="containerPlantilla">
  {{-- Logo inline --}}
    @if (!empty($logo_src))
    <img src="{{ $logo_src }}" alt="Logo" width="200" style="display:block; height:auto; margin:0 auto 20px;" />
    @endif
    <div class="tituloPlantilla">{{ $titulo ?? 'Mensaje Creativo' }}</div>
    <div class="contenidoPlantilla">
      {!! $cuerpo !!}
    </div>
    @if(isset($saludo))
    <div class="saludoPlantilla">{{ $saludo }}</div>
    @endif
  </div>
</body>

</html>
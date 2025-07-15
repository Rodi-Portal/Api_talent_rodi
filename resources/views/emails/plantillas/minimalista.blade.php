<!-- resources/views/emails/plantillas/minimalista.blade.php -->
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <style>
  body {
    background-color: #ffffff;
    font-family: 'Arial', sans-serif;
    color: #222;
    padding: 40px 20px;
  }

  .container {
    max-width: 600px;
    margin: auto;
    border: 1px solid #ddd;
    padding: 30px;
    border-radius: 8px;
  }



  h1 {
    font-size: 24px;
    text-align: center;
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
    margin-bottom: 30px;
  }

  .content {
    font-size: 15px;
    line-height: 1.6;
  }

  .footer {
    margin-top: 40px;
    text-align: right;
    font-style: italic;
    font-size: 14px;
    color: #777;
  }
  </style>
</head>

<body>
  <div class="container">
  {{-- Logo inline --}}
    @if (!empty($logo_src))
    <img src="{{ $logo_src }}" alt="Logo" width="200" style="display:block; height:auto; margin:0 auto 20px;" />
    @endif
    <h1>{{ $titulo ?? 'Título' }}</h1>
    <div class="content">
      {!! $cuerpo !!}
    </div>
    @if(isset($saludo))
    <div class="footer">{{ $saludo }}</div>
    @endif
  </div>
</body>

</html>
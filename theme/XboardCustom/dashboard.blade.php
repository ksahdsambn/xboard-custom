<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <link rel="stylesheet" href="/theme/{{$theme}}/assets/wallet-center.css">
  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR',
        'fr-FR',
        'de-DE',
        'es-ES',
        'it-IT',
        'pt-PT',
        'nl-NL',
        'pl-PL',
        'tr-TR',
        'ru-RU',
        'ar-SA',
        'nb-NO',
        'sv-SE',
        'fi-FI'
      ],
      logo: '{{$logo}}'
    };
    window.xboardCustom = {
      theme: '{{$theme}}',
      version: '1.0.0',
      walletHash: '#/dashboard?xc_wallet=1'
    };
  </script>
  <script src="/theme/{{$theme}}/assets/i18n-extra.js"></script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
</head>

<body>
  <div id="app"></div>
  {!! $theme_config['custom_html'] !!}
  <script src="/theme/{{$theme}}/assets/wallet-center.js"></script>
</body>

</html>

{{--
    租户告警 WS 订阅注入（Admin 面板 panels::body.end）。

    1) 输出 window.__tenantAlerts（租户 ID + Reverb 连接参数）；
    2) 加载 Vite 构建产物 alerts.js（未执行 npm run build 时静默跳过，
       保证纯后端/测试环境页面不受影响）。
--}}
@php
    $tenantId = auth()->user()?->tenant_id;
    $reverbOptions = (array) config('broadcasting.connections.reverb.options', []);

    $alertsConfig = [
        'tenantId' => (int) $tenantId,
        'key' => (string) config('broadcasting.connections.reverb.key', ''),
        'host' => (string) ($reverbOptions['host'] ?? 'localhost'),
        'port' => (int) ($reverbOptions['port'] ?? 8080),
        'scheme' => (string) ($reverbOptions['scheme'] ?? 'http'),
    ];

    try {
        $alertsAsset = \Illuminate\Support\Facades\Vite::asset('resources/js/alerts.js');
    } catch (\Throwable) {
        $alertsAsset = null;
    }
@endphp

@if ($tenantId !== null)
    <script>
        window.__tenantAlerts = {{ \Illuminate\Support\Js::from($alertsConfig) }};
    </script>

    @if ($alertsAsset !== null)
        <script type="module" src="{{ $alertsAsset }}"></script>
    @endif
@endif

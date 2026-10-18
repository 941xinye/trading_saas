/**
 * 租户告警 WebSocket 订阅（Laravel Reverb）。
 *
 * 由 Admin 面板 renderHook（panels::body.end）注入：
 * window.__tenantAlerts 提供 { tenantId, key, host, port, scheme }，
 * 随后加载本脚本订阅 private-tenant.{tenantId}.alerts 频道
 * （授权见 routes/channels.php，跨租户订阅被 /broadcasting/auth 拒绝）。
 *
 * 收到 breaker.alert（App\Events\BreakerAlert，broadcastAs）时弹 Filament 危险通知；
 * FilamentNotification 不可用时降级 console.warn。
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const config = window.__tenantAlerts;

if (config && config.tenantId && config.key) {
    const echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    echo.private(`tenant.${config.tenantId}.alerts`)
        .listen('.breaker.alert', (event) => {
            const body = `实例 #${event.instance_id} 熔断状态迁移至 ${event.state}（规则：${event.rule_type}）`;

            if (window.FilamentNotification) {
                new window.FilamentNotification()
                    .title('熔断告警')
                    .body(body)
                    .danger()
                    .persistent()
                    .send();
            } else {
                console.warn('[tenant-alerts]', body, event);
            }
        });
}

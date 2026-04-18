# haoc/opentelemetry-laravel

Pacote de instrumentação OpenTelemetry para aplicações Laravel do HAOC.

## Instalação

No `composer.json` da aplicação, adicione o repositório local:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../haoc-opentelemetry/packages/laravel"
    }
  ],
  "require": {
    "haoc/opentelemetry-laravel": "*"
  }
}
```

O package auto-discovery do Laravel registra o service provider automaticamente.

## Configuração

Publique o config (opcional):

```bash
php artisan vendor:publish --tag=haoc-otel-config
```

Variáveis de ambiente:

| Variável | Default | Descrição |
|---|---|---|
| `OTEL_SERVICE_NAME` | `APP_NAME` | Nome do serviço no SigNoz |
| `OTEL_ENVIRONMENT` | `APP_ENV` | Ambiente (local/dev/prod) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://host.docker.internal:4318` | Endpoint do OTLP Collector |

## Middleware

Registre o middleware `TraceRequest` no Kernel ou nas rotas:

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Haoc\OpenTelemetry\Middleware\TraceRequest::class);
})

// Ou no Kernel.php (Laravel 10)
protected $middleware = [
    \Haoc\OpenTelemetry\Middleware\TraceRequest::class,
];
```

### O que o middleware captura

- `http.method`, `http.route`, `http.url`, `http.status_code`, `http.duration_ms`
- **User Identity**: `haoc.user.id`, `haoc.user.email` (do Auth)
- **Infraestrutura**: `X-Forwarded-For`, `X-Real-IP`, `Via`, `network.hop_count`
- **Baggage do Frontend**: atributos `page.*`, `browser.*`, `device.*`, `app.*`
- **Query/Route/Body params** (com redação de campos sensíveis)

## Logging

Adicione o canal OTLP na config de logging:

```php
// config/logging.php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'otlp'],
    ],
    'otlp' => [
        'driver' => 'custom',
        'via' => \Haoc\OpenTelemetry\Logging\OtelLogChannelFactory::class,
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],
```

## Docker

No `docker-compose.yml`, adicione a rede do SigNoz e monte o pacote:

```yaml
services:
  app:
    volumes:
      - ../haoc-opentelemetry/packages/laravel:/var/www/vendor/haoc/opentelemetry-laravel
    networks:
      - app
      - signoz

networks:
  signoz:
    name: signoz-shared
    external: true
```

## Atributos Capturados (Spans)

### Request (notação de ponto)

| Atributo | Exemplo |
|---|---|
| `http.method` | `GET` |
| `http.route` | `/api/pacientes` |
| `http.status_code` | `200` |
| `http.duration_ms` | `45` |
| `query.search` | `Maria` |
| `query.page` | `1` |
| `body.name` | `João` |
| `body.password` | `[REDACTED]` |
| `params.id` | `42` |
| `response.data.total` | `10` |

### User Identity (do Auth Laravel)

| Atributo | Exemplo |
|---|---|
| `haoc.user.id` | `PAC12345` |
| `haoc.user.email` | `maria@email.com` |

### Infraestrutura / Hops

| Atributo | Exemplo |
|---|---|
| `http.forwarded_for` | `10.0.0.1, 172.16.0.1` |
| `http.real_ip` | `10.0.0.1` |
| `network.hop_count` | `2` |

### Baggage do Frontend (W3C)

| Atributo | Origem |
|---|---|
| `page.route` | `@haoc/opentelemetry-web` |
| `browser.name` | `@haoc/opentelemetry-web` |
| `device.type` | `@haoc/opentelemetry-web` |
| `haoc.user.id` | `@haoc/opentelemetry-web` |

## Dados Sensíveis (Redação Automática)

Campos automaticamente redatados como `[REDACTED]`:

`password`, `senha`, `secret`, `token`, `access_token`, `refresh_token`, `authorization`, `db_password`, `network_password`, `tasy_password`

## Licença

MIT

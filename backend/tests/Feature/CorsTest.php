<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Без credentials в CORS браузер не отдаёт фронтенду ни одного ответа API:
 * запросы идут с cookie, а на «*» с cookie браузер отвечает отказом.
 * Сервер при этом отвечает 200, так что поломка видна только в браузере.
 */
class CorsTest extends TestCase
{
    public function test_сайт_получает_ответ_с_cookie(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:4200'])
            ->options('/api/v1/home', [], ['Access-Control-Request-Method' => 'GET'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_админка_тоже_разрешена(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:4300'])
            ->options('/api/v1/home', [], ['Access-Control-Request-Method' => 'GET'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4300');
    }

    public function test_чужой_адрес_не_разрешён(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->options('/api/v1/home', [], ['Access-Control-Request-Method' => 'GET'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}

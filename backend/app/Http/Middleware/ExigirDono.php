<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra o operador nas rotas que são só do dono (D-61).
 *
 * A linha entre os dois papéis é **quem altera o estado do jogo de forma difícil de desfazer**:
 * gerir admins (que pode trancar o painel) e realocar colônias (que muda a distância — o eixo de
 * toda a logística — e afeta o mundo de outros jogadores).
 *
 * O operador continua julgando casos, publicando notícias, distribuindo o Tesouro, e vendo,
 * suspendendo e corrigindo jogadores. É bastante poder; só não é este.
 *
 * A checagem é no **servidor**, não só na tela: esconder o botão sem barrar a rota faria da divisão
 * de papéis uma sugestão, e um operador que soubesse a URL passaria por cima dela.
 */
class ExigirDono
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin?->ehDono()) {
            abort(403, 'Só o dono pode fazer isto.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\VersaoDoSistema;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Acrescenta `X-MyMoney-Versao: 0.1.0 (ab07d43)` às respostas web, inclusive às de erro.
 *
 * Serve ao suporte: dizer, sem acesso ao servidor, qual versão respondeu. É só a identificação
 * pública do build.
 */
class AdicionarCabecalhoDeVersao
{
    public const CABECALHO = 'X-MyMoney-Versao';

    public function __construct(private readonly VersaoDoSistema $versao) {}

    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);
        $resposta->headers->set(self::CABECALHO, $this->versao->rotuloParaCabecalho());

        return $resposta;
    }
}

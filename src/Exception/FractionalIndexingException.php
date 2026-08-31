<?php

namespace FractionalIndexing\Exception;

/**
 * Exceção customizada lançada pela biblioteca Fractional Indexing.
 *
 * Utilizada para sinalizar erros de uso da biblioteca, como:
 * - Parâmetros inválidos (ex: alfabetos não suportados, `$n < 0`);
 * - Chaves de ordem fora do formato esperado (ex: trailing zeros, head inválido);
 * - Limites do algoritmo atingidos (quando não é mais possível incrementar ou decrementar a parte inteira).
 *
 * Estende `\InvalidArgumentException` pois a esmagadora maioria dos erros provém de inputs malformados.
 */
class FractionalIndexingException extends \InvalidArgumentException
{
}
